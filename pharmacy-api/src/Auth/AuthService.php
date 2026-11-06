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
     *
     * @param bool $fromAndroid true when User-Agent is AdvancedPharmacy-Android
     */
    public function attempt(string $login, string $password, string $ip, bool $fromAndroid = false): array
    {
        $login = trim($login);
        $rateKeyId = str_contains($login, '@')
            ? strtolower($login)
            : (preg_replace('/\D+/', '', $login) ?? '');
        $key = sha1($rateKeyId . '|' . $ip);

        $maxAttempts = (int) Config::get('app.rate_limit.max_attempts', 5);
        $decay       = (int) Config::get('app.rate_limit.decay_seconds', 30);

        if ($this->loginAttempts->recentFailures($key, $decay) >= $maxAttempts) {
            $retryAfter = $this->loginAttempts->lockRemainingSeconds($key, $decay);
            throw new HttpException(
                "Too many failed attempts. Try again in {$retryAfter}s.",
                429,
                [
                    'retry_after' => $retryAfter,
                    'lock_seconds' => $decay,
                    'max_attempts' => $maxAttempts,
                ]
            );
        }

        $user = $this->users->findByEmailOrPhone($login);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $this->loginAttempts->log($key, $login, false, $ip);
            $failures = $this->loginAttempts->recentFailures($key, $decay);
            if ($failures >= $maxAttempts) {
                $retryAfter = $this->loginAttempts->lockRemainingSeconds($key, $decay);
                throw new HttpException(
                    "Too many failed attempts. Try again in {$retryAfter}s.",
                    429,
                    [
                        'retry_after' => $retryAfter,
                        'lock_seconds' => $decay,
                        'max_attempts' => $maxAttempts,
                    ]
                );
            }
            $left = max(0, $maxAttempts - $failures);
            throw new HttpException(
                'Invalid credentials.',
                401,
                [
                    'attempts_left' => $left,
                    'max_attempts' => $maxAttempts,
                ]
            );
        }

        if (($user['status'] ?? '') !== 'active') {
            throw HttpException::forbidden('Account is not active.');
        }

        // Separate platform_active flag (clients/users) — does not change users.status logic.
        $adminSvc = new \App\Services\AdminRegistrationService();
        $adminSvc->ensurePlatformActiveColumns();
        if (!$adminSvc->isUserPlatformActive($user)) {
            throw HttpException::forbidden(
                'Your account is registered but not activated yet. Please wait for admin approval.'
            );
        }

        $this->assertChannelAllowed($user, $fromAndroid);

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

    /**
     * web_android: both (existing users) | web | android.
     * android_expiry_date is enforced only on Android login when a date is set.
     */
    private function assertChannelAllowed(array $user, bool $fromAndroid): void
    {
        $channel = strtolower(trim((string) ($user['web_android'] ?? 'both')));
        if ($channel === '' || !in_array($channel, ['both', 'web', 'android'], true)) {
            $channel = 'both';
        }

        if ($fromAndroid) {
            if ($channel === 'web') {
                throw HttpException::forbidden(
                    'This account is registered for the web app only. Please sign in on the website.'
                );
            }
            // Paid/trial expiry is handled via subscription.needs_payment (paywall), not a hard login block.
            return;
        }

        if ($channel === 'android') {
            throw HttpException::forbidden(
                'This account is registered for the Android app only. Please sign in on the Android app.'
            );
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

        $subscription = (new \App\Services\ClientSubscriptionService())->snapshotForClient(
            (int) ($user['client_id'] ?? 0),
            $user
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
            'subscription' => $subscription,
        ];
    }
}
