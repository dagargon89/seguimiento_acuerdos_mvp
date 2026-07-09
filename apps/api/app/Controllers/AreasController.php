<?php

namespace App\Controllers;

use App\Entities\Area;
use App\Models\AreaModel;
use App\Models\AuditoriaModel;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

/**
 * GET /areas (doc 05 §2.6) — catálogo de áreas activas, visible para cualquier
 * usuario autenticado. POST/PATCH /areas (ADR-004, contrato v1.2, Tarea 7 /
 * S1.6) — **solo Dirección**: alta con `nombre` requerido y único; edición del
 * nombre (único) y/o `activa`.
 */
class AreasController extends BaseController
{
    private const CAMPOS_ALTA    = ['nombre'];
    private const CAMPOS_EDICION = ['nombre', 'activa'];

    public function index(): ResponseInterface
    {
        $filas = (new AreaModel())->where('activa', 1)->orderBy('nombre', 'ASC')->findAll();

        $data = array_map(static fn (array $f) => Area::desdeFila($f)->aArray(), $filas);

        return $this->response->setJSON(['data' => $data]);
    }

    /** POST /areas (ADR-004) — solo Dirección. Respuesta 201 `{data: Area}`. */
    public function crear(): ResponseInterface
    {
        $actor = service('usuarioActual')->obtener();
        if ($actor['rol'] !== 'direccion') {
            return $this->sinPermiso('Solo Dirección puede crear áreas.');
        }

        $body = $this->cuerpoJson();
        if ($body === null) {
            return $this->errorValidacion('El cuerpo debe ser JSON.');
        }
        $camposDesconocidos = $this->camposDesconocidos($body, self::CAMPOS_ALTA);
        if ($camposDesconocidos !== []) {
            return $this->errorCampoNoPermitido($camposDesconocidos);
        }

        $nombre = is_string($body['nombre'] ?? null) ? trim((string) $body['nombre']) : '';

        $campos = [];
        if ($nombre === '') {
            $campos['nombre'] = 'Requerido';
        } elseif ($this->nombreExiste($nombre, null)) {
            $campos['nombre'] = 'Ya existe un área con ese nombre';
        }

        if ($campos !== []) {
            return $this->errorValidacion('Revisa los campos del área.', $campos);
        }

        $db = Database::connect();
        $db->transException(true)->transStart();

        $model = new AreaModel();
        $model->insert(['nombre' => $nombre, 'activa' => 1]);
        $nuevoId = (int) $model->insertID();

        (new AuditoriaModel())->registrar((int) $actor['id'], 'alta_area', 'area', $nuevoId, null, $this->request->getIPAddress());

        $db->transComplete();

        if (! $db->transStatus()) {
            return $this->response->setStatusCode(500)->setJSON(['error' => 'error_interno', 'mensaje' => 'No se pudo crear el área.']);
        }

        $fila = $model->find($nuevoId);

        return $this->response->setStatusCode(201)->setJSON(['data' => Area::desdeFila($fila)->aArray()]);
    }

    /** PATCH /areas/{id} (ADR-004) — solo Dirección. Respuesta 200 `{data: Area}`. */
    public function editar(string $id): ResponseInterface
    {
        $actor = service('usuarioActual')->obtener();
        if ($actor['rol'] !== 'direccion') {
            return $this->sinPermiso('Solo Dirección puede editar áreas.');
        }

        $model = new AreaModel();
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
        if (array_key_exists('nombre', $body)) {
            $nombre = is_string($body['nombre']) ? trim((string) $body['nombre']) : '';
            if ($nombre === '') {
                $campos['nombre'] = 'Requerido';
            } elseif ($this->nombreExiste($nombre, (int) $id)) {
                $campos['nombre'] = 'Ya existe un área con ese nombre';
            }
        }
        if (array_key_exists('activa', $body) && ! is_bool($body['activa'])) {
            $campos['activa'] = 'Debe ser booleano';
        }

        if ($campos !== []) {
            return $this->errorValidacion('Revisa los campos del área.', $campos);
        }

        $update = [];
        if (array_key_exists('nombre', $body)) {
            $update['nombre'] = trim((string) $body['nombre']);
        }
        if (array_key_exists('activa', $body)) {
            $update['activa'] = $body['activa'] ? 1 : 0;
        }

        if ($update === []) {
            return $this->response->setJSON(['data' => Area::desdeFila($fila)->aArray()]);
        }

        $db = Database::connect();
        $db->transException(true)->transStart();

        $model->update((int) $id, $update);
        (new AuditoriaModel())->registrar((int) $actor['id'], 'editar_area', 'area', (int) $id, ['cambios' => array_keys($update)], $this->request->getIPAddress());

        $db->transComplete();

        if (! $db->transStatus()) {
            return $this->response->setStatusCode(500)->setJSON(['error' => 'error_interno', 'mensaje' => 'No se pudo guardar el cambio.']);
        }

        $fila = $model->find((int) $id);

        return $this->response->setJSON(['data' => Area::desdeFila($fila)->aArray()]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function sinPermiso(string $mensaje): ResponseInterface
    {
        return $this->response->setStatusCode(403)->setJSON(['error' => 'sin_permiso', 'mensaje' => $mensaje]);
    }

    private function noEncontrado(): ResponseInterface
    {
        return $this->response->setStatusCode(404)->setJSON(['error' => 'no_encontrado', 'mensaje' => 'El área no existe.']);
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

    /** ¿Existe otra área (distinta de `$exceptoId`) con este nombre? Case-insensitive por la collation del DDL. */
    private function nombreExiste(string $nombre, ?int $exceptoId): bool
    {
        $builder = Database::connect()->table('areas')->where('nombre', $nombre);
        if ($exceptoId !== null) {
            $builder->where('id !=', $exceptoId);
        }

        return $builder->countAllResults() > 0;
    }
}
