<?php

declare(strict_types=1);

/**
 * Front controller for the PHP built-in server (php -S).
 * Apache uses public/.htaccess instead and never hits this file.
 */
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$file = __DIR__ . $uri;
if ($uri !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';
