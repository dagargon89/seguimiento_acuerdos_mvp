<?php

namespace App\Models;

use CodeIgniter\Model;

/** Bitácora de avances/reprogramaciones/validación/reapertura (doc 03). Solo lectura aquí. */
class AvanceModel extends Model
{
    protected $table         = 'avances';
    protected $primaryKey    = 'id';
    protected $useTimestamps = false;
    protected $returnType    = 'array';
    protected $allowedFields = ['acuerdo_id', 'usuario_id', 'tipo', 'descripcion', 'nueva_fecha'];
}
