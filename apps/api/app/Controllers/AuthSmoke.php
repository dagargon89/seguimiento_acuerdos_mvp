<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * TODO(tarea-5): retirar — smoke temporal del grupo protegido api/v1 (S1.3).
 * Devuelve el usuario resuelto por FirebaseAuthFilter; solo existe para
 * verificar el wiring de CORS + FirebaseAuth + Throttle antes de que haya
 * endpoints de dominio reales.
 */
class AuthSmoke extends BaseController
{
    public function index(): ResponseInterface
    {
        $usuario = service('usuarioActual')->obtener();

        return $this->response->setJSON(['data' => ['usuario' => $usuario]]);
    }
}
