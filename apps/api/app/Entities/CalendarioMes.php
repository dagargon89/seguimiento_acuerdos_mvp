<?php

namespace App\Entities;

/**
 * Respuesta de `GET /calendario` (doc 05 §2.5) — SIN envoltura `data`
 * (types.ts `CalendarioMes`). `dias` solo incluye fechas con al menos un
 * acuerdo visible, ordenadas ascendente.
 */
final class CalendarioMes
{
    public function __construct(
        public readonly string $mes, // YYYY-MM
        /** @var CalendarioDia[] */
        public readonly array $dias,
    ) {
    }

    /** @return array{mes: string, dias: array<int, array<string, mixed>>} */
    public function aArray(): array
    {
        return [
            'mes'  => $this->mes,
            'dias' => array_map(static fn (CalendarioDia $d) => $d->aArray(), $this->dias),
        ];
    }
}
