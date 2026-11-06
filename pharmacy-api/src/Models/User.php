<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class User extends Model
{
    protected string $connection = 'ho';
    protected string $table = 'users';

    public function findByEmail(string $email): ?array
    {
        return $this->firstWhere(['email' => strtolower(trim($email))]);
    }

    public function findByEmailOrPhone(string $username): ?array
    {
        $username = trim($username);
        if ($username === '') {
            return null;
        }
        if (str_contains($username, '@')) {
            return $this->findByEmail($username);
        }
        $digits = preg_replace('/\D+/', '', $username) ?? '';
        $phone = $digits !== '' ? $digits : $username;

        $stmt = $this->db()->prepare(
            'SELECT * FROM `' . $this->table . '`
             WHERE email = :email
                OR phone = :phone
                OR REPLACE(REPLACE(REPLACE(phone, " ", ""), "-", ""), "+", "") = :digits
             LIMIT 1'
        );
        $stmt->execute([
            'email'  => strtolower($username),
            'phone'  => $username,
            'digits' => $digits,
        ]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function setOtp(int $userId, string $otp, string $expiresAt): void
    {
        $this->update($userId, [
            'otp_code'       => $otp,
            'otp_expires_at' => $expiresAt,
        ]);
    }

    /** @param array<string,mixed> $user */
    public function otpMatches(array $user, string $otp): bool
    {
        $stored = trim((string) ($user['otp_code'] ?? ''));
        if ($stored === '' || strcasecmp($stored, 'NA') === 0) {
            return false;
        }
        if ($stored !== trim($otp)) {
            return false;
        }
        $expires = $user['otp_expires_at'] ?? null;
        if ($expires && strtotime((string) $expires) < time()) {
            return false;
        }
        return true;
    }

    public function findActiveById(int $id): ?array
    {
        $row = $this->firstWhere(['id' => $id, 'status' => 'active']);
        if (!$row) {
            return null;
        }
        // platform_active defaults to 1 for existing users; block refresh when inactivated.
        if (array_key_exists('platform_active', $row) && (int) $row['platform_active'] !== 1) {
            return null;
        }
        return $row;
    }

    public function touchLogin(int $id): void
    {
        $this->update($id, ['last_login_at' => now()]);
    }
}
