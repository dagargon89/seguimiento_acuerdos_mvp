<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * Endpoint de salud de la API. Público (no requiere auth); útil como health check.
 */
class Ping extends BaseController
{
    public function index(): ResponseInterface
    {
        return $this->response->setJSON(['data' => ['pong' => true]]);
    }
}
