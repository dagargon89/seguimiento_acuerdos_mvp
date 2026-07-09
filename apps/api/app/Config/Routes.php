<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');

// API v1 — health check público. Los endpoints de dominio (protegidos por
// FirebaseAuth + Throttle) se agregan en el Sprint 1.
$routes->group('api/v1', static function (RouteCollection $routes): void {
    $routes->get('ping', 'Ping::index');
});
