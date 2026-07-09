<?php

namespace App\Models;

use CodeIgniter\Model;

/** Reuniones donde se capturan lotes de acuerdos (doc 03). Solo lectura aquí. */
class ReunionModel extends Model
{
    protected $table         = 'reuniones';
    protected $primaryKey    = 'id';
    protected $useTimestamps = false;
    protected $returnType    = 'array';
    protected $allowedFields = ['nombre', 'fecha'];
}
