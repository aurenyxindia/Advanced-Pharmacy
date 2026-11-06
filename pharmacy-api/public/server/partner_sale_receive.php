<?php

declare(strict_types=1);

/**
 * Paperless Share Stock receiver — exact public URL:
 *   https://pp.gmpserver.com/server/partner_sale_receive.php
 *
 * Deploy this file to the site docroot /server/ (sibling of Angular index.html),
 * not under /php/. It bootstraps pharmacy-api under ../php/.
 */

use App\Controllers\PartnerSaleReceiveController;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\CorsMiddleware;
use App\Middleware\PartnerApiMiddleware;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use Dotenv\Dotenv;
use Throwable;

header('Content-Type: application/json; charset=utf-8');

$phpRoot = dirname(__DIR__) . '/php';
if (!is_dir($phpRoot)) {
    // Local / alternate layout: file lives under pharmacy-api/public/server/
    $phpRoot = dirname(__DIR__, 2);
}
$autoload = $phpRoot . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'API bootstrap not found.']);
    exit;
}

require $autoload;

try {
    if (is_file($phpRoot . '/config/.env')) {
        Dotenv::createImmutable($phpRoot . '/config', '.env')->safeLoad();
    }
    // Ensure Config is loaded the same way as index.php
    $app = new App($phpRoot);
    $app->registerMiddleware([
        'cors'    => CorsMiddleware::class,
        'auth'    => AuthMiddleware::class,
        'role'    => RoleMiddleware::class,
        'partner' => PartnerApiMiddleware::class,
    ]);

    $controller = new PartnerSaleReceiveController();
    $response = $controller->handle(new Request());
    $response->send();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Import failed: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
