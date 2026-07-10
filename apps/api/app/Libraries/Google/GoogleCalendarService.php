<?php

namespace App\Libraries\Google;

use CodeIgniter\I18n\Time;
use Config\Database;
use Throwable;

/**
 * Implementación real de `CalendarSync` (RF-09, S2.3): crea/actualiza el
 * evento all-day del acuerdo en `google_sync`, reconciliando estado,
 * intentos y error. Toda la LÓGICA vive aquí; `CalendarApi` es solo el
 * cliente de bajo nivel (inyectado, testeable con `FakeCalendarApi`).
 *
 * Reglas (CLAUDE.md / RF-09):
 * - Idempotente: si `calendar_event_id` ya existe, SIEMPRE se hace `patch`
 *   (actualizarEvento), nunca se crea uno nuevo — no se duplican eventos.
 * - Un fallo de la API NO se propaga: se captura, la fila queda `error` con
 *   `intentos+1` y el mensaje de la excepción; `calendar_event_id` y
 *   `synced_at` no se toca en ese caso. Quien orquesta (`RecordatorioService`)
 *   sigue con las demás filas.
 * - Fechas en TZ America/Ciudad_Juarez (evento all-day y `synced_at`).
 * - Solo Query Builder (vía la conexión de CI4), nunca SQL crudo.
 *
 * Decisiones de diseño sin especificación exacta en el brief (documentadas):
 * - `colorId` de acuerdo concluido: '8' (graphite/gris), el tono neutro más
 *   cercano en la paleta fija de 11 colores de Calendar (RF-09.2 "color neutro").
 * - `intentos` en éxito: se resetea a 0 (no se deja el contador de fallos
 *   previos "pegado" a una fila que ya sincronizó bien; si vuelve a fallar en
 *   el futuro empieza a contar de nuevo desde 0).
 */
final class GoogleCalendarService implements CalendarSync
{
    private const COLOR_ID_CONCLUIDO = '8';

    public function __construct(
        private readonly CalendarApi $api,
        private readonly string $calendarId,
    ) {
    }

    public function sincronizar(int $acuerdoId): void
    {
        $db = Database::connect();

        $acuerdo = $db->table('acuerdos')
            ->select('acuerdos.id, acuerdos.tema, acuerdos.accion, acuerdos.estado, acuerdos.fecha_compromiso, acuerdos.responsable_id, usuarios.nombre AS responsable_nombre')
            ->join('usuarios', 'usuarios.id = acuerdos.responsable_id', 'left')
            ->where('acuerdos.id', $acuerdoId)
            ->get()->getRowArray();

        $filaSync = $db->table('google_sync')->where('acuerdo_id', $acuerdoId)->get()->getRowArray();

        if ($acuerdo === null || $filaSync === null) {
            // Defensive: nada que sincronizar (acuerdo borrado o sin fila google_sync).
            return;
        }

        $evento = $this->construirEvento($acuerdo);

        try {
            if ($filaSync['calendar_event_id'] === null) {
                $eventId = $this->api->crearEvento($this->calendarId, $evento);
            } else {
                $eventId = $filaSync['calendar_event_id'];
                $this->api->actualizarEvento($this->calendarId, $eventId, $evento);
            }

            $db->table('google_sync')->where('acuerdo_id', $acuerdoId)->update([
                'calendar_event_id' => $eventId,
                'estado'            => 'sincronizado',
                'intentos'          => 0,
                'synced_at'         => Time::now('America/Ciudad_Juarez')->toDateTimeString(),
                'error'             => null,
            ]);
        } catch (Throwable $e) {
            $db->table('google_sync')->where('acuerdo_id', $acuerdoId)->update([
                'estado'   => 'error',
                'intentos' => ((int) $filaSync['intentos']) + 1,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Construye el evento all-day (forma libre que consume `CalendarApi`).
     * Título RF-09: `[Concluido] ` (si aplica) + `[Tema] ` (si `tema` no es
     * null) + `Acción — Responsable`. Fecha all-day en TZ Ciudad Juárez para
     * no desfasar el día (Google exige `end` exclusivo: `fecha_compromiso + 1 día`).
     *
     * @param array<string, mixed> $acuerdo
     *
     * @return array<string, mixed>
     */
    private function construirEvento(array $acuerdo): array
    {
        $concluido = $acuerdo['estado'] === 'concluido';

        $titulo = '';
        if ($concluido) {
            $titulo .= '[Concluido] ';
        }
        if ($acuerdo['tema'] !== null && $acuerdo['tema'] !== '') {
            $titulo .= '[' . $acuerdo['tema'] . '] ';
        }
        $titulo .= $acuerdo['accion'] . ' — ' . (string) $acuerdo['responsable_nombre'];

        $inicio = new \DateTimeImmutable((string) $acuerdo['fecha_compromiso'], new \DateTimeZone('America/Ciudad_Juarez'));
        $fin    = $inicio->modify('+1 day');

        $evento = [
            'summary' => $titulo,
            'start'   => ['date' => $inicio->format('Y-m-d')],
            'end'     => ['date' => $fin->format('Y-m-d')],
        ];

        if ($concluido) {
            $evento['colorId'] = self::COLOR_ID_CONCLUIDO;
        }

        return $evento;
    }
}
