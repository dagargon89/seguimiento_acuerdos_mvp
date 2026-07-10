<?php

namespace App\Libraries\Recordatorios;

use App\Entities\RecordatorioProgramado;

/**
 * Calcula el calendario de recordatorios PROGRAMADOS de un acuerdo abierto —
 * espejo server-side de `programadosDe()` en `apps/web/src/lib/api.mock.ts`
 * (RF-08). Encapsulado aquí porque la Tarea 10 (job de materialización) lo
 * reutilizará para decidir qué envíos realmente disparar.
 *
 * No escribe nada; cruza el calendario calculado con el histórico inmutable
 * de `recordatorios_enviados` solo para anotar el estado ('programado' por
 * default, 'enviado'/'fallido' si ya hay un registro exacto).
 */
final class Programador
{
    /**
     * @param array<string, mixed>      $acuerdo Fila con `estado` (YA derivado),
     *                                            `fecha_compromiso`, `recordatorio_dias`.
     * @param array<string, mixed>      $configGlobal Config vigente (`dias_antes`, `dia_compromiso`,
     *                                            `vencido_cada_dias`, `vencido_max_repeticiones`).
     * @param list<array<string,mixed>> $enviados Filas de `recordatorios_enviados` de ESTE acuerdo.
     *
     * @return RecordatorioProgramado[]
     */
    public static function programadosDe(array $acuerdo, array $configGlobal, array $enviados): array
    {
        if ($acuerdo['estado'] === 'concluido') {
            return [];
        }

        $fechaCompromiso = (string) $acuerdo['fecha_compromiso'];
        $diasOverride    = $acuerdo['recordatorio_dias'] ?? null;
        if (is_string($diasOverride)) {
            $diasOverride = json_decode($diasOverride, true, 512, JSON_THROW_ON_ERROR);
        }
        $dias = $diasOverride ?? $configGlobal['dias_antes'];

        $out = [];

        $diasOrdenados = $dias;
        rsort($diasOrdenados);
        foreach ($diasOrdenados as $d) {
            $d = (int) $d;
            if ($d > 0) {
                $out[] = ['tipo' => 'previo', 'programado_para' => self::desplazarFecha($fechaCompromiso, -$d)];
            }
        }

        if ($configGlobal['dia_compromiso']) {
            $out[] = ['tipo' => 'dia', 'programado_para' => $fechaCompromiso];
        }

        if ($acuerdo['estado'] === 'vencido') {
            $cada = (int) $configGlobal['vencido_cada_dias'];
            $max  = (int) $configGlobal['vencido_max_repeticiones'];
            for ($i = 1; $i <= $max; $i++) {
                $offset = $i * $cada - $cada + 1;
                $out[]  = ['tipo' => 'vencido', 'programado_para' => self::desplazarFecha($fechaCompromiso, $offset)];
            }
        }

        // Cruza con el log real de envíos (histórico inmutable) para anotar estado.
        $porClave = [];
        foreach ($enviados as $e) {
            $clave            = $e['tipo'] . '|' . $e['programado_para'];
            $porClave[$clave] = $e['estado'];
        }

        $programados = array_map(static function (array $p) use ($porClave): RecordatorioProgramado {
            $clave  = $p['tipo'] . '|' . $p['programado_para'];
            $estado = 'programado';
            if (isset($porClave[$clave])) {
                $estado = $porClave[$clave] === 'enviado' ? 'enviado' : 'fallido';
            }

            return new RecordatorioProgramado($p['tipo'], $p['programado_para'], $estado);
        }, $out);

        usort($programados, static fn (RecordatorioProgramado $a, RecordatorioProgramado $b) => $a->programadoPara <=> $b->programadoPara);

        return $programados;
    }

    /** Suma (o resta, con `$dias` negativo) días a una fecha `YYYY-MM-DD`. */
    private static function desplazarFecha(string $fechaIso, int $dias): string
    {
        $fecha = new \DateTimeImmutable($fechaIso);

        return $fecha->modify(($dias >= 0 ? '+' : '') . $dias . ' days')->format('Y-m-d');
    }
}
