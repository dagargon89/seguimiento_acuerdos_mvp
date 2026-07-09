<?php

namespace App\Controllers;

use App\Entities\Acuerdo;
use App\Entities\AcuerdoDetalle;
use App\Entities\Avance;
use App\Entities\UsuarioRef;
use App\Libraries\Recordatorios\Programador;
use App\Models\AcuerdoModel;
use App\Models\AvanceModel;
use App\Models\ConfiguracionModel;
use App\Models\RecordatorioEnviadoModel;
use App\Policies\VisibilidadAcuerdos;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\I18n\Time;
use Config\Database;

/**
 * GET /acuerdos, GET /acuerdos/{id} (doc 05 §2.2) — endpoints de LECTURA.
 * La escritura (lote, editar, corresponsables, avances, concluir, reabrir)
 * se implementa en la Tarea 6.
 */
class AcuerdosController extends BaseController
{
    private const PER_PAGE_DEFAULT = 50;
    private const PER_PAGE_MAX     = 200;

    /** "Hoy" en TZ America/Ciudad_Juarez (App.php `appTimezone`) para el estado derivado (RF-05.2). */
    private function hoy(): string
    {
        return Time::now()->toDateString();
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
            ->select('ac.acuerdo_id, u.id, u.nombre, u.email')
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

    private function noEncontrado(): ResponseInterface
    {
        return $this->response->setStatusCode(404)->setJSON([
            'error'   => 'no_encontrado',
            'mensaje' => 'El acuerdo no existe o no es visible para tu cuenta.',
        ]);
    }
}
