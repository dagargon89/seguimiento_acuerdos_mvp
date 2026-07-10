<?php

namespace App\Entities;

/** Respuesta de `GET /me` (doc 05 §2.1) — SIN envoltura `data` (types.ts `Sesion`). */
final class Sesion
{
    public function __construct(
        public readonly Usuario $usuario,
        public readonly ConfigRecordatorios $configRecordatorios,
    ) {
    }

    /** @return array{usuario: array, config_recordatorios: array} */
    public function aArray(): array
    {
        return [
            'usuario'              => $this->usuario->aArray(),
            'config_recordatorios' => $this->configRecordatorios->aArray(),
        ];
    }
}
