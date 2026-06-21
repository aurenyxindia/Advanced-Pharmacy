<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Config;
use App\Core\HttpException;
use App\Models\AuditLog;
use App\Models\LoginAttempt;
use App\Models\RefreshToken;
use App\Models\User;

final class AuthService
{
    private User $users;
    private RefreshToken $refreshTokens;
    private LoginAttempt $loginAttempts;
    private AuditLog $audit;

    public function __construct()
    {
        $this->users         = new User();
        $this->refreshTokens = new RefreshToken();
        $this->loginAttempts = new LoginAttempt();
        $this->audit         = new AuditLog();
    }

    public static function hashPassword(string $plain): string
    {
        return password_hash($plain, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost'   => 4,
            'threads'     => 2,
        ]);
    }

    /**
     * Attempt login. Returns token bundle + user profile on success.
     */
    public function attempt(string $email, string $password, string $ip): array
    {
        $email = strtolower(trim($email));
        $key   = sha1($email . '|' . $ip);

        $maxAttempts = (int) Config::get('app.rate_limit.max_attempts', 5);
        $decay       = (int) Config::get('app.rate_limit.decay_seconds', 300);

        if ($this->loginAttempts->recentFailures($key, $decay) >= $maxAttempts) {
            throw HttpException::tooManyRequests('Too many failed attempts. Please try again later.');
        }

        $user = $this->users->findByEmail($email);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $this->loginAttempts->log($key, $email, false, $ip);
            throw HttpException::unauthorized('Invalid credentials.');
        }

        if (($user['status'] ?? '') !== 'active') {
            throw HttpException::forbidden('Account is not active.');
        }

        // Rehash if algorithm parameters changed.
        if (password_needs_rehash($user['password_hash'], PASSWORD_ARGON2ID)) {
            $this->users->update((int) $user['id'], [
                'password_hash' => self::hashPassword($password),
            ]);
        }

        $this->loginAttempts->clear($key);
        $this->users->touchLogin((int) $user['id']);

        $this->audit->record([
            'client_id'  => $user['client_id'],
            'branch_id'  => $user['branch_id'],
            'user_id'    => $user['id'],
            'action'     => 'login',
            'ip_address' => $ip,
        ]);

        return $this->issueTokens($user);
    }

    public function refresh(string $refreshToken): array
    {
        $payload = Jwt::verify($refreshToken, 'refresh');

        if (!$this->refreshTokens->isValid($payload['jti'])) {
            throw HttpException::unauthorized('Refresh token has been revoked.');
        }

        $user = $this->users->findActiveById((int) $payload['sub']);
        if (!$user) {
            throw HttpException::unauthorized('User no longer active.');
        }

        // Rotate: revoke the used refresh token, issue a fresh pair.
        $this->refreshTokens->revoke($payload['jti']);

        return $this->issueTokens($user);
    }

    public function logout(string $refreshToken): void
    {
        try {
            $payload = Jwt::verify($refreshToken, 'refresh');
            $this->refreshTokens->revoke($payload['jti']);
        } catch (HttpException) {
            // Already invalid - nothing to do.
        }
    }

    private function issueTokens(array $user): array
    {
        $claims = [
            'sub'       => (int) $user['id'],
            'client_id' => $user['client_id'] !== null ? (int) $user['client_id'] : null,
            'branch_id' => $user['branch_id'] !== null ? (int) $user['branch_id'] : null,
            'role'      => $user['role'],
            'name'      => $user['name'],
            'email'     => $user['email'],
            'scope'     => $user['role'] === 'ho_admin' ? 'ho' : 'branch',
        ];

        $accessToken  = Jwt::issueAccessToken($claims);
        $refreshToken = Jwt::issueRefreshToken(['sub' => (int) $user['id']]);

        // Persist refresh token jti for rotation/revocation.
        $decoded = Jwt::verify($refreshToken, 'refresh');
        $this->refreshTokens->store(
            (int) $user['id'],
            $decoded['jti'],
            date('Y-m-d H:i:s', $decoded['exp'])
        );

        return [
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type'    => 'Bearer',
            'expires_in'    => (int) Config::get('app.jwt.access_ttl', 900),
            'user'          => [
                'id'        => (int) $user['id'],
                'name'      => $user['name'],
                'email'     => $user['email'],
                'role'      => $user['role'],
                'client_id' => $user['client_id'] !== null ? (int) $user['client_id'] : null,
                'branch_id' => $user['branch_id'] !== null ? (int) $user['branch_id'] : null,
            ],
        ];
    }
}
