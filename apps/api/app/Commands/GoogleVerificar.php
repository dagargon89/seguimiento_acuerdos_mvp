<?php

namespace App\Commands;

use App\Libraries\Correo\GmailService;
use App\Libraries\Google\GoogleApiClientCalendarApi;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\I18n\Time;
use Throwable;

/**
 * Humo operativo de la integración Google (ADR-003, pendiente DoD Fase 2):
 *
 *     php spark google:verificar [--correo=destino@planjuarez.org]
 *
 * 1. Diagnostica la configuración (.env): las 3 claves de ADR-003 y que la
 *    clave JSON del service account exista y sea legible.
 * 2. Gmail: envía un correo de prueba real (default: a la propia cuenta
 *    central impersonada) y reporta el gmail_message_id.
 * 3. Calendar: crea un evento all-day de prueba en el calendario compartido,
 *    lo actualiza (patch) y lo elimina — valida los tres permisos sin dejar
 *    basura en el calendario.
 *
 * Cada paso reporta éxito/fallo con pistas para los errores típicos de
 * domain-wide delegation. Código de salida: 0 si todo lo intentado pasó,
 * 1 si algún paso real falló, 2 si la configuración está incompleta.
 */
class GoogleVerificar extends BaseCommand
{
    protected $group       = 'Recordatorios';
    protected $name        = 'google:verificar';
    protected $description = 'Prueba real de Gmail API y Calendar API con las credenciales de .env (humo de ADR-003).';
    protected $usage       = 'google:verificar [--correo=destino]';
    protected $options     = [
        '--correo' => 'Destinatario del correo de prueba. Default: GOOGLE_IMPERSONATED_USER.',
    ];

    public function run(array $params): int
    {
        $credenciales = (string) env('GOOGLE_APPLICATION_CREDENTIALS');
        $impersonado  = (string) env('GOOGLE_IMPERSONATED_USER');
        $calendarId   = (string) env('GOOGLE_CALENDAR_ID');

        CLI::write('── Configuración (.env) ──', 'yellow');
        $baseOk = $this->revisarConfig($credenciales, $impersonado, $calendarId);
        // Gmail solo necesita credenciales + cuenta impersonada; Calendar
        // además el id del calendario. Se prueba lo que ya esté configurado.
        $gmailListo    = $baseOk;
        $calendarListo = $baseOk && $calendarId !== '';

        if (! $gmailListo) {
            CLI::newLine();
            CLI::error('Configuración incompleta: completa las variables de arriba en apps/api/.env y vuelve a correr.');

            return 2;
        }

        $fallos   = 0;
        $omitidos = 0;

        CLI::newLine();
        CLI::write('── Gmail API (gmail.send) ──', 'yellow');
        $destino = (string) ($params['correo'] ?? CLI::getOption('correo') ?? $impersonado);
        $fallos += $this->probarGmail($destino, $impersonado) ? 0 : 1;

        CLI::newLine();
        CLI::write('── Calendar API (calendario compartido) ──', 'yellow');
        if ($calendarListo) {
            $fallos += $this->probarCalendar($calendarId) ? 0 : 1;
        } else {
            $omitidos++;
            CLI::write('  ⚠ Omitido: falta GOOGLE_CALENDAR_ID (crea el calendario compartido con la cuenta central y pon su id).', 'yellow');
        }

        CLI::newLine();
        if ($fallos > 0) {
            CLI::error("✖ {$fallos} paso(s) fallaron — revisa las pistas de arriba.");

            return 1;
        }
        if ($omitidos > 0) {
            CLI::write('⚠ Humo parcial: lo configurado funciona, pero quedan pasos omitidos por configuración incompleta.', 'yellow');

            return 2;
        }
        CLI::write('✔ Humo completo: correo y calendario reales funcionando. Ya puedes programar el cron de recordatorios:procesar.', 'green');

        return 0;
    }

    private function revisarConfig(string $credenciales, string $impersonado, string $calendarId): bool
    {
        $ok = true;

        if ($credenciales === '') {
            CLI::write('  ✖ GOOGLE_APPLICATION_CREDENTIALS: sin configurar (ruta a la clave JSON del service account).', 'red');
            $ok = false;
        } elseif (! is_file($credenciales)) {
            CLI::write("  ✖ GOOGLE_APPLICATION_CREDENTIALS: el archivo no existe → {$credenciales}", 'red');
            $ok = false;
        } elseif (! is_readable($credenciales)) {
            CLI::write("  ✖ GOOGLE_APPLICATION_CREDENTIALS: el archivo existe pero no es legible (revisa permisos) → {$credenciales}", 'red');
            $ok = false;
        } else {
            $json = json_decode((string) file_get_contents($credenciales), true);
            $mail = is_array($json) ? (string) ($json['client_email'] ?? '') : '';
            if ($mail === '') {
                CLI::write('  ✖ GOOGLE_APPLICATION_CREDENTIALS: el JSON no parece una clave de service account (sin client_email).', 'red');
                $ok = false;
            } else {
                CLI::write("  ✔ GOOGLE_APPLICATION_CREDENTIALS: {$credenciales}", 'green');
                CLI::write("      service account: {$mail}");
                $permisos = substr(sprintf('%o', fileperms($credenciales)), -3);
                if ($permisos !== '600' && $permisos !== '400') {
                    CLI::write("      ⚠ permisos {$permisos} — se recomienda 600 (doc 04): chmod 600 '{$credenciales}'", 'yellow');
                }
            }
        }

        if ($impersonado === '') {
            CLI::write('  ✖ GOOGLE_IMPERSONATED_USER: sin configurar (cuenta central, p.ej. acuerdos@planjuarez.org).', 'red');
            $ok = false;
        } else {
            CLI::write("  ✔ GOOGLE_IMPERSONATED_USER: {$impersonado}", 'green');
        }

        if ($calendarId === '') {
            // No bloquea el humo de Gmail: se reporta y el paso de Calendar se omite.
            CLI::write('  ⚠ GOOGLE_CALENDAR_ID: sin configurar (id del calendario compartido "Acuerdos").', 'yellow');
        } else {
            CLI::write("  ✔ GOOGLE_CALENDAR_ID: {$calendarId}", 'green');
        }

        return $ok;
    }

    private function probarGmail(string $destino, string $impersonado): bool
    {
        CLI::write("  Enviando correo de prueba a {$destino}…");

        $ahora = Time::now('America/Ciudad_Juarez')->toDateTimeString();
        $html  = '<div style="font-family:sans-serif;font-size:14px;line-height:1.6">'
            . '<p>Correo de prueba del <strong>Panel de acuerdos · Participa Juárez</strong>.</p>'
            . "<p>Generado por <code>php spark google:verificar</code> el {$ahora} (America/Ciudad_Juarez).</p>"
            . '<p>Si lo estás leyendo, la Gmail API con domain-wide delegation quedó bien configurada.</p>'
            . '</div>';

        try {
            $id = (new GmailService())->enviar($destino, '[Prueba] Panel de acuerdos — Gmail API funcionando', $html);
            CLI::write("  ✔ Enviado. gmail_message_id: {$id} (remitente: {$impersonado})", 'green');

            return true;
        } catch (Throwable $e) {
            CLI::write('  ✖ Falló el envío: ' . $e->getMessage(), 'red');
            $this->pistas($e);

            return false;
        }
    }

    private function probarCalendar(string $calendarId): bool
    {
        $api    = new GoogleApiClientCalendarApi();
        $manana = Time::now('America/Ciudad_Juarez')->addDays(1)->toDateString();
        $pasado = Time::now('America/Ciudad_Juarez')->addDays(2)->toDateString();

        try {
            CLI::write('  Creando evento de prueba (all-day, mañana)…');
            $eventId = $api->crearEvento($calendarId, [
                'summary' => '[PRUEBA] Panel de acuerdos — puede borrarse',
                'start'   => ['date' => $manana],
                'end'     => ['date' => $pasado],
            ], false); // humo: evento de prueba sin invitados, no notificar.
            CLI::write("  ✔ Creado. event_id: {$eventId}", 'green');

            CLI::write('  Actualizando el evento (patch)…');
            $api->actualizarEvento($calendarId, $eventId, [
                'summary' => '[PRUEBA ✔] Panel de acuerdos — actualización OK',
                'start'   => ['date' => $manana],
                'end'     => ['date' => $pasado],
            ], false);
            CLI::write('  ✔ Actualizado.', 'green');

            CLI::write('  Eliminando el evento de prueba…');
            $api->eliminarEvento($calendarId, $eventId);
            CLI::write('  ✔ Eliminado — el calendario quedó limpio.', 'green');

            return true;
        } catch (Throwable $e) {
            CLI::write('  ✖ Falló Calendar: ' . $e->getMessage(), 'red');
            $this->pistas($e);

            return false;
        }
    }

    /** Pistas para los errores típicos de domain-wide delegation / calendario. */
    private function pistas(Throwable $e): void
    {
        $msg = strtolower($e->getMessage());

        if (str_contains($msg, 'unauthorized_client')) {
            CLI::write('    Pista: el client ID del service account no está autorizado en Workspace Admin', 'yellow');
            CLI::write('    (Seguridad → Controles de API → Delegación de todo el dominio) o los scopes no', 'yellow');
            CLI::write('    coinciden EXACTOS: https://www.googleapis.com/auth/gmail.send y https://www.googleapis.com/auth/calendar', 'yellow');
        } elseif (str_contains($msg, 'invalid_grant') || str_contains($msg, 'subject')) {
            CLI::write('    Pista: GOOGLE_IMPERSONATED_USER no existe en el dominio o no es una cuenta de Workspace válida.', 'yellow');
        } elseif (str_contains($msg, 'notfound') || str_contains($msg, 'not found')) {
            CLI::write('    Pista: GOOGLE_CALENDAR_ID incorrecto, o el calendario no pertenece a la cuenta impersonada.', 'yellow');
            CLI::write('    El id correcto está en Configuración del calendario → "Identificador del calendario" (…@group.calendar.google.com).', 'yellow');
        } elseif (str_contains($msg, 'accessnotconfigured') || str_contains($msg, 'has not been used')) {
            CLI::write('    Pista: la API (Gmail o Calendar) no está habilitada en el proyecto de Google Cloud del service account.', 'yellow');
        }
    }
}
