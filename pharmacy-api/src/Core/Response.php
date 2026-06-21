<?php

declare(strict_types=1);

namespace App\Core;

/**
 * JSON HTTP response helper.
 */
final class Response
{
    private array $headers = [];

    public function __construct(
        private mixed $payload = null,
        private int $status = 200
    ) {
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self($data, $status);
    }

    public static function success(mixed $data = null, string $message = 'OK', int $status = 200): self
    {
        return new self([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $status);
    }

    public static function error(string $message, int $status = 400, array $errors = []): self
    {
        $body = [
            'success' => false,
            'message' => $message,
        ];
        if ($errors !== []) {
            $body['errors'] = $errors;
        }
        return new self($body, $status);
    }

    public function withHeader(string $key, string $value): self
    {
        $this->headers[$key] = $value;
        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            header('Content-Type: application/json; charset=utf-8');
            foreach ($this->headers as $key => $value) {
                header("{$key}: {$value}");
            }
        }

        if ($this->payload !== null) {
            echo json_encode(
                $this->payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }
    }
}
