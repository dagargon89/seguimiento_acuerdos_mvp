<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use RuntimeException;

/**
 * S3.1 — Genera volumen SOLO para pruebas de carga manuales (k6, ver
 * tests/perf/README.md). NO toca `db.json` ni corre como parte de
 * `InitialSeeder` — es un seeder aparte, para una BD de desarrollo/carga que
 * ya tiene el seed base (usuarios, áreas, reuniones) aplicado.
 *
 * Genera:
 *   - ~5,000 acuerdos (reparte entre las áreas/usuarios ya existentes),
 *     variando fecha_compromiso y estado para que el listado no sea trivial.
 *   - 1 acuerdo "ancla" (el primero insertado por este seeder) con 50 avances,
 *     para el escenario de detalle de k6 (usa su id como ACUERDO_ID).
 *
 * Uso (NUNCA contra la BD de producción ni la de tests):
 *   php spark db:seed PerfSeeder
 *   — o el comando de conveniencia: php spark perf:seed
 *
 * Requiere que la BD destino ya tenga al menos 1 área y 2 usuarios activos
 * (el seed base de dev los trae). Idempotencia: cada corrida AÑADE ~5,000
 * filas nuevas (no borra nada) — si quieres repetir desde cero, restaura la
 * BD de dev antes de volver a correrlo.
 */
class PerfSeeder extends Seeder
{
    private const TOTAL_ACUERDOS = 5000;
    private const AVANCES_ANCLA  = 50;

    public function run(): void
    {
        $areas = $this->db->table('areas')->select('id')->where('activa', 1)->get()->getResultArray();
        $usuarios = $this->db->table('usuarios')->select('id')->where('activo', 1)->get()->getResultArray();

        if ($areas === [] || $usuarios === []) {
            throw new RuntimeException(
                'PerfSeeder requiere al menos 1 área y 1 usuario activos ya sembrados '
                . '(corre InitialSeeder/el seed de dev primero).',
            );
        }

        $areaIds     = array_map(static fn (array $a) => (int) $a['id'], $areas);
        $usuarioIds  = array_map(static fn (array $u) => (int) $u['id'], $usuarios);

        $reunionId = $this->obtenerOCrearReunionDeCarga();
        $capturadoPorId = $usuarioIds[0];

        $this->db->transException(true)->transStart();

        $anclaId = null;
        $lote    = [];
        $estados = ['en_proceso', 'en_proceso', 'en_proceso', 'vencido', 'concluido'];

        for ($i = 0; $i < self::TOTAL_ACUERDOS; $i++) {
            $areaId        = $areaIds[$i % count($areaIds)];
            $responsableId = $usuarioIds[$i % count($usuarioIds)];
            $estado        = $estados[$i % count($estados)];
            // Reparte fechas en una ventana de -30 a +90 días para que filtros
            // desde/hasta y el estado derivado (vencido) tengan datos reales.
            $offsetDias = ($i % 121) - 30;

            $fila = [
                'reunion_id'       => $reunionId,
                'area_id'          => $areaId,
                'tema'             => "Carga de rendimiento #{$i}",
                'accion'           => "Acuerdo generado por PerfSeeder para pruebas de carga k6 (#{$i}).",
                'responsable_id'   => $responsableId,
                'capturado_por_id' => $capturadoPorId,
                'fecha_compromiso' => date('Y-m-d', strtotime("{$offsetDias} days")),
                'estado'           => $estado === 'concluido' ? 'concluido' : 'en_proceso',
                'enlace'           => null,
                'observaciones'    => null,
                'recordatorio_dias' => null,
                'concluido_por_id' => $estado === 'concluido' ? $capturadoPorId : null,
                'concluido_at'     => $estado === 'concluido' ? date('Y-m-d H:i:s') : null,
                'created_at'       => date('Y-m-d H:i:s'),
            ];

            $this->db->table('acuerdos')->insert($fila);
            $nuevoId = (int) $this->db->insertID();

            if ($anclaId === null) {
                $anclaId = $nuevoId;
            }
        }

        // Acuerdo ancla con 50 avances (escenario de detalle de k6).
        if ($anclaId !== null) {
            $avancesAncla = [];
            for ($j = 0; $j < self::AVANCES_ANCLA; $j++) {
                $avancesAncla[] = [
                    'acuerdo_id'  => $anclaId,
                    'usuario_id'  => $usuarioIds[$j % count($usuarioIds)],
                    'tipo'        => 'avance',
                    'descripcion' => "Avance de carga generado por PerfSeeder (#{$j}).",
                    'nueva_fecha' => null,
                    'created_at'  => date('Y-m-d H:i:s', strtotime("-{$j} minutes")),
                ];
            }
            $this->db->table('avances')->insertBatch($avancesAncla);
        }

        $this->db->transComplete();

        if (! $this->db->transStatus()) {
            throw new RuntimeException('PerfSeeder falló al insertar el volumen de carga.');
        }

        echo self::TOTAL_ACUERDOS . " acuerdos insertados. Acuerdo ancla (con " . self::AVANCES_ANCLA
            . " avances) para el escenario de detalle de k6: id={$anclaId} (usa ACUERDO_ID={$anclaId}).\n";
    }

    private function obtenerOCrearReunionDeCarga(): int
    {
        $existente = $this->db->table('reuniones')
            ->where('nombre', 'Carga de rendimiento (PerfSeeder)')
            ->get()
            ->getFirstRow('array');

        if ($existente !== null) {
            return (int) $existente['id'];
        }

        $this->db->table('reuniones')->insert([
            'nombre'     => 'Carga de rendimiento (PerfSeeder)',
            'fecha'      => date('Y-m-d'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->insertID();
    }
}
