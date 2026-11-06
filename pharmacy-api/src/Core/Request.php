<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Immutable representation of the incoming HTTP request.
 */
final class Request
{
    private array $body;
    private array $query;
    private array $headers;
    private array $attributes = [];
    private string $rawBody = '';

    public function __construct()
    {
        $this->query   = $_GET;
        $this->headers = $this->parseHeaders();
        $this->body    = $this->parseBody();
    }

    /** Raw request body (needed for webhook HMAC verification). */
    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public function path(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        // Strip a leading "/index.php" if the server routes through it.
        $uri = preg_replace('#^/index\.php#', '', $uri) ?: '/';
        return '/' . trim($uri, '/');
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function only(array $keys): array
    {
        return array_intersect_key($this->all(), array_flip($keys));
    }

    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function header(string $key, ?string $default = null): ?string
    {
        return $this->headers[strtolower($key)] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('authorization');
        if ($auth && preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    public function ip(): string
    {
        return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public function userAgent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? '';
    }

    public function isAndroidApp(): bool
    {
        return str_contains(strtolower($this->userAgent()), 'advancedpharmacy-android');
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /** Authenticated user payload (set by AuthMiddleware). */
    public function user(): ?array
    {
        return $this->attributes['user'] ?? null;
    }

    public function clientId(): ?int
    {
        $user = $this->user();
        return isset($user['client_id']) ? (int) $user['client_id'] : null;
    }

    public function branchId(): ?int
    {
        $user = $this->user();
        return isset($user['branch_id']) ? (int) $user['branch_id'] : null;
    }

    private function parseHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            }
        }
        // CGI / some PHP SAPI setups expose Authorization only via REDIRECT_*.
        if (!isset($headers['authorization'])) {
            $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
                ?? $_SERVER['HTTP_AUTHORIZATION']
                ?? null;
            if (is_string($auth) && $auth !== '') {
                $headers['authorization'] = $auth;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        return $headers;
    }

    private function parseBody(): array
    {
        $contentType = $this->headers['content-type'] ?? '';

        if (str_contains($contentType, 'application/json')
            || str_contains($contentType, '+json')
            || str_contains(strtolower($contentType), 'application/json')) {
            $this->rawBody = file_get_contents('php://input') ?: '';
            $decoded = json_decode($this->rawBody, true);
            return is_array($decoded) ? $decoded : [];
        }

        return $_POST;
    }
}
