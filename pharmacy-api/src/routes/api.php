<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\RegistrationController;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

/** @var Router $router */

// ---- Public ------------------------------------------------------------
$router->get('/api/health', static fn (Request $r) => Response::success([
    'service' => config('app.name'),
    'time'    => now(),
    'status'  => 'ok',
], 'Service healthy.'));

$router->group(['prefix' => '/api/auth'], function (Router $router): void {
    $router->post('/register', [RegistrationController::class, 'register']);
    $router->post('/login', [AuthController::class, 'login']);
    $router->post('/refresh', [AuthController::class, 'refresh']);
    $router->post('/logout', [AuthController::class, 'logout']);
});

// ---- Authenticated -----------------------------------------------------
$router->group(['prefix' => '/api', 'middleware' => ['auth']], function (Router $router): void {
    $router->get('/auth/me', [AuthController::class, 'me']);
    $router->get('/dashboard/summary', [DashboardController::class, 'summary']);
});
