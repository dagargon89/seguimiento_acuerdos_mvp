<?php

namespace Tests\Unit;

use App\Libraries\Correo\NoopMailer;
use App\Libraries\Google\NoopCalendarSync;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

/**
 * Guardia dura de `Config\Services::mailer()`/`calendarSync()` (S2.2/ADR-009):
 * en ENVIRONMENT === 'testing' SIEMPRE resuelven a los Noop, aunque el `.env`
 * del desarrollador tenga credenciales reales de Google configuradas — sin
 * esta guardia, los tests de escritura dispararían la sincronización
 * inmediata (ADR-009) y el job contra la API real de Gmail/Calendar.
 *
 * La rama con credenciales (GmailService/GoogleCalendarService) NO se
 * ejercita aquí: instanciarlas dispara `Google\Client::setAuthConfig()`
 * contra una ruta real — fuera del alcance de un test sin red/credenciales.
 *
 * @internal
 */
final class ServicesMailerTest extends CIUnitTestCase
{
    public function testEnTestingResuelveANoopMailerAunqueHayaCredenciales(): void
    {
        // No se asume nada sobre el .env del desarrollador: la garantía es la
        // guardia de entorno, no la ausencia de credenciales.
        $mailer = Services::mailer(false);

        $this->assertInstanceOf(NoopMailer::class, $mailer);
    }

    public function testServiceHelperResuelveANoopMailerPorDefecto(): void
    {
        $mailer = service('mailer');

        $this->assertInstanceOf(NoopMailer::class, $mailer);
    }

    public function testEnTestingCalendarSyncResuelveANoopAunqueHayaCredenciales(): void
    {
        $sync = Services::calendarSync(false);

        $this->assertInstanceOf(NoopCalendarSync::class, $sync);
    }
}
