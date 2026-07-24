<?php

namespace App\Controllers;

use App\Entities\Acuerdo;
use App\Entities\AcuerdoDetalle;
use App\Entities\Avance;
use App\Entities\UsuarioRef;
use App\Libraries\Recordatorios\NotificadorAsignacion;
use App\Libraries\Recordatorios\Programador;
use App\Models\AcuerdoCorresponsableModel;
use App\Models\AcuerdoModel;
use App\Models\AreaModel;
use App\Models\AuditoriaModel;
use App\Models\AvanceModel;
use App\Models\ConfiguracionModel;
use App\Models\GoogleSyncModel;
use App\Models\RecordatorioEnviadoModel;
use App\Models\ReunionModel;
use App\Models\UsuarioModel;
use App\Policies\VisibilidadAcuerdos;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\I18n\Time;
use Config\Database;
use Throwable;

/**
 * GET /acuerdos, GET /acuerdos/{id} (doc 05 §2.2) — LECTURA (Tarea 5).
 * POST /acuerdos/lote, PATCH /acuerdos/{id}, PUT .../corresponsables,
 * POST .../avances — ESCRITURA (Tarea 6, S1.5).
 * PATCH .../concluir, PATCH .../reabrir, GET /checklist — CONCLUSIÓN/
 * REAPERTURA + checklist de validación (Tarea 7, S1.6). Regla (ADR-012):
 * **Dirección concluye cualquier acuerdo; el coordinador concluye los de su
 * área**; **reabrir/eliminar siguen siendo solo de Dirección** (403 +
 * auditoría del intento para quien no tenga permiso).
 */
class AcuerdosController extends BaseController
{
    /** Campos que SÍ acepta `NuevoAcuerdo` (doc 05 §2.2 / types.ts) — cualquier otro → 422 (OW-08). */
    private const CAMPOS_NUEVO_ACUERDO = [
        'tema', 'accion', 'responsable_id', 'corresponsables_ids', 'area_id',
        'fecha_compromiso', 'enlace', 'enlaces', 'observaciones', 'recordatorio_dias',
    ];

    /** Campos que SÍ acepta `EdicionAcuerdo` (opcionales) — cualquier otro → 422 (`campo_no_permitido`/OW-08). */
    private const CAMPOS_EDICION_ACUERDO = [
        'tema', 'accion', 'responsable_id', 'area_id', 'enlace', 'enlaces', 'observaciones', 'recordatorio_dias',
    ];

    /** Tope defensivo de enlaces de productos por acuerdo. */
    private const MAX_ENLACES = 20;

    /** Campos que SÍ acepta `NuevoAvance`. */
    private const CAMPOS_NUEVO_AVANCE = ['descripcion', 'nueva_fecha'];

    private const PER_PAGE_DEFAULT = 50;
    private const PER_PAGE_MAX     = 200;

    /** "Hoy" en TZ America/Ciudad_Juarez (App.php `appTimezone`) para el estado derivado (RF-05.2). */
    private function hoy(): string
    {
        return Time::now()->toDateString();
    }

    /**
     * Sincronización inmediata con Google Calendar (ADR-009): best-effort
     * DESPUÉS del commit, para que el evento aparezca al momento en lugar de
     * esperar la corrida diaria. `CalendarSync::sincronizar()` ya no propaga
     * fallos de la API (deja la fila `google_sync` en pendiente/error y el job
     * diario la reintenta); el try/catch defensivo garantiza que ningún
     * problema inesperado rompa la respuesta HTTP de una escritura confirmada.
     */
    private function sincronizarCalendarioAhora(int ...$acuerdoIds): void
    {
        foreach ($acuerdoIds as $acuerdoId) {
            try {
                service('calendarSync')->sincronizar($acuerdoId);
            } catch (Throwable $e) {
                log_message('error', 'Sincronización inmediata de calendario falló para acuerdo {id}: {msg}', [
                    'id'  => $acuerdoId,
                    'msg' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Notificación inmediata de asignación (ADR-010): correo al responsable y
     * corresponsables al capturar. Best-effort DESPUÉS del commit — el propio
     * NotificadorAsignacion no propaga fallos por destinatario; este try/catch
     * cubre cualquier imprevisto para no romper la respuesta HTTP.
     */
    private function notificarAsignaciones(int ...$acuerdoIds): void
    {
        $notificador = new NotificadorAsignacion();

        foreach ($acuerdoIds as $acuerdoId) {
            try {
                $notificador->notificar($acuerdoId);
            } catch (Throwable $e) {
                log_message('error', 'Notificación de asignación falló para acuerdo {id}: {msg}', [
                    'id'  => $acuerdoId,
                    'msg' => $e->getMessage(),
                ]);
            }
        }
    }

    public function index(): ResponseInterface
    {
        $actor = service('usuarioActual')->obtener();
        $hoy   = $this->hoy();

        $model      = new AcuerdoModel();
        $estadoExpr = AcuerdoModel::estadoDerivadoExpr($hoy);

        $builder = $model->builderConJoins($hoy);
        $builder = VisibilidadAcuerdos::aplicarAlListado($builder, $actor);

        $estadoFiltro = $this->request->getGet('estado');
        $this->aplicarFiltroEstado($builder, $estadoExpr, $estadoFiltro);

        $responsableId = $this->request->getGet('responsable_id');
        if ($responsableId !== null && $responsableId !== '') {
            $builder->where('acuerdos.responsable_id', (int) $responsableId);
        }

        // Filtro "míos" (ADR-013): acuerdos donde el actor es responsable o
        // corresponsable. Solo el valor literal '1' lo activa (doc 05 v1.8).
        if ($this->request->getGet('mios') === '1') {
            $actorId = (int) $actor['id'];
            $builder->groupStart()
                ->where('acuerdos.responsable_id', $actorId)
                ->orWhereIn('acuerdos.id', static function (BaseBuilder $b) use ($actorId): BaseBuilder {
                    return $b->select('acuerdo_id')->from('acuerdo_corresponsables')->where('usuario_id', $actorId);
                })
                ->groupEnd();
        }

        $desde = $this->request->getGet('desde');
        if ($desde !== null && $desde !== '') {
            $builder->where('acuerdos.fecha_compromiso >=', $desde);
        }

        $hasta = $this->request->getGet('hasta');
        if ($hasta !== null && $hasta !== '') {
            $builder->where('acuerdos.fecha_compromiso <=', $hasta);
        }

        $q = trim((string) $this->request->getGet('q'));
        if ($q !== '') {
            $builder->groupStart()
                ->like('acuerdos.tema', $q)
                ->orLike('acuerdos.accion', $q)
                ->orLike('resp.nombre', $q)
                ->groupEnd();
        }

        // Total ANTES de paginar (mismo builder, sin el SELECT de columnas ni el orden).
        $total = $builder->countAllResults(false);

        $perPage = (int) ($this->request->getGet('per_page') ?? self::PER_PAGE_DEFAULT);
        $perPage = $perPage > 0 ? min($perPage, self::PER_PAGE_MAX) : self::PER_PAGE_DEFAULT;
        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));

        $filas = $builder
            ->orderBy('acuerdos.fecha_compromiso', 'ASC')
            ->orderBy('acuerdos.id', 'ASC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        $corresponsablesPorAcuerdo = $this->cargarCorresponsables(array_map(static fn (array $f) => (int) $f['id'], $filas));

        $data = array_map(
            static fn (array $f) => Acuerdo::desdeFilaJoin($f, $corresponsablesPorAcuerdo[(int) $f['id']] ?? [])->aArray(),
            $filas,
        );

        return $this->response->setJSON([
            'data' => $data,
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
        ]);
    }

    /**
     * Filtro de estado sobre la expresión DERIVADA (RF-05.2). Se construye
     * como SQL crudo con el valor ya validado contra una lista blanca — nunca
     * interpolamos `$estadoFiltro` sin validar, y nunca usamos `whereIn()`
     * con una clave no-columna (su bind interno no tolera paréntesis/espacios).
     */
    private function aplicarFiltroEstado(BaseBuilder $builder, string $estadoExpr, ?string $estadoFiltro): void
    {
        $validos = ['en_proceso', 'vencido', 'concluido'];

        if ($estadoFiltro === null || $estadoFiltro === '' || $estadoFiltro === 'todos_abiertos') {
            $builder->where("({$estadoExpr}) IN ('en_proceso','vencido')", null, false);

            return;
        }

        if (! in_array($estadoFiltro, $validos, true)) {
            // Filtro desconocido: se comporta como "todos_abiertos" (ningún acuerdo del
            // dominio tiene un estado fuera de la lista blanca, así que no hay fuga de datos).
            $builder->where("({$estadoExpr}) IN ('en_proceso','vencido')", null, false);

            return;
        }

        $builder->where("({$estadoExpr}) = '{$estadoFiltro}'", null, false);
    }

    public function show(string $id): ResponseInterface
    {
        $actor = service('usuarioActual')->obtener();
        $hoy   = $this->hoy();
        $db    = Database::connect();

        $model   = new AcuerdoModel();
        $builder = $model->builderConJoins($hoy)->where('acuerdos.id', (int) $id);
        $fila    = $builder->get()->getFirstRow('array');

        if ($fila === null) {
            return $this->noEncontrado();
        }

        $esCorresponsable = $db->table('acuerdo_corresponsables')
            ->where('acuerdo_id', (int) $id)
            ->where('usuario_id', (int) $actor['id'])
            ->countAllResults() > 0;

        if (! VisibilidadAcuerdos::puedeVer($actor, $fila, $esCorresponsable)) {
            return $this->noEncontrado();
        }

        $corresponsables = $this->cargarCorresponsables([(int) $id])[(int) $id] ?? [];
        $acuerdo          = Acuerdo::desdeFilaJoin($fila, $corresponsables);

        $avancesFilas = (new AvanceModel())
            ->where('acuerdo_id', (int) $id)
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->findAll();
        $avances = $this->hidratarAvances($avancesFilas);

        $configGlobal = (new ConfiguracionModel())->recordatoriosDefault();
        $enviados     = (new RecordatorioEnviadoModel())->where('acuerdo_id', (int) $id)->findAll();

        // Programador espera `estado` YA derivado + `fecha_compromiso` + `recordatorio_dias` crudo.
        $filaParaProgramador = [
            'estado'            => $acuerdo->estado,
            'fecha_compromiso'  => $acuerdo->fechaCompromiso,
            'recordatorio_dias' => $acuerdo->recordatorioDias,
        ];
        $recordatorios = Programador::programadosDe($filaParaProgramador, $configGlobal, $enviados);

        $detalle = new AcuerdoDetalle($acuerdo, $avances, $recordatorios);

        return $this->response->setJSON(['data' => $detalle->aArray()]);
    }

    /**
     * POST /acuerdos/lote (doc 05 §2.2, RF-02) — captura transaccional de 1..N
     * acuerdos. Cualquier usuario activo. Todo-o-nada (regla №8 de CLAUDE.md):
     * si UN renglón es inválido no se persiste nada (ni reunión, ni acuerdos,
     * ni corresponsables, ni google_sync).
     */
    public function lote(): ResponseInterface
    {
        $actor = service('usuarioActual')->obtener();
        $body  = $this->cuerpoJson();
        if ($body === null) {
            return $this->errorValidacion('El cuerpo debe ser JSON.');
        }

        $camposDesconocidos = $this->camposDesconocidos($body, ['reunion', 'acuerdos']);
        if ($camposDesconocidos !== []) {
            return $this->errorCampoNoPermitido($camposDesconocidos);
        }

        $reunion = $body['reunion'] ?? null;
        if (! is_array($reunion) || ! is_string($reunion['nombre'] ?? null) || trim((string) $reunion['nombre']) === ''
            || ! is_string($reunion['fecha'] ?? null) || ! $this->esFechaValida((string) $reunion['fecha'])) {
            return $this->errorValidacion('El lote no se guardó: hay acuerdos incompletos.', ['reunion' => 'Nombre y fecha (YYYY-MM-DD) son requeridos']);
        }

        $acuerdos = $body['acuerdos'] ?? null;
        if (! is_array($acuerdos) || $acuerdos === [] || ! array_is_list($acuerdos)) {
            return $this->errorValidacion('El lote está vacío.');
        }

        $hoy = $this->hoy();
        $usuariosActivos = $this->idsUsuariosActivos();
        $areasActivas    = $this->idsAreasActivas();

        $campos = [];
        foreach ($acuerdos as $i => $n) {
            if (! is_array($n)) {
                $campos["acuerdos.{$i}"] = 'Debe ser un objeto';

                continue;
            }

            $extra = $this->camposDesconocidos($n, self::CAMPOS_NUEVO_ACUERDO);
            if ($extra !== []) {
                return $this->errorCampoNoPermitido(array_map(static fn (string $c) => "acuerdos.{$i}.{$c}", $extra));
            }
            if (array_key_exists('estado', $n)) {
                return $this->errorCampoNoPermitido(["acuerdos.{$i}.estado"]);
            }

            $this->validarNuevoAcuerdo($i, $n, $campos, $hoy, $usuariosActivos, $areasActivas);
        }

        if ($campos !== []) {
            return $this->errorValidacion('El lote no se guardó: hay acuerdos incompletos.', $campos);
        }

        $db = Database::connect();
        $db->transException(true)->transStart();

        $reunionId = (new ReunionModel())->obtenerOCrear(trim((string) $reunion['nombre']), (string) $reunion['fecha']);

        $idsCreados = [];
        foreach ($acuerdos as $n) {
            $idsCreados[] = $this->insertarAcuerdo($reunionId, $n, (int) $actor['id']);
        }

        $db->transComplete();

        if (! $db->transStatus()) {
            return $this->response->setStatusCode(500)->setJSON([
                'error'   => 'error_interno',
                'mensaje' => 'No se pudo guardar el lote. Intenta de nuevo.',
            ]);
        }

        $this->sincronizarCalendarioAhora(...$idsCreados);
        $this->notificarAsignaciones(...$idsCreados);

        $data = array_map(fn (int $id) => $this->cargarAcuerdoCompleto($id, $hoy)->aArray(), $idsCreados);

        return $this->response->setStatusCode(201)->setJSON(['data' => $data]);
    }

    /**
     * PATCH /acuerdos/{id} (doc 05 §2.2) — Dirección o coordinación del área
     * del acuerdo. Campos estructurales opcionales; `estado` nunca se acepta
     * (422 `campo_no_permitido`, regla №5 de CLAUDE.md).
     */
    public function update(string $id): ResponseInterface
    {
        $actor = service('usuarioActual')->obtener();
        $hoy   = $this->hoy();

        $acuerdoModel = new AcuerdoModel();
        $builder      = $acuerdoModel->builderConJoins($hoy)->where('acuerdos.id', (int) $id);
        $fila         = $builder->get()->getFirstRow('array');
        if ($fila === null) {
            return $this->noEncontrado();
        }

        $db                = Database::connect();
        $esCorresponsable  = $db->table('acuerdo_corresponsables')
            ->where('acuerdo_id', (int) $id)->where('usuario_id', (int) $actor['id'])->countAllResults() > 0;
        if (! VisibilidadAcuerdos::puedeVer($actor, $fila, $esCorresponsable)) {
            return $this->noEncontrado();
        }

        if (! $this->puedeEditarEstructura($actor, $fila)) {
            return $this->sinPermiso('No puedes editar este acuerdo.');
        }

        $body = $this->cuerpoJson();
        if ($body === null) {
            return $this->errorValidacion('El cuerpo debe ser JSON.');
        }

        if (array_key_exists('estado', $body)) {
            return $this->errorCampoNoPermitido(['estado']);
        }

        $camposDesconocidos = $this->camposDesconocidos($body, self::CAMPOS_EDICION_ACUERDO);
        if ($camposDesconocidos !== []) {
            return $this->errorCampoNoPermitido($camposDesconocidos);
        }

        $usuariosActivos = $this->idsUsuariosActivos();
        $areasActivas    = $this->idsAreasActivas();
        $campos          = [];

        if (array_key_exists('accion', $body) && (! is_string($body['accion']) || trim($body['accion']) === '')) {
            $campos['accion'] = 'Requerido';
        }
        if (array_key_exists('responsable_id', $body) && ! in_array((int) $body['responsable_id'], $usuariosActivos, true)) {
            $campos['responsable_id'] = 'Usuario inactivo o inexistente';
        }
        if (array_key_exists('area_id', $body) && ! in_array((int) $body['area_id'], $areasActivas, true)) {
            $campos['area_id'] = 'Área inactiva o inexistente';
        }
        if (array_key_exists('enlace', $body) && $body['enlace'] !== null && ! $this->esEnlaceValido($body['enlace'])) {
            $campos['enlace'] = 'Debe ser una URL http(s)';
        }
        if (array_key_exists('enlaces', $body) && ! $this->esEnlacesValido($body['enlaces'])) {
            $campos['enlaces'] = 'Cada enlace debe ser una URL http(s) (máx. ' . self::MAX_ENLACES . ')';
        }
        if (array_key_exists('recordatorio_dias', $body) && ! $this->esRecordatorioDiasValido($body['recordatorio_dias'])) {
            $campos['recordatorio_dias'] = 'Cada aviso debe estar entre 0 y 30 días';
        }

        // Corresponsable ≠ responsable: si se cambia el responsable, valida contra los corresponsables actuales.
        if (array_key_exists('responsable_id', $body)) {
            $corresponsablesActuales = array_map(static fn (array $c) => (int) $c['usuario_id'],
                $db->table('acuerdo_corresponsables')->where('acuerdo_id', (int) $id)->get()->getResultArray());
            if (in_array((int) $body['responsable_id'], $corresponsablesActuales, true)) {
                $campos['responsable_id'] = 'El responsable no puede ser corresponsable';
            }
        }

        if ($campos !== []) {
            return $this->errorValidacion('Revisa los campos del acuerdo.', $campos);
        }

        $update = [];
        foreach (self::CAMPOS_EDICION_ACUERDO as $campo) {
            if (! array_key_exists($campo, $body)) {
                continue;
            }
            $update[$campo] = match ($campo) {
                'tema', 'enlace', 'observaciones' => $body[$campo] === null ? null : (trim((string) $body[$campo]) !== '' ? trim((string) $body[$campo]) : null),
                'accion' => trim((string) $body['accion']),
                'responsable_id', 'area_id' => (int) $body[$campo],
                // Columnas JSON: el Query Builder no serializa arrays PHP automáticamente
                // en UPDATE (a diferencia de InitialSeeder/insert, que lo hacen a mano).
                'recordatorio_dias' => $body[$campo] === null ? null : json_encode($body[$campo]),
                'enlaces' => ($norm = $this->normalizarEnlaces(is_array($body[$campo]) ? $body[$campo] : [])) === [] ? null : json_encode($norm),
                default => $body[$campo],
            };
        }

        if ($update === []) {
            // Nada que cambiar; devolvemos el acuerdo tal cual (evita un UPDATE vacío).
            return $this->response->setJSON(['data' => $this->cargarAcuerdoCompleto((int) $id, $hoy)->aArray()]);
        }

        $db->transException(true)->transStart();
        $acuerdoModel->update((int) $id, $update);
        (new GoogleSyncModel())->marcarPendientePorAcuerdo((int) $id);
        (new AuditoriaModel())->registrar((int) $actor['id'], 'editar', 'acuerdo', (int) $id, ['cambios' => array_keys($update)], $this->request->getIPAddress());
        $db->transComplete();

        if (! $db->transStatus()) {
            return $this->response->setStatusCode(500)->setJSON(['error' => 'error_interno', 'mensaje' => 'No se pudo guardar el cambio.']);
        }

        $this->sincronizarCalendarioAhora((int) $id);

        return $this->response->setJSON(['data' => $this->cargarAcuerdoCompleto((int) $id, $hoy)->aArray()]);
    }

    /**
     * PUT /acuerdos/{id}/corresponsables (doc 05 §2.2) — Dirección o
     * coordinación del área. Reemplaza TODO el conjunto (semántica PUT).
     */
    public function corresponsables(string $id): ResponseInterface
    {
        $actor = service('usuarioActual')->obtener();
        $hoy   = $this->hoy();

        $acuerdoModel = new AcuerdoModel();
        $fila         = $acuerdoModel->builderConJoins($hoy)->where('acuerdos.id', (int) $id)->get()->getFirstRow('array');
        if ($fila === null) {
            return $this->noEncontrado();
        }

        $db               = Database::connect();
        $esCorresponsable = $db->table('acuerdo_corresponsables')
            ->where('acuerdo_id', (int) $id)->where('usuario_id', (int) $actor['id'])->countAllResults() > 0;
        if (! VisibilidadAcuerdos::puedeVer($actor, $fila, $esCorresponsable)) {
            return $this->noEncontrado();
        }

        if (! $this->puedeEditarEstructura($actor, $fila)) {
            return $this->sinPermiso('No puedes editar los corresponsables de este acuerdo.');
        }

        $body = $this->cuerpoJson();
        if ($body === null) {
            return $this->errorValidacion('El cuerpo debe ser JSON.');
        }

        $camposDesconocidos = $this->camposDesconocidos($body, ['usuarios_ids']);
        if ($camposDesconocidos !== []) {
            return $this->errorCampoNoPermitido($camposDesconocidos);
        }

        $usuariosIds = $body['usuarios_ids'] ?? null;
        if (! is_array($usuariosIds) || ! array_is_list($usuariosIds)) {
            return $this->errorValidacion('Revisa los corresponsables.', ['usuarios_ids' => 'Debe ser una lista de ids']);
        }

        $usuariosIds = array_map(static fn ($v) => (int) $v, $usuariosIds);
        $unicos      = array_values(array_unique($usuariosIds));

        $campos = [];
        if (count($unicos) !== count($usuariosIds)) {
            $campos['usuarios_ids'] = 'No se permiten corresponsables duplicados';
        } elseif (in_array((int) $fila['responsable_id'], $unicos, true)) {
            $campos['usuarios_ids'] = 'El responsable no puede ser corresponsable';
        } else {
            $activos = $this->idsUsuariosActivos();
            foreach ($unicos as $uid) {
                if (! in_array($uid, $activos, true)) {
                    $campos['usuarios_ids'] = 'Usuario inactivo o inexistente';

                    break;
                }
            }
        }

        if ($campos !== []) {
            return $this->errorValidacion('Revisa los corresponsables.', $campos);
        }

        $db->transException(true)->transStart();
        (new AcuerdoCorresponsableModel())->reemplazarDe((int) $id, $unicos);
        (new GoogleSyncModel())->marcarPendientePorAcuerdo((int) $id);
        (new AuditoriaModel())->registrar((int) $actor['id'], 'corresponsables', 'acuerdo', (int) $id, ['usuarios_ids' => $unicos], $this->request->getIPAddress());
        $db->transComplete();

        if (! $db->transStatus()) {
            return $this->response->setStatusCode(500)->setJSON(['error' => 'error_interno', 'mensaje' => 'No se pudo guardar el cambio.']);
        }

        $this->sincronizarCalendarioAhora((int) $id);

        return $this->response->setJSON(['data' => $this->cargarDetalleCompleto((int) $id, $hoy)->aArray()]);
    }

    /**
     * POST /acuerdos/{id}/avances (doc 05 §2.2, RF-07) — responsable,
     * corresponsables, coordinación del área o Dirección. Con `nueva_fecha` =
     * reprogramación (`vencido` → `en_proceso`); sobre `concluido` → 409.
     */
    public function avances(string $id): ResponseInterface
    {
        $actor = service('usuarioActual')->obtener();
        $hoy   = $this->hoy();

        $acuerdoModel = new AcuerdoModel();
        $fila         = $acuerdoModel->builderConJoins($hoy)->where('acuerdos.id', (int) $id)->get()->getFirstRow('array');
        if ($fila === null) {
            return $this->noEncontrado();
        }

        $db               = Database::connect();
        $esCorresponsable = $db->table('acuerdo_corresponsables')
            ->where('acuerdo_id', (int) $id)->where('usuario_id', (int) $actor['id'])->countAllResults() > 0;
        if (! VisibilidadAcuerdos::puedeVer($actor, $fila, $esCorresponsable)) {
            return $this->noEncontrado();
        }

        if (! $this->puedeRegistrarAvance($actor, $fila, $esCorresponsable)) {
            return $this->sinPermiso('No participas en este acuerdo.');
        }

        if ($fila['estado'] === 'concluido') {
            return $this->response->setStatusCode(409)->setJSON([
                'error'   => 'estado_invalido',
                'mensaje' => 'El acuerdo ya está concluido.',
            ]);
        }

        $body = $this->cuerpoJson();
        if ($body === null) {
            return $this->errorValidacion('El cuerpo debe ser JSON.');
        }

        $camposDesconocidos = $this->camposDesconocidos($body, self::CAMPOS_NUEVO_AVANCE);
        if ($camposDesconocidos !== []) {
            return $this->errorCampoNoPermitido($camposDesconocidos);
        }

        $campos = [];
        $descripcion = $body['descripcion'] ?? null;
        if (! is_string($descripcion) || trim($descripcion) === '') {
            $campos['descripcion'] = 'Requerido';
        }

        $nuevaFecha = $body['nueva_fecha'] ?? null;
        if ($nuevaFecha !== null) {
            if (! is_string($nuevaFecha) || ! $this->esFechaValida($nuevaFecha)) {
                $campos['nueva_fecha'] = 'Debe ser una fecha YYYY-MM-DD';
            } elseif ($nuevaFecha < $hoy) {
                $campos['nueva_fecha'] = 'Debe ser hoy o futura';
            }
        }

        if ($campos !== []) {
            return $this->errorValidacion('Revisa el avance.', $campos);
        }

        $esReprogramacion = $nuevaFecha !== null;

        $db->transException(true)->transStart();

        (new AvanceModel())->insert([
            'acuerdo_id'  => (int) $id,
            'usuario_id'  => (int) $actor['id'],
            'tipo'        => $esReprogramacion ? 'reprogramacion' : 'avance',
            'descripcion' => trim($descripcion),
            'nueva_fecha' => $esReprogramacion ? $nuevaFecha : null,
        ]);

        if ($esReprogramacion) {
            $update = ['fecha_compromiso' => $nuevaFecha];
            if ($fila['estado'] === 'vencido') {
                $update['estado'] = 'en_proceso'; // RF-05.3 / RF-07: reprogramar limpia el vencido.
            }
            $acuerdoModel->update((int) $id, $update);
            (new GoogleSyncModel())->marcarPendientePorAcuerdo((int) $id);
        }

        (new AuditoriaModel())->registrar(
            (int) $actor['id'],
            $esReprogramacion ? 'reprogramar' : 'avance',
            'acuerdo',
            (int) $id,
            null,
            $this->request->getIPAddress(),
        );

        $db->transComplete();

        if (! $db->transStatus()) {
            return $this->response->setStatusCode(500)->setJSON(['error' => 'error_interno', 'mensaje' => 'No se pudo registrar el avance.']);
        }

        if ($esReprogramacion) {
            $this->sincronizarCalendarioAhora((int) $id);
        }

        return $this->response->setJSON(['data' => $this->cargarDetalleCompleto((int) $id, $hoy)->aArray()]);
    }

    /**
     * GET /acuerdos/{id}/actividad — bitácora unificada (quick win #3): une
     * avances + auditoría de ciclo de vida (crear/editar/corresponsables) en
     * una sola línea de tiempo, sin duplicar concluir/reabrir (ya presentes
     * como avance validacion/reapertura). Mismo gate de visibilidad que
     * `show()` (ADR-007).
     */
    public function actividad(string $id): ResponseInterface
    {
        $actor = service('usuarioActual')->obtener();
        $hoy   = $this->hoy();
        $db    = Database::connect();

        $fila = (new AcuerdoModel())->builderConJoins($hoy)
            ->where('acuerdos.id', (int) $id)->get()->getFirstRow('array');
        if ($fila === null) {
            return $this->noEncontrado();
        }

        $esCorresponsable = $db->table('acuerdo_corresponsables')
            ->where('acuerdo_id', (int) $id)
            ->where('usuario_id', (int) $actor['id'])
            ->countAllResults() > 0;
        if (! VisibilidadAcuerdos::puedeVer($actor, $fila, $esCorresponsable)) {
            return $this->noEncontrado();
        }

        return $this->response->setJSON(['data' => $this->construirActividad((int) $id)]);
    }

    /**
     * PATCH /acuerdos/{id}/concluir (doc 05 §2.2, RF-06, ADR-012) — Dirección
     * concluye cualquier acuerdo; el **coordinador concluye los de su área**
     * (`puedeConcluir`). Quien no tenga permiso → 403 **y se audita el intento**
     * (doc 05 §4). Efecto: estado='concluido', concluido_por_id/at (respeta el
     * CHECK del DDL), avance `tipo='validacion'` con la nota, google_sync →
     * pendiente. Todo en transacción. `estado` nunca se acepta del cliente.
     */
    public function concluir(string $id): ResponseInterface
    {
        $actor = service('usuarioActual')->obtener();
        $hoy   = $this->hoy();

        $fila = (new AcuerdoModel())->builderConJoins($hoy)->where('acuerdos.id', (int) $id)->get()->getFirstRow('array');
        if ($fila === null) {
            return $this->noEncontrado();
        }

        // Dirección concluye cualquier acuerdo; el coordinador solo los de su área
        // (ADR-012). Todo intento denegado se AUDITA (posible abuso).
        if (! $this->puedeConcluir($actor, $fila)) {
            (new AuditoriaModel())->registrar(
                (int) $actor['id'],
                'intento_concluir',
                'acuerdo',
                (int) $id,
                ['rol' => $actor['rol'], 'resultado' => 'denegado'],
                $this->request->getIPAddress(),
            );

            return $this->sinPermiso('No tienes permiso para concluir este acuerdo.');
        }

        if ($fila['estado'] === 'concluido') {
            return $this->conflictoEstado('El acuerdo ya está concluido.');
        }

        $body = $this->cuerpoJson();
        if ($body === null) {
            return $this->errorValidacion('El cuerpo debe ser JSON.');
        }
        $camposDesconocidos = $this->camposDesconocidos($body, ['nota']);
        if ($camposDesconocidos !== []) {
            return $this->errorCampoNoPermitido($camposDesconocidos);
        }

        // Contrato (api.mock.ts): la nota de conclusión es opcional; si viene vacía
        // se usa un texto por defecto para el avance de validación.
        $notaCruda = $body['nota'] ?? null;
        $nota      = is_string($notaCruda) ? trim($notaCruda) : '';
        $descripcionAvance = $nota !== '' ? $nota : 'Validado desde el checklist.';

        $db = Database::connect();
        $db->transException(true)->transStart();

        (new AcuerdoModel())->update((int) $id, [
            'estado'           => 'concluido',
            'concluido_por_id' => (int) $actor['id'],
            'concluido_at'     => Time::now()->toDateTimeString(),
        ]);
        (new AvanceModel())->insert([
            'acuerdo_id'  => (int) $id,
            'usuario_id'  => (int) $actor['id'],
            'tipo'        => 'validacion',
            'descripcion' => $descripcionAvance,
            'nueva_fecha' => null,
        ]);
        (new GoogleSyncModel())->marcarPendientePorAcuerdo((int) $id);
        (new AuditoriaModel())->registrar((int) $actor['id'], 'concluir', 'acuerdo', (int) $id, ['nota' => $nota], $this->request->getIPAddress());

        $db->transComplete();

        if (! $db->transStatus()) {
            return $this->response->setStatusCode(500)->setJSON(['error' => 'error_interno', 'mensaje' => 'No se pudo concluir el acuerdo.']);
        }

        $this->sincronizarCalendarioAhora((int) $id);

        return $this->response->setJSON(['data' => $this->cargarAcuerdoCompleto((int) $id, $hoy)->aArray()]);
    }

    /**
     * PATCH /acuerdos/{id}/reabrir (doc 05 §2.2, RF-06) — **SOLO Dirección**.
     * Cualquier otro rol → 403 + auditoría del intento. Solo desde `concluido`
     * (si no → 409); `nota` obligatoria (vacía → 422). Efecto:
     * estado='en_proceso', limpia concluido_por_id/at (respeta el CHECK), avance
     * `tipo='reapertura'`, google_sync → pendiente. El vencimiento se recalcula
     * por lectura (estado derivado): si la fecha ya pasó, se leerá `vencido`.
     */
    public function reabrir(string $id): ResponseInterface
    {
        $actor = service('usuarioActual')->obtener();
        $hoy   = $this->hoy();

        $fila = (new AcuerdoModel())->builderConJoins($hoy)->where('acuerdos.id', (int) $id)->get()->getFirstRow('array');
        if ($fila === null) {
            return $this->noEncontrado();
        }

        if ($actor['rol'] !== 'direccion') {
            (new AuditoriaModel())->registrar(
                (int) $actor['id'],
                'intento_reabrir',
                'acuerdo',
                (int) $id,
                ['rol' => $actor['rol'], 'resultado' => 'denegado'],
                $this->request->getIPAddress(),
            );

            return $this->sinPermiso('Solo Dirección puede reabrir un acuerdo.');
        }

        if ($fila['estado'] !== 'concluido') {
            return $this->conflictoEstado('Solo se reabre un acuerdo concluido.');
        }

        $body = $this->cuerpoJson();
        if ($body === null) {
            return $this->errorValidacion('El cuerpo debe ser JSON.');
        }
        $camposDesconocidos = $this->camposDesconocidos($body, ['nota']);
        if ($camposDesconocidos !== []) {
            return $this->errorCampoNoPermitido($camposDesconocidos);
        }

        $notaCruda = $body['nota'] ?? null;
        $nota      = is_string($notaCruda) ? trim($notaCruda) : '';
        if ($nota === '') {
            return $this->errorValidacion('La nota de reapertura es obligatoria.', ['nota' => 'Requerida']);
        }

        $db = Database::connect();
        $db->transException(true)->transStart();

        // estado='en_proceso': el estado derivado en lectura (estadoDerivadoExpr)
        // lo mostrará como 'vencido' si la fecha ya pasó — no lo persistimos aquí.
        (new AcuerdoModel())->update((int) $id, [
            'estado'           => 'en_proceso',
            'concluido_por_id' => null,
            'concluido_at'     => null,
        ]);
        (new AvanceModel())->insert([
            'acuerdo_id'  => (int) $id,
            'usuario_id'  => (int) $actor['id'],
            'tipo'        => 'reapertura',
            'descripcion' => $nota,
            'nueva_fecha' => null,
        ]);
        (new GoogleSyncModel())->marcarPendientePorAcuerdo((int) $id);
        (new AuditoriaModel())->registrar((int) $actor['id'], 'reabrir', 'acuerdo', (int) $id, ['nota' => $nota], $this->request->getIPAddress());

        $db->transComplete();

        if (! $db->transStatus()) {
            return $this->response->setStatusCode(500)->setJSON(['error' => 'error_interno', 'mensaje' => 'No se pudo reabrir el acuerdo.']);
        }

        $this->sincronizarCalendarioAhora((int) $id);

        return $this->response->setJSON(['data' => $this->cargarAcuerdoCompleto((int) $id, $hoy)->aArray()]);
    }

    /**
     * DELETE /acuerdos/{id} (ADR-011, contrato v1.7) — **SOLO Dirección**.
     * Borrado definitivo: la BD limpia dependientes por cascada (avances,
     * corresponsables, recordatorios, google_sync — DDL doc 03) y el evento
     * de calendario se elimina best-effort tras el commit. El 403 de un
     * no-Dirección se audita (mismo criterio que concluir/reabrir); la
     * eliminación exitosa se audita con la ficha del acuerdo para conservar
     * rastro de QUÉ se borró.
     */
    public function eliminar(string $id): ResponseInterface
    {
        $actor = service('usuarioActual')->obtener();

        $fila = (new AcuerdoModel())->find((int) $id);
        if ($fila === null) {
            return $this->noEncontrado();
        }

        if ($actor['rol'] !== 'direccion') {
            (new AuditoriaModel())->registrar(
                (int) $actor['id'],
                'intento_eliminar',
                'acuerdo',
                (int) $id,
                ['rol' => $actor['rol'], 'resultado' => 'denegado'],
                $this->request->getIPAddress(),
            );

            return $this->sinPermiso('Solo Dirección puede eliminar un acuerdo.');
        }

        $db = Database::connect();

        // El id del evento y la ficha para el aviso se toman ANTES del delete
        // (la cascada borra google_sync y corresponsables).
        $sync    = $db->table('google_sync')->select('calendar_event_id')->where('acuerdo_id', (int) $id)->get()->getRowArray();
        $eventId = $sync['calendar_event_id'] ?? null;
        $aviso   = (new NotificadorAsignacion())->datosParaAvisoDeEliminacion((int) $id);

        $db->transException(true)->transStart();

        (new AuditoriaModel())->registrar(
            (int) $actor['id'],
            'eliminar',
            'acuerdo',
            (int) $id,
            ['accion' => $fila['accion'], 'estado' => $fila['estado'], 'fecha_compromiso' => $fila['fecha_compromiso']],
            $this->request->getIPAddress(),
        );
        $db->table('acuerdos')->where('id', (int) $id)->delete();

        $db->transComplete();

        if (! $db->transStatus()) {
            return $this->response->setStatusCode(500)->setJSON(['error' => 'error_interno', 'mensaje' => 'No se pudo eliminar el acuerdo.']);
        }

        if ($eventId !== null && $eventId !== '') {
            try {
                service('calendarSync')->eliminarEventoPorId((string) $eventId);
            } catch (Throwable $e) {
                log_message('error', 'Fallo al eliminar el evento de calendario del acuerdo {id}: {msg}', [
                    'id'  => $id,
                    'msg' => $e->getMessage(),
                ]);
            }
        }

        // Aviso por correo al responsable y corresponsables (ADR-011), best-effort.
        if ($aviso !== null) {
            try {
                (new NotificadorAsignacion())->avisarEliminacion($aviso);
            } catch (Throwable $e) {
                log_message('error', 'Fallo al avisar la eliminación del acuerdo {id}: {msg}', [
                    'id'  => $id,
                    'msg' => $e->getMessage(),
                ]);
            }
        }

        return $this->response->setStatusCode(204);
    }

    /**
     * GET /checklist (doc 05 §2.4, RF-06, ADR-012) — Dirección o coordinador.
     * Lista los acuerdos ABIERTOS (en_proceso + vencido; NO concluidos) que el
     * actor puede concluir: Dirección ve todos; el **coordinador solo los de su
     * área**. Priorizados: vencidos primero, luego por `fecha_compromiso` ASC.
     * Cada item lleva el acuerdo (forma `Acuerdo`), `total_avances` y
     * `ultimo_avance` (o null). Sin N+1: corresponsables y avances se agregan
     * con `whereIn` sobre la lista de ids.
     */
    public function checklist(): ResponseInterface
    {
        $actor = service('usuarioActual')->obtener();
        if (! in_array($actor['rol'], ['direccion', 'coordinador'], true)) {
            return $this->sinPermiso('El checklist de validación es solo para Dirección y Coordinación.');
        }

        $hoy        = $this->hoy();
        $estadoExpr = AcuerdoModel::estadoDerivadoExpr($hoy);

        // Solo abiertos; orden: vencidos primero (0), luego por fecha ASC.
        $builder = (new AcuerdoModel())->builderConJoins($hoy)
            ->where("({$estadoExpr}) IN ('en_proceso','vencido')", null, false);

        // El coordinador solo valida los acuerdos de su área (mismo criterio que puedeConcluir).
        if ($actor['rol'] === 'coordinador') {
            $builder->where('acuerdos.area_id', (int) $actor['area_id']);
        }

        $filas = $builder
            ->orderBy("CASE WHEN ({$estadoExpr}) = 'vencido' THEN 0 ELSE 1 END", 'ASC', false)
            ->orderBy('acuerdos.fecha_compromiso', 'ASC')
            ->orderBy('acuerdos.id', 'ASC')
            ->get()
            ->getResultArray();

        $ids = array_map(static fn (array $f) => (int) $f['id'], $filas);

        $corresponsablesPorAcuerdo = $this->cargarCorresponsables($ids);
        [$totalPorAcuerdo, $ultimoPorAcuerdo] = $this->agregarAvances($ids);

        $data = [];
        foreach ($filas as $f) {
            $aid     = (int) $f['id'];
            $acuerdo = Acuerdo::desdeFilaJoin($f, $corresponsablesPorAcuerdo[$aid] ?? []);
            $ultimo  = $ultimoPorAcuerdo[$aid] ?? null;

            $data[] = [
                'acuerdo'       => $acuerdo->aArray(),
                'total_avances' => $totalPorAcuerdo[$aid] ?? 0,
                'ultimo_avance' => $ultimo === null ? null : $ultimo->aArray(),
            ];
        }

        return $this->response->setJSON(['data' => $data]);
    }

    /**
     * Agrega los avances de un conjunto de acuerdos en UNA sola query (cero
     * N+1): devuelve [total_por_acuerdo, ultimo_avance_por_acuerdo]. El "último"
     * es el más reciente por (created_at, id) descendente.
     *
     * @param int[] $acuerdoIds
     *
     * @return array{0: array<int, int>, 1: array<int, Avance>}
     */
    private function agregarAvances(array $acuerdoIds): array
    {
        if ($acuerdoIds === []) {
            return [[], []];
        }

        $filas = (new AvanceModel())
            ->whereIn('acuerdo_id', $acuerdoIds)
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->findAll();

        $porAcuerdo = [];
        foreach ($filas as $f) {
            $porAcuerdo[(int) $f['acuerdo_id']][] = $f;
        }

        // Hidrata los usuarios de TODOS los avances con un solo whereIn.
        $avancesHidratados = $this->hidratarAvances($filas);
        $hidratadoPorId    = [];
        foreach ($avancesHidratados as $a) {
            $hidratadoPorId[$a->id] = $a;
        }

        $total  = [];
        $ultimo = [];
        foreach ($porAcuerdo as $aid => $lista) {
            $total[$aid]  = count($lista);
            $primero      = $lista[0]; // ya viene ordenado DESC → el más reciente.
            $ultimo[$aid] = $hidratadoPorId[(int) $primero['id']];
        }

        return [$total, $ultimo];
    }

    /**
     * Carga corresponsables de TODA la página en UNA sola query (`whereIn`
     * agrupado) — evita el N+1 de resolverlos acuerdo por acuerdo.
     *
     * @param int[] $acuerdoIds
     *
     * @return array<int, UsuarioRef[]> Indexado por acuerdo_id.
     */
    private function cargarCorresponsables(array $acuerdoIds): array
    {
        if ($acuerdoIds === []) {
            return [];
        }

        $filas = Database::connect()
            ->table('acuerdo_corresponsables ac')
            ->select('ac.acuerdo_id, u.id, u.nombre, u.email, u.avatar_color')
            ->join('usuarios u', 'u.id = ac.usuario_id', 'inner')
            ->whereIn('ac.acuerdo_id', $acuerdoIds)
            ->orderBy('u.nombre', 'ASC')
            ->get()
            ->getResultArray();

        $out = [];
        foreach ($filas as $f) {
            $aid         = (int) $f['acuerdo_id'];
            $out[$aid] ??= [];
            $out[$aid][] = UsuarioRef::desdeFila($f);
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $filas */
    private function hidratarAvances(array $filas): array
    {
        if ($filas === []) {
            return [];
        }

        $usuarioIds = array_values(array_unique(array_map(static fn (array $f) => (int) $f['usuario_id'], $filas)));
        $usuarios   = Database::connect()->table('usuarios')->whereIn('id', $usuarioIds)->get()->getResultArray();
        $porId      = [];
        foreach ($usuarios as $u) {
            $porId[(int) $u['id']] = UsuarioRef::desdeFila($u);
        }

        return array_map(
            static fn (array $f) => new Avance(
                (int) $f['id'],
                $porId[(int) $f['usuario_id']],
                (string) $f['tipo'],
                (string) $f['descripcion'],
                $f['nueva_fecha'],
                (string) $f['created_at'],
            ),
            $filas,
        );
    }

    /**
     * Une avances + auditoría de ciclo de vida en eventos normalizados,
     * ordenados desc por created_at. Cero N+1: un query por tabla + un
     * whereIn para los usuarios.
     *
     * @return list<array<string, mixed>>
     */
    private function construirActividad(int $id): array
    {
        $db = Database::connect();

        $avances = $db->table('avances')
            ->where('acuerdo_id', $id)->get()->getResultArray();
        $auditoria = $db->table('auditoria')
            ->where('entidad', 'acuerdo')->where('entidad_id', $id)
            ->whereIn('accion', ['crear', 'editar', 'corresponsables'])
            ->get()->getResultArray();

        // Hidratar usuarios de ambas fuentes en un solo whereIn.
        $ids = [];
        foreach ($avances as $f) {
            $ids[] = (int) $f['usuario_id'];
        }
        foreach ($auditoria as $f) {
            if ($f['usuario_id'] !== null) {
                $ids[] = (int) $f['usuario_id'];
            }
        }
        $porId = [];
        if ($ids !== []) {
            foreach ($db->table('usuarios')->whereIn('id', array_values(array_unique($ids)))->get()->getResultArray() as $u) {
                $porId[(int) $u['id']] = UsuarioRef::desdeFila($u)->aArray();
            }
        }

        $eventos = [];
        foreach ($avances as $f) {
            $eventos[] = [
                'id'          => 'avance:' . (int) $f['id'],
                'fuente'      => 'avance',
                'tipo'        => (string) $f['tipo'],
                'usuario'     => $porId[(int) $f['usuario_id']] ?? null,
                'descripcion' => (string) $f['descripcion'],
                'nueva_fecha' => $f['nueva_fecha'],
                'created_at'  => (string) $f['created_at'],
            ];
        }
        foreach ($auditoria as $f) {
            $uid = $f['usuario_id'] !== null ? (int) $f['usuario_id'] : null;
            $eventos[] = [
                'id'          => 'auditoria:' . (int) $f['id'],
                'fuente'      => 'auditoria',
                'tipo'        => (string) $f['accion'],
                'usuario'     => $uid !== null ? ($porId[$uid] ?? null) : null,
                'descripcion' => $this->descripcionAuditoria((string) $f['accion'], $f['detalle']),
                'nueva_fecha' => null,
                'created_at'  => (string) $f['created_at'],
            ];
        }

        // Orden desc por fecha; desempate estable por id textual.
        usort($eventos, static function (array $a, array $b): int {
            return [$b['created_at'], $b['id']] <=> [$a['created_at'], $a['id']];
        });

        return $eventos;
    }

    /** Texto legible para un evento de auditoría de ciclo de vida. */
    private function descripcionAuditoria(string $accion, ?string $detalleJson): string
    {
        if ($accion === 'crear') {
            return 'Creó el acuerdo';
        }
        if ($accion === 'corresponsables') {
            return 'Actualizó los corresponsables';
        }
        // 'editar' → "Actualizó: tema, responsable"
        $etiquetas = [
            'tema'              => 'tema',
            'accion'            => 'acción',
            'responsable_id'    => 'responsable',
            'area_id'           => 'área',
            'fecha_compromiso'  => 'fecha compromiso',
            'enlace'            => 'enlaces',
            'enlaces'           => 'enlaces',
            'observaciones'     => 'observaciones',
            'recordatorio_dias' => 'recordatorios',
        ];
        $detalle = $detalleJson !== null ? json_decode($detalleJson, true) : null;
        $campos  = is_array($detalle) && isset($detalle['cambios']) && is_array($detalle['cambios'])
            ? $detalle['cambios'] : [];
        $legibles = [];
        foreach ($campos as $c) {
            $legibles[$etiquetas[$c] ?? (string) $c] = true; // dedup (enlace/enlaces → "enlaces")
        }

        return $legibles === [] ? 'Editó el acuerdo' : 'Actualizó: ' . implode(', ', array_keys($legibles));
    }

    private function noEncontrado(): ResponseInterface
    {
        return $this->response->setStatusCode(404)->setJSON([
            'error'   => 'no_encontrado',
            'mensaje' => 'El acuerdo no existe o no es visible para tu cuenta.',
        ]);
    }

    private function sinPermiso(string $mensaje): ResponseInterface
    {
        return $this->response->setStatusCode(403)->setJSON(['error' => 'sin_permiso', 'mensaje' => $mensaje]);
    }

    /** 409 conflicto de estado (doc 05 §1): concluir un concluido, reabrir un no-concluido, etc. */
    private function conflictoEstado(string $mensaje): ResponseInterface
    {
        return $this->response->setStatusCode(409)->setJSON(['error' => 'estado_invalido', 'mensaje' => $mensaje]);
    }

    /** @param array<string, string> $campos */
    private function errorValidacion(string $mensaje, array $campos = []): ResponseInterface
    {
        $body = ['error' => 'validacion', 'mensaje' => $mensaje];
        if ($campos !== []) {
            $body['campos'] = $campos;
        }

        return $this->response->setStatusCode(422)->setJSON($body);
    }

    /**
     * 422 `campo_no_permitido` (doc 05 §2.2 nota de seguridad): `estado` o
     * cualquier otro campo desconocido en el body de creación/edición. Nunca
     * se acepta silenciosamente ni se ignora (regla №5 de CLAUDE.md / OW-08).
     *
     * @param string[] $campos
     */
    private function errorCampoNoPermitido(array $campos): ResponseInterface
    {
        return $this->response->setStatusCode(422)->setJSON([
            'error'   => 'campo_no_permitido',
            'mensaje' => 'El body contiene campos no permitidos.',
            'campos'  => array_fill_keys($campos, 'Campo no permitido'),
        ]);
    }

    /**
     * Decodifica el body JSON de la request. `null` si no es un objeto JSON
     * válido (el caller responde 422).
     *
     * @return array<string, mixed>|null
     */
    private function cuerpoJson(): ?array
    {
        $crudo = $this->request->getBody();
        if ($crudo === null || trim((string) $crudo) === '') {
            return [];
        }

        try {
            $decodificado = json_decode((string) $crudo, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decodificado) ? $decodificado : null;
    }

    /**
     * @param array<string, mixed> $body
     * @param string[]             $permitidos
     *
     * @return string[] Claves presentes en `$body` que NO están en `$permitidos`.
     */
    private function camposDesconocidos(array $body, array $permitidos): array
    {
        return array_values(array_diff(array_keys($body), $permitidos));
    }

    private function esFechaValida(string $fecha): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) && \DateTime::createFromFormat('Y-m-d', $fecha) !== false;
    }

    private function esEnlaceValido(mixed $enlace): bool
    {
        if (! is_string($enlace) || trim($enlace) === '') {
            return true; // cadena vacía se normaliza a null, no es un enlace invalido.
        }

        return (bool) preg_match('/^https?:\/\//i', trim($enlace));
    }

    /**
     * `enlaces`: null (o ausente), o una lista de URLs http(s). Se descartan las
     * cadenas vacías antes de validar; cada URL debe ser http(s) y ≤ 2048 chars,
     * y no se aceptan más de MAX_ENLACES por acuerdo.
     */
    private function esEnlacesValido(mixed $valor): bool
    {
        if ($valor === null) {
            return true;
        }
        if (! is_array($valor) || ! array_is_list($valor)) {
            return false;
        }
        $limpios = $this->normalizarEnlaces($valor);
        if (count($limpios) > self::MAX_ENLACES) {
            return false;
        }
        foreach ($limpios as $url) {
            if (mb_strlen($url) > 2048 || ! $this->esEnlaceValido($url)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Normaliza una lista de enlaces: castea a string, recorta, descarta vacíos,
     * deduplica preservando el orden y reindexa (lista JSON limpia).
     *
     * @param  array<int, mixed> $valor
     * @return string[]
     */
    private function normalizarEnlaces(array $valor): array
    {
        $out = [];
        foreach ($valor as $v) {
            if (! is_string($v)) {
                continue;
            }
            $u = trim($v);
            if ($u !== '' && ! in_array($u, $out, true)) {
                $out[] = $u;
            }
        }

        return $out;
    }

    /** `recordatorio_dias`: null, o lista de enteros en [0..30] (regla №4 del brief de la Tarea 6). */
    private function esRecordatorioDiasValido(mixed $valor): bool
    {
        if ($valor === null) {
            return true;
        }
        if (! is_array($valor) || ! array_is_list($valor)) {
            return false;
        }
        foreach ($valor as $d) {
            if (! is_int($d) || $d < 0 || $d > 30) {
                return false;
            }
        }

        return true;
    }

    /** @return int[] */
    private function idsUsuariosActivos(): array
    {
        return array_map(static fn (array $u) => (int) $u['id'], (new UsuarioModel())->activos());
    }

    /** @return int[] */
    private function idsAreasActivas(): array
    {
        return array_map(static fn (array $a) => (int) $a['id'], (new AreaModel())->where('activa', 1)->findAll());
    }

    /**
     * Valida un renglón de `NuevoAcuerdo` del lote (reglas №3/4/5/9 del brief
     * de la Tarea 6) y escribe los errores en `$campos["acuerdos.{i}.campo"]`.
     *
     * @param array<string, mixed>  $n
     * @param array<string, string> $campos
     * @param int[]                 $usuariosActivos
     * @param int[]                 $areasActivas
     */
    private function validarNuevoAcuerdo(int $i, array $n, array &$campos, string $hoy, array $usuariosActivos, array $areasActivas): void
    {
        $accion = $n['accion'] ?? null;
        if (! is_string($accion) || trim($accion) === '') {
            $campos["acuerdos.{$i}.accion"] = 'Requerido';
        }

        $responsableId = $n['responsable_id'] ?? null;
        if ($responsableId === null || ! in_array((int) $responsableId, $usuariosActivos, true)) {
            $campos["acuerdos.{$i}.responsable_id"] = 'Usuario inactivo o inexistente';
        }

        $areaId = $n['area_id'] ?? null;
        if ($areaId === null || ! in_array((int) $areaId, $areasActivas, true)) {
            $campos["acuerdos.{$i}.area_id"] = 'Área inactiva o inexistente';
        }

        $fechaCompromiso = $n['fecha_compromiso'] ?? null;
        if (! is_string($fechaCompromiso) || ! $this->esFechaValida($fechaCompromiso)) {
            $campos["acuerdos.{$i}.fecha_compromiso"] = 'Requerido';
        } elseif ($fechaCompromiso < $hoy) {
            $campos["acuerdos.{$i}.fecha_compromiso"] = 'Debe ser hoy o futura';
        }

        $corresponsablesIds = $n['corresponsables_ids'] ?? [];
        if (! is_array($corresponsablesIds) || ! array_is_list($corresponsablesIds)) {
            $campos["acuerdos.{$i}.corresponsables_ids"] = 'Debe ser una lista de ids';
        } else {
            $corresponsablesIds = array_map(static fn ($v) => (int) $v, $corresponsablesIds);
            $unicos             = array_values(array_unique($corresponsablesIds));

            if (count($unicos) !== count($corresponsablesIds)) {
                $campos["acuerdos.{$i}.corresponsables_ids"] = 'No se permiten corresponsables duplicados';
            } elseif ($responsableId !== null && in_array((int) $responsableId, $unicos, true)) {
                $campos["acuerdos.{$i}.corresponsables_ids"] = 'El responsable no puede ser corresponsable';
            } else {
                foreach ($unicos as $uid) {
                    if (! in_array($uid, $usuariosActivos, true)) {
                        $campos["acuerdos.{$i}.corresponsables_ids"] = 'Usuario inactivo o inexistente';

                        break;
                    }
                }
            }
        }

        if (array_key_exists('recordatorio_dias', $n) && ! $this->esRecordatorioDiasValido($n['recordatorio_dias'])) {
            $campos["acuerdos.{$i}.recordatorio_dias"] = 'Cada aviso debe estar entre 0 y 30 días';
        }

        if (array_key_exists('enlace', $n) && $n['enlace'] !== null && ! $this->esEnlaceValido($n['enlace'])) {
            $campos["acuerdos.{$i}.enlace"] = 'Debe ser una URL http(s)';
        }

        if (array_key_exists('enlaces', $n) && ! $this->esEnlacesValido($n['enlaces'])) {
            $campos["acuerdos.{$i}.enlaces"] = 'Cada enlace debe ser una URL http(s) (máx. ' . self::MAX_ENLACES . ')';
        }
    }

    /**
     * Inserta un acuerdo del lote (ya validado) + sus corresponsables + la
     * fila `google_sync` pendiente, y audita. Vive dentro de la transacción
     * abierta por `lote()` — nunca se llama fuera de ella.
     *
     * @param array<string, mixed> $n
     */
    private function insertarAcuerdo(int $reunionId, array $n, int $actorId): int
    {
        $corresponsablesIds = array_values(array_unique(array_map(static fn ($v) => (int) $v, $n['corresponsables_ids'] ?? [])));

        $tema          = isset($n['tema']) && is_string($n['tema']) && trim($n['tema']) !== '' ? trim($n['tema']) : null;
        $enlace        = isset($n['enlace']) && is_string($n['enlace']) && trim($n['enlace']) !== '' ? trim($n['enlace']) : null;
        $observaciones = isset($n['observaciones']) && is_string($n['observaciones']) && trim($n['observaciones']) !== '' ? trim($n['observaciones']) : null;

        $enlaces = isset($n['enlaces']) && is_array($n['enlaces']) ? $this->normalizarEnlaces($n['enlaces']) : [];

        $acuerdoModel = new AcuerdoModel();
        $acuerdoModel->insert([
            'reunion_id'        => $reunionId,
            'area_id'           => (int) $n['area_id'],
            'tema'              => $tema,
            'accion'            => trim((string) $n['accion']),
            'responsable_id'    => (int) $n['responsable_id'],
            'capturado_por_id'  => $actorId,
            'fecha_compromiso'  => (string) $n['fecha_compromiso'],
            'estado'            => 'en_proceso', // único estado inicial (RF-05.1); el cliente jamás manda estado.
            'enlace'            => $enlace,
            // Columna JSON: se serializa a mano (el Query Builder no lo hace en insert).
            'enlaces'           => $enlaces !== [] ? json_encode($enlaces) : null,
            'observaciones'     => $observaciones,
            'recordatorio_dias' => isset($n['recordatorio_dias']) && $n['recordatorio_dias'] !== null
                ? json_encode($n['recordatorio_dias'])
                : null,
        ]);
        $acuerdoId = (int) $acuerdoModel->insertID();

        if ($corresponsablesIds !== []) {
            (new AcuerdoCorresponsableModel())->reemplazarDe($acuerdoId, $corresponsablesIds);
        }

        (new GoogleSyncModel())->crearPendientePara($acuerdoId);
        (new AuditoriaModel())->registrar($actorId, 'crear', 'acuerdo', $acuerdoId, null, $this->request->getIPAddress());

        return $acuerdoId;
    }

    /** Recarga un `Acuerdo` completo (con corresponsables) tras una escritura. */
    private function cargarAcuerdoCompleto(int $id, string $hoy): Acuerdo
    {
        $fila = (new AcuerdoModel())->builderConJoins($hoy)->where('acuerdos.id', $id)->get()->getFirstRow('array');
        $corresponsables = $this->cargarCorresponsables([$id])[$id] ?? [];

        return Acuerdo::desdeFilaJoin($fila, $corresponsables);
    }

    /** Recarga un `AcuerdoDetalle` completo (avances + recordatorios) tras una escritura. */
    private function cargarDetalleCompleto(int $id, string $hoy): AcuerdoDetalle
    {
        $acuerdo = $this->cargarAcuerdoCompleto($id, $hoy);

        $avancesFilas = (new AvanceModel())->where('acuerdo_id', $id)->orderBy('created_at', 'DESC')->orderBy('id', 'DESC')->findAll();
        $avances      = $this->hidratarAvances($avancesFilas);

        $configGlobal = (new ConfiguracionModel())->recordatoriosDefault();
        $enviados     = (new RecordatorioEnviadoModel())->where('acuerdo_id', $id)->findAll();
        $filaParaProgramador = [
            'estado'            => $acuerdo->estado,
            'fecha_compromiso'  => $acuerdo->fechaCompromiso,
            'recordatorio_dias' => $acuerdo->recordatorioDias,
        ];
        $recordatorios = Programador::programadosDe($filaParaProgramador, $configGlobal, $enviados);

        return new AcuerdoDetalle($acuerdo, $avances, $recordatorios);
    }

    /**
     * Editar campos estructurales (tema/acción/responsable/área/enlace/
     * observaciones/recordatorio_dias) y corresponsables: Dirección,
     * coordinación DEL ÁREA del acuerdo (doc 05 §2.2, SRS matriz de roles),
     * o quien CAPTURÓ el acuerdo (ADR-011: cada quien puede corregir lo que
     * registró).
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $acuerdo Fila con al menos area_id y capturado_por_id.
     */
    private function puedeEditarEstructura(array $actor, array $acuerdo): bool
    {
        if ($actor['rol'] === 'direccion') {
            return true;
        }

        if ((int) ($acuerdo['capturado_por_id'] ?? 0) === (int) $actor['id']) {
            return true;
        }

        return $actor['rol'] === 'coordinador' && ((int) $acuerdo['area_id']) === (int) $actor['area_id'];
    }

    /**
     * Concluir (RF-06, ADR-012): Dirección concluye cualquier acuerdo; el
     * coordinador solo los de SU área (`acuerdo.area_id == actor.area_id`).
     * Reabrir/eliminar siguen siendo exclusivos de Dirección.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $acuerdo Fila con al menos area_id.
     */
    private function puedeConcluir(array $actor, array $acuerdo): bool
    {
        if ($actor['rol'] === 'direccion') {
            return true;
        }

        return $actor['rol'] === 'coordinador' && ((int) $acuerdo['area_id']) === (int) $actor['area_id'];
    }

    /**
     * Registrar avance (RF-07): responsable, corresponsables, coordinación
     * del área o Dirección.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $acuerdo Fila con al menos area_id, responsable_id.
     */
    private function puedeRegistrarAvance(array $actor, array $acuerdo, bool $esCorresponsable): bool
    {
        if ($actor['rol'] === 'direccion') {
            return true;
        }
        if (((int) $acuerdo['responsable_id']) === (int) $actor['id'] || $esCorresponsable) {
            return true;
        }

        return $actor['rol'] === 'coordinador' && ((int) $acuerdo['area_id']) === (int) $actor['area_id'];
    }
}
