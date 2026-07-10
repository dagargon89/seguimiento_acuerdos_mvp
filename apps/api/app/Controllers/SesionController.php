<?php

namespace App\Controllers;

use App\Entities\ConfigRecordatorios;
use App\Entities\Sesion;
use App\Entities\Usuario;
use App\Models\ConfiguracionModel;
use CodeIgniter\HTTP\ResponseInterface;

/** GET /me (doc 05 §2.1) — identidad + config global de recordatorios, SIN envoltura `data`. */
class SesionController extends BaseController
{
    public function me(): ResponseInterface
    {
        $actor = service('usuarioActual')->obtener();

        $valor = (new ConfiguracionModel())->recordatoriosDefault();

        $sesion = new Sesion(Usuario::desdeFila($actor), ConfigRecordatorios::desdeValor($valor));

        return $this->response->setJSON($sesion->aArray());
    }
}
