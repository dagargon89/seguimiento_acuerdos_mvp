<?php

namespace App\Controllers;

use App\Entities\ConfigRecordatorios;
use App\Entities\Sesion;
use App\Entities\Usuario;
use App\Libraries\Auth\AuthCache;
use App\Models\AuditoriaModel;
use App\Models\ConfiguracionModel;
use App\Models\UsuarioModel;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

/** GET /me (doc 05 §2.1) — identidad + config global de recordatorios, SIN envoltura `data`. */
class SesionController extends BaseController
{
    /** Campos que acepta `ActualizacionPerfil` (types.ts) — cualquier otro → 422 `campo_no_permitido`. */
    private const CAMPOS_PERFIL = ['nombre'];

    public function me(): ResponseInterface
    {
        $actor = service('usuarioActual')->obtener();

        $valor = (new ConfiguracionModel())->recordatoriosDefault();

        $sesion = new Sesion(Usuario::desdeFila($actor), ConfigRecordatorios::desdeValor($valor));

        return $this->response->setJSON($sesion->aArray());
    }

    /**
     * PATCH /me (doc 05 §2.1, ADR-005) — self-service: cualquier usuario activo
     * edita su propio `nombre`. Ningún otro campo (email/rol/area_id/activo) es
     * aceptado por esta vía (422 `campo_no_permitido`) — esos siguen siendo
     * exclusivos de `PATCH /usuarios/{id}` (solo Dirección).
     */
    public function editarMiPerfil(): ResponseInterface
    {
        $actor = service('usuarioActual')->obtener();

        $body = $this->cuerpoJson();
        if ($body === null) {
            return $this->errorValidacion('El cuerpo debe ser JSON.');
        }
        $camposDesconocidos = $this->camposDesconocidos($body, self::CAMPOS_PERFIL);
        if ($camposDesconocidos !== []) {
            return $this->errorCampoNoPermitido($camposDesconocidos);
        }

        $nombre = is_string($body['nombre'] ?? null) ? trim((string) $body['nombre']) : '';

        $campos = [];
        if (! array_key_exists('nombre', $body) || $nombre === '') {
            $campos['nombre'] = 'Requerido';
        } elseif (mb_strlen($nombre) > 120) {
            $campos['nombre'] = 'Máximo 120 caracteres';
        }

        if ($campos !== []) {
            return $this->errorValidacion('Revisa los campos de tu perfil.', $campos);
        }

        $actorId = (int) $actor['id'];
        $model   = new UsuarioModel();

        $db = Database::connect();
        $db->transException(true)->transStart();

        $model->update($actorId, ['nombre' => $nombre]);

        (new AuditoriaModel())->registrar($actorId, 'editar_perfil', 'usuario', $actorId, ['cambios' => ['nombre']], $this->request->getIPAddress());

        $db->transComplete();

        if (! $db->transStatus()) {
            return $this->response->setStatusCode(500)->setJSON(['error' => 'error_interno', 'mensaje' => 'No se pudo guardar tu perfil.']);
        }

        AuthCache::invalidar($actorId);

        $fila = $model->find($actorId);

        return $this->response->setJSON(['data' => Usuario::desdeFila($fila)->aArray()]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

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
