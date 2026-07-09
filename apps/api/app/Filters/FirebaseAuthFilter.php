<?php

namespace App\Filters;

use App\Libraries\Auth\AuthCache;
use App\Libraries\Auth\TokenInvalidoException;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

/**
 * Autenticación de borde (ADR-002, doc 04 §A07). Verifica el ID token de
 * Firebase, resuelve el usuario local (lista blanca en `usuarios`, enlace de
 * primer login) y lo deja disponible para el resto de la request vía
 * Services::usuarioActual().
 *
 * Errores en el formato del doc 05 §1: {"error":"codigo_snake","mensaje":"..."}.
 */
class FirebaseAuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (! $request instanceof IncomingRequest) {
            return null;
        }

        $header = $request->getHeaderLine('Authorization');

        if ($header === '' || ! str_starts_with($header, 'Bearer ')) {
            return $this->error(401, 'token_faltante', 'Falta el encabezado Authorization: Bearer <token>.');
        }

        $idToken = trim(substr($header, 7));

        if ($idToken === '') {
            return $this->error(401, 'token_faltante', 'Falta el encabezado Authorization: Bearer <token>.');
        }

        try {
            $verificado = service('tokenVerifier')->verify($idToken);
        } catch (TokenInvalidoException) {
            return $this->error(401, 'token_invalido', 'El token de sesión no es válido o expiró.');
        }

        $usuario = $this->resolverUsuario($verificado->uid, $verificado->email, $verificado->emailVerified);

        if ($usuario === null) {
            return $this->error(403, 'usuario_no_registrado', 'Esta cuenta no tiene acceso al panel.');
        }

        service('usuarioActual')->establecer($usuario);

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return $response;
    }

    /**
     * Resuelve el usuario local por firebase_uid (cache 60 s) o, en primer
     * login, por email verificado — y en ese caso enlaza firebase_uid (RF-01.3).
     * Un usuario inactivo o sin match se trata igual: null (403 sin distinguir
     * el motivo, doc 04).
     *
     * @return array<string, mixed>|null
     */
    private function resolverUsuario(string $uid, string $email, bool $emailVerified): ?array
    {
        $cacheado = AuthCache::obtenerPorUid($uid);
        if ($cacheado !== null) {
            return ((int) $cacheado['activo']) === 1 ? $cacheado : null;
        }

        $db = Database::connect();

        $usuario = $db->table('usuarios')->where('firebase_uid', $uid)->get()->getRowArray();

        if ($usuario === null && $emailVerified) {
            $candidato = $db->table('usuarios')->where('email', $email)->get()->getRowArray();

            if ($candidato !== null && $candidato['firebase_uid'] === null) {
                // Primer login (RF-01.3): enlaza firebase_uid al usuario dado de alta por Dirección.
                $db->table('usuarios')->where('id', $candidato['id'])->update(['firebase_uid' => $uid]);
                $candidato['firebase_uid'] = $uid;
                $this->auditarLogin((int) $candidato['id']);
                $usuario = $candidato;
            }
        }

        if ($usuario === null) {
            return null;
        }

        // Se cachea también el caso inactivo: evita golpear la BD en cada
        // request repetida de una cuenta desactivada, y AuthCache::invalidar()
        // (tarea de edición de usuarios) permite forzar el 403 antes del TTL.
        AuthCache::guardar($uid, $usuario);

        return ((int) $usuario['activo']) === 1 ? $usuario : null;
    }

    private function auditarLogin(int $usuarioId): void
    {
        Database::connect()->table('auditoria')->insert([
            'usuario_id' => $usuarioId,
            'accion'     => 'login',
            'entidad'    => 'usuarios',
            'entidad_id' => $usuarioId,
            'detalle'    => json_encode(['primer_login' => true]),
            'ip'         => service('request')->getIPAddress(),
        ]);
    }

    private function error(int $status, string $codigo, string $mensaje): ResponseInterface
    {
        $response = service('response');
        $response->setStatusCode($status);
        $response->setJSON(['error' => $codigo, 'mensaje' => $mensaje]);

        return $response;
    }
}
