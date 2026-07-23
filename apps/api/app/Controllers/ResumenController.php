<?php

namespace App\Controllers;

use App\Entities\Area;
use App\Entities\Resumen;
use App\Entities\ResumenPorResponsable;
use App\Entities\UsuarioRef;
use App\Models\AcuerdoModel;
use App\Models\AreaModel;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\I18n\Time;

/**
 * GET /resumen (doc 05 §2.7, RF-11) — SIN envoltura `data`. Dirección ve el
 * ámbito `general` (todos los acuerdos); coordinación ve `area` (solo su
 * área, por `area_id` — NO la visibilidad de participación de `/acuerdos`);
 * responsable → 403, igual que `api.mock.ts::getResumen`.
 *
 * Totales sobre acuerdos ABIERTOS (en_proceso + vencido derivado) para
 * `en_proceso`/`vencidos`/`por_vencer_7d`/`por_responsable`; `concluidos`
 * cuenta también los concluidos del ámbito.
 */
class ResumenController extends BaseController
{
    public function index(): ResponseInterface
    {
        $actor = service('usuarioActual')->obtener();

        if (! in_array($actor['rol'], ['direccion', 'coordinador'], true)) {
            return $this->response->setStatusCode(403)->setJSON([
                'error'   => 'sin_permiso',
                'mensaje' => 'El resumen es para dirección y coordinaciones.',
            ]);
        }

        $hoy = Time::now()->toDateString();
        $en7 = Time::parse($hoy)->addDays(7)->toDateString();

        $model      = new AcuerdoModel();
        $estadoExpr = AcuerdoModel::estadoDerivadoExpr($hoy);

        $builder = $model->builderConJoins($hoy);
        if ($actor['rol'] !== 'direccion') {
            $builder->where('acuerdos.area_id', (int) $actor['area_id']);
        }

        $filas = $builder->get()->getResultArray();

        $enProceso   = 0;
        $vencidos    = 0;
        $porVencer7d = 0;
        $concluidos  = 0;
        /** @var array<int, array{ref: array, en_proceso: int, vencidos: int, por_vencer_7d: int}> $porResp */
        $porResp = [];

        foreach ($filas as $f) {
            $estado = (string) $f['estado']; // ya derivado por builderConJoins()
            if ($estado === 'concluido') {
                $concluidos++;

                continue;
            }

            $respId = (int) $f['responsable_id'];
            $porResp[$respId] ??= [
                'ref'           => ['id' => $respId, 'nombre' => (string) $f['responsable__nombre'], 'email' => (string) $f['responsable__email'], 'avatar_color' => $f['responsable__avatar_color'] ?? null],
                'en_proceso'    => 0,
                'vencidos'      => 0,
                'por_vencer_7d' => 0,
            ];

            if ($estado === 'vencido') {
                $vencidos++;
                $porResp[$respId]['vencidos']++;
            } else {
                $enProceso++;
                $porResp[$respId]['en_proceso']++;
            }

            $fechaCompromiso = (string) $f['fecha_compromiso'];
            if ($estado === 'en_proceso' && $fechaCompromiso >= $hoy && $fechaCompromiso <= $en7) {
                $porVencer7d++;
                $porResp[$respId]['por_vencer_7d']++;
            }
        }

        $porResponsable = array_map(
            static fn (array $r) => new ResumenPorResponsable(
                UsuarioRef::desdeFila($r['ref']),
                $r['en_proceso'],
                $r['vencidos'],
                $r['por_vencer_7d'],
            ),
            array_values($porResp),
        );

        usort($porResponsable, static fn (ResumenPorResponsable $a, ResumenPorResponsable $b) => $b->vencidos <=> $a->vencidos);

        $area = null;
        if ($actor['rol'] !== 'direccion') {
            $filaArea = (new AreaModel())->find((int) $actor['area_id']);
            $area     = $filaArea === null ? null : Area::desdeFila($filaArea);
        }

        $resumen = new Resumen(
            $actor['rol'] === 'direccion' ? 'general' : 'area',
            $area,
            $enProceso,
            $vencidos,
            $porVencer7d,
            $concluidos,
            $porResponsable,
        );

        return $this->response->setJSON($resumen->aArray());
    }
}
