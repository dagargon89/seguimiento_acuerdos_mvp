<?php

namespace App\Controllers;

use App\Entities\ConfigRecordatorios;
use App\Entities\RecordatorioVista;
use App\Entities\UsuarioRef;
use App\Libraries\Recordatorios\Programador;
use App\Models\AcuerdoModel;
use App\Models\AuditoriaModel;
use App\Models\ConfiguracionModel;
use App\Models\RecordatorioEnviadoModel;
use App\Policies\VisibilidadAcuerdos;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\I18n\Time;
use Config\Database;
use JsonException;

/**
 * GET /recordatorios/proximos, GET /recordatorios/historial (doc 05 §2.3,
 * RF-08) — ámbito de visibilidad del actor (misma regla que `/acuerdos`).
 * Envoltura `{data: RecordatorioVista[]}` (api.real.ts). Espejo de
 * `listRecordatoriosProximos`/`listRecordatoriosHistorial` en `api.mock.ts`.
 */
class RecordatoriosController extends BaseController
{
    /**
     * `proximos`: envíos futuros materializables (estado 'programado', fecha
     * >= hoy) de los acuerdos ABIERTOS visibles, uno por destinatario
     * (responsable + corresponsables), calculados con `Programador` y
     * cruzados contra `recordatorios_enviados` (para no repetir lo ya
     * enviado).
     */
    public function proximos(): ResponseInterface
    {
        $actor = service('usuarioActual')->obtener();
        $hoy   = Time::now()->toDateString();

        $filas = $this->acuerdosVisiblesAbiertos($actor, $hoy);
        if ($filas === []) {
            return $this->response->setJSON(['data' => []]);
        }

        $ids               = array_map(static fn (array $f) => (int) $f['id'], $filas);
        $configGlobal      = (new ConfiguracionModel())->recordatoriosDefault();
        $enviadosPorAcuerdo = $this->agruparEnviadosPorAcuerdo($ids);
        $destinatariosPorAcuerdo = $this->cargarDestinatarios($filas);

        $out = [];
        foreach ($filas as $f) {
            $aid = (int) $f['id'];

            $filaProgramador = [
                'estado'            => (string) $f['estado'],
                'fecha_compromiso'  => (string) $f['fecha_compromiso'],
                'recordatorio_dias' => $f['recordatorio_dias'],
            ];
            $programados = Programador::programadosDe($filaProgramador, $configGlobal, $enviadosPorAcuerdo[$aid] ?? []);

            foreach ($programados as $p) {
                if ($p->estado !== 'programado' || $p->programadoPara < $hoy) {
                    continue;
                }
                foreach ($destinatariosPorAcuerdo[$aid] ?? [] as $d) {
                    $out[] = new RecordatorioVista(
                        "{$aid}|{$p->tipo}|{$p->programadoPara}|{$d->id}",
                        $aid,
                        $p->tipo,
                        $p->programadoPara,
                        $d,
                        (string) $f['accion'],
                        $f['tema'],
                        (string) $f['fecha_compromiso'],
                        false,
                        null,
                        null,
                    );
                }
            }
        }

        usort($out, static fn (RecordatorioVista $a, RecordatorioVista $b) => $a->programadoPara <=> $b->programadoPara);

        return $this->response->setJSON(['data' => array_map(static fn (RecordatorioVista $r) => $r->aArray(), $out)]);
    }

    /**
     * `historial`: filas de `recordatorios_enviados` de acuerdos visibles.
     * Las filas de tipo `resumen` (`acuerdo_id IS NULL`) se muestran a
     * cualquiera que NO sea responsable (igual que `api.mock.ts`), pero SOLO
     * las del propio actor (`usuario_id = actor.id`): son un envío personal,
     * no un acuerdo con visibilidad compartida, así que un coordinador no debe
     * ver que Dirección u otra coordinación recibió su resumen.
     */
    public function historial(): ResponseInterface
    {
        $actor = service('usuarioActual')->obtener();
        $hoy   = Time::now()->toDateString();

        $idsVisibles = $this->idsAcuerdosVisibles($actor, $hoy);

        $builder = (new RecordatorioEnviadoModel())->builder();
        if ($actor['rol'] === 'responsable') {
            if ($idsVisibles === []) {
                return $this->response->setJSON(['data' => []]);
            }
            $builder->whereIn('acuerdo_id', $idsVisibles);
        } else {
            $actorId = (int) $actor['id'];
            $builder->groupStart()
                ->groupStart()
                    ->where('acuerdo_id', null)
                    ->where('usuario_id', $actorId)
                ->groupEnd()
                ->orWhereIn('acuerdo_id', $idsVisibles === [] ? [0] : $idsVisibles)
                ->groupEnd();
        }

        $enviados = $builder->orderBy('programado_para', 'DESC')->orderBy('id', 'DESC')->get()->getResultArray();

        $acuerdoIds = array_values(array_unique(array_filter(
            array_map(static fn (array $e) => $e['acuerdo_id'] === null ? null : (int) $e['acuerdo_id'], $enviados),
            static fn ($v) => $v !== null,
        )));
        $acuerdosPorId = $this->cargarAcuerdosPorId($acuerdoIds, $hoy);

        $usuarioIds = array_values(array_unique(array_map(static fn (array $e) => (int) $e['usuario_id'], $enviados)));
        $usuariosPorId = $this->cargarUsuariosPorId($usuarioIds);

        $out = [];
        foreach ($enviados as $e) {
            $aid     = $e['acuerdo_id'] === null ? null : (int) $e['acuerdo_id'];
            $acuerdo = $aid === null ? null : ($acuerdosPorId[$aid] ?? null);

            $out[] = new RecordatorioVista(
                'env-' . $e['id'],
                $aid,
                (string) $e['tipo'],
                (string) $e['programado_para'],
                $usuariosPorId[(int) $e['usuario_id']] ?? new UsuarioRef((int) $e['usuario_id'], '—', '—'),
                $acuerdo['accion'] ?? 'Resumen periódico de pendientes',
                $acuerdo['tema'] ?? null,
                $acuerdo['fecha_compromiso'] ?? null,
                $e['estado'] === 'enviado',
                (string) $e['estado'],
                $e['error'],
            );
        }

        return $this->response->setJSON(['data' => array_map(static fn (RecordatorioVista $r) => $r->aArray(), $out)]);
    }

    /** GET /configuracion/recordatorios (doc 05 §2.3) — cualquier usuario activo. SIN envoltura. */
    public function getConfig(): ResponseInterface
    {
        service('usuarioActual')->obtener(); // solo exige autenticación; sin restricción de rol.
        $valor = (new ConfiguracionModel())->recordatoriosDefault();

        return $this->response->setJSON(ConfigRecordatorios::desdeValor($valor)->aArray());
    }

    /**
     * PUT /configuracion/recordatorios (doc 05 §2.3) — **solo Dirección**.
     * Valida `dias_antes` ⊆ [0..30] no vacío (ordenado ascendente exigido por
     * el brief; se persiste descendente, igual que `api.mock.ts`),
     * `vencido_cada_dias` ≥1, `vencido_max_repeticiones` ≥0,
     * `resumen_frecuencia` en la lista blanca. Actualiza SOLO el default
     * global (nunca toca `acuerdos.recordatorio_dias`, los overrides por
     * acuerdo — RE-09). Audita `cambio_config`.
     */
    public function putConfig(): ResponseInterface
    {
        $actor = service('usuarioActual')->obtener();
        if ($actor['rol'] !== 'direccion') {
            return $this->response->setStatusCode(403)->setJSON([
                'error'   => 'sin_permiso',
                'mensaje' => 'Esta acción está reservada a Dirección.',
            ]);
        }

        $body = $this->cuerpoJson();
        if ($body === null) {
            return $this->errorValidacion('El cuerpo debe ser JSON.');
        }

        $permitidos = ['dias_antes', 'dia_compromiso', 'vencido_cada_dias', 'vencido_max_repeticiones', 'resumen_frecuencia'];
        $desconocidos = array_values(array_diff(array_keys($body), $permitidos));
        if ($desconocidos !== []) {
            return $this->response->setStatusCode(422)->setJSON([
                'error'   => 'campo_no_permitido',
                'mensaje' => 'El body contiene campos no permitidos.',
                'campos'  => array_fill_keys($desconocidos, 'Campo no permitido'),
            ]);
        }

        $faltantes = array_values(array_diff($permitidos, array_keys($body)));
        if ($faltantes !== []) {
            return $this->errorValidacion('Revisa los campos de la configuración.', array_fill_keys($faltantes, 'Requerido'));
        }

        $campos = [];

        $diasAntes = $body['dias_antes'];
        if (! is_array($diasAntes) || ! array_is_list($diasAntes) || $diasAntes === []) {
            $campos['dias_antes'] = 'Debe ser una lista de enteros entre 0 y 30';
        } else {
            $enteros = [];
            $valido  = true;
            foreach ($diasAntes as $d) {
                if (! is_int($d) || $d < 0 || $d > 30) {
                    $valido = false;

                    break;
                }
                $enteros[] = $d;
            }
            if (! $valido) {
                $campos['dias_antes'] = 'Cada aviso debe estar entre 0 y 30 días';
            } else {
                $ordenadoAsc = $enteros;
                sort($ordenadoAsc);
                if ($ordenadoAsc !== $enteros) {
                    $campos['dias_antes'] = 'Debe estar ordenado ascendente';
                }
            }
        }

        if (! is_bool($body['dia_compromiso'])) {
            $campos['dia_compromiso'] = 'Debe ser booleano';
        }

        if (! is_int($body['vencido_cada_dias']) || $body['vencido_cada_dias'] < 1) {
            $campos['vencido_cada_dias'] = 'Debe ser un entero ≥ 1';
        }

        if (! is_int($body['vencido_max_repeticiones']) || $body['vencido_max_repeticiones'] < 0) {
            $campos['vencido_max_repeticiones'] = 'Debe ser un entero ≥ 0';
        }

        $frecuenciasValidas = ['semanal', 'quincenal', 'mensual'];
        if (! is_string($body['resumen_frecuencia']) || ! in_array($body['resumen_frecuencia'], $frecuenciasValidas, true)) {
            $campos['resumen_frecuencia'] = 'Debe ser semanal, quincenal o mensual';
        }

        if ($campos !== []) {
            return $this->errorValidacion('Revisa los campos de la configuración.', $campos);
        }

        // Persistimos dias_antes descendente (igual que api.mock.ts) — el orden
        // ascendente solo se exige/valida en el request.
        $diasDescendente = $diasAntes;
        rsort($diasDescendente);

        $nuevoValor = [
            'dias_antes'               => $diasDescendente,
            'dia_compromiso'           => $body['dia_compromiso'],
            'vencido_cada_dias'        => $body['vencido_cada_dias'],
            'vencido_max_repeticiones' => $body['vencido_max_repeticiones'],
            'resumen_frecuencia'       => $body['resumen_frecuencia'],
        ];

        $db = Database::connect();
        $db->transException(true)->transStart();

        (new ConfiguracionModel())->update('recordatorios_default', [
            'valor'      => json_encode($nuevoValor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => Time::now()->toDateTimeString(),
        ]);
        (new AuditoriaModel())->registrar(
            (int) $actor['id'],
            'cambio_config',
            'configuracion',
            null,
            ['clave' => 'recordatorios_default'],
            $this->request->getIPAddress(),
        );

        $db->transComplete();

        if (! $db->transStatus()) {
            return $this->response->setStatusCode(500)->setJSON(['error' => 'error_interno', 'mensaje' => 'No se pudo guardar la configuración.']);
        }

        $valor = (new ConfiguracionModel())->recordatoriosDefault();

        return $this->response->setJSON(ConfigRecordatorios::desdeValor($valor)->aArray());
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Acuerdos visibles y ABIERTOS (en_proceso + vencido derivado) del actor,
     * con las columnas crudas que necesita `Programador` (`fecha_compromiso`,
     * `recordatorio_dias`) más `accion`/`tema` para la vista.
     *
     * @return list<array<string, mixed>>
     */
    private function acuerdosVisiblesAbiertos(array $actor, string $hoy): array
    {
        $estadoExpr = AcuerdoModel::estadoDerivadoExpr($hoy);
        $builder    = (new AcuerdoModel())->builder()
            ->select("acuerdos.id, acuerdos.accion, acuerdos.tema, acuerdos.responsable_id, acuerdos.fecha_compromiso,
                      acuerdos.recordatorio_dias, ({$estadoExpr}) AS estado", false)
            ->where("({$estadoExpr}) != 'concluido'", null, false);
        $builder = VisibilidadAcuerdos::aplicarAlListado($builder, $actor);

        return $builder->get()->getResultArray();
    }

    /** @return int[] Ids de TODOS los acuerdos visibles (abiertos o no) del actor. */
    private function idsAcuerdosVisibles(array $actor, string $hoy): array
    {
        $builder = (new AcuerdoModel())->builder()->select('acuerdos.id');
        $builder = VisibilidadAcuerdos::aplicarAlListado($builder, $actor);

        return array_map(static fn (array $f) => (int) $f['id'], $builder->get()->getResultArray());
    }

    /**
     * @param int[] $acuerdoIds
     *
     * @return array<int, list<array<string, mixed>>> Filas de `recordatorios_enviados` por acuerdo_id.
     */
    private function agruparEnviadosPorAcuerdo(array $acuerdoIds): array
    {
        if ($acuerdoIds === []) {
            return [];
        }

        $filas = (new RecordatorioEnviadoModel())->whereIn('acuerdo_id', $acuerdoIds)->findAll();

        $out = [];
        foreach ($filas as $f) {
            $out[(int) $f['acuerdo_id']][] = $f;
        }

        return $out;
    }

    /**
     * Responsable + corresponsables de cada acuerdo, en UNA sola query
     * adicional (whereIn) — cero N+1.
     *
     * @param list<array<string, mixed>> $filas
     *
     * @return array<int, UsuarioRef[]>
     */
    private function cargarDestinatarios(array $filas): array
    {
        $ids = array_map(static fn (array $f) => (int) $f['id'], $filas);

        $responsableIds = array_values(array_unique(array_map(static fn (array $f) => (int) $f['responsable_id'], $filas)));
        $usuariosPorId  = $this->cargarUsuariosPorId($responsableIds);

        $corresponsablesPorAcuerdo = [];
        if ($ids !== []) {
            $filasCorresp = Database::connect()
                ->table('acuerdo_corresponsables ac')
                ->select('ac.acuerdo_id, u.id, u.nombre, u.email')
                ->join('usuarios u', 'u.id = ac.usuario_id', 'inner')
                ->whereIn('ac.acuerdo_id', $ids)
                ->orderBy('u.nombre', 'ASC')
                ->get()
                ->getResultArray();
            foreach ($filasCorresp as $fc) {
                $corresponsablesPorAcuerdo[(int) $fc['acuerdo_id']][] = UsuarioRef::desdeFila($fc);
            }
        }

        $out = [];
        foreach ($filas as $f) {
            $aid  = (int) $f['id'];
            $resp = $usuariosPorId[(int) $f['responsable_id']] ?? new UsuarioRef((int) $f['responsable_id'], '—', '—');
            $out[$aid] = [$resp, ...($corresponsablesPorAcuerdo[$aid] ?? [])];
        }

        return $out;
    }

    /**
     * @param int[] $ids
     *
     * @return array<int, UsuarioRef>
     */
    private function cargarUsuariosPorId(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $filas = Database::connect()->table('usuarios')->whereIn('id', $ids)->get()->getResultArray();
        $out   = [];
        foreach ($filas as $f) {
            $out[(int) $f['id']] = UsuarioRef::desdeFila($f);
        }

        return $out;
    }

    /**
     * @param int[] $ids
     *
     * @return array<int, array<string, mixed>>
     */
    private function cargarAcuerdosPorId(array $ids, string $hoy): array
    {
        if ($ids === []) {
            return [];
        }

        $filas = (new AcuerdoModel())->builder()
            ->select('acuerdos.id, acuerdos.accion, acuerdos.tema, acuerdos.fecha_compromiso')
            ->whereIn('acuerdos.id', $ids)
            ->get()
            ->getResultArray();

        $out = [];
        foreach ($filas as $f) {
            $out[(int) $f['id']] = $f;
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function cuerpoJson(): ?array
    {
        $crudo = $this->request->getBody();
        if ($crudo === null || trim((string) $crudo) === '') {
            return [];
        }

        try {
            $decodificado = json_decode((string) $crudo, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decodificado) ? $decodificado : null;
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
}
