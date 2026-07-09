<?php

namespace App\Entities;

/**
 * Recordatorio PROGRAMADO (calculado, no persistido) de un acuerdo abierto —
 * doc 05 §2.2 detalle, types.ts `RecordatorioProgramado`. Lo produce
 * `App\Libraries\Recordatorios\Programador`.
 */
final class RecordatorioProgramado
{
    public function __construct(
        public readonly string $tipo,
        public readonly string $programadoPara,
        public readonly string $estado, // 'programado'|'enviado'|'fallido'
    ) {
    }

    /** @return array{tipo: string, programado_para: string, estado: string} */
    public function aArray(): array
    {
        return ['tipo' => $this->tipo, 'programado_para' => $this->programadoPara, 'estado' => $this->estado];
    }
}
