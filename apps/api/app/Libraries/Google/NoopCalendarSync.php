<?php

namespace App\Libraries\Google;

/**
 * Doble sin efectos: NO llama a Google Calendar. Binding por defecto de
 * `Config\Services::calendarSync()` mientras la integración real
 * (`GoogleCalendarSync`, S2.3) no exista o no haya credenciales.
 *
 * El job orquesta e invoca esta interfaz para los `google_sync` pendientes;
 * con el noop la fila no cambia de estado (se documenta que en este modo la
 * sincronización real NO ocurre).
 */
final class NoopCalendarSync implements CalendarSync
{
    public function sincronizar(int $acuerdoId): void
    {
        log_message('info', 'NoopCalendarSync: sincronización NO realizada (sin credenciales Google) acuerdo {id}', [
            'id' => $acuerdoId,
        ]);
    }

    public function eliminarEventoPorId(string $calendarEventId): void
    {
        log_message('info', 'NoopCalendarSync: eliminación NO realizada (sin credenciales Google) evento {id}', [
            'id' => $calendarEventId,
        ]);
    }
}
