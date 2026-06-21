<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Minimal file logger writing JSON lines to storage/logs.
 */
final class Logger
{
    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        $dir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $entry = json_encode([
            'ts'      => date('c'),
            'level'   => $level,
            'message' => $message,
            'context' => $context,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        @file_put_contents(
            $dir . '/app-' . date('Y-m-d') . '.log',
            $entry . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
}
