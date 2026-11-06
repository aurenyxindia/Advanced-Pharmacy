<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Database\Connection;
use PDO;
use PDOException;

/**
 * Retail-parity stock adjustment: physical count vs system qty on existing
 * inventory_batches. Positive diff → addStock; negative → reduceBatch.
 * Ledger txn_type = adjustment. Syncs inventory_stock via bump/reconcile.
 */
final class StockAdjustmentService
{
    private static bool $schemaReady = false;

    public function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }
        $db = Connection::ops();
        try {
            $db->exec(
                "CREATE TABLE IF NOT EXISTS stock_adjustments (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    client_id BIGINT UNSIGNED NOT NULL,
                    branch_id BIGINT UNSIGNED NOT NULL,
                    adjustment_no VARCHAR(60) NOT NULL,
                    adjustment_date DATE NOT NULL,
                    adjustment_type ENUM('PHYSICAL_COUNT','DAMAGE','EXPIRY','THEFT','OTHER') NOT NULL DEFAULT 'PHYSICAL_COUNT',
                    remarks VARCHAR(255) NULL,
                    total_items INT NOT NULL DEFAULT 0,
                    status VARCHAR(20) NOT NULL DEFAULT 'APPROVED',
                    entry_by VARCHAR(120) NULL,
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_adj_no (client_id, branch_id, adjustment_no),
                    KEY idx_adj_scope (client_id, branch_id, adjustment_date)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $db->exec(
                "CREATE TABLE IF NOT EXISTS stock_adjustment_lines (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    adjustment_id BIGINT UNSIGNED NOT NULL,
                    client_id BIGINT UNSIGNED NOT NULL,
                    branch_id BIGINT UNSIGNED NOT NULL,
                    product_binding_id BIGINT UNSIGNED NOT NULL,
                    batch_id BIGINT UNSIGNED NOT NULL,
                    product_name VARCHAR(255) NOT NULL,
                    batch_no VARCHAR(60) NOT NULL,
                    system_qty INT NOT NULL DEFAULT 0,
                    system_unit_qty INT NOT NULL DEFAULT 0,
                    physical_qty INT NOT NULL DEFAULT 0,
                    physical_unit_qty INT NOT NULL DEFAULT 0,
                    difference_qty INT NOT NULL DEFAULT 0,
                    difference_unit_qty INT NOT NULL DEFAULT 0,
                    reason VARCHAR(255) NULL,
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    KEY idx_adj_lines_header (adjustment_id),
                    KEY idx_adj_lines_batch (client_id, branch_id, batch_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $db->exec(
                "CREATE TABLE IF NOT EXISTS stock_adjustment_audit_log (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    client_id BIGINT UNSIGNED NOT NULL,
                    branch_id BIGINT UNSIGNED NOT NULL,
                    adjustment_id BIGINT UNSIGNED NOT NULL,
                    adjustment_no VARCHAR(60) NOT NULL,
                    adjustment_type VARCHAR(30) NOT NULL,
                    product_binding_id BIGINT UNSIGNED NOT NULL,
                    batch_id BIGINT UNSIGNED NOT NULL,
                    product_name VARCHAR(255) NOT NULL,
                    batch_no VARCHAR(60) NOT NULL,
                    system_unit_qty INT NOT NULL DEFAULT 0,
                    physical_unit_qty INT NOT NULL DEFAULT 0,
                    difference_unit_qty INT NOT NULL DEFAULT 0,
                    reason VARCHAR(255) NOT NULL,
                    user_id BIGINT UNSIGNED NULL,
                    user_name VARCHAR(150) NOT NULL,
                    user_role VARCHAR(40) NULL,
                    ip_address VARCHAR(64) NOT NULL,
                    user_agent VARCHAR(255) NULL,
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    KEY idx_saal_scope (client_id, branch_id, created_at),
                    KEY idx_saal_adjustment (adjustment_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (PDOException) {
            // Table may already exist with compatible shape.
        }
        self::$schemaReady = true;
    }

    /**
     * Batches available for adjustment (Retail getAllAvailableProducts).
     *
     * @return list<array<string, mixed>>
     */
    public function listBatches(int $clientId, int $branchId, string $search = ''): array
    {
        $search = trim($search);
        $params = ['c' => $clientId, 'b' => $branchId];
        $where = 'ib.client_id = :c AND ib.branch_id = :b';

        if ($search !== '') {
            $where .= ' AND (pb.product_name LIKE :q OR ib.batch_no LIKE :q2)';
            $params['q'] = '%' . $search . '%';
            $params['q2'] = '%' . $search . '%';
            // With search: include zero-qty / allow browsing (Retail).
        } else {
            $where .= ' AND ib.quantity > 0';
            $where .= ' AND (ib.expiry_date IS NULL OR ib.expiry_date >= CURDATE())';
        }

        $stmt = Connection::ops()->prepare(
            "SELECT ib.id AS batch_id,
                    ib.product_binding_id,
                    pb.product_name AS p_name,
                    ib.batch_no,
                    ib.quantity AS system_unit_qty,
                    ib.expiry_date AS expiry,
                    ib.shelf_location AS shelf,
                    pb.pack_size,
                    COALESCE(NULLIF(pb.units_per_pack, 0), 1) AS units_per_pack,
                    pb.manufacturer_short AS m_name,
                    pb.hsn_code,
                    ib.mrp,
                    ib.purchase_rate,
                    ib.ptr,
                    ib.pts
             FROM inventory_batches ib
             JOIN product_bindings pb ON pb.id = ib.product_binding_id
             WHERE {$where}
             ORDER BY pb.product_name, ib.batch_no
             LIMIT 500"
        );
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $units = max(0, (int) ($r['system_unit_qty'] ?? 0));
            $upp = max(1, (int) ($r['units_per_pack'] ?? 1));
            $packs = intdiv($units, $upp);
            $out[] = [
                'batch_id'           => (int) $r['batch_id'],
                'product_binding_id' => (int) $r['product_binding_id'],
                'p_name'             => (string) $r['p_name'],
                'batch_no'           => (string) $r['batch_no'],
                'pack_size'          => $r['pack_size'],
                'units_per_pack'     => $upp,
                'expiry'             => $r['expiry'],
                'shelf'              => $r['shelf'],
                'm_name'             => $r['m_name'],
                'system_unit_qty'    => $units,
                'system_qty'         => $packs,
                'current_unit_qty'   => $units,
                'current_qty'        => $packs,
                'mrp'                => (float) ($r['mrp'] ?? 0),
                'purchase_rate'      => (float) ($r['purchase_rate'] ?? 0),
                'ptr'                => (float) ($r['ptr'] ?? 0),
                'pts'                => (float) ($r['pts'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * @param array{
     *   adjustment_type?: string,
     *   remarks?: string,
     *   entry_by?: string,
     *   lines: list<array<string, mixed>>
     * } $payload
     * @param array{user_id?: int|null, user_name?: string, user_role?: string|null, ip_address?: string, user_agent?: string|null} $audit
     * @return array{adjustment_no: string, lines_saved: int, errors: list<string>}
     */
    public function adjust(int $clientId, int $branchId, array $payload, array $audit = []): array
    {
        $this->ensureSchema();

        $allowed = ['PHYSICAL_COUNT', 'DAMAGE', 'EXPIRY', 'THEFT', 'OTHER'];
        $type = strtoupper(trim((string) ($payload['adjustment_type'] ?? 'PHYSICAL_COUNT')));
        if (!in_array($type, $allowed, true)) {
            $type = 'PHYSICAL_COUNT';
        }
        $remarks = mb_substr(trim((string) ($payload['remarks'] ?? '')), 0, 255);
        $entryBy = trim((string) ($payload['entry_by'] ?? 'SYSTEM'));
        if ($entryBy === '') {
            $entryBy = 'SYSTEM';
        }

        $rawLines = $payload['lines'] ?? [];
        if (!is_array($rawLines) || $rawLines === []) {
            throw HttpException::validation(['lines' => ['No adjustment lines provided.']]);
        }

        $changed = [];
        foreach ($rawLines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $batchId = (int) ($line['batch_id'] ?? 0);
            $sysU = (int) ($line['system_unit_qty'] ?? 0);
            $physU = (int) ($line['physical_unit_qty'] ?? $sysU);
            $sysP = (int) ($line['system_qty'] ?? 0);
            $physP = (int) ($line['physical_qty'] ?? $sysP);
            $packChanged = !empty($line['pack_changed']);
            if ($batchId <= 0) {
                continue;
            }
            // Qty change and/or pack size edit (persists to product_bindings for sales).
            if ($physU === $sysU && $physP === $sysP && !$packChanged) {
                continue;
            }
            $changed[] = $line;
        }
        if ($changed === []) {
            throw HttpException::validation([
                'lines' => ['No adjustments (all physical qty / pack size match system).'],
            ]);
        }
        foreach ($changed as $line) {
            if (trim((string) ($line['reason'] ?? '')) === '' && $remarks === '') {
                throw HttpException::validation([
                    'reason' => ['Reason is required. Enter a reason before saving the adjustment.'],
                ]);
            }
        }
        $auditRow = [
            'uid'   => isset($audit['user_id']) && (int) $audit['user_id'] > 0 ? (int) $audit['user_id'] : null,
            'uname' => mb_substr(trim((string) ($audit['user_name'] ?? '')) ?: $entryBy, 0, 150),
            'urole' => isset($audit['user_role']) ? mb_substr((string) $audit['user_role'], 0, 40) : null,
            'ip'    => mb_substr(trim((string) ($audit['ip_address'] ?? '')) ?: '0.0.0.0', 0, 64),
            'ua'    => isset($audit['user_agent']) ? mb_substr((string) $audit['user_agent'], 0, 255) : null,
        ];

        $adjNo = $this->nextAdjustmentNo($clientId, $branchId);
        $adjDate = date('Y-m-d');
        $now = now();
        $inv = new InventoryService();
        $errors = [];
        $saved = 0;
        $bindingIds = [];

        Connection::transaction('ops', function (PDO $db) use (
            $clientId, $branchId, $type, $remarks, $entryBy, $adjNo, $adjDate, $now,
            $changed, $inv, $auditRow, &$errors, &$saved, &$bindingIds
        ) {
            $insH = $db->prepare(
                'INSERT INTO stock_adjustments
                    (client_id, branch_id, adjustment_no, adjustment_date, adjustment_type,
                     remarks, total_items, status, entry_by, created_at)
                 VALUES (:c, :b, :no, :dt, :type, :rem, :n, :st, :by, :now)'
            );
            $insH->execute([
                'c' => $clientId,
                'b' => $branchId,
                'no' => $adjNo,
                'dt' => $adjDate,
                'type' => $type,
                'rem' => $remarks !== '' ? $remarks : null,
                'n' => count($changed),
                'st' => 'APPROVED',
                'by' => $entryBy,
                'now' => $now,
            ]);
            $adjustmentId = (int) $db->lastInsertId();

            $batchStmt = $db->prepare(
                'SELECT ib.id, ib.product_binding_id, ib.batch_no, ib.quantity, ib.expiry_date,
                        ib.shelf_location, ib.mrp, ib.purchase_rate, ib.ptr, ib.pts,
                        pb.product_name, pb.pack_size AS binding_pack_size,
                        COALESCE(NULLIF(pb.units_per_pack, 0), 1) AS units_per_pack
                 FROM inventory_batches ib
                 JOIN product_bindings pb ON pb.id = ib.product_binding_id
                 WHERE ib.id = :id AND ib.client_id = :c AND ib.branch_id = :b
                 LIMIT 1 FOR UPDATE'
            );

            $updPack = $db->prepare(
                'UPDATE product_bindings
                 SET pack_size = :pack, units_per_pack = :upp
                 WHERE id = :id AND client_id = :c AND branch_id = :b'
            );

            $insL = $db->prepare(
                'INSERT INTO stock_adjustment_lines
                    (adjustment_id, client_id, branch_id, product_binding_id, batch_id,
                     product_name, batch_no, system_qty, system_unit_qty, physical_qty, physical_unit_qty,
                     difference_qty, difference_unit_qty, reason, created_at)
                 VALUES
                    (:aid, :c, :b, :pb, :bid, :pname, :bno, :sq, :su, :pq, :pu, :dq, :du, :reason, :now)'
            );

            $insAudit = $db->prepare(
                'INSERT INTO stock_adjustment_audit_log
                    (client_id, branch_id, adjustment_id, adjustment_no, adjustment_type,
                     product_binding_id, batch_id, product_name, batch_no,
                     system_unit_qty, physical_unit_qty, difference_unit_qty, reason,
                     user_id, user_name, user_role, ip_address, user_agent, created_at)
                 VALUES
                    (:c, :b, :aid, :no, :type, :pb, :bid, :pname, :bno, :su, :pu, :du, :reason,
                     :uid, :uname, :urole, :ip, :ua, :now)'
            );

            foreach ($changed as $line) {
                $batchId = (int) ($line['batch_id'] ?? 0);
                $batchStmt->execute(['id' => $batchId, 'c' => $clientId, 'b' => $branchId]);
                $batch = $batchStmt->fetch();
                if (!$batch) {
                    $errors[] = "Batch #{$batchId} not found.";
                    continue;
                }

                $upp = max(1, (int) ($batch['units_per_pack'] ?? 1));
                $bindingId = (int) $batch['product_binding_id'];
                $liveUnits = max(0, (int) $batch['quantity']);

                // Optional pack size edit — updates product_bindings so sales / purchase / reports see it.
                $packRaw = isset($line['pack_size']) ? trim((string) $line['pack_size']) : '';
                $lineUpp = isset($line['units_per_pack']) ? (int) $line['units_per_pack'] : 0;
                $packChangedFlag = !empty($line['pack_changed']);
                $newUpp = $lineUpp > 0 ? max(1, $lineUpp) : $upp;
                $curPack = trim((string) ($batch['binding_pack_size'] ?? ''));
                $packUpdated = false;
                if ($packChangedFlag) {
                    $newPack = $packRaw !== '' ? mb_substr(strtoupper($packRaw), 0, 80) : $curPack;
                    if ($newPack !== $curPack || $newUpp !== $upp) {
                        $updPack->execute([
                            'pack' => $newPack !== '' ? $newPack : null,
                            'upp' => $newUpp,
                            'id' => $bindingId,
                            'c' => $clientId,
                            'b' => $branchId,
                        ]);
                        $packUpdated = true;
                    }
                    // Use new upp for pack qty math even if a sibling line already updated the binding.
                    $upp = $newUpp;
                }

                // Pack counts under the NEW upp. When pack size changes, packs are kept and
                // units become packs × new U/Pk (e.g. 16 packs × 1x2 → 32 units for sales).
                $livePacksUnderNew = intdiv($liveUnits, max(1, $upp));
                if ($packChangedFlag && array_key_exists('physical_qty', $line)) {
                    $physPacks = max(0, (int) $line['physical_qty']);
                    $physUnits = $physPacks * $upp;
                } elseif (array_key_exists('physical_unit_qty', $line)) {
                    $physUnits = max(0, (int) $line['physical_unit_qty']);
                    $physPacks = array_key_exists('physical_qty', $line)
                        ? max(0, (int) $line['physical_qty'])
                        : intdiv($physUnits, $upp);
                } else {
                    $physPacks = max(0, (int) ($line['physical_qty'] ?? $livePacksUnderNew));
                    $physUnits = $physPacks * $upp;
                }

                $diffUnits = $physUnits - $liveUnits;
                $diffPacks = $physPacks - $livePacksUnderNew;
                if ($diffUnits === 0 && !$packUpdated) {
                    continue;
                }

                $reason = trim((string) ($line['reason'] ?? ''));
                if ($reason === '') {
                    $reason = $remarks;
                }
                if ($reason === '' && $packUpdated && $diffUnits === 0) {
                    $reason = 'Pack size correction';
                }
                $reason = mb_substr($reason, 0, 255);

                $insL->execute([
                    'aid' => $adjustmentId,
                    'c' => $clientId,
                    'b' => $branchId,
                    'pb' => $bindingId,
                    'bid' => $batchId,
                    'pname' => (string) $batch['product_name'],
                    'bno' => (string) $batch['batch_no'],
                    'sq' => (int) $livePacksUnderNew,
                    'su' => (int) $liveUnits,
                    'pq' => (int) $physPacks,
                    'pu' => (int) $physUnits,
                    'dq' => (int) $diffPacks,
                    'du' => (int) $diffUnits,
                    'reason' => $reason !== '' ? $reason : null,
                    'now' => $now,
                ]);
                $lineId = (int) $db->lastInsertId();
                $bindingIds[] = $bindingId;
                $note = $adjNo . ($reason !== '' ? ' — ' . $reason : '');

                if ($diffUnits !== 0) {
                    try {
                        if ($diffUnits > 0) {
                            $inv->addStock(
                                $clientId,
                                $branchId,
                                $bindingId,
                                (string) $batch['batch_no'],
                                $batch['expiry_date'] !== null ? (string) $batch['expiry_date'] : null,
                                (float) ($batch['mrp'] ?? 0),
                                (float) ($batch['purchase_rate'] ?? 0),
                                $diffUnits,
                                $batch['shelf_location'] !== null ? (string) $batch['shelf_location'] : null,
                                (float) ($batch['ptr'] ?? 0),
                                (float) ($batch['pts'] ?? 0),
                                'adjustment',
                                $adjDate,
                                'stock_adjustment',
                                $lineId,
                                $note
                            );
                        } else {
                            $outQty = abs($diffUnits);
                            if ($outQty > $liveUnits) {
                                $errors[] = (string) $batch['product_name'] . ' / ' . (string) $batch['batch_no']
                                    . ": cannot reduce below zero (have {$liveUnits}, need -{$outQty}).";
                                continue;
                            }
                            $inv->reduceBatch(
                                $batchId,
                                $outQty,
                                'adjustment',
                                $adjDate,
                                'stock_adjustment',
                                $lineId
                            );
                            $inv->bumpAggregate($clientId, $branchId, $bindingId, -$outQty);
                        }
                    } catch (\Throwable $e) {
                        $errors[] = (string) $batch['product_name'] . ' / ' . (string) $batch['batch_no']
                            . ': ' . $e->getMessage();
                        continue;
                    }
                }

                // Outside the try: an audit failure must roll back the whole adjustment.
                $insAudit->execute([
                    'c' => $clientId,
                    'b' => $branchId,
                    'aid' => $adjustmentId,
                    'no' => $adjNo,
                    'type' => $type,
                    'pb' => $bindingId,
                    'bid' => $batchId,
                    'pname' => (string) $batch['product_name'],
                    'bno' => (string) $batch['batch_no'],
                    'su' => $liveUnits,
                    'pu' => $physUnits,
                    'du' => $diffUnits,
                    'reason' => $reason,
                    'now' => $now,
                ] + $auditRow);
                $saved++;
            }

            if ($saved === 0 && $errors !== []) {
                throw HttpException::validation(['lines' => $errors]);
            }

            $db->prepare('UPDATE stock_adjustments SET total_items = :n WHERE id = :id')
                ->execute(['n' => $saved, 'id' => $adjustmentId]);
        });

        $inv->reconcileAggregates($clientId, $branchId, $bindingIds);

        return [
            'adjustment_no' => $adjNo,
            'lines_saved'   => $saved,
            'errors'        => $errors,
        ];
    }

    /**
     * Audit trail rows for the Stock Adjustment Audit Log report.
     *
     * @return list<array<string, mixed>>
     */
    public function auditLog(int $clientId, int $branchId, string $from, string $to): array
    {
        $this->ensureSchema();
        $params = ['c' => $clientId, 'from' => $from . ' 00:00:00', 'to' => $to . ' 23:59:59'];
        $where = 'client_id = :c AND created_at BETWEEN :from AND :to';
        if ($branchId > 0) {
            $where .= ' AND branch_id = :b';
            $params['b'] = $branchId;
        }
        $stmt = Connection::ops()->prepare(
            "SELECT created_at, adjustment_no, adjustment_type, product_name, batch_no,
                    system_unit_qty, physical_unit_qty, difference_unit_qty, reason,
                    user_name, user_role, ip_address
             FROM stock_adjustment_audit_log
             WHERE {$where}
             ORDER BY created_at DESC, id DESC
             LIMIT 5000"
        );
        $stmt->execute($params);

        $types = [
            'PHYSICAL_COUNT' => 'Physical count',
            'DAMAGE' => 'Damage',
            'EXPIRY' => 'Expiry write-off',
            'THEFT' => 'Theft',
            'OTHER' => 'Other',
        ];
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $ts = strtotime((string) $r['created_at']) ?: time();
            $out[] = [
                'date'            => date('Y-m-d', $ts),
                'time'            => date('h:i:s A', $ts),
                'adjustment_no'   => (string) $r['adjustment_no'],
                'adjustment_type' => $types[(string) $r['adjustment_type']] ?? (string) $r['adjustment_type'],
                'product_name'    => (string) $r['product_name'],
                'batch_no'        => (string) $r['batch_no'],
                'system_qty'      => (int) $r['system_unit_qty'],
                'physical_qty'    => (int) $r['physical_unit_qty'],
                'difference_qty'  => (int) $r['difference_unit_qty'],
                'reason'          => (string) $r['reason'],
                'user_name'       => (string) $r['user_name'],
                'ip_address'      => (string) $r['ip_address'],
            ];
        }
        return $out;
    }

    private function nextAdjustmentNo(int $clientId, int $branchId): string
    {
        $this->ensureSchema();
        $year = date('y');
        $like = 'ADJ/' . $branchId . '/' . $year . '/%';
        $stmt = Connection::ops()->prepare(
            "SELECT adjustment_no FROM stock_adjustments
             WHERE client_id = :c AND branch_id = :b AND adjustment_no LIKE :like
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(['c' => $clientId, 'b' => $branchId, 'like' => $like]);
        $last = $stmt->fetchColumn();
        $serial = 1;
        if (is_string($last) && $last !== '') {
            $parts = explode('/', $last);
            if (isset($parts[3]) && is_numeric($parts[3])) {
                $serial = (int) $parts[3] + 1;
            }
        }
        return 'ADJ/' . $branchId . '/' . $year . '/' . $serial;
    }
}
