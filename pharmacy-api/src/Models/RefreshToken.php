<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class RefreshToken extends Model
{
    protected string $connection = 'ho';
    protected string $table = 'refresh_tokens';

    public function store(int $userId, string $jti, string $expiresAt): int
    {
        return $this->insert([
            'user_id'    => $userId,
            'jti'        => $jti,
            'expires_at' => $expiresAt,
            'created_at' => now(),
        ]);
    }

    public function isValid(string $jti): bool
    {
        $row = $this->rawOne(
            "SELECT id FROM `{$this->table}` WHERE jti = :jti AND revoked_at IS NULL AND expires_at > NOW() LIMIT 1",
            ['jti' => $jti]
        );
        return $row !== null;
    }

    public function revoke(string $jti): void
    {
        $stmt = $this->db()->prepare("UPDATE `{$this->table}` SET revoked_at = NOW() WHERE jti = :jti");
        $stmt->execute(['jti' => $jti]);
    }

    public function revokeAllForUser(int $userId): void
    {
        $stmt = $this->db()->prepare(
            "UPDATE `{$this->table}` SET revoked_at = NOW() WHERE user_id = :uid AND revoked_at IS NULL"
        );
        $stmt->execute(['uid' => $userId]);
    }
}
