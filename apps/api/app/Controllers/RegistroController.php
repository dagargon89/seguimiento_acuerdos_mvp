<?php

namespace App\Controllers;

use App\Entities\Usuario;
use App\Models\AuditoriaModel;
use App\Models\UsuarioModel;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

/**
 * POST /registro (ADR-006, "Registro A") — autorregistro client-side: cualquier
 * persona con una cuenta Firebase válida (Google o email/password) puede crear
 * su fila en `usuarios` con rol `pendiente` — sin acceso funcional hasta que
 * Dirección le asigna un rol operativo vía `PATCH /usuarios/{id}`.
 *
 * El uid/email SIEMPRE salen del token verificado (Services::tokenVerificado,
 * publicado por FirebaseAuthFilter en modo `sin_lista`), jamás del body:
 * `estado`/`rol` no son campos aceptados aquí.
 */
class RegistroController extends BaseController
{
    /** Único campo que acepta el body de `POST /registro` (types.ts `RegistroCuenta`). */
    private const CAMPOS_REGISTRO = ['nombre'];

    public function crear(): ResponseInterface
    {
        $token = service('tokenVerificado')->obtener();
        if ($token === null) {
            // No debería ocurrir: firebaseauth:sin_lista siempre lo publica si
            // llega hasta aquí (401 antes si el token falta/es inválido).
            return $this->response->setStatusCode(401)->setJSON([
                'error'   => 'token_invalido',
                'mensaje' => 'El token de sesión no es válido o expiró.',
            ]);
        }

        $body = $this->cuerpoJson();
        if ($body === null) {
            return $this->errorValidacion('El cuerpo debe ser JSON.');
        }
        $camposDesconocidos = $this->camposDesconocidos($body, self::CAMPOS_REGISTRO);
        if ($camposDesconocidos !== []) {
            return $this->errorCampoNoPermitido($camposDesconocidos);
        }

        $nombre = is_string($body['nombre'] ?? null) ? trim((string) $body['nombre']) : '';

        $campos = [];
        if ($nombre === '') {
            $campos['nombre'] = 'Requerido';
        } elseif (mb_strlen($nombre) > 120) {
            $campos['nombre'] = 'Máximo 120 caracteres';
        }

        if ($campos !== []) {
            return $this->errorValidacion('Revisa los campos de tu registro.', $campos);
        }

        if ($this->cuentaYaExiste($token->uid, $token->email)) {
            return $this->response->setStatusCode(409)->setJSON([
                'error'   => 'cuenta_ya_existe',
                'mensaje' => 'Ya existe una cuenta para este correo. Inicia sesión.',
            ]);
        }

        $db = Database::connect();
        $db->transException(true)->transStart();

        $model = new UsuarioModel();
        $model->insert([
            'firebase_uid' => $token->uid,
            'nombre'       => $nombre,
            'email'        => $token->email,
            'rol'          => 'pendiente',
            'area_id'      => null,
            'activo'       => 1,
        ]);
        $nuevoId = (int) $model->insertID();

        (new AuditoriaModel())->registrar($nuevoId, 'registro_usuario', 'usuario', $nuevoId, ['rol' => 'pendiente'], $this->request->getIPAddress());

        $db->transComplete();

        if (! $db->transStatus()) {
            return $this->response->setStatusCode(500)->setJSON(['error' => 'error_interno', 'mensaje' => 'No se pudo completar tu registro.']);
        }

        $fila = $model->find($nuevoId);

        return $this->response->setStatusCode(201)->setJSON(['data' => Usuario::desdeFila($fila)->aArray()]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** ¿Ya existe un usuario con este firebase_uid o este email? */
    private function cuentaYaExiste(string $uid, string $email): bool
    {
        $db = Database::connect();

        $porUid = $db->table('usuarios')->where('firebase_uid', $uid)->countAllResults();
        if ($porUid > 0) {
            return true;
        }

        return $db->table('usuarios')->where('email', $email)->countAllResults() > 0;
    }

    /** @param array<string, string> $campos */
    private function errorValidacion(string $mensaje, array $campos = []): ResponseInterface
    {
        $body = ['error' => 'validacion', 'mensaje' => $mensaje];
        if ($campos !== []) {
            $body['campos'] = $campos;
        }

        return $this->response->setStatusCode(422)->setJSON($body);
    }

    /** @param string[] $campos */
    private function errorCampoNoPermitido(array $campos): ResponseInterface
    {
        return $this->response->setStatusCode(422)->setJSON([
            'error'   => 'campo_no_permitido',
            'mensaje' => 'El body contiene campos no permitidos.',
            'campos'  => array_fill_keys($campos, 'Campo no permitido'),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function cuerpoJson(): ?array
    {
        $crudo = $this->request->getBody();
        if ($crudo === null || trim((string) $crudo) === '') {
            return [];
        }

        try {
            $decodificado = json_decode((string) $crudo, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decodificado) ? $decodificado : null;
    }

    /**
     * @param array<string, mixed> $body
     * @param string[]             $permitidos
     *
     * @return string[]
     */
    private function camposDesconocidos(array $body, array $permitidos): array
    {
        return array_values(array_diff(array_keys($body), $permitidos));
    }
}
