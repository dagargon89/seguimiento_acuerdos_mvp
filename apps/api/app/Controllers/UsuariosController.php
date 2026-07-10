<?php

namespace App\Controllers;

use App\Entities\Usuario;
use App\Libraries\Auth\AuthCache;
use App\Models\AreaModel;
use App\Models\AuditoriaModel;
use App\Models\UsuarioModel;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

/**
 * GET /usuarios (doc 05 §2.6) — directorio activo, visible para cualquier
 * usuario autenticado. POST/PATCH /usuarios (RF-10, Tarea 7 / S1.6) — **solo
 * Dirección**: alta con email único y área para coordinación; edición/baja
 * lógica que no puede desactivar a la última cuenta de Dirección y que invalida
 * el `AuthCache` del usuario (efecto ≤60 s, RF-01 / AU-10).
 */
class UsuariosController extends BaseController
{
    /** Campos que acepta `AltaUsuario` (types.ts) — cualquier otro → 422 `campo_no_permitido`. */
    private const CAMPOS_ALTA = ['nombre', 'email', 'rol', 'area_id'];

    /** Campos que acepta `EdicionUsuario` (todos opcionales). */
    private const CAMPOS_EDICION = ['nombre', 'email', 'rol', 'area_id', 'activo'];

    /** `pendiente` (ADR-006) — Dirección puede asignarlo/verlo; las reglas de coordinador→área
     *  y última cuenta de Dirección activa no cambian. */
    private const ROLES = ['direccion', 'coordinador', 'responsable', 'pendiente'];

    public function index(): ResponseInterface
    {
        $filas = (new UsuarioModel())->activos();

        $data = array_map(static fn (array $f) => Usuario::desdeFila($f)->aArray(), $filas);

        return $this->response->setJSON(['data' => $data]);
    }

    /** POST /usuarios (doc 05 §2.6, RF-10) — solo Dirección. Respuesta 201 `{data: Usuario}`. */
    public function crear(): ResponseInterface
    {
        $actor = service('usuarioActual')->obtener();
        if ($actor['rol'] !== 'direccion') {
            return $this->sinPermiso('Solo Dirección puede dar de alta usuarios.');
        }

        $body = $this->cuerpoJson();
        if ($body === null) {
            return $this->errorValidacion('El cuerpo debe ser JSON.');
        }
        $camposDesconocidos = $this->camposDesconocidos($body, self::CAMPOS_ALTA);
        if ($camposDesconocidos !== []) {
            return $this->errorCampoNoPermitido($camposDesconocidos);
        }

        $nombre  = is_string($body['nombre'] ?? null) ? trim((string) $body['nombre']) : '';
        $email   = is_string($body['email'] ?? null) ? trim((string) $body['email']) : '';
        $rol     = $body['rol'] ?? null;
        $areaId  = $body['area_id'] ?? null;

        $campos = [];
        if ($nombre === '') {
            $campos['nombre'] = 'Requerido';
        }
        if (! $this->esEmailValido($email)) {
            $campos['email'] = 'Correo inválido';
        } elseif ($this->emailExiste($email, null)) {
            $campos['email'] = 'Ya existe una cuenta con este correo';
        }
        if (! is_string($rol) || ! in_array($rol, self::ROLES, true)) {
            $campos['rol'] = 'Rol inválido';
        }
        if ($rol === 'coordinador') {
            if ($areaId === null || ! in_array((int) $areaId, $this->idsAreasActivas(), true)) {
                $campos['area_id'] = 'Una coordinación requiere un área activa';
            }
        }

        if ($campos !== []) {
            return $this->errorValidacion('Revisa los campos del alta.', $campos);
        }

        // Solo coordinación lleva área (CHECK chk_coordinador_area del DDL); los demás roles la ignoran.
        $areaFinal = $rol === 'coordinador' ? (int) $areaId : null;

        $db = Database::connect();
        $db->transException(true)->transStart();

        $model = new UsuarioModel();
        $model->insert([
            'firebase_uid' => null,
            'nombre'       => $nombre,
            'email'        => $email,
            'rol'          => $rol,
            'area_id'      => $areaFinal,
            'activo'       => 1,
        ]);
        $nuevoId = (int) $model->insertID();

        (new AuditoriaModel())->registrar((int) $actor['id'], 'alta_usuario', 'usuario', $nuevoId, ['rol' => $rol], $this->request->getIPAddress());

        $db->transComplete();

        if (! $db->transStatus()) {
            return $this->response->setStatusCode(500)->setJSON(['error' => 'error_interno', 'mensaje' => 'No se pudo dar de alta al usuario.']);
        }

        $fila = $model->find($nuevoId);

        return $this->response->setStatusCode(201)->setJSON(['data' => Usuario::desdeFila($fila)->aArray()]);
    }

    /** PATCH /usuarios/{id} (doc 05 §2.6, RF-10) — solo Dirección. Respuesta 200 `{data: Usuario}`. */
    public function editar(string $id): ResponseInterface
    {
        $actor = service('usuarioActual')->obtener();
        if ($actor['rol'] !== 'direccion') {
            return $this->sinPermiso('Solo Dirección puede editar usuarios.');
        }

        $model = new UsuarioModel();
        $fila  = $model->find((int) $id);
        if ($fila === null) {
            return $this->noEncontrado();
        }

        $body = $this->cuerpoJson();
        if ($body === null) {
            return $this->errorValidacion('El cuerpo debe ser JSON.');
        }
        $camposDesconocidos = $this->camposDesconocidos($body, self::CAMPOS_EDICION);
        if ($camposDesconocidos !== []) {
            return $this->errorCampoNoPermitido($camposDesconocidos);
        }

        $campos = [];

        if (array_key_exists('nombre', $body) && (! is_string($body['nombre']) || trim((string) $body['nombre']) === '')) {
            $campos['nombre'] = 'Requerido';
        }
        if (array_key_exists('email', $body)) {
            $email = is_string($body['email']) ? trim((string) $body['email']) : '';
            if (! $this->esEmailValido($email)) {
                $campos['email'] = 'Correo inválido';
            } elseif ($this->emailExiste($email, (int) $id)) {
                $campos['email'] = 'Ya existe una cuenta con este correo';
            }
        }
        if (array_key_exists('rol', $body) && (! is_string($body['rol']) || ! in_array($body['rol'], self::ROLES, true))) {
            $campos['rol'] = 'Rol inválido';
        }

        // Rol resultante (tras el posible cambio) para validar la regla de coordinación.
        $rolResultante = array_key_exists('rol', $body) && is_string($body['rol']) ? $body['rol'] : (string) $fila['rol'];
        if ($rolResultante === 'coordinador') {
            $areaResultante = array_key_exists('area_id', $body) ? $body['area_id'] : $fila['area_id'];
            if ($areaResultante === null || ! in_array((int) $areaResultante, $this->idsAreasActivas(), true)) {
                $campos['area_id'] = 'Una coordinación requiere un área activa';
            }
        }

        // No desactivar a la última cuenta de Dirección activa (RF-10).
        if (array_key_exists('activo', $body) && $body['activo'] === false
            && (string) $fila['rol'] === 'direccion' && ((int) $fila['activo']) === 1
            && $this->direccionesActivas() <= 1) {
            $campos['activo'] = 'No puedes desactivar a la última cuenta de Dirección';
        }

        if ($campos !== []) {
            return $this->errorValidacion('Revisa los campos del usuario.', $campos);
        }

        $update = [];
        foreach (self::CAMPOS_EDICION as $campo) {
            if (! array_key_exists($campo, $body)) {
                continue;
            }
            $update[$campo] = match ($campo) {
                'nombre', 'email' => trim((string) $body[$campo]),
                'rol'     => (string) $body[$campo],
                'area_id' => $body[$campo] === null ? null : (int) $body[$campo],
                'activo'  => $body[$campo] ? 1 : 0,
                default   => $body[$campo],
            };
        }

        // Al cambiar el rol a uno no-coordinación, el área heredada se limpia
        // (mantiene la fila coherente; el CHECK chk_coordinador_area solo prohíbe
        // coordinador SIN área, no un no-coordinador CON área, pero no queremos
        // dejar un área colgada tras un cambio de rol).
        if (array_key_exists('rol', $update) && $update['rol'] !== 'coordinador'
            && ! array_key_exists('area_id', $update) && $fila['area_id'] !== null) {
            $update['area_id'] = null;
        }

        if ($update === []) {
            return $this->response->setJSON(['data' => Usuario::desdeFila($fila)->aArray()]);
        }

        $db = Database::connect();
        $db->transException(true)->transStart();

        $model->update((int) $id, $update);

        $accion = array_key_exists('activo', $update) && $update['activo'] === 0 ? 'baja_usuario' : 'editar_usuario';
        (new AuditoriaModel())->registrar((int) $actor['id'], $accion, 'usuario', (int) $id, ['cambios' => array_keys($update)], $this->request->getIPAddress());

        $db->transComplete();

        if (! $db->transStatus()) {
            return $this->response->setStatusCode(500)->setJSON(['error' => 'error_interno', 'mensaje' => 'No se pudo guardar el cambio.']);
        }

        // Invalida el AuthCache del usuario editado para que la desactivación /
        // el cambio de rol tenga efecto ≤60 s (RF-01 / AU-10). Se invalida
        // siempre en edición (no solo en baja): un cambio de rol también debe
        // reflejarse pronto.
        AuthCache::invalidar((int) $id);

        $fila = $model->find((int) $id);

        return $this->response->setJSON(['data' => Usuario::desdeFila($fila)->aArray()]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function sinPermiso(string $mensaje): ResponseInterface
    {
        return $this->response->setStatusCode(403)->setJSON(['error' => 'sin_permiso', 'mensaje' => $mensaje]);
    }

    private function noEncontrado(): ResponseInterface
    {
        return $this->response->setStatusCode(404)->setJSON(['error' => 'no_encontrado', 'mensaje' => 'El usuario no existe.']);
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

    private function esEmailValido(string $email): bool
    {
        return $email !== '' && (bool) preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email);
    }

    /** ¿Existe otro usuario (distinto de `$exceptoId`) con este email? Case-insensitive por la collation del DDL. */
    private function emailExiste(string $email, ?int $exceptoId): bool
    {
        $builder = Database::connect()->table('usuarios')->where('email', $email);
        if ($exceptoId !== null) {
            $builder->where('id !=', $exceptoId);
        }

        return $builder->countAllResults() > 0;
    }

    private function direccionesActivas(): int
    {
        return Database::connect()->table('usuarios')->where('rol', 'direccion')->where('activo', 1)->countAllResults();
    }

    /** @return int[] */
    private function idsAreasActivas(): array
    {
        return array_map(static fn (array $a) => (int) $a['id'], (new AreaModel())->where('activa', 1)->findAll());
    }
}
