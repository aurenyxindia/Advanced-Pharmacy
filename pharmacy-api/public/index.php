<?php

declare(strict_types=1);

use App\Core\App;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\CorsMiddleware;
use App\Middleware\RoleMiddleware;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = new App(dirname(__DIR__));

// Register middleware aliases used in route definitions.
$app->registerMiddleware([
    'cors' => CorsMiddleware::class,
    'auth' => AuthMiddleware::class,
    'role' => RoleMiddleware::class,
]);

$router = $app->router();

// CORS runs on every route via a wildcard group.
$router->group(['middleware' => ['cors']], function (Router $router): void {
    require dirname(__DIR__) . '/src/routes/api.php';
});

$app->run();
