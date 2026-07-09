<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Estado de sincronización con Google Calendar por acuerdo (doc 03). La
 * sincronización real (llamada a la API) es del Sprint 2 — aquí solo se
 * inserta/marca `pendiente` en cada escritura de acuerdo (regla №8 del brief
 * de la Tarea 6).
 */
class GoogleSyncModel extends Model
{
    protected $table         = 'google_sync';
    protected $primaryKey    = 'id';
    protected $useTimestamps = false;
    protected $returnType    = 'array';
    protected $allowedFields = ['acuerdo_id', 'calendar_event_id', 'estado', 'intentos', 'synced_at', 'error'];

    /** Crea la fila `pendiente` inicial para un acuerdo recién capturado. */
    public function crearPendientePara(int $acuerdoId): void
    {
        $this->insert(['acuerdo_id' => $acuerdoId, 'estado' => 'pendiente']);
    }

    /** Marca `pendiente` de nuevo tras una edición que invalida el evento sincronizado. */
    public function marcarPendientePorAcuerdo(int $acuerdoId): void
    {
        $this->where('acuerdo_id', $acuerdoId)->set(['estado' => 'pendiente'])->update();
    }
}
