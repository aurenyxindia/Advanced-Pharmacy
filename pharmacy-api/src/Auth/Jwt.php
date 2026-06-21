<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Config;
use App\Core\HttpException;

/**
 * Self-contained HS256 JSON Web Token implementation.
 *
 * Uses base64url encoding and a constant-time signature comparison.
 * No external dependency is required, keeping the security surface minimal
 * and fully auditable.
 */
final class Jwt
{
    public static function issueAccessToken(array $claims): string
    {
        return self::encode($claims, (int) Config::get('app.jwt.access_ttl', 900), 'access');
    }

    public static function issueRefreshToken(array $claims): string
    {
        return self::encode($claims, (int) Config::get('app.jwt.refresh_ttl', 1209600), 'refresh');
    }

    public static function verify(string $token, string $expectedType = 'access'): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw HttpException::unauthorized('Malformed token.');
        }
        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $header = json_decode(self::base64UrlDecode($encodedHeader), true);
        if (!is_array($header) || ($header['alg'] ?? null) !== self::algo()) {
            throw HttpException::unauthorized('Unsupported token algorithm.');
        }

        $expected = self::sign($encodedHeader . '.' . $encodedPayload);
        if (!hash_equals($expected, $encodedSignature)) {
            throw HttpException::unauthorized('Invalid token signature.');
        }

        $payload = json_decode(self::base64UrlDecode($encodedPayload), true);
        if (!is_array($payload)) {
            throw HttpException::unauthorized('Invalid token payload.');
        }

        $now = time();
        $leeway = 5;
        if (isset($payload['nbf']) && $payload['nbf'] > $now + $leeway) {
            throw HttpException::unauthorized('Token not yet valid.');
        }
        if (isset($payload['exp']) && $payload['exp'] < $now - $leeway) {
            throw HttpException::unauthorized('Token has expired.');
        }
        if (($payload['iss'] ?? null) !== Config::get('app.jwt.issuer')) {
            throw HttpException::unauthorized('Invalid token issuer.');
        }
        if (($payload['typ'] ?? null) !== $expectedType) {
            throw HttpException::unauthorized('Invalid token type.');
        }

        return $payload;
    }

    private static function encode(array $claims, int $ttl, string $type): string
    {
        $now = time();
        $header = ['alg' => self::algo(), 'typ' => 'JWT'];
        $payload = array_merge($claims, [
            'iss' => (string) Config::get('app.jwt.issuer', 'advanced-pharmacy'),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttl,
            'jti' => bin2hex(random_bytes(16)),
            'typ' => $type,
        ]);

        $segments = [
            self::base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES)),
            self::base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES)),
        ];
        $signingInput = implode('.', $segments);
        $segments[] = self::sign($signingInput);

        return implode('.', $segments);
    }

    private static function sign(string $input): string
    {
        $secret = (string) Config::get('app.jwt.secret', '');
        if ($secret === '') {
            throw new HttpException('JWT secret is not configured.', 500);
        }
        $signature = hash_hmac('sha256', $input, $secret, true);
        return self::base64UrlEncode($signature);
    }

    private static function algo(): string
    {
        return 'HS256';
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/')) ?: '';
    }
}
