<?php

namespace App\Services;

/**
 * Contadores de una corrida del job `recordatorios:procesar` (doc 02 §job).
 * Se audita al final de `RecordatorioService::procesar()` y lo imprime el
 * comando spark. Value object mutable simple: el servicio va acumulando.
 */
final class ResumenCorrida
{
    public function __construct(
        public string $fecha = '',
        public int $vencidosMarcados = 0,
        public int $materializados = 0,
        public int $enviados = 0,
        public int $fallidos = 0,
        public int $calendarioSincronizado = 0,
        public int $calendarioFallido = 0,
        public int $resumenesEnviados = 0,
        public int $resumenesFallidos = 0,
        public int $solicitudesEnviadas = 0,
        public int $solicitudesFallidas = 0,
    ) {
    }

    /** @return array<string, int|string> */
    public function aArray(): array
    {
        return [
            'fecha'                   => $this->fecha,
            'vencidos_marcados'       => $this->vencidosMarcados,
            'materializados'          => $this->materializados,
            'enviados'                => $this->enviados,
            'fallidos'                => $this->fallidos,
            'calendario_sincronizado' => $this->calendarioSincronizado,
            'calendario_fallido'      => $this->calendarioFallido,
            'resumenes_enviados'      => $this->resumenesEnviados,
            'resumenes_fallidos'      => $this->resumenesFallidos,
            'solicitudes_enviadas'    => $this->solicitudesEnviadas,
            'solicitudes_fallidas'    => $this->solicitudesFallidas,
        ];
    }
}
