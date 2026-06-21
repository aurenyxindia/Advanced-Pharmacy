<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use Closure;

final class CorsMiddleware implements Middleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $cors    = Config::get('app.cors', []);
        $origin  = $request->header('origin');
        $allowed = $cors['allowed_origins'] ?? [];

        $allowOrigin = ($origin && in_array($origin, $allowed, true)) ? $origin : ($allowed[0] ?? '*');

        $apply = static function (Response $response) use ($cors, $allowOrigin): Response {
            return $response
                ->withHeader('Access-Control-Allow-Origin', $allowOrigin)
                ->withHeader('Access-Control-Allow-Methods', implode(', ', $cors['allowed_methods'] ?? []))
                ->withHeader('Access-Control-Allow-Headers', implode(', ', $cors['allowed_headers'] ?? []))
                ->withHeader('Access-Control-Allow-Credentials', 'true')
                ->withHeader('Access-Control-Max-Age', (string) ($cors['max_age'] ?? 86400))
                ->withHeader('Vary', 'Origin');
        };

        if ($request->method() === 'OPTIONS') {
            return $apply(Response::json(null, 204));
        }

        return $apply($next($request));
    }
}
