<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Catálogo de áreas (doc 03). Solo lectura en esta tarea; el alta/edición
 * (POST/PATCH /areas, ADR-004) llega en una tarea posterior.
 */
class AreaModel extends Model
{
    protected $table         = 'areas';
    protected $primaryKey    = 'id';
    protected $useTimestamps = false;
    protected $returnType    = 'array';
    protected $allowedFields = ['nombre', 'activa'];
}
