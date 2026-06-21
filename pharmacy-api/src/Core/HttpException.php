<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

class HttpException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $statusCode = 400,
        private readonly array $errors = []
    ) {
        parent::__construct($message, $statusCode);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public static function notFound(string $message = 'Resource not found'): self
    {
        return new self($message, 404);
    }

    public static function unauthorized(string $message = 'Unauthenticated'): self
    {
        return new self($message, 401);
    }

    public static function forbidden(string $message = 'Forbidden'): self
    {
        return new self($message, 403);
    }

    public static function validation(array $errors, string $message = 'Validation failed'): self
    {
        return new self($message, 422, $errors);
    }

    public static function tooManyRequests(string $message = 'Too many requests'): self
    {
        return new self($message, 429);
    }
}
