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

    $routes->get('usuarios', 'UsuariosController::index');
    $routes->options('usuarios', 'UsuariosController::index');

    $routes->get('areas', 'AreasController::index');
    $routes->options('areas', 'AreasController::index');
});
