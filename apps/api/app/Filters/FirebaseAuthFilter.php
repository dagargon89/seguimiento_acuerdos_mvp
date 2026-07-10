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
 * Modo `sin_lista` (ADR-006, `firebaseauth:sin_lista` en `POST /registro`):
 * el token se verifica igual (401 si falta/inválido) pero NO exige que el
 * usuario ya exista — publica los claims verificados en
 * Services::tokenVerificado() para que RegistroController decida (alta o
 * 409). Si el usuario SÍ existe ya (reintento de alta), también se resuelve
 * normal en Services::usuarioActual() — útil para el 409 `cuenta_ya_existe`.
 * La guardia `cuenta_pendiente` NO aplica en este modo.
 *
 * Guardia central `cuenta_pendiente` (modo normal): un usuario con
 * rol=`pendiente` (autorregistrado sin rol asignado) solo puede usar
 * `GET/PATCH /me`; cualquier otra ruta del grupo protegido → 403.
 *
 * Errores en el formato del doc 05 §1: {"error":"codigo_snake","mensaje":"..."}.
 */
class FirebaseAuthFilter implements FilterInterface
{
    /** Rutas (sin el prefijo `api/v1/`) exentas de la guardia `cuenta_pendiente`. */
    private const RUTAS_EXENTAS_DE_PENDIENTE = ['me'];

    public function before(RequestInterface $request, $arguments = null)
    {
        if (! $request instanceof IncomingRequest) {
            return null;
        }

        $sinLista = is_array($arguments) && in_array('sin_lista', $arguments, true);

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

        if ($sinLista) {
            service('tokenVerificado')->establecer($verificado);

            // Si el usuario ya existe (reintento de registro con cuenta ya
            // dada de alta), también se resuelve normal — el controller lo usa
            // para el 409 `cuenta_ya_existe`. Se busca SOLO por firebase_uid o
            // email (sin el enlace automático de "primer login" de
            // resolverUsuario(): en modo registro no queremos enlazar
            // firebase_uid a una cuenta existente como efecto colateral, esa
            // decisión es del controller). No se aplica la guardia de
            // pendiente ni el 403 `usuario_no_registrado` en este modo.
            $usuario = $this->buscarUsuarioExistente($verificado->uid, $verificado->email);
            if ($usuario !== null) {
                service('usuarioActual')->establecer($usuario);
            }

            return null;
        }

        $usuario = $this->resolverUsuario($verificado->uid, $verificado->email, $verificado->emailVerified);

        if ($usuario === null) {
            return $this->error(403, 'usuario_no_registrado', 'Esta cuenta no tiene acceso al panel.');
        }

        service('usuarioActual')->establecer($usuario);

        if ($usuario['rol'] === 'pendiente' && ! $this->esRutaExentaDePendiente($request)) {
            return $this->error(403, 'cuenta_pendiente', 'Tu cuenta está pendiente de aprobación.');
        }

        return null;
    }

    /** ¿La ruta actual está exenta de la guardia `cuenta_pendiente` (p.ej. `api/v1/me`)? */
    private function esRutaExentaDePendiente(IncomingRequest $request): bool
    {
        $path = trim($request->getUri()->getPath(), '/');
        // Quita cualquier prefijo antes de 'api/v1/' (front controller 'index.php/' en
        // algunos entornos/tests) y el propio 'api/v1/'; robusto con y sin slash inicial.
        $path = preg_replace('#^.*api/v1/#', '', $path) ?? $path;

        return in_array($path, self::RUTAS_EXENTAS_DE_PENDIENTE, true);
    }

    /**
     * Busca un usuario existente por firebase_uid o, si no hay match, por
     * email — SIN el efecto colateral de enlazar firebase_uid (a diferencia
     * de resolverUsuario()). Usado solo en modo `sin_lista` (POST /registro)
     * para que RegistroController pueda responder 409 `cuenta_ya_existe`
     * cuando el uid o el email del token ya están en uso.
     *
     * @return array<string, mixed>|null
     */
    private function buscarUsuarioExistente(string $uid, string $email): ?array
    {
        $db = Database::connect();

        $usuario = $db->table('usuarios')->where('firebase_uid', $uid)->get()->getRowArray();
        if ($usuario === null) {
            $usuario = $db->table('usuarios')->where('email', $email)->get()->getRowArray();
        }

        return $usuario;
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
