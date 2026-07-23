<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Directorio de usuarios (doc 03). Solo lectura en esta tarea; alta/edición
 * (POST/PATCH /usuarios) llega en una tarea posterior.
 */
class UsuarioModel extends Model
{
    protected $table         = 'usuarios';
    protected $primaryKey    = 'id';
    protected $useTimestamps = false;
    protected $returnType    = 'array';
    protected $allowedFields = ['firebase_uid', 'nombre', 'email', 'rol', 'area_id', 'avatar_color', 'activo'];

    /** Directorio activo, orden alfabético (para selects de responsable/corresponsables). */
    public function activos(): array
    {
        return $this->where('activo', 1)->orderBy('nombre', 'ASC')->findAll();
    }
}
