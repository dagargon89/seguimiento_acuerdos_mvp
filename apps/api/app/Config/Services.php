<?php

namespace Config;

use App\Libraries\Auth\KreaitTokenVerifier;
use App\Libraries\Auth\TokenVerifierInterface;
use App\Libraries\Auth\UsuarioActual;
use CodeIgniter\Config\BaseService;
use Kreait\Firebase\Factory;

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
     * Nota (concern documentado en la tarea 4): kreait/firebase-php solo acepta
     * un cache PSR-6 (CacheItemPoolInterface) para las claves públicas de Google
     * (JWKS) vía Factory::withVerifierCache(). El proyecto no trae ningún
     * adaptador PSR-6 respaldado por Redis (solo beste/in-memory-cache, que es
     * en memoria de proceso); implementar uno propio queda fuera de alcance de
     * esta tarea. Se deja con el default de kreait (InMemoryCache), que sigue
     * evitando llamadas de red repetidas dentro del mismo proceso PHP pero no
     * comparte el cache de JWKS entre workers/requests como sí lo hace el cache
     * Redis de CI4 para el resto de la app.
     */
    public static function tokenVerifier(bool $getShared = true): TokenVerifierInterface
    {
        if ($getShared) {
            return static::getSharedInstance('tokenVerifier');
        }

        $auth = (new Factory())
            ->withProjectId((string) env('FIREBASE_PROJECT_ID'))
            ->createAuth();

        return new KreaitTokenVerifier($auth);
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
}
