<?php

namespace App\Filters;

use App\Libraries\Auth\UsuarioActual;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Rate limit (doc 04 §A07, doc 05 §1). Corre DESPUÉS de FirebaseAuthFilter en el
 * grupo protegido: si ya hay usuario resuelto, limita 60 req/min por usuario
 * (clave `rl.usuario.{id}`); si no (rutas públicas que decidan usar este filter),
 * limita 10 req/min por IP (clave `rl.ip.{ip}`).
 *
 * Usa el throttler nativo de CI4 (service('throttler')), respaldado por el cache
 * configurado (Redis en dev/producción).
 *
 * Nota de nomenclatura: el separador es `.`, no `:` — ver comentario equivalente
 * en App\Libraries\Auth\AuthCache (Config\Cache::$reservedCharacters).
 */
class ThrottleFilter implements FilterInterface
{
    private const CAPACIDAD_USUARIO = 60;

    private const CAPACIDAD_IP = 10;

    private const VENTANA_SEGUNDOS = 60;

    public function before(RequestInterface $request, $arguments = null)
    {
        $usuarioId = service('usuarioActual')->id();

        if ($usuarioId !== null) {
            $clave     = 'rl.usuario.' . $usuarioId;
            $capacidad = self::CAPACIDAD_USUARIO;
        } else {
            $clave     = 'rl.ip.' . $request->getIPAddress();
            $capacidad = self::CAPACIDAD_IP;
        }

        $throttler = service('throttler');

        if (! $throttler->check($clave, $capacidad, self::VENTANA_SEGUNDOS)) {
            $retryAfter = max(1, $throttler->getTokenTime());

            $response = service('response');
            $response->setStatusCode(429);
            $response->setHeader('Retry-After', (string) $retryAfter);
            $response->setJSON([
                'error'   => 'rate_limit',
                'mensaje' => 'Demasiadas solicitudes. Intenta de nuevo más tarde.',
            ]);

            return $response;
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return $response;
    }
}
