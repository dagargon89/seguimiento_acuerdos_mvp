<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');

// API v1 — health check público (solo cors, sin auth: es un health check).
$routes->group('api/v1', ['filter' => ['cors']], static function (RouteCollection $routes): void {
    $routes->get('ping', 'Ping::index');
});

// API v1 — grupo protegido: todo endpoint de dominio del Sprint 1 vive aquí,
// detrás de CORS + FirebaseAuth + Throttle (S1.3).
$routes->group('api/v1', ['filter' => ['cors', 'firebaseauth', 'throttle']], static function (RouteCollection $routes): void {
    // Endpoints de LECTURA (Tarea 5 / S1.4). Escritura llega en la Tarea 6.
    $routes->get('me', 'SesionController::me');
    $routes->options('me', 'SesionController::me');

    $routes->get('acuerdos', 'AcuerdosController::index');
    $routes->options('acuerdos', 'AcuerdosController::index');
    $routes->get('acuerdos/(:num)', 'AcuerdosController::show/$1');
    $routes->options('acuerdos/(:num)', 'AcuerdosController::show/$1');

    // Endpoints de ESCRITURA (Tarea 6 / S1.5).
    $routes->post('acuerdos/lote', 'AcuerdosController::lote');
    $routes->options('acuerdos/lote', 'AcuerdosController::lote');
    $routes->patch('acuerdos/(:num)', 'AcuerdosController::update/$1');
    $routes->put('acuerdos/(:num)/corresponsables', 'AcuerdosController::corresponsables/$1');
    $routes->options('acuerdos/(:num)/corresponsables', 'AcuerdosController::corresponsables/$1');
    $routes->post('acuerdos/(:num)/avances', 'AcuerdosController::avances/$1');
    $routes->options('acuerdos/(:num)/avances', 'AcuerdosController::avances/$1');

    // Conclusión / reapertura (Tarea 7 / S1.6) — solo Dirección (403 + auditoría para otros roles).
    $routes->patch('acuerdos/(:num)/concluir', 'AcuerdosController::concluir/$1');
    $routes->options('acuerdos/(:num)/concluir', 'AcuerdosController::concluir/$1');
    $routes->patch('acuerdos/(:num)/reabrir', 'AcuerdosController::reabrir/$1');
    $routes->options('acuerdos/(:num)/reabrir', 'AcuerdosController::reabrir/$1');

    // Checklist de validación (Tarea 7 / S1.6) — solo Dirección.
    $routes->get('checklist', 'AcuerdosController::checklist');
    $routes->options('checklist', 'AcuerdosController::checklist');

    $routes->get('usuarios', 'UsuariosController::index');
    $routes->options('usuarios', 'UsuariosController::index');
    // Alta/edición de usuarios (Tarea 7 / S1.6) — solo Dirección.
    $routes->post('usuarios', 'UsuariosController::crear');
    $routes->patch('usuarios/(:num)', 'UsuariosController::editar/$1');
    $routes->options('usuarios/(:num)', 'UsuariosController::editar/$1');

    $routes->get('areas', 'AreasController::index');
    $routes->options('areas', 'AreasController::index');
    // Alta/edición de áreas (Tarea 7 / S1.6, ADR-004) — solo Dirección.
    $routes->post('areas', 'AreasController::crear');
    $routes->patch('areas/(:num)', 'AreasController::editar/$1');
    $routes->options('areas/(:num)', 'AreasController::editar/$1');
});
