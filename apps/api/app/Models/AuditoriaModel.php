<?php

namespace App\Models;

use CodeIgniter\Model;

/** Bitácora de auditoría (doc 03/04) — toda escritura registra quién hizo qué. */
class AuditoriaModel extends Model
{
    protected $table         = 'auditoria';
    protected $primaryKey    = 'id';
    protected $useTimestamps = false;
    protected $returnType    = 'array';
    protected $allowedFields = ['usuario_id', 'accion', 'entidad', 'entidad_id', 'detalle', 'ip'];

    /**
     * Registra un evento de auditoría. `$detalle` se serializa a JSON (columna
     * JSON); nunca se concatena SQL (Query Builder / insert, regla №7 de
     * CLAUDE.md).
     *
     * @param array<string, mixed>|null $detalle
     */
    public function registrar(?int $usuarioId, string $accion, string $entidad, ?int $entidadId, ?array $detalle = null, ?string $ip = null): void
    {
        $this->insert([
            'usuario_id' => $usuarioId,
            'accion'     => $accion,
            'entidad'    => $entidad,
            'entidad_id' => $entidadId,
            'detalle'    => $detalle === null ? null : json_encode($detalle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip'         => $ip,
        ]);
    }
}
