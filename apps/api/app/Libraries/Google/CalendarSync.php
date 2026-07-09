<?php

namespace App\Libraries\Google;

/**
 * Sincronización de un acuerdo con Google Calendar (RF-09 / doc 02 §job). El
 * job de recordatorios la invoca para cada `google_sync` pendiente/erróneo,
 * pero depende de la interfaz para poder probarse con un doble SIN credenciales.
 *
 * La implementación real `GoogleCalendarSync` (crear/actualizar evento, manejo
 * de reintentos e idempotencia contra `calendar_event_id`) llega en S2.3; por
 * defecto `Config\Services::calendarSync()` resuelve a `NoopCalendarSync`.
 */
interface CalendarSync
{
    /**
     * Sincroniza el evento del acuerdo indicado. Debe ser idempotente: llamarla
     * varias veces para el mismo acuerdo no crea eventos duplicados (la impl
     * real reconcilia contra `google_sync.calendar_event_id`).
     */
    public function sincronizar(int $acuerdoId): void;
}
