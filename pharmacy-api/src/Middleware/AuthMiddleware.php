<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Auth\Jwt;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use Closure;

/**
 * Validates the Bearer access token and attaches the user payload to the request.
 */
final class AuthMiddleware implements Middleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if (!$token) {
            throw HttpException::unauthorized('Authorization token missing.');
        }

        $payload = Jwt::verify($token, 'access');

        $request->setAttribute('user', [
            'id'        => $payload['sub'] ?? null,
            'client_id' => $payload['client_id'] ?? null,
            'branch_id' => $payload['branch_id'] ?? null,
            'role'      => $payload['role'] ?? null,
            'name'      => $payload['name'] ?? null,
            'email'     => $payload['email'] ?? null,
            'scope'     => $payload['scope'] ?? 'branch',
        ]);

        return $next($request);
    }
}
