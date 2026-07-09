<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Headers de seguridad globales (doc 04 §A05, §3 "Borde"). Global `before`
 * (ver Config\Filters::$globals) — corre primero en antes de cualquier otro
 * filter y escribe sobre el objeto `response` COMPARTIDO (service('response')
 * es singleton), así que los headers sobreviven aunque un filter posterior
 * (FirebaseAuthFilter, ThrottleFilter) corte el ciclo devolviendo una Response
 * antes de llegar al controller: CI4 NO corre los filters `after` cuando un
 * filter `before` hace short-circuit (ver CodeIgniter::handleRequest), por eso
 * este filter NO puede vivir solo en `after` si se quiere que 401/403/429
 * también lleven estos headers.
 *
 * NO se agrega Strict-Transport-Security aquí: HSTS solo tiene sentido detrás de
 * HTTPS ya forzado y se activa en el hardening de despliegue (S3.2, doc 04
 * "Hardening de despliegue"); agregarlo antes, en dev sobre HTTP, rompería el
 * acceso local.
 */
class SecurityHeadersFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $response = service('response');
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('X-Frame-Options', 'DENY');
        $response->setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->setHeader('Content-Security-Policy', "default-src 'none'");

        // TODO(hardening S3.2): agregar Strict-Transport-Security cuando HTTPS
        // esté forzado en producción (app.forceGlobalSecureRequests = true).

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return $response;
    }
}
