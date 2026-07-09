<?php

namespace App\Libraries\Google;

use Google\Client as GoogleClient;
use Google\Service\Calendar as GoogleCalendar;
use Google\Service\Calendar\Event as GoogleCalendarEvent;
use Google\Service\Calendar\EventDateTime as GoogleCalendarEventDateTime;
use RuntimeException;

/**
 * Implementación real de `CalendarApi` con `google/apiclient` (RF-09, S2.3,
 * ADR-003). Usa domain-wide delegation: el service account impersona a
 * `GOOGLE_IMPERSONATED_USER` (misma cuenta central que Gmail, ver
 * `GmailService`), scope `Calendar::CALENDAR` (lectura/escritura de eventos).
 *
 * `Config\Services::calendarSync()` solo instancia esta clase cuando
 * `GOOGLE_APPLICATION_CREDENTIALS` y `GOOGLE_CALENDAR_ID` están configurados;
 * de lo contrario usa `NoopCalendarSync`. Nunca hardcodea credenciales: todo
 * vía `env()`.
 *
 * Toda la lógica de negocio (idempotencia, título RF-09, manejo de
 * reintentos) vive en `GoogleCalendarService`; esta clase es solo el
 * traductor entre el array de evento y `Google\Service\Calendar\Event`. Un
 * fallo de red/API se propaga sin capturar — es responsabilidad de quien
 * llama (`GoogleCalendarService`) capturarla y marcar la fila `google_sync`
 * como error sin abortar el job (RE-07 / regla №2 de CLAUDE.md aplicada a
 * este job).
 */
final class GoogleApiClientCalendarApi implements CalendarApi
{
    private GoogleCalendar $calendarService;

    /**
     * @param GoogleClient|null $client Inyectable para pruebas; en producción
     *                                  `Config\Services::calendarSync()` no lo pasa y este
     *                                  constructor arma el cliente real con las
     *                                  credenciales de `.env` (ADR-003).
     */
    public function __construct(?GoogleClient $client = null)
    {
        if ($client === null) {
            $client = new GoogleClient();
            $client->setAuthConfig((string) env('GOOGLE_APPLICATION_CREDENTIALS'));
            $client->setSubject((string) env('GOOGLE_IMPERSONATED_USER'));
            $client->addScope(GoogleCalendar::CALENDAR);
        }

        $this->calendarService = new GoogleCalendar($client);
    }

    public function crearEvento(string $calendarId, array $evento): string
    {
        $event = $this->mapearEvento($evento);

        $creado = $this->calendarService->events->insert($calendarId, $event);

        $id = $creado->getId();
        if ($id === null || $id === '') {
            // No debería ocurrir con una respuesta exitosa de la API, pero si
            // pasa no queremos devolver un id vacío que se registre como éxito.
            throw new RuntimeException('GoogleApiClientCalendarApi: la API no devolvió un id de evento.');
        }

        return $id;
    }

    public function actualizarEvento(string $calendarId, string $eventId, array $evento): void
    {
        $event = $this->mapearEvento($evento);

        // patch (nunca insert/update completo): reconcilia solo los campos
        // presentes, evita pisar campos que no gestionamos (RF-09: nunca se
        // crea un evento nuevo cuando ya existe calendar_event_id).
        $this->calendarService->events->patch($calendarId, $eventId, $event);
    }

    /**
     * Traduce el array de evento (forma libre, la construye
     * `GoogleCalendarService`) a `Google\Service\Calendar\Event`. Solo
     * soporta eventos all-day (`start.date` / `end.date`, RF-09).
     *
     * @param array<string, mixed> $evento
     */
    private function mapearEvento(array $evento): GoogleCalendarEvent
    {
        $event = new GoogleCalendarEvent();
        $event->setSummary((string) $evento['summary']);

        $start = new GoogleCalendarEventDateTime();
        $start->setDate((string) $evento['start']['date']);
        $event->setStart($start);

        $end = new GoogleCalendarEventDateTime();
        $end->setDate((string) $evento['end']['date']);
        $event->setEnd($end);

        if (isset($evento['colorId'])) {
            $event->setColorId((string) $evento['colorId']);
        }

        return $event;
    }
}
