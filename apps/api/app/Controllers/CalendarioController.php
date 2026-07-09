<?php

namespace App\Controllers;

use App\Entities\Acuerdo;
use App\Entities\CalendarioDia;
use App\Entities\CalendarioMes;
use App\Entities\UsuarioRef;
use App\Models\AcuerdoModel;
use App\Policies\VisibilidadAcuerdos;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\I18n\Time;
use Config\Database;
use DateTime;

/**
 * GET /calendario (doc 05 §2.5, RF-04) — acuerdos visibles del mes, agrupados
 * por día. Mismo ámbito de visibilidad que `GET /acuerdos` (Tarea 5); oculta
 * concluidos salvo `incluir_concluidos=true`. Espejo de `getCalendario()` en
 * `api.mock.ts`.
 *
 * Regresión BQS: el rango del mes se calcula sobre fechas `Y-m-d` puras (sin
 * componente de hora), interpretadas en TZ America/Ciudad_Juarez
 * (`App.php::appTimezone`) — nunca se compara contra medianoche UTC.
 */
class CalendarioController extends BaseController
{
    public function index(): ResponseInterface
    {
        $actor = service('usuarioActual')->obtener();

        $mes = (string) $this->request->getGet('mes');
        if (! $this->esMesValido($mes)) {
            return $this->errorValidacion('El parámetro "mes" debe tener el formato YYYY-MM.', ['mes' => 'Formato YYYY-MM']);
        }

        $incluirConcluidos = $this->esVerdadero($this->request->getGet('incluir_concluidos'));

        [$desde, $hasta] = $this->rangoDelMes($mes);
        $hoy = Time::now()->toDateString();

        $model      = new AcuerdoModel();
        $estadoExpr = AcuerdoModel::estadoDerivadoExpr($hoy);

        $builder = $model->builderConJoins($hoy)
            ->where('acuerdos.fecha_compromiso >=', $desde)
            ->where('acuerdos.fecha_compromiso <=', $hasta);
        $builder = VisibilidadAcuerdos::aplicarAlListado($builder, $actor);

        if (! $incluirConcluidos) {
            $builder->where("({$estadoExpr}) != 'concluido'", null, false);
        }

        $filas = $builder
            ->orderBy('acuerdos.fecha_compromiso', 'ASC')
            ->orderBy('acuerdos.id', 'ASC')
            ->get()
            ->getResultArray();

        $ids                        = array_map(static fn (array $f) => (int) $f['id'], $filas);
        $corresponsablesPorAcuerdo  = $this->cargarCorresponsables($ids);

        $porDia = [];
        foreach ($filas as $f) {
            $aid     = (int) $f['id'];
            $acuerdo = Acuerdo::desdeFilaJoin($f, $corresponsablesPorAcuerdo[$aid] ?? []);
            $porDia[$acuerdo->fechaCompromiso][] = $acuerdo;
        }

        ksort($porDia);
        $dias = [];
        foreach ($porDia as $fecha => $acuerdos) {
            $dias[] = new CalendarioDia($fecha, $acuerdos);
        }

        $calendario = new CalendarioMes($mes, $dias);

        return $this->response->setJSON($calendario->aArray());
    }

    /**
     * Rango `[desde, hasta]` (inclusive, `Y-m-d`) del mes `YYYY-MM`, calculado
     * en TZ America/Ciudad_Juarez para no depender de la zona del servidor.
     *
     * @return array{0: string, 1: string}
     */
    private function rangoDelMes(string $mes): array
    {
        $inicio = Time::createFromFormat('Y-m-d', $mes . '-01', 'America/Ciudad_Juarez');
        $desde  = $inicio->toDateString();
        // Último día del mes: el día 1 del mes siguiente menos 1 día (evita depender
        // de un helper de "fin de mes" que esta versión de Time no expone).
        $hasta  = $inicio->addMonths(1)->subDays(1)->toDateString();

        return [$desde, $hasta];
    }

    private function esMesValido(string $mes): bool
    {
        if (! (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) {
            return false;
        }

        return DateTime::createFromFormat('Y-m-d', $mes . '-01') !== false;
    }

    private function esVerdadero(?string $valor): bool
    {
        return in_array($valor, ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * Carga corresponsables de TODO el mes en UNA sola query — cero N+1.
     *
     * @param int[] $acuerdoIds
     *
     * @return array<int, UsuarioRef[]>
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
