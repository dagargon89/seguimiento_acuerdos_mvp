<?php

namespace App\Models;

use CodeIgniter\Model;

/** Configuración clave/valor (doc 03) — hoy solo `recordatorios_default`. */
class ConfiguracionModel extends Model
{
    protected $table         = 'configuracion';
    protected $primaryKey    = 'clave';
    protected $useAutoIncrement = false;
    protected $useTimestamps = false;
    protected $returnType    = 'array';
    protected $allowedFields = ['valor'];

    /** @return array<string, mixed> Config vigente de recordatorios, ya decodificada. */
    public function recordatoriosDefault(): array
    {
        $fila = $this->find('recordatorios_default');

        return json_decode((string) $fila['valor'], true, 512, JSON_THROW_ON_ERROR);
    }
}
