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
    // TODO(tarea-5): retirar — smoke temporal para verificar el wiring de los
    // Filters de borde antes de que existan endpoints de dominio reales.
    $routes->get('_auth_smoke', 'AuthSmoke::index');

    // El filter `cors` solo corre si la ruta existe: sin esta entrada OPTIONS,
    // el router lanza 404 antes de que el filter pueda responder el preflight.
    // Los endpoints de dominio de tareas posteriores deberán agregar su propia
    // ruta `options()` si el navegador necesita preflight (métodos distintos de
    // GET/HEAD o headers custom como Authorization).
    $routes->options('_auth_smoke', 'AuthSmoke::index');
});
