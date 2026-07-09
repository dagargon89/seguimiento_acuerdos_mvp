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
 * Strict-Transport-Security (HSTS, S3.2/doc 04 §A02) se agrega SOLO cuando la
 * request llega por HTTPS real (`$request->isSecure()`, que en CI4 consulta
 * `$_SERVER['HTTPS']` — ver IncomingRequest::isSecure()). Nunca en HTTP plano:
 * anunciarlo ahí forzaría al navegador a exigir HTTPS en un host que todavía
 * no lo tiene (dev local, o un healthcheck sin TLS), dejándolo inaccesible.
 * En despliegues detrás de un proxy/balanceador que termina TLS y reenvía por
 * HTTP interno, el proxy debe fijar `$_SERVER['HTTPS']` (o el equivalente que
 * CI4 lea) para que esta condición se cumpla.
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

        if ($request->isSecure()) {
            $response->setHeader('Strict-Transport-Security', 'max-age=63072000; includeSubDomains; preload');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return $response;
    }
}
