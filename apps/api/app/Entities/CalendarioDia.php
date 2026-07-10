<?php

namespace App\Entities;

/** Un día del calendario `{fecha, acuerdos[]}` (doc 05 §2.5) — types.ts `CalendarioDia`. */
final class CalendarioDia
{
    public function __construct(
        public readonly string $fecha,
        /** @var Acuerdo[] */
        public readonly array $acuerdos,
    ) {
    }

    /** @return array{fecha: string, acuerdos: array<int, array<string, mixed>>} */
    public function aArray(): array
    {
        return [
            'fecha'    => $this->fecha,
            'acuerdos' => array_map(static fn (Acuerdo $a) => $a->aArray(), $this->acuerdos),
        ];
    }
}
