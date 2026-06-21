<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use Closure;

/**
 * Restricts a route to one or more roles. Used as "role:ho_admin,branch_admin".
 */
final class RoleMiddleware implements Middleware
{
    /** @var string[] */
    private array $allowedRoles;

    public function __construct(string $roles = '')
    {
        $this->allowedRoles = array_filter(array_map('trim', explode(',', $roles)));
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user) {
            throw HttpException::unauthorized();
        }

        if ($this->allowedRoles !== [] && !in_array($user['role'] ?? '', $this->allowedRoles, true)) {
            throw HttpException::forbidden('You do not have permission to access this resource.');
        }

        return $next($request);
    }
}
