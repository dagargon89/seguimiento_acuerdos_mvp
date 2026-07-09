<?php

namespace App\Models;

use CodeIgniter\Model;

/** Tabla puente acuerdo↔corresponsable (doc 03). Solo lectura en esta tarea. */
class AcuerdoCorresponsableModel extends Model
{
    protected $table         = 'acuerdo_corresponsables';
    protected $primaryKey    = null; // PK compuesta (acuerdo_id, usuario_id); no se usa find() por id.
    protected $useAutoIncrement = false;
    protected $useTimestamps = false;
    protected $returnType    = 'array';
    protected $allowedFields = ['acuerdo_id', 'usuario_id'];
}
