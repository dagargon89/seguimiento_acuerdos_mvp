<?php

namespace App\Controllers;

use App\Entities\Area;
use App\Models\AreaModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * GET /areas (doc 05 §2.6) — catálogo de áreas activas. Visible para cualquier
 * usuario autenticado. POST/PATCH /areas (ADR-004, contrato v1.2) llegan en
 * una tarea posterior — no implementados aquí.
 */
class AreasController extends BaseController
{
    public function index(): ResponseInterface
    {
        $filas = (new AreaModel())->where('activa', 1)->orderBy('nombre', 'ASC')->findAll();

        $data = array_map(static fn (array $f) => Area::desdeFila($f)->aArray(), $filas);

        return $this->response->setJSON(['data' => $data]);
    }
}
