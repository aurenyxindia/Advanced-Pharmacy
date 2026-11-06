<?php

declare(strict_types=1);

namespace App\Core;

use App\Support\Logger;
use Closure;
use Dotenv\Dotenv;
use Throwable;

/**
 * Application kernel: bootstraps config, builds the middleware pipeline,
 * dispatches the matched route and renders the response.
 */
final class App
{
    private Router $router;

    /** Map of middleware aliases => class names. */
    private array $middlewareAliases = [];

    public function __construct(private readonly string $basePath)
    {
        $this->bootstrap();
        $this->router = new Router();
    }

    private function bootstrap(): void
    {
        // Load .env (config/.env), falling back silently if missing.
        $configDir = $this->basePath . '/config';
        if (file_exists($configDir . '/.env')) {
            Dotenv::createImmutable($configDir, '.env')->safeLoad();
        }

        Config::load($configDir);

        date_default_timezone_set((string) Config::get('app.timezone', 'UTC'));

        $debug = (bool) Config::get('app.debug', false);
        error_reporting($debug ? E_ALL : E_ALL & ~E_DEPRECATED & ~E_NOTICE);
        ini_set('display_errors', $debug ? '1' : '0');
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function registerMiddleware(array $aliases): void
    {
        $this->middlewareAliases = array_merge($this->middlewareAliases, $aliases);
    }

    public function run(): void
    {
        $request = new Request();

        // Answer CORS preflight globally so it never depends on route matching.
        if ($request->method() === 'OPTIONS') {
            $this->preflightResponse($request)->send();
            return;
        }

        try {
            $match = $this->router->match($request->method(), $request->path());

            foreach ($match['params'] as $key => $value) {
                $request->setAttribute($key, $value);
            }

            $response = $this->runPipeline($request, $match['middleware'], function (Request $req) use ($match) {
                return $this->callHandler($match['handler'], $req);
            });
        } catch (HttpException $e) {
            // Apply CORS on errors thrown outside middleware so the browser can read the message
            // (otherwise Angular shows "0 Unknown Error").
            $response = $this->withCors($request, Response::error($e->getMessage(), $e->statusCode(), $e->errors()));
        } catch (Throwable $e) {
            Logger::error($e->getMessage(), [
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            $debug = (bool) Config::get('app.debug', false);
            $response = $this->withCors($request, Response::error(
                $debug ? $e->getMessage() : 'Internal server error',
                500,
                $debug ? ['trace' => explode("\n", $e->getTraceAsString())] : []
            ));
        }

        $response->send();
    }

    private function withCors(Request $request, Response $response): Response
    {
        $cors    = Config::get('app.cors', []);
        $origin  = $request->header('origin');
        $allowed = $cors['allowed_origins'] ?? [];
        $allowOrigin = ($origin && in_array($origin, $allowed, true)) ? $origin : ($allowed[0] ?? '*');

        return $response
            ->withHeader('Access-Control-Allow-Origin', $allowOrigin)
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $cors['allowed_methods'] ?? []))
            ->withHeader('Access-Control-Allow-Headers', implode(', ', $cors['allowed_headers'] ?? []))
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Access-Control-Max-Age', (string) ($cors['max_age'] ?? 86400))
            ->withHeader('Vary', 'Origin');
    }

    private function preflightResponse(Request $request): Response
    {
        $cors    = Config::get('app.cors', []);
        $origin  = $request->header('origin');
        $allowed = $cors['allowed_origins'] ?? [];
        $allowOrigin = ($origin && in_array($origin, $allowed, true)) ? $origin : ($allowed[0] ?? '*');

        return Response::json(null, 204)
            ->withHeader('Access-Control-Allow-Origin', $allowOrigin)
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $cors['allowed_methods'] ?? []))
            ->withHeader('Access-Control-Allow-Headers', implode(', ', $cors['allowed_headers'] ?? []))
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Access-Control-Max-Age', (string) ($cors['max_age'] ?? 86400))
            ->withHeader('Vary', 'Origin');
    }

    private function runPipeline(Request $request, array $middleware, Closure $destination): Response
    {
        $pipeline = array_reduce(
            array_reverse($middleware),
            function (Closure $next, string $alias): Closure {
                return function (Request $request) use ($next, $alias): Response {
                    $instance = $this->resolveMiddleware($alias);
                    return $instance->handle($request, $next);
                };
            },
            $destination
        );

        return $pipeline($request);
    }

    private function resolveMiddleware(string $alias)
    {
        // Support "alias:param" syntax (e.g. role:admin)
        [$name, $param] = array_pad(explode(':', $alias, 2), 2, null);
        $class = $this->middlewareAliases[$name] ?? $name;

        if (!class_exists($class)) {
            throw new HttpException("Unknown middleware: {$name}", 500);
        }

        return $param !== null ? new $class($param) : new $class();
    }

    private function callHandler(mixed $handler, Request $request): Response
    {
        if (is_array($handler)) {
            [$class, $method] = $handler;
            $controller = new $class();
            $result = $controller->{$method}($request);
        } elseif (is_callable($handler)) {
            $result = $handler($request);
        } else {
            throw new HttpException('Invalid route handler', 500);
        }

        return $result instanceof Response ? $result : Response::json($result);
    }
}
