<?php

namespace App\Entities;

/** Área `{id, nombre, activa}` (doc 05 §2.6) — types.ts `Area`. */
final class Area
{
    public function __construct(
        public readonly int $id,
        public readonly string $nombre,
        public readonly bool $activa,
    ) {
    }

    /** @param array<string, mixed> $fila */
    public static function desdeFila(array $fila): self
    {
        return new self((int) $fila['id'], (string) $fila['nombre'], ((int) $fila['activa']) === 1);
    }

    /** @return array{id: int, nombre: string, activa: bool} */
    public function aArray(): array
    {
        return ['id' => $this->id, 'nombre' => $this->nombre, 'activa' => $this->activa];
    }
}
