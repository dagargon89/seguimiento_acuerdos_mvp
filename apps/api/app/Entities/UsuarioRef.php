<?php

namespace App\Entities;

/**
 * Referencia liviana a un usuario `{id, nombre, email}` (doc 05 / types.ts
 * `UsuarioRef`). Se usa para responsable, corresponsables, capturado_por,
 * concluido_por y el usuario de cada avance.
 */
final class UsuarioRef
{
    public function __construct(
        public readonly int $id,
        public readonly string $nombre,
        public readonly string $email,
    ) {
    }

    /** @param array<string, mixed> $fila */
    public static function desdeFila(array $fila): self
    {
        return new self((int) $fila['id'], (string) $fila['nombre'], (string) $fila['email']);
    }

    /** @return array{id: int, nombre: string, email: string} */
    public function aArray(): array
    {
        return ['id' => $this->id, 'nombre' => $this->nombre, 'email' => $this->email];
    }
}
