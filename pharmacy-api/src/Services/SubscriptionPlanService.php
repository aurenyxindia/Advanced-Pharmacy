<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Database\Connection;
use PDO;

/**
 * HO table `subscription_plans`. Amounts are computed from monthly rate, months, discount, GST.
 */
final class SubscriptionPlanService
{
    public function ensureTable(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $db = Connection::ho();
        $db->exec(
            'CREATE TABLE IF NOT EXISTS subscription_plans (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                code VARCHAR(32) NOT NULL,
                name VARCHAR(80) NOT NULL,
                months SMALLINT NOT NULL DEFAULT 1,
                monthly_rate DECIMAL(10,2) NOT NULL DEFAULT 349.00,
                discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                gst_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                lifetime_base DECIMAL(10,2) NULL DEFAULT NULL,
                sort_order SMALLINT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                updated_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_subscription_plans_code (code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $count = (int) $db->query('SELECT COUNT(*) FROM subscription_plans')->fetchColumn();
        if ($count === 0) {
            $now = date('Y-m-d H:i:s');
            $ins = $db->prepare(
                'INSERT INTO subscription_plans
                    (code, name, months, monthly_rate, discount_pct, gst_pct, lifetime_base, sort_order, is_active, updated_at)
                 VALUES
                    (:code, :name, :months, :monthly_rate, :discount_pct, :gst_pct, :lifetime_base, :sort_order, 1, :now)'
            );
            foreach ($this->seedRows() as $row) {
                $ins->execute([
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'months' => $row['months'],
                    'monthly_rate' => $row['monthly_rate'],
                    'discount_pct' => $row['discount_pct'],
                    'gst_pct' => $row['gst_pct'],
                    'lifetime_base' => $row['lifetime_base'],
                    'sort_order' => $row['sort_order'],
                    'now' => $now,
                ]);
            }
        }
    }

    /** @return list<array<string,mixed>> */
    private function seedRows(): array
    {
        return [
            ['code' => 'monthly', 'name' => 'Monthly', 'months' => 1, 'monthly_rate' => 349, 'discount_pct' => 0, 'gst_pct' => 0, 'lifetime_base' => null, 'sort_order' => 1],
            ['code' => 'quarterly', 'name' => 'Quarterly', 'months' => 3, 'monthly_rate' => 349, 'discount_pct' => 5, 'gst_pct' => 0, 'lifetime_base' => null, 'sort_order' => 2],
            ['code' => 'half_yearly', 'name' => 'Half yearly', 'months' => 6, 'monthly_rate' => 349, 'discount_pct' => 7.5, 'gst_pct' => 0, 'lifetime_base' => null, 'sort_order' => 3],
            ['code' => 'yearly', 'name' => 'Yearly', 'months' => 12, 'monthly_rate' => 349, 'discount_pct' => 10, 'gst_pct' => 0, 'lifetime_base' => null, 'sort_order' => 4],
            ['code' => 'lifetime', 'name' => 'Lifetime', 'months' => 0, 'monthly_rate' => 349, 'discount_pct' => 0, 'gst_pct' => 18, 'lifetime_base' => 4999, 'sort_order' => 5],
        ];
    }

    /** @return array<string,mixed> */
    public function compute(array $plan): array
    {
        $months = (int) ($plan['months'] ?? 0);
        $monthly = round((float) ($plan['monthly_rate'] ?? 0), 2);
        $discPct = round((float) ($plan['discount_pct'] ?? 0), 2);
        $gstPct = round((float) ($plan['gst_pct'] ?? 0), 2);
        $isLifetime = $months <= 0;
        if ($isLifetime) {
            $base = round((float) ($plan['lifetime_base'] ?? 0), 2);
            $discountAmt = 0.0;
            $afterDisc = $base;
        } else {
            $base = round($monthly * $months, 2);
            $discountAmt = round($base * $discPct / 100, 2);
            $afterDisc = round($base - $discountAmt, 2);
        }
        $gstAmt = round($afterDisc * $gstPct / 100, 2);
        $payable = round($afterDisc + $gstAmt, 2);

        return [
            'id' => (int) ($plan['id'] ?? 0),
            'code' => (string) ($plan['code'] ?? ''),
            'name' => (string) ($plan['name'] ?? ''),
            'months' => $months,
            'monthly_rate' => $monthly,
            'discount_pct' => $discPct,
            'gst_pct' => $gstPct,
            'lifetime_base' => $isLifetime ? round((float) ($plan['lifetime_base'] ?? 0), 2) : null,
            'is_lifetime' => $isLifetime,
            'is_active' => (int) ($plan['is_active'] ?? 1) === 1,
            'sort_order' => (int) ($plan['sort_order'] ?? 0),
            'base_amount' => $base,
            'discount_amount' => $discountAmt,
            'gst_amount' => $gstAmt,
            'payable' => $payable,
            'currency' => 'INR',
        ];
    }

    /** @return list<array<string,mixed>> */
    public function list(bool $activeOnly = false): array
    {
        $this->ensureTable();
        $sql = 'SELECT * FROM subscription_plans';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';
        $rows = Connection::ho()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(fn (array $r) => $this->compute($r), $rows);
    }

    public function findByCode(string $code): ?array
    {
        $this->ensureTable();
        $st = Connection::ho()->prepare('SELECT * FROM subscription_plans WHERE code = :c LIMIT 1');
        $st->execute(['c' => $code]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->compute($row) : null;
    }

    public function expiryDateForPlan(array $plan, ?\DateTimeInterface $from = null): string
    {
        $from = $from ? \DateTimeImmutable::createFromInterface($from) : new \DateTimeImmutable('today');
        $months = (int) ($plan['months'] ?? 0);
        if ($months <= 0) {
            return '2099-12-31';
        }
        return $from->modify('+' . $months . ' months')->format('Y-m-d');
    }

    /**
     * Create a new subscription plan (admin).
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function create(array $input): array
    {
        $this->ensureTable();

        $code = strtolower(trim((string) ($input['code'] ?? '')));
        $code = preg_replace('/[^a-z0-9_]+/', '_', $code) ?? '';
        $code = trim($code, '_');
        if ($code === '' || strlen($code) > 32) {
            throw HttpException::validation(['code' => ['Use a short code like test_1rs (letters, numbers, underscore).']]);
        }
        if ($this->findByCode($code)) {
            throw HttpException::validation(['code' => ['This plan code already exists.']]);
        }

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw HttpException::validation(['name' => ['Plan name is required.']]);
        }

        $months = (int) ($input['months'] ?? 1);
        if ($months < 0 || $months > 1200) {
            throw HttpException::validation(['months' => ['Months must be 0 (lifetime) or 1–1200.']]);
        }
        $isLifetime = $months <= 0;

        $monthly = round((float) ($input['monthly_rate'] ?? 0), 2);
        $disc = round((float) ($input['discount_pct'] ?? 0), 2);
        $gst = round((float) ($input['gst_pct'] ?? 0), 2);
        $life = array_key_exists('lifetime_base', $input) && $input['lifetime_base'] !== null && $input['lifetime_base'] !== ''
            ? round((float) $input['lifetime_base'], 2)
            : ($isLifetime ? round((float) ($input['lifetime_base'] ?? 0), 2) : null);

        if ($monthly < 0 || $disc < 0 || $disc > 100 || $gst < 0 || $gst > 100) {
            throw HttpException::validation(['plan' => ['Rate, discount and GST must be valid numbers.']]);
        }
        if ($isLifetime && ($life === null || $life < 0)) {
            throw HttpException::validation(['lifetime_base' => ['Lifetime base amount is required.']]);
        }
        if (!$isLifetime && $monthly <= 0) {
            throw HttpException::validation(['monthly_rate' => ['Monthly rate must be greater than 0.']]);
        }

        $active = array_key_exists('is_active', $input)
            ? ((int) $input['is_active'] === 1 || $input['is_active'] === true || $input['is_active'] === 'true' ? 1 : 0)
            : 1;

        $sort = array_key_exists('sort_order', $input)
            ? (int) $input['sort_order']
            : ((int) Connection::ho()->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM subscription_plans')->fetchColumn());

        $now = date('Y-m-d H:i:s');
        Connection::ho()->prepare(
            'INSERT INTO subscription_plans
                (code, name, months, monthly_rate, discount_pct, gst_pct, lifetime_base, sort_order, is_active, updated_at)
             VALUES
                (:code, :name, :months, :monthly_rate, :discount_pct, :gst_pct, :lifetime_base, :sort_order, :is_active, :now)'
        )->execute([
            'code' => $code,
            'name' => $name,
            'months' => $isLifetime ? 0 : $months,
            'monthly_rate' => $isLifetime ? 0 : $monthly,
            'discount_pct' => $isLifetime ? 0 : $disc,
            'gst_pct' => $gst,
            'lifetime_base' => $isLifetime ? $life : null,
            'sort_order' => $sort,
            'is_active' => $active,
            'now' => $now,
        ]);

        $created = $this->findByCode($code);
        if (!$created) {
            throw new HttpException('Plan was created but could not be loaded.', 500);
        }
        return $created;
    }

    public function update(string $code, array $input): array
    {
        $this->ensureTable();
        $existing = $this->findByCode($code);
        if (!$existing) {
            throw HttpException::notFound('Plan not found.');
        }
        $monthly = array_key_exists('monthly_rate', $input)
            ? round((float) $input['monthly_rate'], 2)
            : (float) $existing['monthly_rate'];
        $disc = array_key_exists('discount_pct', $input)
            ? round((float) $input['discount_pct'], 2)
            : (float) $existing['discount_pct'];
        $gst = array_key_exists('gst_pct', $input)
            ? round((float) $input['gst_pct'], 2)
            : (float) $existing['gst_pct'];
        $life = array_key_exists('lifetime_base', $input)
            ? ($input['lifetime_base'] === null || $input['lifetime_base'] === ''
                ? null
                : round((float) $input['lifetime_base'], 2))
            : $existing['lifetime_base'];
        $name = array_key_exists('name', $input)
            ? trim((string) $input['name'])
            : (string) $existing['name'];
        $active = array_key_exists('is_active', $input)
            ? ((int) $input['is_active'] === 1 || $input['is_active'] === true || $input['is_active'] === 'true' ? 1 : 0)
            : ((int) $existing['is_active'] ? 1 : 0);

        if ($monthly < 0 || $disc < 0 || $disc > 100 || $gst < 0 || $gst > 100) {
            throw HttpException::validation(['plan' => ['Rate, discount and GST must be valid numbers.']]);
        }
        if ($name === '') {
            throw HttpException::validation(['name' => ['Plan name is required.']]);
        }

        $st = Connection::ho()->prepare(
            'UPDATE subscription_plans
             SET name = :name, monthly_rate = :monthly_rate, discount_pct = :discount_pct,
                 gst_pct = :gst_pct, lifetime_base = :lifetime_base, is_active = :is_active, updated_at = :now
             WHERE code = :code'
        );
        $st->execute([
            'name' => $name,
            'monthly_rate' => $monthly,
            'discount_pct' => $disc,
            'gst_pct' => $gst,
            'lifetime_base' => $life,
            'is_active' => $active,
            'now' => date('Y-m-d H:i:s'),
            'code' => $code,
        ]);
        return $this->findByCode($code) ?? $existing;
    }
}
