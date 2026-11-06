<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\AuthService;
use App\Core\HttpException;
use App\Database\Connection;
use PDO;
use PDOException;

/**
 * Per-module View / Edit / Delete rights (Retail userRights.php parity).
 */
final class UserRightsService
{
    /** @return list<array{code:string,name:string,sort:int}> */
    public static function modules(): array
    {
        return [
            ['code' => 'HOME', 'name' => 'Home', 'sort' => 10],
            ['code' => 'SALES', 'name' => 'Sales', 'sort' => 20],
            ['code' => 'PURCHASE', 'name' => 'Purchase', 'sort' => 30],
            ['code' => 'PURCHASE_ORDER', 'name' => 'Purchase Order', 'sort' => 32],
            ['code' => 'DISPENSING', 'name' => 'Hospital Dispensing', 'sort' => 33],
            ['code' => 'IMPORT_BILL', 'name' => 'Import Bill', 'sort' => 35],
            ['code' => 'OPENING_STOCK', 'name' => 'Opening Stock', 'sort' => 36],
            ['code' => 'MASTERS', 'name' => 'Masters', 'sort' => 40],
            ['code' => 'ALL_REPORTS', 'name' => 'All Reports', 'sort' => 50],
            ['code' => 'PAYMENTS', 'name' => 'Payments', 'sort' => 60],
            // One right for Debit Note + Credit Note + Retail Sales Return (+ deduction).
            ['code' => 'RETURN_NOTE', 'name' => 'Return Note', 'sort' => 65],
            ['code' => 'DAILY_CLOSING', 'name' => 'Daily Closing', 'sort' => 70],
            ['code' => 'DELIVERIES', 'name' => 'Deliveries', 'sort' => 80],
            ['code' => 'AURA_GHAR_TAK', 'name' => 'AuraGharTak', 'sort' => 81],
            ['code' => 'AGT_RIDER', 'name' => 'AuraGharTak Rider (delivery app)', 'sort' => 83],
            ['code' => 'AURA_AI', 'name' => 'AuraAI', 'sort' => 82],
            ['code' => 'MR', 'name' => 'MR (Medical Representative)', 'sort' => 84],
            ['code' => 'IPD', 'name' => 'IPD / Admissions', 'sort' => 85],
            ['code' => 'REFILLS', 'name' => 'Refill Reminders', 'sort' => 90],
            ['code' => 'MY_PROFILE', 'name' => 'My Profile', 'sort' => 140],
        ];
    }

    public static function isAdminRole(string $role): bool
    {
        return in_array($role, ['ho_admin', 'branch_admin'], true);
    }

    public function ensureSchema(): void
    {
        $db = Connection::ho();
        $db->exec(
            'CREATE TABLE IF NOT EXISTS app_modules (
                module_code VARCHAR(50) PRIMARY KEY,
                module_name VARCHAR(120) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $db->exec(
            'CREATE TABLE IF NOT EXISTS user_module_rights (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                client_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                module_code VARCHAR(50) NOT NULL,
                can_view TINYINT(1) NOT NULL DEFAULT 0,
                can_edit TINYINT(1) NOT NULL DEFAULT 0,
                can_delete TINYINT(1) NOT NULL DEFAULT 0,
                updated_at DATETIME NULL,
                UNIQUE KEY uk_client_user_module (client_id, user_id, module_code),
                KEY idx_client_user (client_id, user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $ins = $db->prepare(
            'INSERT INTO app_modules (module_code, module_name, sort_order, is_active)
             VALUES (:c, :n, :s, 1)
             ON DUPLICATE KEY UPDATE module_name = VALUES(module_name), sort_order = VALUES(sort_order), is_active = 1'
        );
        foreach (self::modules() as $m) {
            $ins->execute(['c' => $m['code'], 'n' => $m['name'], 's' => $m['sort']]);
        }

        // Retire split debit/credit rights — one Return Note covers all return screens.
        $db->exec(
            "UPDATE app_modules SET is_active = 0
             WHERE module_code IN ('DEBIT_NOTE', 'CREDIT_NOTE')"
        );
        $this->migrateLegacyReturnRights($db);
        $this->migrateDeliveriesToAuraGharTak($db);

        $userCols = [
            'reg_no'       => 'VARCHAR(40) NULL',
            'joining_date' => 'DATE NULL',
            'address'      => 'VARCHAR(255) NULL',
        ];
        foreach ($userCols as $col => $def) {
            try {
                $db->exec("ALTER TABLE users ADD COLUMN {$col} {$def}");
            } catch (PDOException) {
                // column exists
            }
        }
    }

    /** @return list<array<string,mixed>> */
    public function listModules(): array
    {
        $this->ensureSchema();
        return self::modules();
    }

    /** @return list<array<string,mixed>> */
    public function listUsers(int $clientId): array
    {
        $this->ensureSchema();
        $db = Connection::ho();
        $stmt = $db->prepare(
            "SELECT id, name, email, phone, reg_no, role, branch_id, status, joining_date, address
             FROM users
             WHERE client_id = :c AND status = 'active' AND role NOT IN ('ho_admin')
             ORDER BY name ASC"
        );
        $stmt->execute(['c' => $clientId]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($users as &$u) {
            $u['rights'] = $this->rightsForUser($clientId, (int) $u['id']);
            unset($u['password_hash']);
        }
        unset($u);

        return $users;
    }

    /** @return array<string,mixed> */
    public function getUser(int $clientId, int $userId): array
    {
        $user = $this->findUser($clientId, $userId);
        $user['rights'] = $this->rightsForUser($clientId, $userId);
        unset($user['password_hash']);
        return $user;
    }

    /** @param array<string,mixed> $data @return array{id:int} */
    public function saveUser(int $clientId, array $data): array
    {
        $this->ensureSchema();
        $db = Connection::ho();

        $name = trim((string) ($data['name'] ?? ''));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $role = $this->mapStaffRole((string) ($data['staff_role'] ?? 'pharmacist'));
        $branchId = (int) ($data['branch_id'] ?? 0);
        $password = (string) ($data['password'] ?? '');
        $userId = (int) ($data['id'] ?? 0);

        if ($name === '' || $email === '' || $branchId <= 0) {
            throw HttpException::validation([
                'name' => ['Name, email and branch are required.'],
            ]);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw HttpException::validation(['email' => ['Enter a valid login email.']]);
        }

        if ($userId <= 0 && strlen($password) < 8) {
            throw HttpException::validation(['password' => ['Password must be at least 8 characters.']]);
        }

        // Email is globally unique (uq_users_email) — check across all clients.
        $dupSql = 'SELECT id, client_id, status, name FROM users WHERE email = :e';
        $bind = ['e' => $email];
        if ($userId > 0) {
            $dupSql .= ' AND id <> :id';
            $bind['id'] = $userId;
        }
        $dupSql .= ' LIMIT 1';
        $dup = $db->prepare($dupSql);
        $dup->execute($bind);
        $existing = $dup->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $sameClient = (int) ($existing['client_id'] ?? 0) === $clientId;
            $status = (string) ($existing['status'] ?? '');
            if ($sameClient && $status !== 'active') {
                throw HttpException::validation([
                    'email' => ['This login email belongs to a deactivated user. Reactivate that user or use a different email.'],
                ]);
            }
            throw HttpException::validation([
                'email' => ['This login email is already in use. Choose a different email.'],
            ]);
        }

        $joiningDate = trim((string) ($data['joining_date'] ?? ''));
        if ($joiningDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $joiningDate)) {
            $joiningDate = null;
        }

        $payload = [
            'name'          => $name,
            'email'         => $email,
            'phone'         => ($data['phone'] ?? null) !== null && trim((string) $data['phone']) !== ''
                ? trim((string) $data['phone'])
                : null,
            'reg_no'        => isset($data['reg_no']) && trim((string) $data['reg_no']) !== ''
                ? strtoupper(trim((string) $data['reg_no']))
                : null,
            'joining_date'  => $joiningDate,
            'address'       => isset($data['address']) && trim((string) $data['address']) !== ''
                ? strtoupper(trim((string) $data['address']))
                : null,
            'role'          => $role,
            'branch_id'     => $branchId,
            'status'        => 'active',
            'updated_at'    => now(),
        ];

        try {
            if ($userId > 0) {
                if ($password !== '') {
                    $payload['password_hash'] = AuthService::hashPassword($password);
                }
                $db->prepare(
                    'UPDATE users SET name=:name, email=:email, phone=:phone, reg_no=:reg_no,
                     joining_date=:joining_date, address=:address, role=:role, branch_id=:branch_id,
                     status=:status, updated_at=:updated_at'
                    . (isset($payload['password_hash']) ? ', password_hash=:password_hash' : '')
                    . ' WHERE id=:id AND client_id=:c'
                )->execute(array_merge($payload, ['id' => $userId, 'c' => $clientId]));
            } else {
                $payload['client_id'] = $clientId;
                $payload['password_hash'] = AuthService::hashPassword($password);
                $payload['created_at'] = now();
                $adminReg = new AdminRegistrationService();
                $adminReg->ensureAccessColumns();
                $cols = 'client_id, branch_id, name, email, phone, reg_no, joining_date, address,
                     password_hash, role, status, created_at, updated_at';
                $vals = ':client_id, :branch_id, :name, :email, :phone, :reg_no, :joining_date, :address,
                     :password_hash, :role, :status, :created_at, :updated_at';
                if ($adminReg->hasUsersColumn('web_android')) {
                    $access = $adminReg->accessDefaultsForClient($clientId);
                    $payload['web_android'] = $access['web_android'];
                    $payload['android_expiry_date'] = $access['android_expiry_date'];
                    $cols .= ', web_android, android_expiry_date';
                    $vals .= ', :web_android, :android_expiry_date';
                }
                $db->prepare("INSERT INTO users ($cols) VALUES ($vals)")->execute($payload);
                $userId = (int) $db->lastInsertId();
            }
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062 || str_contains($e->getMessage(), 'Duplicate entry')) {
                throw HttpException::validation([
                    'email' => ['This login email is already in use. Choose a different email.'],
                ]);
            }
            throw $e;
        }

        $rights = is_array($data['rights'] ?? null) ? $data['rights'] : [];
        if ($role === AgtDeliveryService::RIDER_ROLE) {
            $rights[] = ['module_code' => 'AGT_RIDER', 'can_view' => 1, 'can_edit' => 1, 'can_delete' => 0];
        }
        $this->saveRights($clientId, $userId, $rights);

        return ['id' => $userId];
    }

    public function deleteUser(int $clientId, int $userId): void
    {
        $db = Connection::ho();
        $stmt = $db->prepare(
            "UPDATE users SET status = 'inactive', updated_at = :now WHERE id = :id AND client_id = :c"
        );
        $stmt->execute(['now' => now(), 'id' => $userId, 'c' => $clientId]);
    }

    /** @return array{is_admin:bool,user_id:int,rights:list<array<string,mixed>>} */
    public function myRights(int $clientId, int $userId, string $role): array
    {
        $this->ensureSchema();

        if (self::isAdminRole($role)) {
            $rights = [];
            foreach (self::modules() as $m) {
                $rights[] = [
                    'module_code' => $m['code'],
                    'module_name' => $m['name'],
                    'can_view'    => 1,
                    'can_edit'    => 1,
                    'can_delete'  => 1,
                ];
            }
            return ['is_admin' => true, 'user_id' => $userId, 'rights' => $rights];
        }

        return [
            'is_admin' => false,
            'user_id'  => $userId,
            'rights'   => $this->rightsForUser($clientId, $userId),
        ];
    }

    /** @param list<array<string,mixed>> $rights */
    private function saveRights(int $clientId, int $userId, array $rights): void
    {
        $db = Connection::ho();
        $db->prepare('DELETE FROM user_module_rights WHERE client_id = :c AND user_id = :u')
            ->execute(['c' => $clientId, 'u' => $userId]);

        $ins = $db->prepare(
            'INSERT INTO user_module_rights
                (client_id, user_id, module_code, can_view, can_edit, can_delete, updated_at)
             VALUES (:c, :u, :m, :v, :e, :d, :now)'
        );

        $merged = [];
        foreach ($rights as $r) {
            $code = strtoupper(trim((string) ($r['module_code'] ?? '')));
            if ($code === 'DEBIT_NOTE' || $code === 'CREDIT_NOTE') {
                $code = 'RETURN_NOTE';
            }
            if ($code === '') {
                continue;
            }
            $view = $this->flag($r['can_view'] ?? 0);
            $edit = $this->flag($r['can_edit'] ?? 0);
            $del  = $this->flag($r['can_delete'] ?? 0);
            if ($edit || $del) {
                $view = 1;
            }
            if (!$view && !$edit && !$del) {
                continue;
            }
            if (!isset($merged[$code])) {
                $merged[$code] = ['v' => 0, 'e' => 0, 'd' => 0];
            }
            $merged[$code]['v'] = max($merged[$code]['v'], $view);
            $merged[$code]['e'] = max($merged[$code]['e'], $edit);
            $merged[$code]['d'] = max($merged[$code]['d'], $del);
        }

        foreach ($merged as $code => $flags) {
            $ins->execute([
                'c' => $clientId, 'u' => $userId, 'm' => $code,
                'v' => $flags['v'], 'e' => $flags['e'], 'd' => $flags['d'], 'now' => now(),
            ]);
        }
    }

    /** @return list<array<string,mixed>> */
    private function rightsForUser(int $clientId, int $userId): array
    {
        $db = Connection::ho();
        $stmt = $db->prepare(
            'SELECT r.module_code, m.module_name, r.can_view, r.can_edit, r.can_delete
             FROM user_module_rights r
             LEFT JOIN app_modules m ON m.module_code = r.module_code
             WHERE r.client_id = :c AND r.user_id = :u
             ORDER BY COALESCE(m.sort_order, 999), r.module_code'
        );
        $stmt->execute(['c' => $clientId, 'u' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed> */
    private function findUser(int $clientId, int $userId): array
    {
        $db = Connection::ho();
        $stmt = $db->prepare('SELECT * FROM users WHERE id = :id AND client_id = :c LIMIT 1');
        $stmt->execute(['id' => $userId, 'c' => $clientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw HttpException::notFound('User not found.');
        }
        return $row;
    }

    private function mapStaffRole(string $role): string
    {
        return match (strtolower($role)) {
            'purchase', 'non-pharmacist', 'non_pharmacist' => 'purchase',
            'cashier' => 'cashier',
            'delivery', 'rider', 'delivery_boy' => AgtDeliveryService::RIDER_ROLE,
            default => 'pharmacist',
        };
    }

    private function flag(mixed $v): int
    {
        return ($v === 1 || $v === true || $v === '1') ? 1 : 0;
    }

    /**
     * Merge old DEBIT_NOTE / CREDIT_NOTE rows into RETURN_NOTE, then drop the old codes.
     */
    /**
     * Staff who already had Deliveries rights keep AuraGharTak access after the split module.
     */
    private function migrateDeliveriesToAuraGharTak(PDO $db): void
    {
        // Avoid INSERT…SELECT into the same table (MariaDB ambiguous UPDATE columns).
        $db->exec(
            "INSERT INTO user_module_rights
                (client_id, user_id, module_code, can_view, can_edit, can_delete, updated_at)
             SELECT d.client_id, d.user_id, 'AURA_GHAR_TAK',
                    d.can_view, d.can_edit, d.can_delete, NOW()
             FROM user_module_rights d
             WHERE d.module_code = 'DELIVERIES'
               AND NOT EXISTS (
                 SELECT 1 FROM user_module_rights a
                 WHERE a.client_id = d.client_id AND a.user_id = d.user_id
                   AND a.module_code = 'AURA_GHAR_TAK'
               )"
        );
        $db->exec(
            "UPDATE user_module_rights agt
             INNER JOIN user_module_rights del
               ON del.client_id = agt.client_id AND del.user_id = agt.user_id
              AND del.module_code = 'DELIVERIES'
             SET agt.can_view = GREATEST(agt.can_view, del.can_view),
                 agt.can_edit = GREATEST(agt.can_edit, del.can_edit),
                 agt.can_delete = GREATEST(agt.can_delete, del.can_delete),
                 agt.updated_at = NOW()
             WHERE agt.module_code = 'AURA_GHAR_TAK'"
        );
    }

    public function requireModule(
        int $clientId,
        int $userId,
        string $role,
        string $moduleCode,
        bool $requireEdit = false,
    ): void {
        if (self::isAdminRole($role)) {
            return;
        }
        $this->ensureSchema();
        $code = strtoupper(trim($moduleCode));
        foreach ($this->rightsForUser($clientId, $userId) as $row) {
            if (strtoupper((string) ($row['module_code'] ?? '')) !== $code) {
                continue;
            }
            $view = $this->flag($row['can_view'] ?? 0);
            $edit = $this->flag($row['can_edit'] ?? 0);
            if ($requireEdit && !$edit) {
                throw HttpException::forbidden('You do not have edit access for this module.');
            }
            if (!$requireEdit && !$view && !$edit) {
                throw HttpException::forbidden('You do not have access to this module.');
            }
            return;
        }
        throw HttpException::forbidden('You do not have access to this module.');
    }

    private function migrateLegacyReturnRights(PDO $db): void
    {
        $db->exec(
            "INSERT INTO user_module_rights
                (client_id, user_id, module_code, can_view, can_edit, can_delete, updated_at)
             SELECT src.client_id, src.user_id, 'RETURN_NOTE',
                    src.can_view, src.can_edit, src.can_delete, NOW()
             FROM (
                 SELECT client_id, user_id,
                        MAX(can_view) AS can_view,
                        MAX(can_edit) AS can_edit,
                        MAX(can_delete) AS can_delete
                 FROM user_module_rights
                 WHERE module_code IN ('DEBIT_NOTE', 'CREDIT_NOTE', 'RETURN_NOTE')
                 GROUP BY client_id, user_id
             ) src
             WHERE NOT EXISTS (
                 SELECT 1 FROM user_module_rights rn
                 WHERE rn.client_id = src.client_id AND rn.user_id = src.user_id
                   AND rn.module_code = 'RETURN_NOTE'
             )"
        );
        $db->exec(
            "UPDATE user_module_rights rn
             INNER JOIN (
                 SELECT client_id, user_id,
                        MAX(can_view) AS can_view,
                        MAX(can_edit) AS can_edit,
                        MAX(can_delete) AS can_delete
                 FROM user_module_rights
                 WHERE module_code IN ('DEBIT_NOTE', 'CREDIT_NOTE', 'RETURN_NOTE')
                 GROUP BY client_id, user_id
             ) src ON src.client_id = rn.client_id AND src.user_id = rn.user_id
             SET rn.can_view = GREATEST(rn.can_view, src.can_view),
                 rn.can_edit = GREATEST(rn.can_edit, src.can_edit),
                 rn.can_delete = GREATEST(rn.can_delete, src.can_delete),
                 rn.updated_at = NOW()
             WHERE rn.module_code = 'RETURN_NOTE'"
        );
        $db->exec(
            "DELETE FROM user_module_rights
             WHERE module_code IN ('DEBIT_NOTE', 'CREDIT_NOTE')"
        );
    }
}
