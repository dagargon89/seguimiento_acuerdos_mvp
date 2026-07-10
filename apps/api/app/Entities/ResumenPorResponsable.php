<?php

namespace App\Entities;

/** Desglose por responsable dentro de `Resumen` (doc 05 §2.7, RF-11) — types.ts `ResumenPorResponsable`. */
final class ResumenPorResponsable
{
    public function __construct(
        public readonly UsuarioRef $responsable,
        public readonly int $enProceso,
        public readonly int $vencidos,
        public readonly int $porVencer7d,
    ) {
    }

    /** @return array{responsable: array, en_proceso: int, vencidos: int, por_vencer_7d: int} */
    public function aArray(): array
    {
        return [
            'responsable'   => $this->responsable->aArray(),
            'en_proceso'    => $this->enProceso,
            'vencidos'      => $this->vencidos,
            'por_vencer_7d' => $this->porVencer7d,
        ];
    }
}
