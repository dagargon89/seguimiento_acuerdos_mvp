<?php

namespace App\Entities;

/**
 * Respuesta de `GET /resumen` (doc 05 §2.7, RF-11) — SIN envoltura `data`
 * (types.ts `Resumen`). `ambito` es `general` para Dirección o `area` para
 * coordinación (con `area` poblada); `por_responsable` solo cuenta acuerdos
 * ABIERTOS (en_proceso + vencido), igual que `api.mock.ts`.
 */
final class Resumen
{
    public function __construct(
        public readonly string $ambito, // 'general'|'area'
        public readonly ?Area $area,
        public readonly int $enProceso,
        public readonly int $vencidos,
        public readonly int $porVencer7d,
        public readonly int $concluidos,
        /** @var ResumenPorResponsable[] */
        public readonly array $porResponsable,
    ) {
    }

    /** @return array<string, mixed> */
    public function aArray(): array
    {
        return [
            'ambito'          => $this->ambito,
            'area'            => $this->area?->aArray(),
            'en_proceso'      => $this->enProceso,
            'vencidos'        => $this->vencidos,
            'por_vencer_7d'   => $this->porVencer7d,
            'concluidos'      => $this->concluidos,
            'por_responsable' => array_map(static fn (ResumenPorResponsable $r) => $r->aArray(), $this->porResponsable),
        ];
    }
}
