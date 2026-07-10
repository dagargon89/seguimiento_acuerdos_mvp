<?php

namespace App\Entities;

/** Reunión `{id, nombre, fecha}` embebida en `Acuerdo` (doc 05 §2.2). */
final class Reunion
{
    public function __construct(
        public readonly int $id,
        public readonly string $nombre,
        public readonly string $fecha,
    ) {
    }

    /** @param array<string, mixed> $fila */
    public static function desdeFila(array $fila): self
    {
        return new self((int) $fila['id'], (string) $fila['nombre'], (string) $fila['fecha']);
    }

    /** @return array{id: int, nombre: string, fecha: string} */
    public function aArray(): array
    {
        return ['id' => $this->id, 'nombre' => $this->nombre, 'fecha' => $this->fecha];
    }
}
