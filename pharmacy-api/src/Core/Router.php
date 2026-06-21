<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal regex-based router with per-route middleware support.
 */
final class Router
{
    /** @var array<int, array{method:string, pattern:string, handler:mixed, middleware:string[]}> */
    private array $routes = [];

    private array $groupStack = [];

    public function get(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    public function put(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    public function patch(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('PATCH', $path, $handler, $middleware);
    }

    public function delete(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    /**
     * Group routes under a common prefix and/or shared middleware.
     *
     * @param array{prefix?:string, middleware?:string[]} $attributes
     */
    public function group(array $attributes, callable $callback): void
    {
        $this->groupStack[] = $attributes;
        $callback($this);
        array_pop($this->groupStack);
    }

    private function add(string $method, string $path, mixed $handler, array $middleware): void
    {
        $prefix = '';
        $groupMiddleware = [];
        foreach ($this->groupStack as $group) {
            $prefix .= $group['prefix'] ?? '';
            $groupMiddleware = array_merge($groupMiddleware, $group['middleware'] ?? []);
        }

        $fullPath = '/' . trim($prefix . $path, '/');
        $fullPath = $fullPath === '/' ? '/' : rtrim($fullPath, '/');

        $this->routes[] = [
            'method'     => $method,
            'pattern'    => $this->compile($fullPath),
            'handler'    => $handler,
            'middleware' => array_merge($groupMiddleware, $middleware),
        ];
    }

    private function compile(string $path): string
    {
        $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $path);
        return '#^' . $regex . '$#';
    }

    /**
     * @return array{handler:mixed, middleware:string[], params:array<string,string>}
     */
    public function match(string $method, string $path): array
    {
        $methodNotAllowed = false;

        foreach ($this->routes as $route) {
            if (preg_match($route['pattern'], $path, $matches)) {
                if ($route['method'] !== $method) {
                    $methodNotAllowed = true;
                    continue;
                }
                $params = array_filter(
                    $matches,
                    static fn ($k) => !is_int($k),
                    ARRAY_FILTER_USE_KEY
                );
                return [
                    'handler'    => $route['handler'],
                    'middleware' => $route['middleware'],
                    'params'     => $params,
                ];
            }
        }

        if ($methodNotAllowed) {
            throw new HttpException('Method not allowed', 405);
        }

        throw HttpException::notFound('Route not found: ' . $path);
    }
}
