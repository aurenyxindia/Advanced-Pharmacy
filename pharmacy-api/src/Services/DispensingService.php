<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Database\Connection;
use PDO;
use PDOException;

/**
 * Hospital Dispensing (Purchase → Dispensing): hospital-attached medical stores issue
 * stock to OT / wards. Stock reduces batch-wise like a sale, but there is no payment —
 * the MRP amount is stored for record only.
 *
 * Everything for a dispensing lives in `h_dispensing` (one row per batch line, grouped
 * by dispensing_no). Hospitals come from the `hospitals` master (one default per branch).
 */
final class DispensingService
{
    private static bool $schemaReady = false;

    public static function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }
        $db = Connection::ops();
        try {
            $db->exec(
                "CREATE TABLE IF NOT EXISTS hospitals (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    client_id BIGINT UNSIGNED NOT NULL,
                    branch_id BIGINT UNSIGNED NOT NULL,
                    name VARCHAR(150) NOT NULL,
                    address VARCHAR(255) NULL,
                    phone VARCHAR(20) NULL,
                    is_default TINYINT(1) NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    KEY idx_hospital_scope (client_id, branch_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $db->exec(
                "CREATE TABLE IF NOT EXISTS h_dispensing (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    client_id BIGINT UNSIGNED NOT NULL,
                    branch_id BIGINT UNSIGNED NOT NULL,
                    dispensing_no VARCHAR(40) NOT NULL,
                    dispensed_at DATETIME NOT NULL,
                    hospital_id BIGINT UNSIGNED NULL,
                    hospital_name VARCHAR(150) NOT NULL,
                    patient_name VARCHAR(150) NULL,
                    department VARCHAR(100) NULL,
                    remarks VARCHAR(255) NULL,
                    product_binding_id BIGINT UNSIGNED NOT NULL,
                    batch_id BIGINT UNSIGNED NOT NULL,
                    product_name VARCHAR(255) NOT NULL,
                    pack_size VARCHAR(40) NULL,
                    batch_no VARCHAR(60) NOT NULL,
                    expiry_date DATE NULL,
                    units_per_pack INT NOT NULL DEFAULT 1,
                    qty INT NOT NULL,
                    unit_mrp DECIMAL(12,4) NOT NULL DEFAULT 0,
                    pack_mrp DECIMAL(12,2) NOT NULL DEFAULT 0,
                    discount_pct DECIMAL(6,2) NOT NULL DEFAULT 0,
                    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                    user_id BIGINT UNSIGNED NULL,
                    user_name VARCHAR(150) NOT NULL,
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    KEY idx_hd_scope (client_id, branch_id, dispensed_at),
                    KEY idx_hd_no (client_id, branch_id, dispensing_no),
                    KEY idx_hd_hospital (client_id, hospital_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (PDOException) {
            // Tables may already exist with a compatible shape.
        }
        self::$schemaReady = true;
    }

    /** Only one default hospital per branch. */
    public static function makeDefault(int $clientId, int $branchId, int $hospitalId): void
    {
        Connection::ops()->prepare(
            'UPDATE hospitals SET is_default = CASE WHEN id = :id THEN 1 ELSE 0 END
             WHERE client_id = :c AND branch_id = :b'
        )->execute(['id' => $hospitalId, 'c' => $clientId, 'b' => $branchId]);
    }

    /**
     * @param array{hospital_id?:int, patient_name?:string, department?:string, remarks?:string,
     *              lines?:list<array{batch_id?:int, qty?:int, discount_pct?:float}>} $data
     * @param array{user_id?:int|null, user_name?:string} $user
     * @return array{dispensing_no:string, items:int, total_qty:int, amount:float}
     */
    public function create(int $clientId, int $branchId, array $data, array $user): array
    {
        self::ensureSchema();
        if ($branchId <= 0) {
            throw HttpException::forbidden('A branch context is required.');
        }

        $hospitalId = (int) ($data['hospital_id'] ?? 0);
        $hSt = Connection::ops()->prepare(
            'SELECT id, name FROM hospitals WHERE id = :id AND client_id = :c AND branch_id = :b LIMIT 1'
        );
        $hSt->execute(['id' => $hospitalId, 'c' => $clientId, 'b' => $branchId]);
        $hospital = $hSt->fetch(PDO::FETCH_ASSOC);
        if (!$hospital) {
            throw HttpException::validation(['hospital_id' => ['Please select a hospital.']]);
        }

        // Merge repeated batches so stock is checked against the total qty.
        $wanted = [];
        foreach ((array) ($data['lines'] ?? []) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $batchId = (int) ($raw['batch_id'] ?? 0);
            $qty = (int) ($raw['qty'] ?? 0);
            if ($batchId <= 0 || $qty <= 0) {
                continue;
            }
            $disc = max(0.0, min(100.0, round((float) ($raw['discount_pct'] ?? 0), 2)));
            if (isset($wanted[$batchId])) {
                $wanted[$batchId]['qty'] += $qty;
            } else {
                $wanted[$batchId] = ['qty' => $qty, 'disc' => $disc];
            }
        }
        if ($wanted === []) {
            throw HttpException::validation(['lines' => ['Add at least one product with qty.']]);
        }

        $patient = $this->str($data['patient_name'] ?? null, 150);
        $department = $this->str($data['department'] ?? null, 100);
        $remarks = $this->str($data['remarks'] ?? null, 255);
        $userId = isset($user['user_id']) && (int) $user['user_id'] > 0 ? (int) $user['user_id'] : null;
        $userName = mb_substr(trim((string) ($user['user_name'] ?? '')) ?: 'SYSTEM', 0, 150);

        $inv = new InventoryService();
        $bindingIds = [];

        $result = Connection::transaction('ops', function (PDO $db) use (
            $clientId, $branchId, $hospital, $wanted, $patient, $department, $remarks,
            $userId, $userName, $inv, &$bindingIds
        ) {
            $year = date('Y');
            $seqSt = $db->prepare(
                "SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(dispensing_no, '-', -1) AS UNSIGNED)), 0)
                 FROM h_dispensing
                 WHERE client_id = :c AND branch_id = :b AND dispensing_no LIKE :like
                 FOR UPDATE"
            );
            $seqSt->execute(['c' => $clientId, 'b' => $branchId, 'like' => 'DSP-' . $year . '-%']);
            $dispNo = 'DSP-' . $year . '-' . ((int) $seqSt->fetchColumn() + 1);
            $now = now();
            $today = date('Y-m-d');

            $batchSt = $db->prepare(
                'SELECT ib.id, ib.product_binding_id, ib.batch_no, ib.expiry_date, ib.quantity, ib.mrp,
                        pb.product_name, pb.pack_size, COALESCE(NULLIF(pb.units_per_pack, 0), 1) AS units_per_pack
                 FROM inventory_batches ib
                 JOIN product_bindings pb ON pb.id = ib.product_binding_id
                 WHERE ib.id = :id AND ib.client_id = :c AND ib.branch_id = :b
                 LIMIT 1 FOR UPDATE'
            );
            $mrpSt = $db->prepare(
                'SELECT pi.mrp, pi.units_per_pack
                 FROM purchase_items pi
                 JOIN purchases p ON p.id = pi.purchase_id
                 WHERE p.client_id = :c AND p.branch_id = :b AND pi.product_binding_id = :pb AND pi.batch_no = :bno
                 ORDER BY p.invoice_date DESC, p.id DESC, pi.id DESC
                 LIMIT 1'
            );
            $ins = $db->prepare(
                'INSERT INTO h_dispensing
                    (client_id, branch_id, dispensing_no, dispensed_at, hospital_id, hospital_name,
                     patient_name, department, remarks, product_binding_id, batch_id, product_name,
                     pack_size, batch_no, expiry_date, units_per_pack, qty, unit_mrp, pack_mrp,
                     discount_pct, amount, user_id, user_name, created_at)
                 VALUES
                    (:c, :b, :no, :at, :hid, :hname, :patient, :dept, :rem, :pb, :bid, :pname,
                     :pack, :bno, :exp, :upp, :qty, :umrp, :pmrp, :disc, :amt, :uid, :uname, :now)'
            );

            $items = 0;
            $totalQty = 0;
            $totalAmt = 0.0;
            foreach ($wanted as $batchId => $w) {
                $batchSt->execute(['id' => $batchId, 'c' => $clientId, 'b' => $branchId]);
                $batch = $batchSt->fetch(PDO::FETCH_ASSOC);
                if (!$batch) {
                    throw HttpException::validation(['lines' => ["Batch #{$batchId} not found in this branch."]]);
                }
                $name = (string) $batch['product_name'] . ' / ' . (string) $batch['batch_no'];
                if (InventoryService::isBatchExpired($batch['expiry_date'] ?? null)) {
                    throw HttpException::validation(['lines' => ["{$name} is expired and cannot be dispensed."]]);
                }
                $have = (int) $batch['quantity'];
                if ($w['qty'] > $have) {
                    throw HttpException::validation(['lines' => ["{$name}: only {$have} in stock, cannot dispense {$w['qty']}."]]);
                }

                $upp = max(1, (int) $batch['units_per_pack']);
                $unitMrp = round((float) $batch['mrp'], 4);
                $packMrp = round($unitMrp * $upp, 2);
                $mrpSt->execute([
                    'c' => $clientId, 'b' => $branchId,
                    'pb' => (int) $batch['product_binding_id'], 'bno' => (string) $batch['batch_no'],
                ]);
                $pur = $mrpSt->fetch(PDO::FETCH_ASSOC);
                if ($pur && (float) $pur['mrp'] > 0) {
                    $packMrp = round((float) $pur['mrp'], 2);
                    $unitMrp = round($packMrp / max(1, (int) ($pur['units_per_pack'] ?? $upp)), 4);
                }
                $amount = round($unitMrp * $w['qty'] * (1 - $w['disc'] / 100), 2);

                $ins->execute([
                    'c' => $clientId,
                    'b' => $branchId,
                    'no' => $dispNo,
                    'at' => $now,
                    'hid' => (int) $hospital['id'],
                    'hname' => (string) $hospital['name'],
                    'patient' => $patient,
                    'dept' => $department,
                    'rem' => $remarks,
                    'pb' => (int) $batch['product_binding_id'],
                    'bid' => $batchId,
                    'pname' => (string) $batch['product_name'],
                    'pack' => $batch['pack_size'] !== null ? mb_substr((string) $batch['pack_size'], 0, 40) : null,
                    'bno' => (string) $batch['batch_no'],
                    'exp' => $batch['expiry_date'],
                    'upp' => $upp,
                    'qty' => $w['qty'],
                    'umrp' => $unitMrp,
                    'pmrp' => $packMrp,
                    'disc' => $w['disc'],
                    'amt' => $amount,
                    'uid' => $userId,
                    'uname' => $userName,
                    'now' => $now,
                ]);
                $lineId = (int) $db->lastInsertId();

                $inv->reduceBatch($batchId, $w['qty'], 'transfer_out', $today, 'h_dispensing', $lineId);
                $inv->bumpAggregate($clientId, $branchId, (int) $batch['product_binding_id'], -$w['qty']);
                $bindingIds[] = (int) $batch['product_binding_id'];

                $items++;
                $totalQty += $w['qty'];
                $totalAmt += $amount;
            }

            return [
                'dispensing_no' => $dispNo,
                'items' => $items,
                'total_qty' => $totalQty,
                'amount' => round($totalAmt, 2),
            ];
        });

        $inv->reconcileAggregates($clientId, $branchId, $bindingIds);
        return $result;
    }

    /**
     * One row per dispensing (grouped lines).
     *
     * @param array{from?:string,to?:string,hospital_id?:int,search?:string} $f
     * @return list<array<string,mixed>>
     */
    public function list(int $clientId, int $branchId, array $f = []): array
    {
        self::ensureSchema();
        [$where, $bind] = $this->filterWhere($clientId, $branchId, $f);

        $st = Connection::ops()->prepare(
            'SELECT dispensing_no, MIN(dispensed_at) AS dispensed_at, MAX(hospital_name) AS hospital_name,
                    MAX(patient_name) AS patient_name, MAX(department) AS department,
                    COUNT(*) AS items, SUM(qty) AS total_qty, SUM(amount) AS amount, MAX(user_name) AS user_name,
                    GROUP_CONCAT(DISTINCT CONCAT(product_name, " [", batch_no, "]") ORDER BY id SEPARATOR ", ") AS batch_summary
             FROM h_dispensing
             WHERE ' . implode(' AND ', $where) . '
             GROUP BY dispensing_no
             ORDER BY MAX(id) DESC
             LIMIT 500'
        );
        $st->execute($bind);
        return array_map(static fn (array $r) => [
            'dispensing_no' => (string) $r['dispensing_no'],
            'dispensed_at' => (string) $r['dispensed_at'],
            'hospital_name' => (string) $r['hospital_name'],
            'patient_name' => (string) ($r['patient_name'] ?? ''),
            'department' => (string) ($r['department'] ?? ''),
            'items' => (int) $r['items'],
            'total_qty' => (int) $r['total_qty'],
            'amount' => round((float) $r['amount'], 2),
            'user_name' => (string) $r['user_name'],
            'batch_summary' => (string) ($r['batch_summary'] ?? ''),
        ], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null */
    public function show(int $clientId, int $branchId, string $dispensingNo): ?array
    {
        self::ensureSchema();
        $st = Connection::ops()->prepare(
            'SELECT * FROM h_dispensing
             WHERE client_id = :c AND branch_id = :b AND dispensing_no = :no
             ORDER BY id ASC'
        );
        $st->execute(['c' => $clientId, 'b' => $branchId, 'no' => $dispensingNo]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return null;
        }
        $first = $rows[0];
        return [
            'dispensing_no' => (string) $first['dispensing_no'],
            'dispensed_at' => (string) $first['dispensed_at'],
            'hospital_name' => (string) $first['hospital_name'],
            'patient_name' => (string) ($first['patient_name'] ?? ''),
            'department' => (string) ($first['department'] ?? ''),
            'remarks' => (string) ($first['remarks'] ?? ''),
            'user_name' => (string) $first['user_name'],
            'amount' => round(array_sum(array_map(static fn ($r) => (float) $r['amount'], $rows)), 2),
            'items' => array_map(static fn (array $r) => [
                'product_name' => (string) $r['product_name'],
                'pack_size' => (string) ($r['pack_size'] ?? ''),
                'batch_no' => (string) $r['batch_no'],
                'expiry_date' => $r['expiry_date'],
                'qty' => (int) $r['qty'],
                'unit_mrp' => round((float) $r['unit_mrp'], 2),
                'discount_pct' => round((float) $r['discount_pct'], 2),
                'amount' => round((float) $r['amount'], 2),
            ], $rows),
        ];
    }

    /**
     * Line-level rows for Reports → Hospital Dispensing.
     *
     * @return list<array<string,mixed>>
     */
    public function report(int $clientId, int $branchId, string $from, string $to, string $hospitalName = ''): array
    {
        self::ensureSchema();
        $where = ['client_id = :c', 'dispensed_at BETWEEN :from AND :to'];
        $bind = ['c' => $clientId, 'from' => $from . ' 00:00:00', 'to' => $to . ' 23:59:59'];
        if ($branchId > 0) {
            $where[] = 'branch_id = :b';
            $bind['b'] = $branchId;
        }
        if ($hospitalName !== '') {
            $where[] = 'hospital_name = :h';
            $bind['h'] = $hospitalName;
        }
        $st = Connection::ops()->prepare(
            'SELECT dispensed_at, dispensing_no, hospital_name, patient_name, department, product_name,
                    pack_size, batch_no, expiry_date, qty, unit_mrp, discount_pct, amount, user_name
             FROM h_dispensing
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY dispensed_at DESC, id ASC
             LIMIT 10000'
        );
        $st->execute($bind);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ts = strtotime((string) $r['dispensed_at']) ?: time();
            $out[] = [
                'date' => date('Y-m-d', $ts),
                'time' => date('h:i A', $ts),
                'dispensing_no' => (string) $r['dispensing_no'],
                'hospital_name' => (string) $r['hospital_name'],
                'patient_name' => (string) ($r['patient_name'] ?? ''),
                'department' => (string) ($r['department'] ?? ''),
                'product_name' => (string) $r['product_name'],
                'pack_size' => (string) ($r['pack_size'] ?? ''),
                'batch_no' => (string) $r['batch_no'],
                'expiry' => $r['expiry_date'],
                'qty' => (int) $r['qty'],
                'mrp' => round((float) $r['unit_mrp'], 2),
                'discount_pct' => round((float) $r['discount_pct'], 2),
                'amount' => round((float) $r['amount'], 2),
                'user_name' => (string) $r['user_name'],
            ];
        }
        return $out;
    }

    /**
     * Hospital Dispensing Report PDF: summary, product-wise dispensed qty, full line register.
     *
     * @param array{from?:string,to?:string,hospital_id?:int,search?:string} $f
     */
    public function reportPdf(int $clientId, int $branchId, array $f = []): string
    {
        self::ensureSchema();
        $from = $this->validDate($f['from'] ?? null) ?? date('Y-m-01');
        $to = $this->validDate($f['to'] ?? null) ?? date('Y-m-d');
        $f['from'] = $from;
        $f['to'] = $to;
        [$where, $bind] = $this->filterWhere($clientId, $branchId, $f);

        $st = Connection::ops()->prepare(
            'SELECT dispensed_at, dispensing_no, hospital_name, patient_name, department, product_name,
                    pack_size, batch_no, expiry_date, qty, unit_mrp, discount_pct, amount, user_name
             FROM h_dispensing
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY dispensed_at ASC, id ASC
             LIMIT 20000'
        );
        $st->execute($bind);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $hospitalLabel = 'All hospitals';
        if (($hid = (int) ($f['hospital_id'] ?? 0)) > 0) {
            $hs = Connection::ops()->prepare('SELECT name FROM hospitals WHERE id = :id AND client_id = :c LIMIT 1');
            $hs->execute(['id' => $hid, 'c' => $clientId]);
            $hospitalLabel = (string) ($hs->fetchColumn() ?: 'Hospital #' . $hid);
        }

        $client = Connection::ho()->query('SELECT * FROM clients WHERE id = ' . $clientId)->fetch(PDO::FETCH_ASSOC) ?: [];
        $branch = $branchId > 0
            ? (Connection::ho()->query('SELECT * FROM branches WHERE id = ' . $branchId)->fetch(PDO::FETCH_ASSOC) ?: [])
            : [];
        $pick = static function (array $keys, array ...$sources): string {
            foreach ($sources as $src) {
                foreach ($keys as $k) {
                    $v = trim((string) ($src[$k] ?? ''));
                    if ($v !== '') {
                        return $v;
                    }
                }
            }
            return '';
        };
        $shopName = $pick(['business_name', 'name'], $client, $branch) ?: 'Pharmacy';
        $shopAddr = trim(implode(', ', array_filter([
            $pick(['address'], $branch, $client),
            trim($pick(['city'], $branch, $client) . ' ' . $pick(['pincode'], $branch, $client)),
        ])), ', ');
        $shopPhone = $pick(['phone'], $branch, $client);
        $shopDl = $pick(['drug_license_no'], $client, $branch);
        $shopGst = $pick(['gstin'], $branch, $client);

        $e = static fn ($v): string => htmlspecialchars(trim((string) $v), ENT_QUOTES, 'UTF-8');
        $money = static fn (float $v): string => number_format($v, 2, '.', ',');
        $dmy = static fn (?string $d): string => $d ? date('d-m-Y', strtotime($d)) : '';
        $exp = static fn (?string $d): string => $d ? date('m/y', strtotime($d)) : '';

        $products = [];
        $dispNos = [];
        $totalQty = 0;
        $totalAmt = 0.0;
        foreach ($rows as $r) {
            $key = mb_strtoupper(trim((string) $r['product_name'])) . '|' . trim((string) ($r['pack_size'] ?? ''));
            if (!isset($products[$key])) {
                $products[$key] = ['name' => (string) $r['product_name'], 'pack' => (string) ($r['pack_size'] ?? ''), 'qty' => 0, 'amount' => 0.0, 'nos' => []];
            }
            $products[$key]['qty'] += (int) $r['qty'];
            $products[$key]['amount'] += (float) $r['amount'];
            $products[$key]['nos'][(string) $r['dispensing_no']] = true;
            $dispNos[(string) $r['dispensing_no']] = true;
            $totalQty += (int) $r['qty'];
            $totalAmt += (float) $r['amount'];
        }
        uasort($products, static fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        $th = 'background-color:#0f766e; color:#ffffff; border:1px solid #334155; font-weight:bold; font-size:8px;';
        $td = 'border:1px solid #94a3b8; font-size:8px;';
        $tf = 'border:1px solid #334155; font-size:8px; font-weight:bold; background-color:#f1f5f9;';
        $c = ' text-align:center;';
        $rgt = ' text-align:right;';

        $productRows = '';
        $n = 0;
        foreach ($products as $p) {
            $n++;
            $productRows .= '<tr nobr="true">
                <td style="' . $td . $c . ' width:6%;">' . $n . '</td>
                <td style="' . $td . ' width:44%;">' . $e($p['name']) . '</td>
                <td style="' . $td . $c . ' width:14%;">' . $e($p['pack']) . '</td>
                <td style="' . $td . $c . ' width:12%;">' . count($p['nos']) . '</td>
                <td style="' . $td . $rgt . ' width:12%;"><b>' . $p['qty'] . '</b></td>
                <td style="' . $td . $rgt . ' width:12%;">' . $money($p['amount']) . '</td>
            </tr>';
        }

        $detailRows = '';
        foreach ($rows as $i => $r) {
            $ts = strtotime((string) $r['dispensed_at']) ?: time();
            $detailRows .= '<tr nobr="true">
                <td style="' . $td . $c . ' width:3%;">' . ($i + 1) . '</td>
                <td style="' . $td . $c . ' width:8%;">' . date('d-m-Y', $ts) . '<br>' . date('h:i A', $ts) . '</td>
                <td style="' . $td . $c . ' width:8%;">' . $e($r['dispensing_no']) . '</td>
                <td style="' . $td . ' width:12%;">' . $e($r['hospital_name']) . '</td>
                <td style="' . $td . ' width:9%;">' . $e($r['patient_name'] ?? '') . '</td>
                <td style="' . $td . $c . ' width:6%;">' . $e($r['department'] ?? '') . '</td>
                <td style="' . $td . ' width:17%;">' . $e($r['product_name']) . '</td>
                <td style="' . $td . $c . ' width:6%;">' . $e($r['pack_size'] ?? '') . '</td>
                <td style="' . $td . $c . ' width:7%;">' . $e($r['batch_no']) . '</td>
                <td style="' . $td . $c . ' width:4%;">' . $exp($r['expiry_date'] ?? null) . '</td>
                <td style="' . $td . $rgt . ' width:4%;"><b>' . (int) $r['qty'] . '</b></td>
                <td style="' . $td . $rgt . ' width:5%;">' . $money((float) $r['unit_mrp']) . '</td>
                <td style="' . $td . $rgt . ' width:4%;">' . rtrim(rtrim(number_format((float) $r['discount_pct'], 2, '.', ''), '0'), '.') . '</td>
                <td style="' . $td . $rgt . ' width:7%;">' . $money((float) $r['amount']) . '</td>
            </tr>';
        }

        $empty = '<tr><td colspan="%d" style="' . $td . $c . ' color:#64748b;">No dispensing found for the selected period.</td></tr>';
        $search = trim((string) ($f['search'] ?? ''));

        $html = '
<table cellpadding="3">
  <tr><td style="text-align:center; font-size:14px; color:#0f766e;"><b>' . $e($shopName) . '</b></td></tr>
  <tr><td style="text-align:center; font-size:8.5px; color:#334155;">' . $e($shopAddr)
            . ($shopPhone !== '' ? ' &nbsp;|&nbsp; Ph: ' . $e($shopPhone) : '') . '</td></tr>'
            . ($shopDl !== '' || $shopGst !== ''
                ? '<tr><td style="text-align:center; font-size:8px; color:#475569;">'
                    . ($shopDl !== '' ? 'D.L. No: ' . $e($shopDl) : '')
                    . ($shopDl !== '' && $shopGst !== '' ? ' &nbsp;|&nbsp; ' : '')
                    . ($shopGst !== '' ? 'GSTIN: ' . $e($shopGst) : '') . '</td></tr>'
                : '') . '
</table>
<table cellpadding="5" style="border-top:1.5px solid #0f766e; border-bottom:1px solid #cbd5e1;">
  <tr><td style="text-align:center; font-size:12px;"><b>HOSPITAL DISPENSING REPORT</b></td></tr>
</table>
<table cellpadding="4" style="font-size:8.5px; background-color:#f8fafc; border:1px solid #e2e8f0;">
  <tr>
    <td style="width:30%;"><b>Period:</b> ' . $dmy($from) . ' to ' . $dmy($to) . '</td>
    <td style="width:35%;"><b>Hospital:</b> ' . $e($hospitalLabel) . '</td>
    <td style="width:35%; text-align:right;"><b>Generated:</b> ' . date('d-m-Y h:i A') . '</td>
  </tr>'
            . ($search !== '' ? '<tr><td colspan="3"><b>Search:</b> ' . $e($search) . '</td></tr>' : '') . '
</table>
<br>
<table cellpadding="5" style="font-size:9px;">
  <tr>
    <td style="width:25%; border:1px solid #cbd5e1; text-align:center;">Dispensings<br><b style="font-size:11px;">' . count($dispNos) . '</b></td>
    <td style="width:25%; border:1px solid #cbd5e1; text-align:center;">Products<br><b style="font-size:11px;">' . count($products) . '</b></td>
    <td style="width:25%; border:1px solid #cbd5e1; text-align:center;">Total Dispensed Qty<br><b style="font-size:11px;">' . $totalQty . '</b></td>
    <td style="width:25%; border:1px solid #cbd5e1; text-align:center;">Amount (record only)<br><b style="font-size:11px;">Rs. ' . $money($totalAmt) . '</b></td>
  </tr>
</table>
<br>
<div style="font-size:10px; color:#0f766e;"><b>Product-wise Dispensed Qty</b></div>
<table cellpadding="3">
  <thead>
    <tr>
      <th style="' . $th . $c . ' width:6%;">Sr.</th>
      <th style="' . $th . ' width:44%;">Product Name</th>
      <th style="' . $th . $c . ' width:14%;">Pack</th>
      <th style="' . $th . $c . ' width:12%;">Dispensings</th>
      <th style="' . $th . $rgt . ' width:12%;">Dispensed Qty</th>
      <th style="' . $th . $rgt . ' width:12%;">Amount</th>
    </tr>
  </thead>'
            . ($productRows !== '' ? $productRows . '
  <tr>
    <td colspan="4" style="' . $tf . $rgt . ' width:76%;">TOTAL</td>
    <td style="' . $tf . $rgt . ' width:12%;">' . $totalQty . '</td>
    <td style="' . $tf . $rgt . ' width:12%;">' . $money($totalAmt) . '</td>
  </tr>' : sprintf($empty, 6)) . '
</table>
<br>
<div style="font-size:10px; color:#0f766e;"><b>Dispensing Register (all lines)</b></div>
<table cellpadding="3">
  <thead>
    <tr>
      <th style="' . $th . $c . ' width:3%;">#</th>
      <th style="' . $th . $c . ' width:8%;">Date / Time</th>
      <th style="' . $th . $c . ' width:8%;">Disp. No</th>
      <th style="' . $th . ' width:12%;">Hospital</th>
      <th style="' . $th . ' width:9%;">Patient</th>
      <th style="' . $th . $c . ' width:6%;">OT / Ward</th>
      <th style="' . $th . ' width:17%;">Product Name</th>
      <th style="' . $th . $c . ' width:6%;">Pack</th>
      <th style="' . $th . $c . ' width:7%;">Batch</th>
      <th style="' . $th . $c . ' width:4%;">Exp.</th>
      <th style="' . $th . $rgt . ' width:4%;">Qty</th>
      <th style="' . $th . $rgt . ' width:5%;">MRP</th>
      <th style="' . $th . $rgt . ' width:4%;">Disc%</th>
      <th style="' . $th . $rgt . ' width:7%;">Amount</th>
    </tr>
  </thead>'
            . ($detailRows !== '' ? $detailRows . '
  <tr>
    <td colspan="10" style="' . $tf . $rgt . ' width:80%;">TOTAL</td>
    <td style="' . $tf . $rgt . ' width:4%;">' . $totalQty . '</td>
    <td colspan="2" style="' . $tf . ' width:9%;"></td>
    <td style="' . $tf . $rgt . ' width:7%;">' . $money($totalAmt) . '</td>
  </tr>' : sprintf($empty, 14)) . '
</table>
<br>
<div style="font-size:7.5px; color:#64748b;">No payment collected — amounts are MRP based and kept for record only.</div>';

        $pdf = new class ('L', 'mm', 'A4', true, 'UTF-8') extends \TCPDF {
            protected $tcpdflink = false;

            public function Header(): void {}

            public function Footer(): void
            {
                $this->SetY(-10);
                $this->SetFont('helvetica', 'I', 7);
                $this->Cell(0, 6, 'Hospital Dispensing Report  -  Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'C');
            }
        };
        $pdf->SetCreator('Advanced Pharmacy Suite');
        $pdf->SetAuthor($shopName);
        $pdf->SetTitle('Hospital Dispensing Report');
        $pdf->SetSubject('Hospital Dispensing Report ' . $dmy($from) . ' to ' . $dmy($to));
        $pdf->setViewerPreferences(['DisplayDocTitle' => true]);
        $pdf->SetMargins(8, 8, 8);
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->AddPage('L');
        $pdf->writeHTML($html, true, false, true, false, '');
        return $pdf->Output('hospital-dispensing-report.pdf', 'S');
    }

    /**
     * @param array{from?:string,to?:string,hospital_id?:int,search?:string} $f
     * @return array{0:list<string>,1:array<string,mixed>}
     */
    private function filterWhere(int $clientId, int $branchId, array $f): array
    {
        $where = ['client_id = :c', 'branch_id = :b'];
        $bind = ['c' => $clientId, 'b' => $branchId];
        if ($from = $this->validDate($f['from'] ?? null)) {
            $where[] = 'dispensed_at >= :from';
            $bind['from'] = $from . ' 00:00:00';
        }
        if ($to = $this->validDate($f['to'] ?? null)) {
            $where[] = 'dispensed_at <= :to';
            $bind['to'] = $to . ' 23:59:59';
        }
        if (($hid = (int) ($f['hospital_id'] ?? 0)) > 0) {
            $where[] = 'hospital_id = :hid';
            $bind['hid'] = $hid;
        }
        $search = trim((string) ($f['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(dispensing_no LIKE :q1 OR product_name LIKE :q2 OR patient_name LIKE :q3 OR department LIKE :q4)';
            $bind['q1'] = $bind['q2'] = $bind['q3'] = $bind['q4'] = '%' . $search . '%';
        }
        return [$where, $bind];
    }

    private function validDate(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
    }

    private function str(mixed $v, int $max): ?string
    {
        $s = trim((string) ($v ?? ''));
        return $s === '' ? null : mb_substr($s, 0, $max);
    }
}
