<?php

namespace Config;

use App\Libraries\Auth\KreaitTokenVerifier;
use App\Libraries\Auth\TokenVerifierInterface;
use App\Libraries\Auth\UsuarioActual;
use App\Libraries\Correo\Mailer;
use App\Libraries\Correo\NoopMailer;
use App\Libraries\Google\CalendarSync;
use App\Libraries\Google\NoopCalendarSync;
use App\Services\RecordatorioService;
use CodeIgniter\Config\BaseService;
use Kreait\Firebase\JWT\IdTokenVerifier;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 *
 * This file holds any application-specific services, or service overrides
 * that you might need. An example has been included with the general
 * method format you should use for your service methods. For more examples,
 * see the core Services file at system/Config/Services.php.
 */
class Services extends BaseService
{
    /**
     * Verificador de ID tokens de Firebase (ADR-002). En producción resuelve a
     * KreaitTokenVerifier; en tests se sustituye con FakeTokenVerifier vía
     * Services::injectMock('tokenVerifier', $fake).
     *
     * Usa IdTokenVerifier de kreait/firebase-tokens (createWithProjectId): valida
     * el token solo con el project id + las llaves públicas de Google, SIN
     * credenciales de service account. `Factory::createAuth()` NO sirve aquí
     * porque exige credenciales (falla con "Unable to create an API client
     * without credentials") aun para verificar; esas credenciales son de la
     * integración Gmail/Calendar del Sprint 2, no de la autenticación.
     */
    public static function tokenVerifier(bool $getShared = true): TokenVerifierInterface
    {
        if ($getShared) {
            return static::getSharedInstance('tokenVerifier');
        }

        $verifier = IdTokenVerifier::createWithProjectId((string) env('FIREBASE_PROJECT_ID'));

        return new KreaitTokenVerifier($verifier);
    }

    /**
     * Usuario local resuelto por FirebaseAuthFilter para la request en curso.
     * Los controllers de tareas posteriores lo consumen vía Services::usuarioActual().
     */
    public static function usuarioActual(bool $getShared = true): UsuarioActual
    {
        if ($getShared) {
            return static::getSharedInstance('usuarioActual');
        }

        return new UsuarioActual();
    }

    /**
     * Mailer transaccional (RF-08.4). Binding por defecto: NoopMailer (no envía,
     * sin credenciales). La impl real GmailMailer llega en S2.2. En tests se
     * sustituye con FakeMailer vía Services::injectMock('mailer', $fake).
     */
    public static function mailer(bool $getShared = true): Mailer
    {
        if ($getShared) {
            return static::getSharedInstance('mailer');
        }

        return new NoopMailer();
    }

    /**
     * Sincronizador de Google Calendar (RF-09). Binding por defecto:
     * NoopCalendarSync (no sincroniza, sin credenciales). La impl real
     * GoogleCalendarSync llega en S2.3. En tests se sustituye vía
     * Services::injectMock('calendarSync', $fake).
     */
    public static function calendarSync(bool $getShared = true): CalendarSync
    {
        if ($getShared) {
            return static::getSharedInstance('calendarSync');
        }

        return new NoopCalendarSync();
    }

    /**
     * Servicio orquestador del job `recordatorios:procesar` (S2.1). Se resuelve
     * SIEMPRE fresco (no shared) para tomar los bindings actuales de
     * mailer/calendarSync (incluidos los mocks inyectados en tests).
     */
    public static function recordatorioService(bool $getShared = true): RecordatorioService
    {
        if ($getShared) {
            return static::getSharedInstance('recordatorioService');
        }

        return new RecordatorioService(
            static::mailer(),
            static::calendarSync(),
        );
    }
}
