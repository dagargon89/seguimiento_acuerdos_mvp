<?php

namespace App\Entities;

/**
 * Fila de `GET /recordatorios/proximos|historial` (doc 05 §2.3) —
 * types.ts `RecordatorioVista`. `key` es una clave de UI estable (no un id de
 * BD): para "próximos" combina acuerdo+tipo+fecha+destinatario (aún no
 * existen como fila); para "historial" es `env-{id}` del registro real.
 */
final class RecordatorioVista
{
    public function __construct(
        public readonly string $key,
        public readonly ?int $acuerdoId,
        public readonly string $tipo,
        public readonly string $programadoPara,
        public readonly UsuarioRef $destinatario,
        public readonly string $accion,
        public readonly ?string $tema,
        public readonly ?string $fechaCompromiso,
        public readonly bool $enviado,
        public readonly ?string $estadoEnvio, // 'enviado'|'fallido'|null
        public readonly ?string $error,
    ) {
    }

    /** @return array<string, mixed> */
    public function aArray(): array
    {
        return [
            'key'              => $this->key,
            'acuerdo_id'       => $this->acuerdoId,
            'tipo'             => $this->tipo,
            'programado_para'  => $this->programadoPara,
            'destinatario'     => $this->destinatario->aArray(),
            'accion'           => $this->accion,
            'tema'             => $this->tema,
            'fecha_compromiso' => $this->fechaCompromiso,
            'enviado'          => $this->enviado,
            'estado_envio'     => $this->estadoEnvio,
            'error'            => $this->error,
        ];
    }
}
