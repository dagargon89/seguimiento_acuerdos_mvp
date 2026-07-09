<?php

namespace Tests\Unit;

use App\Libraries\Correo\NoopMailer;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

/**
 * `Config\Services::mailer()` (S2.2): con `GOOGLE_APPLICATION_CREDENTIALS`
 * vacío (estado actual del entorno de tests — ver `.env`/`phpunit.dist.xml`,
 * no se configuran credenciales de Google ahí), debe resolver a `NoopMailer`.
 *
 * La rama con credenciales (GmailService) NO se ejercita aquí: instanciar
 * GmailService dispara `Google\Client::setAuthConfig()` contra una ruta real,
 * lo que requeriría credenciales válidas o lanzaría una excepción de la
 * librería de Google — fuera del alcance de un test sin red/credenciales.
 *
 * @internal
 */
final class ServicesMailerTest extends CIUnitTestCase
{
    public function testSinCredencialesResuelveANoopMailer(): void
    {
        $this->assertSame('', (string) env('GOOGLE_APPLICATION_CREDENTIALS'), 'precondición: sin credenciales en el entorno de tests');

        $mailer = Services::mailer(false);

        $this->assertInstanceOf(NoopMailer::class, $mailer);
    }

    public function testServiceHelperResuelveANoopMailerPorDefecto(): void
    {
        $mailer = service('mailer');

        $this->assertInstanceOf(NoopMailer::class, $mailer);
    }
}
