<?php

namespace App\Models;

use CodeIgniter\Model;

/** Reuniones donde se capturan lotes de acuerdos (doc 03). */
class ReunionModel extends Model
{
    protected $table         = 'reuniones';
    protected $primaryKey    = 'id';
    protected $useTimestamps = false;
    protected $returnType    = 'array';
    protected $allowedFields = ['nombre', 'fecha'];

    /**
     * Reutiliza la reunión si ya existe una con el mismo `nombre`+`fecha`
     * (UNIQUE KEY uq_reuniones_nombre_fecha); si no, la crea. Regla №8 del
     * brief de la Tarea 6 (captura de lote).
     */
    public function obtenerOCrear(string $nombre, string $fecha): int
    {
        $existente = $this->where('nombre', $nombre)->where('fecha', $fecha)->first();
        if ($existente !== null) {
            return (int) $existente['id'];
        }

        $this->insert(['nombre' => $nombre, 'fecha' => $fecha]);

        return (int) $this->insertID();
    }
}
