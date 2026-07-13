<?php

namespace App\Libraries\Google;

/**
 * Cliente de bajo nivel contra la API de Google Calendar (RF-09, S2.3). Solo
 * conoce crear/actualizar eventos por su `calendarId`/`eventId`; NO conoce
 * `google_sync` ni acuerdos — esa orquestación (idempotencia, título RF-09,
 * manejo de reintentos) vive en `GoogleCalendarService` (implementa
 * `CalendarSync`), que depende de esta interfaz para poder probarse sin red
 * vía `Tests\Support\FakeCalendarApi`.
 */
interface CalendarApi
{
    /**
     * Crea un evento en el calendario `$calendarId`. Devuelve el `event_id`
     * asignado por Google (para persistirlo en `google_sync.calendar_event_id`).
     *
     * @param array<string, mixed> $evento Forma libre (la implementación real la
     *                                      traduce a `Google\Service\Calendar\Event`).
     */
    public function crearEvento(string $calendarId, array $evento): string;

    /**
     * Actualiza (patch) un evento ya existente. NO crea uno nuevo: si
     * `$eventId` no existe, la implementación real deja que la excepción de la
     * API se propague (la captura la vive en `GoogleCalendarService`).
     *
     * @param array<string, mixed> $evento
     */
    public function actualizarEvento(string $calendarId, string $eventId, array $evento): void;

    /**
     * Elimina un evento. El flujo de sincronización NO borra eventos (RF-09
     * los marca [Concluido]); existe para el humo operativo
     * (`spark google:verificar`), que crea un evento de prueba y lo limpia.
     */
    public function eliminarEvento(string $calendarId, string $eventId): void;
}
