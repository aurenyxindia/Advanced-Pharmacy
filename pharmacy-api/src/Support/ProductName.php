<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Strip decorative asterisks from product names (Retail import / OCR artifacts).
 * Digit*digit (e.g. 25**1) becomes 25x1 so pack notation is preserved.
 */
final class ProductName
{
    public static function clean(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        $name = preg_replace('/(\d)\*+(\d)/', '$1x$2', $name) ?? $name;
        $name = str_replace('*', '', $name);
        $name = preg_replace('/\s+/', ' ', trim($name)) ?? $name;
        return $name;
    }

    public static function cleanUpper(string $name): string
    {
        return strtoupper(self::clean($name));
    }
}
