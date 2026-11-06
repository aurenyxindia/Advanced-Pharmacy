<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Database\Connection;
use PDO;

/**
 * Return Deduction % master (Retail returnDidiction / returnMargin).
 * Slots: months-before-expiry → deduction percent used on wholesale credit notes.
 */
final class ReturnDeductionService
{
    public function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        Connection::ops()->exec(
            "CREATE TABLE IF NOT EXISTS return_deductions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                client_id BIGINT UNSIGNED NOT NULL,
                return_months INT NOT NULL DEFAULT 0,
                deduction_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_rd_client_months (client_id, return_months),
                KEY idx_rd_client (client_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /** @return list<array<string,mixed>> */
    public function list(int $clientId): array
    {
        $this->ensureSchema();
        $st = Connection::ops()->prepare(
            'SELECT id, return_months, deduction_pct, created_at
             FROM return_deductions WHERE client_id = :c
             ORDER BY return_months ASC'
        );
        $st->execute(['c' => $clientId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'return_months' => (int) $row['return_months'],
                'returnMonth' => (int) $row['return_months'],
                'deduction_pct' => (float) $row['deduction_pct'],
                'didcuction' => (float) $row['deduction_pct'],
                'created_at' => $row['created_at'],
            ];
        }
        return $out;
    }

    /** @param array<string,mixed> $data */
    public function save(int $clientId, array $data): array
    {
        $this->ensureSchema();
        $months = (int) ($data['return_months'] ?? $data['returnMonth'] ?? 0);
        $pct = (float) ($data['deduction_pct'] ?? $data['didcuction'] ?? 0);
        if ($months < 0) {
            throw HttpException::validation(['return_months' => ['Months must be 0 or more.']]);
        }
        if ($pct < 0 || $pct > 100) {
            throw HttpException::validation(['deduction_pct' => ['Deduction % must be 0–100.']]);
        }
        $chk = Connection::ops()->prepare(
            'SELECT id FROM return_deductions WHERE client_id = :c AND return_months = :m LIMIT 1'
        );
        $chk->execute(['c' => $clientId, 'm' => $months]);
        if ($chk->fetch()) {
            throw HttpException::validation(['return_months' => ['This month slot already exists.']]);
        }
        $st = Connection::ops()->prepare(
            'INSERT INTO return_deductions (client_id, return_months, deduction_pct, created_at)
             VALUES (:c, :m, :p, :now)'
        );
        $st->execute(['c' => $clientId, 'm' => $months, 'p' => $pct, 'now' => now()]);
        return ['id' => (int) Connection::ops()->lastInsertId(), 'return_months' => $months, 'deduction_pct' => $pct];
    }

    /**
     * Pick deduction % for an expiry date (Retail checkSlot).
     * Prefer the slot whose return_months is closest but ≥ months remaining; else 0.
     */
    public function deductionForExpiry(int $clientId, ?string $expiryDate): float
    {
        $slots = $this->list($clientId);
        if ($slots === [] || !$expiryDate) {
            return 0.0;
        }
        $exp = strtotime(substr($expiryDate, 0, 10));
        if ($exp === false) {
            return 0.0;
        }
        $now = time();
        $monthsLeft = (int) max(0, floor(($exp - $now) / (30 * 86400)));
        $best = null;
        foreach ($slots as $slot) {
            $m = (int) $slot['return_months'];
            if ($monthsLeft <= $m) {
                if ($best === null || $m < (int) $best['return_months']) {
                    $best = $slot;
                }
            }
        }
        return $best ? (float) $best['deduction_pct'] : 0.0;
    }
}
