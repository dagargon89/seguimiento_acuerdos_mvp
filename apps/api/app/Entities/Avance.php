<?php

namespace App\Entities;

/** Avance de un acuerdo (doc 05 §2.2 detalle) — types.ts `Avance`. */
final class Avance
{
    public function __construct(
        public readonly int $id,
        public readonly UsuarioRef $usuario,
        public readonly string $tipo,
        public readonly string $descripcion,
        public readonly ?string $nuevaFecha,
        public readonly string $createdAt,
    ) {
    }

    /** @return array{id: int, usuario: array, tipo: string, descripcion: string, nueva_fecha: string|null, created_at: string} */
    public function aArray(): array
    {
        return [
            'id'          => $this->id,
            'usuario'     => $this->usuario->aArray(),
            'tipo'        => $this->tipo,
            'descripcion' => $this->descripcion,
            'nueva_fecha' => $this->nuevaFecha,
            'created_at'  => $this->createdAt,
        ];
    }
}
