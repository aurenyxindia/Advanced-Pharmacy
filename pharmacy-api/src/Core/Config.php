<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Loads and caches the PHP config files in /config and exposes dot-notation access.
 */
final class Config
{
    private static array $items = [];
    private static bool $loaded = false;

    public static function load(string $configDir): void
    {
        foreach (glob($configDir . DIRECTORY_SEPARATOR . '*.php') as $file) {
            $name = basename($file, '.php');
            self::$items[$name] = require $file;
        }
        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (!self::$loaded) {
            return $default;
        }

        $segments = explode('.', $key);
        $value = self::$items;

        foreach ($segments as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } else {
                return $default;
            }
        }

        return $value;
    }
}
