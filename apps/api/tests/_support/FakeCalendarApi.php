<?php

namespace Tests\Support;

use App\Libraries\Google\CalendarApi;
use RuntimeException;
use Throwable;

/**
 * Doble de CalendarApi para tests de GoogleCalendarService: registra cada
 * llamada (con sus argumentos) para poder assertar qué se invocó, y permite
 * forzar una excepción en crearEvento/actualizarEvento para probar el manejo
 * de errores del servicio. No hace red.
 */
final class FakeCalendarApi implements CalendarApi
{
    /** @var list<array{calendarId: string, evento: array<string, mixed>}> */
    public array $creados = [];

    /** @var list<array{calendarId: string, eventId: string, evento: array<string, mixed>}> */
    public array $actualizados = [];

    /** @var list<array{calendarId: string, eventId: string}> */
    public array $eliminados = [];

    /** Si no es null, crearEvento() la lanza en vez de "crear". */
    public ?Throwable $lanzarErrorEnCrear = null;

    /** Si no es null, actualizarEvento() la lanza en vez de "actualizar". */
    public ?Throwable $lanzarErrorEnActualizar = null;

    /** event_id fake devuelto por crearEvento(); configurable para deterministas. */
    public string $proximoEventId = 'fake-event-1';

    private int $contador = 0;

    public function crearEvento(string $calendarId, array $evento): string
    {
        if ($this->lanzarErrorEnCrear !== null) {
            throw $this->lanzarErrorEnCrear;
        }

        $this->creados[] = ['calendarId' => $calendarId, 'evento' => $evento];
        $this->contador++;

        return $this->proximoEventId;
    }

    public function actualizarEvento(string $calendarId, string $eventId, array $evento): void
    {
        if ($this->lanzarErrorEnActualizar !== null) {
            throw $this->lanzarErrorEnActualizar;
        }

        $this->actualizados[] = ['calendarId' => $calendarId, 'eventId' => $eventId, 'evento' => $evento];
    }

    public function eliminarEvento(string $calendarId, string $eventId): void
    {
        $this->eliminados[] = ['calendarId' => $calendarId, 'eventId' => $eventId];
    }

    /** Configura que crearEvento() lance una excepción con el mensaje dado. */
    public function fallarEnCrear(string $mensaje = 'fallo simulado en crearEvento'): void
    {
        $this->lanzarErrorEnCrear = new RuntimeException($mensaje);
    }

    /** Configura que actualizarEvento() lance una excepción con el mensaje dado. */
    public function fallarEnActualizar(string $mensaje = 'fallo simulado en actualizarEvento'): void
    {
        $this->lanzarErrorEnActualizar = new RuntimeException($mensaje);
    }

    public function llamadasCrear(): int
    {
        return count($this->creados);
    }

    public function llamadasActualizar(): int
    {
        return count($this->actualizados);
    }
}
