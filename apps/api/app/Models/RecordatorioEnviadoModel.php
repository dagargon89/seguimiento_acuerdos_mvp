<?php

namespace App\Models;

use CodeIgniter\Model;

/** Log inmutable de envíos de recordatorios (doc 03). Solo lectura en esta tarea. */
class RecordatorioEnviadoModel extends Model
{
    protected $table         = 'recordatorios_enviados';
    protected $primaryKey    = 'id';
    protected $useTimestamps = false;
    protected $returnType    = 'array';
    protected $allowedFields = [
        'acuerdo_id', 'usuario_id', 'tipo', 'programado_para',
        'enviado_at', 'estado', 'gmail_message_id', 'error',
    ];
}
