<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use Closure;

interface Middleware
{
    /**
     * Handle the request and either short-circuit with a Response,
     * or call $next($request) to continue the pipeline.
     */
    public function handle(Request $request, Closure $next): Response;
}
