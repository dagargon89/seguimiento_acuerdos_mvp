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
        public readonly ?string $avatarColor = null,
    ) {
    }

    /** @param array<string, mixed> $fila */
    public static function desdeFila(array $fila): self
    {
        $color = $fila['avatar_color'] ?? null;

        return new self(
            (int) $fila['id'],
            (string) $fila['nombre'],
            (string) $fila['email'],
            $color === null || $color === '' ? null : (string) $color,
        );
    }

    /** @return array{id: int, nombre: string, email: string, avatar_color: string|null} */
    public function aArray(): array
    {
        return ['id' => $this->id, 'nombre' => $this->nombre, 'email' => $this->email, 'avatar_color' => $this->avatarColor];
    }
}
