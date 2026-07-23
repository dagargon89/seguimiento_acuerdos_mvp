<?php

namespace App\Entities;

/** Usuario completo (doc 05 §2.1 `/me`, `GET /usuarios`) — types.ts `Usuario`. */
final class Usuario
{
    public function __construct(
        public readonly int $id,
        public readonly string $nombre,
        public readonly string $email,
        public readonly string $rol,
        public readonly ?int $areaId,
        public readonly bool $activo,
        public readonly ?string $avatarColor = null,
    ) {
    }

    /** @param array<string, mixed> $fila Fila de la tabla `usuarios`. */
    public static function desdeFila(array $fila): self
    {
        $color = $fila['avatar_color'] ?? null;

        return new self(
            (int) $fila['id'],
            (string) $fila['nombre'],
            (string) $fila['email'],
            (string) $fila['rol'],
            $fila['area_id'] === null ? null : (int) $fila['area_id'],
            ((int) $fila['activo']) === 1,
            $color === null || $color === '' ? null : (string) $color,
        );
    }

    /** @return array{id: int, nombre: string, email: string, rol: string, area_id: int|null, activo: bool, avatar_color: string|null} */
    public function aArray(): array
    {
        return [
            'id'           => $this->id,
            'nombre'       => $this->nombre,
            'email'        => $this->email,
            'rol'          => $this->rol,
            'area_id'      => $this->areaId,
            'activo'       => $this->activo,
            'avatar_color' => $this->avatarColor,
        ];
    }
}
