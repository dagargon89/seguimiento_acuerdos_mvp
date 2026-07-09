<?php

namespace App\Controllers;

use App\Entities\Usuario;
use App\Models\UsuarioModel;
use CodeIgniter\HTTP\ResponseInterface;

/** GET /usuarios (doc 05 §2.6) — directorio activo. Visible para cualquier usuario autenticado. */
class UsuariosController extends BaseController
{
    public function index(): ResponseInterface
    {
        $filas = (new UsuarioModel())->activos();

        $data = array_map(static fn (array $f) => Usuario::desdeFila($f)->aArray(), $filas);

        return $this->response->setJSON(['data' => $data]);
    }
}
