<?php

declare(strict_types=1);

namespace App\Core;

/**
 * JSON HTTP response helper.
 */
final class Response
{
    private array $headers = [];
    private ?string $rawBody = null;
    private string $contentType = 'application/json; charset=utf-8';

    public function __construct(
        private mixed $payload = null,
        private int $status = 200
    ) {
    }

    /** Build a raw (non-JSON) response, e.g. a PDF or CSV download. */
    public static function raw(string $body, string $contentType, int $status = 200, array $headers = []): self
    {
        $response = new self(null, $status);
        $response->rawBody = $body;
        $response->contentType = $contentType;
        $response->headers = $headers;
        return $response;
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
            header('Content-Type: ' . $this->contentType);
            foreach ($this->headers as $key => $value) {
                header("{$key}: {$value}");
            }
        }

        if ($this->rawBody !== null) {
            echo $this->rawBody;
            return;
        }

        if ($this->payload !== null) {
            echo json_encode(
                $this->payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }
    }
}
