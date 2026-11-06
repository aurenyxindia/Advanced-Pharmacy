<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Database\Connection;
use App\Models\AuditLog;
use PDO;
use PDOException;

/**
 * Platform admin (client_id=1, branch_id=1):
 * activate / inactivate pharmacies via clients.platform_active + users.platform_active.
 * Does not change users.status / subscription_status business logic.
 */
final class AdminRegistrationService
{
    public const PLATFORM_CLIENT_ID = 1;
    public const PLATFORM_BRANCH_ID = 1;

    public function ensurePlatformActiveColumns(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $db = Connection::ho();
        foreach (
            [
                'clients' => 'ALTER TABLE clients ADD COLUMN platform_active TINYINT(1) NOT NULL DEFAULT 1',
                'users' => 'ALTER TABLE users ADD COLUMN platform_active TINYINT(1) NOT NULL DEFAULT 1',
            ] as $sql
        ) {
            try {
                $db->exec($sql);
            } catch (PDOException) {
                // column already exists
            }
        }
        $this->ensureAccessColumns();

        // Existing rows stay active (DEFAULT 1 + explicit backfill).
        try {
            $db->exec('UPDATE clients SET platform_active = 1 WHERE platform_active IS NULL');
            $db->exec('UPDATE users SET platform_active = 1 WHERE platform_active IS NULL');
            // Heal earlier pending-status registrations onto platform_active (keep users.status active).
            $db->exec(
                "UPDATE users SET platform_active = 0, status = 'active'
                 WHERE status = 'pending'"
            );
            $db->exec(
                "UPDATE clients c
                 INNER JOIN users u ON u.client_id = c.id AND u.role = 'ho_admin' AND u.platform_active = 0
                 SET c.platform_active = 0
                 WHERE c.id <> " . self::PLATFORM_CLIENT_ID
            );
        } catch (PDOException) {
            // ignore
        }
    }

    /**
     * users.web_android: both | web | android.
     * users.android_expiry_date: NULL = no Android expiry (existing users keep working).
     */
    public function ensureAccessColumns(): void
    {
        static $accessDone = false;
        if ($accessDone) {
            return;
        }
        $accessDone = true;
        $db = Connection::ho();
        foreach (
            [
                "ALTER TABLE users ADD COLUMN web_android VARCHAR(16) NOT NULL DEFAULT 'both'",
                'ALTER TABLE users ADD COLUMN android_expiry_date DATE NULL DEFAULT NULL',
            ] as $sql
        ) {
            try {
                $db->exec($sql);
            } catch (PDOException) {
                // column already exists
            }
        }
    }

    public function hasUsersColumn(string $column): bool
    {
        try {
            $st = Connection::ho()->query('SHOW COLUMNS FROM users LIKE ' . Connection::ho()->quote($column));
            return (bool) ($st && $st->fetch());
        } catch (PDOException) {
            return false;
        }
    }

    /** @return array{web_android:string, android_expiry_date:?string} */
    public function accessDefaultsForClient(int $clientId): array
    {
        $this->ensureAccessColumns();
        if (!$this->hasUsersColumn('web_android')) {
            return ['web_android' => 'both', 'android_expiry_date' => null];
        }
        $st = Connection::ho()->prepare(
            "SELECT COALESCE(NULLIF(TRIM(web_android), ''), 'both') AS web_android, android_expiry_date
             FROM users WHERE client_id = :c AND role = 'ho_admin'
             ORDER BY id ASC LIMIT 1"
        );
        $st->execute(['c' => $clientId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $channel = strtolower(trim((string) ($row['web_android'] ?? 'both')));
        if (!in_array($channel, ['both', 'web', 'android'], true)) {
            $channel = 'both';
        }
        $expiry = $row['android_expiry_date'] ?? null;
        return [
            'web_android' => $channel,
            'android_expiry_date' => $expiry !== null && $expiry !== '' ? (string) $expiry : null,
        ];
    }

    /** @deprecated kept for RegistrationService call site compatibility */
    public function ensurePendingStatusColumn(): void
    {
        $this->ensurePlatformActiveColumns();
    }

    public function assertPlatformAdmin(int $clientId, int $branchId, string $role): void
    {
        if (
            $clientId !== self::PLATFORM_CLIENT_ID
            || $branchId !== self::PLATFORM_BRANCH_ID
            || !in_array($role, ['ho_admin', 'branch_admin'], true)
        ) {
            throw HttpException::forbidden('Admin is only available for the platform admin account.');
        }
    }

    /**
     * All pharmacies except platform client, with HO admin login + platform_active flags.
     *
     * @return list<array<string,mixed>>
     */
    public function listAll(): array
    {
        $this->ensurePlatformActiveColumns();
        $db = Connection::ho();
        $st = $db->prepare(
            "SELECT c.id AS client_id, c.business_name, c.owner_name, c.email AS client_email, c.phone AS client_phone,
                    c.drug_license_no, c.gstin, c.city, c.state, c.business_type,
                    c.subscription_status, c.subscription_plan, c.created_at AS registered_at,
                    COALESCE(c.platform_active, 1) AS client_platform_active,
                    u.id AS user_id, u.branch_id, u.name AS owner_login_name, u.email, u.phone,
                    u.status AS user_status, COALESCE(u.platform_active, 1) AS user_platform_active,
                    u.updated_at AS user_updated_at" .
                    ($this->hasUsersColumn('web_android')
                        ? ", COALESCE(NULLIF(TRIM(u.web_android), ''), 'both') AS web_android"
                        : ", 'both' AS web_android") .
                    ($this->hasUsersColumn('android_expiry_date')
                        ? ', u.android_expiry_date'
                        : ', NULL AS android_expiry_date') . "
             FROM clients c
             LEFT JOIN users u ON u.client_id = c.id AND u.role = 'ho_admin'
               AND u.id = (
                   SELECT MIN(u2.id) FROM users u2
                   WHERE u2.client_id = c.id AND u2.role = 'ho_admin'
               )
             WHERE c.id <> :platform
             ORDER BY COALESCE(c.platform_active, 1) ASC, c.created_at DESC
             LIMIT 1000"
        );
        $st->execute(['platform' => self::PLATFORM_CLIENT_ID]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['platform_active'] = ((int) ($r['client_platform_active'] ?? 1) === 1
                && (int) ($r['user_platform_active'] ?? 1) === 1) ? 1 : 0;
        }
        unset($r);
        return $rows;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listPending(): array
    {
        return array_values(array_filter(
            $this->listAll(),
            static fn (array $r) => (int) ($r['platform_active'] ?? 1) === 0
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listRecentActivated(int $limit = 50): array
    {
        $active = array_values(array_filter(
            $this->listAll(),
            static fn (array $r) => (int) ($r['platform_active'] ?? 1) === 1
        ));
        return array_slice($active, 0, max(1, min(200, $limit)));
    }

    public function setPlatformActive(
        int $clientId,
        bool $active,
        int $actorUserId,
        string $ip
    ): array {
        $this->ensurePlatformActiveColumns();
        if ($clientId === self::PLATFORM_CLIENT_ID) {
            throw HttpException::validation(['client_id' => ['Cannot change platform admin account.']]);
        }

        $db = Connection::ho();
        $st = $db->prepare(
            "SELECT c.id AS client_id, c.business_name, c.platform_active AS client_active,
                    u.id AS user_id, u.branch_id, u.platform_active AS user_active
             FROM clients c
             LEFT JOIN users u ON u.client_id = c.id AND u.role = 'ho_admin'
               AND u.id = (
                   SELECT MIN(u2.id) FROM users u2
                   WHERE u2.client_id = c.id AND u2.role = 'ho_admin'
               )
             WHERE c.id = :c
             LIMIT 1"
        );
        $st->execute(['c' => $clientId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw HttpException::notFound('Pharmacy not found.');
        }

        $flag = $active ? 1 : 0;
        $now = now();
        $db->prepare(
            'UPDATE clients SET platform_active = :a, updated_at = :now WHERE id = :id'
        )->execute(['a' => $flag, 'now' => $now, 'id' => $clientId]);

        if (!empty($row['user_id'])) {
            $db->prepare(
                'UPDATE users SET platform_active = :a, updated_at = :now WHERE id = :id'
            )->execute(['a' => $flag, 'now' => $now, 'id' => (int) $row['user_id']]);
        }

        // When activating a brand-new sign-up, start trial if still suspended.
        if ($active) {
            $db->prepare(
                "UPDATE clients SET subscription_status = 'trial',
                        trial_ends_at = COALESCE(trial_ends_at, :trial),
                        updated_at = :now
                 WHERE id = :id AND subscription_status = 'suspended'"
            )->execute([
                'trial' => date('Y-m-d H:i:s', strtotime('+14 days')),
                'now' => $now,
                'id' => $clientId,
            ]);
            if (!empty($row['branch_id'])) {
                $db->prepare(
                    "UPDATE branches SET status = 'active', updated_at = :now
                     WHERE client_id = :c AND id = :b AND status = 'inactive'"
                )->execute(['now' => $now, 'c' => $clientId, 'b' => (int) $row['branch_id']]);
            }
        }

        (new AuditLog())->record([
            'client_id'  => self::PLATFORM_CLIENT_ID,
            'branch_id'  => self::PLATFORM_BRANCH_ID,
            'user_id'    => $actorUserId,
            'action'     => $active ? 'platform_activate' : 'platform_inactivate',
            'entity'     => 'client',
            'entity_id'  => $clientId,
            'ip_address' => $ip,
            'meta'       => json_encode([
                'business_name' => $row['business_name'] ?? '',
                'platform_active' => $flag,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        return [
            'client_id' => $clientId,
            'user_id' => (int) ($row['user_id'] ?? 0),
            'platform_active' => $flag,
        ];
    }

    /** Backward-compatible activate used by older route. */
    public function activate(int $clientId, int $actorUserId, string $ip): array
    {
        $result = $this->setPlatformActive($clientId, true, $actorUserId, $ip);
        $result['already_active'] = false;
        return $result;
    }

    public function isUserPlatformActive(array $user): bool
    {
        $this->ensurePlatformActiveColumns();
        if (array_key_exists('platform_active', $user) && (int) $user['platform_active'] !== 1) {
            return false;
        }
        $clientId = (int) ($user['client_id'] ?? 0);
        if ($clientId <= 0) {
            return true;
        }
        $st = Connection::ho()->prepare(
            'SELECT COALESCE(platform_active, 1) FROM clients WHERE id = :id LIMIT 1'
        );
        $st->execute(['id' => $clientId]);
        return (int) ($st->fetchColumn() ?: 1) === 1;
    }
}
