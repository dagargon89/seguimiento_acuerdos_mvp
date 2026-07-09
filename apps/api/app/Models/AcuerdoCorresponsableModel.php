<?php

namespace App\Models;

use CodeIgniter\Model;

/** Tabla puente acuerdo↔corresponsable (doc 03). */
class AcuerdoCorresponsableModel extends Model
{
    protected $table         = 'acuerdo_corresponsables';
    protected $primaryKey    = null; // PK compuesta (acuerdo_id, usuario_id); no se usa find() por id.
    protected $useAutoIncrement = false;
    protected $useTimestamps = false;
    protected $returnType    = 'array';
    protected $allowedFields = ['acuerdo_id', 'usuario_id'];

    /**
     * Reemplaza TODO el conjunto de corresponsables de un acuerdo (PUT
     * .../corresponsables — semántica de reemplazo total, doc 05 §2.2).
     *
     * @param int[] $usuarioIds Ya deduplicados por el controller.
     */
    public function reemplazarDe(int $acuerdoId, array $usuarioIds): void
    {
        $this->where('acuerdo_id', $acuerdoId)->delete();
        if ($usuarioIds === []) {
            return;
        }

        // insertBatch() del Model exige `primaryKey` (single-column); esta tabla
        // tiene PK compuesta, así que usamos el Query Builder crudo directamente
        // (misma conexión, mismo nivel de "prepared" — regla №7 de CLAUDE.md).
        $filas = array_map(
            static fn (int $uid): array => ['acuerdo_id' => $acuerdoId, 'usuario_id' => $uid],
            $usuarioIds,
        );
        $this->builder()->insertBatch($filas);
    }
}
