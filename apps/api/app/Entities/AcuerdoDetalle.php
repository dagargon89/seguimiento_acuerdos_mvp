<?php

namespace App\Entities;

/** Detalle de acuerdo = `Acuerdo` + `avances[]` + `recordatorios[]` programados (doc 05 §2.2). */
final class AcuerdoDetalle
{
    public function __construct(
        public readonly Acuerdo $acuerdo,
        /** @var Avance[] */
        public readonly array $avances,
        /** @var RecordatorioProgramado[] */
        public readonly array $recordatorios,
    ) {
    }

    /** @return array<string, mixed> */
    public function aArray(): array
    {
        return [
            ...$this->acuerdo->aArray(),
            'avances'       => array_map(static fn (Avance $a) => $a->aArray(), $this->avances),
            'recordatorios' => array_map(static fn (RecordatorioProgramado $r) => $r->aArray(), $this->recordatorios),
        ];
    }
}
