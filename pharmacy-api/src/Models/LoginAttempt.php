<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class LoginAttempt extends Model
{
    protected string $connection = 'ho';
    protected string $table = 'login_attempts';

    public function recentFailures(string $key, int $decaySeconds): int
    {
        $row = $this->rawOne(
            "SELECT COUNT(*) AS c FROM `{$this->table}`
             WHERE attempt_key = :k AND successful = 0
               AND created_at > (NOW() - INTERVAL :decay SECOND)",
            ['k' => $key, 'decay' => $decaySeconds]
        );
        return (int) ($row['c'] ?? 0);
    }

    public function log(string $key, string $email, bool $successful, string $ip): void
    {
        $this->insert([
            'attempt_key' => $key,
            'email'       => $email,
            'successful'  => $successful ? 1 : 0,
            'ip_address'  => $ip,
            'created_at'  => now(),
        ]);
    }

    public function clear(string $key): void
    {
        $stmt = $this->db()->prepare("DELETE FROM `{$this->table}` WHERE attempt_key = :k");
        $stmt->execute(['k' => $key]);
    }
}
