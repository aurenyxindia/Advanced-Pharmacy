<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Database\Connection;
use PDO;

/**
 * Retail Sales Return — return qty against a retail bill, stock IN (sale_return).
 */
final class RetailSalesReturnService
{
    private InventoryService $inventory;

    public function __construct(?InventoryService $inventory = null)
    {
        $this->inventory = $inventory ?? new InventoryService();
    }

    public function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $db = Connection::ops();

        $this->addColumnIfMissing($db, 'sales', 'return_total', 'ADD COLUMN return_total DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER net_amount');
        $this->addColumnIfMissing($db, 'sale_items', 'return_qty', 'ADD COLUMN return_qty INT NOT NULL DEFAULT 0 AFTER quantity');
        $this->addColumnIfMissing($db, 'sale_items', 'return_amt', 'ADD COLUMN return_amt DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER return_qty');

        $db->exec(
            "CREATE TABLE IF NOT EXISTS retail_sale_returns (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                client_id BIGINT UNSIGNED NOT NULL,
                branch_id BIGINT UNSIGNED NOT NULL,
                return_no VARCHAR(40) NOT NULL,
                sale_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                sale_invoice_no VARCHAR(60) NULL,
                sale_invoice_date DATETIME NULL,
                customer_id BIGINT UNSIGNED NULL,
                customer_name VARCHAR(150) NULL,
                doctor_name VARCHAR(150) NULL,
                contact_no VARCHAR(20) NULL,
                email VARCHAR(150) NULL,
                return_total DECIMAL(12,2) NOT NULL DEFAULT 0,
                status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
                created_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_rsr_no (client_id, branch_id, return_no),
                KEY idx_rsr_tenant (client_id, branch_id),
                KEY idx_rsr_sale (sale_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $db->exec(
            "CREATE TABLE IF NOT EXISTS retail_sale_return_items (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                return_id BIGINT UNSIGNED NOT NULL,
                client_id BIGINT UNSIGNED NOT NULL,
                branch_id BIGINT UNSIGNED NOT NULL,
                sale_item_id BIGINT UNSIGNED NULL,
                product_binding_id BIGINT UNSIGNED NULL,
                batch_id BIGINT UNSIGNED NULL,
                product_name VARCHAR(200) NOT NULL,
                batch_no VARCHAR(60) NULL,
                pack_size VARCHAR(60) NULL,
                expiry_date DATE NULL,
                sale_qty INT NOT NULL DEFAULT 0,
                return_qty INT NOT NULL DEFAULT 0,
                return_amt DECIMAL(12,2) NOT NULL DEFAULT 0,
                mrp DECIMAL(12,2) NOT NULL DEFAULT 0,
                discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
                gst_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_rsri_return (return_id),
                KEY idx_rsri_tenant (client_id, branch_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // External / walk-in returns (no system sale bill).
        $this->addColumnIfMissing($db, 'retail_sale_returns', 'return_type', "ADD COLUMN return_type VARCHAR(20) NOT NULL DEFAULT 'bill' AFTER sale_id");
        $this->addColumnIfMissing($db, 'retail_sale_return_items', 'unit_rate', 'ADD COLUMN unit_rate DECIMAL(12,4) NOT NULL DEFAULT 0 AFTER mrp');
        $this->addColumnIfMissing($db, 'retail_sale_return_items', 'discount_amt', 'ADD COLUMN discount_amt DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER discount_pct');
    }

    private function addColumnIfMissing(PDO $db, string $table, string $col, string $alterSql): void
    {
        try {
            $r = $db->query("SHOW COLUMNS FROM {$table} LIKE " . $db->quote($col));
            if ($r && $r->fetch()) {
                return;
            }
            $db->exec("ALTER TABLE {$table} {$alterSql}");
        } catch (\Throwable) {
        }
    }

    /** @return list<array<string,mixed>> */
    public function listSalesForReturn(int $clientId, int $branchId, ?string $search, int $limit = 30): array
    {
        $this->ensureSchema();
        $limit = max(1, min(100, $limit));
        $params = ['c' => $clientId, 'b' => $branchId];
        $where = "s.client_id = :c AND s.branch_id = :b AND LOWER(COALESCE(s.bill_mode,'retail')) <> 'wholesale'";
        if ($search !== null && trim($search) !== '') {
            $where .= ' AND (s.invoice_no LIKE :q OR s.customer_name LIKE :q2 OR s.patient_name LIKE :q3 OR s.doctor_name LIKE :q4 OR s.contact_no LIKE :q5)';
            $like = '%' . trim($search) . '%';
            $params['q'] = $like;
            $params['q2'] = $like;
            $params['q3'] = $like;
            $params['q4'] = $like;
            $params['q5'] = $like;
        }
        $st = Connection::ops()->prepare(
            "SELECT s.id, s.invoice_no, s.invoice_date, s.customer_name, s.patient_name, s.doctor_name,
                    s.contact_no, s.net_amount, s.payment_mode, COALESCE(s.return_total,0) AS return_total
             FROM sales s
             WHERE {$where}
             ORDER BY s.id DESC
             LIMIT {$limit}"
        );
        $st->execute($params);
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $net = (float) $row['net_amount'];
            $ret = (float) $row['return_total'];
            $paid = strtolower((string) ($row['payment_mode'] ?? 'cash')) === 'credit' ? 0.0 : max(0, $net - $ret);
            $balance = strtolower((string) ($row['payment_mode'] ?? 'cash')) === 'credit' ? max(0, $net - $ret) : 0.0;
            $rows[] = [
                'id' => (int) $row['id'],
                'invoice_no' => (string) $row['invoice_no'],
                'order_no' => (string) $row['invoice_no'],
                'invoice_date' => $row['invoice_date'],
                'order_date' => $row['invoice_date'],
                'customer_name' => (string) ($row['customer_name'] ?: $row['patient_name'] ?: 'Walk-in'),
                'doctor_name' => (string) ($row['doctor_name'] ?? ''),
                'contact_no' => (string) ($row['contact_no'] ?? ''),
                'net_amount' => $net,
                'amount' => $net,
                'paid' => $paid,
                'balance' => $balance,
                'return_total' => $ret,
                'payment_mode' => (string) ($row['payment_mode'] ?? ''),
            ];
        }
        return $rows;
    }

    /** @return array<string,mixed> */
    public function saleDetails(int $clientId, int $branchId, int $saleId): array
    {
        $this->ensureSchema();
        $st = Connection::ops()->prepare(
            'SELECT * FROM sales WHERE id = :id AND client_id = :c AND branch_id = :b LIMIT 1'
        );
        $st->execute(['id' => $saleId, 'c' => $clientId, 'b' => $branchId]);
        $sale = $st->fetch(PDO::FETCH_ASSOC);
        if (!$sale) {
            throw HttpException::notFound('Sale bill not found.');
        }
        if (strtolower((string) ($sale['bill_mode'] ?? 'retail')) === 'wholesale') {
            throw HttpException::validation(['sale' => ['Use Wholesale Sales Return for wholesale bills.']]);
        }
        $items = Connection::ops()->prepare(
            'SELECT si.id, si.product_binding_id, si.batch_id, si.product_name, si.batch_no, si.pack AS pack_size, si.expiry_date,
                    si.quantity, COALESCE(si.return_qty,0) AS returned_qty, COALESCE(si.return_amt,0) AS returned_amt,
                    si.mrp, si.rate, si.discount_pct, si.gst_rate, si.line_total, si.total_amt,
                    COALESCE(pb.units_per_pack, 1) AS units_per_pack
             FROM sale_items si
             LEFT JOIN product_bindings pb ON pb.id = si.product_binding_id
             WHERE si.sale_id = :s ORDER BY si.id ASC'
        );
        $items->execute(['s' => $saleId]);
        $products = [];
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $it) {
            $qty = (int) $it['quantity'];
            $already = (int) $it['returned_qty'];
            $maxRet = max(0, $qty - $already);
            $upp = max(1, (int) $it['units_per_pack']);
            // sale_items.quantity and rate are per base unit (tablet); mrp is per pack.
            $unitRate = (float) $it['rate'] > 0 ? (float) $it['rate'] : round((float) $it['mrp'] / $upp, 4);
            $products[] = [
                'id' => (int) $it['id'],
                'sale_item_id' => (int) $it['id'],
                'product_binding_id' => (int) ($it['product_binding_id'] ?? 0),
                'batch_id' => (int) ($it['batch_id'] ?? 0),
                'p_name' => (string) $it['product_name'],
                'product_name' => (string) $it['product_name'],
                'batch_no' => (string) ($it['batch_no'] ?? ''),
                'pack_size' => (string) ($it['pack_size'] ?? ''),
                'exp_date' => $it['expiry_date'],
                'qty' => $qty,
                'sale_qty' => $qty,
                'returned_qty' => $already,
                'max_return_qty' => $maxRet,
                'this_return_qty' => 0,
                'mrp' => (float) $it['mrp'],
                'rate' => (float) $it['rate'],
                'unit_rate' => $unitRate,
                'units_per_pack' => $upp,
                'qty_unit' => $upp > 1 ? 'tab' : 'unit',
                'disc_per' => (float) $it['discount_pct'],
                'discount_pct' => (float) $it['discount_pct'],
                'gst' => (float) $it['gst_rate'],
                'gst_rate' => (float) $it['gst_rate'],
                'return_amt' => 0.0,
            ];
        }
        $net = (float) $sale['net_amount'];
        $ret = (float) ($sale['return_total'] ?? 0);
        $isCredit = strtolower((string) ($sale['payment_mode'] ?? '')) === 'credit';
        return [
            'id' => (int) $sale['id'],
            'invoice_no' => (string) $sale['invoice_no'],
            'order_no' => (string) $sale['invoice_no'],
            'invoice_date' => $sale['invoice_date'],
            'order_date' => $sale['invoice_date'],
            'customer_name' => (string) ($sale['customer_name'] ?: $sale['patient_name'] ?: 'Walk-in'),
            'doctor_name' => (string) ($sale['doctor_name'] ?? ''),
            'contact_no' => (string) ($sale['contact_no'] ?? ''),
            'email' => (string) ($sale['email'] ?? ''),
            'net_amount' => $net,
            'return_total' => $ret,
            'balance' => $isCredit ? max(0, $net - $ret) : 0.0,
            'payment_mode' => (string) ($sale['payment_mode'] ?? ''),
            'products' => $products,
        ];
    }

    /** @param array<string,mixed> $payload */
    public function save(int $clientId, int $branchId, ?int $userId, array $payload): array
    {
        $this->ensureSchema();
        $saleId = (int) ($payload['id'] ?? $payload['sale_id'] ?? 0);
        if ($saleId <= 0) {
            throw HttpException::validation(['sale' => ['Sale bill required.']]);
        }
        $detail = $this->saleDetails($clientId, $branchId, $saleId);
        $linesIn = $payload['products'] ?? [];
        if (!is_array($linesIn) || $linesIn === []) {
            throw HttpException::validation(['products' => ['Enter return quantity for at least one product.']]);
        }

        $byId = [];
        foreach ($detail['products'] as $p) {
            $byId[(int) $p['id']] = $p;
        }

        $returnLines = [];
        $loggedTotal = 0.0;
        foreach ($linesIn as $raw) {
            $itemId = (int) ($raw['id'] ?? $raw['sale_item_id'] ?? 0);
            $qty = (int) ($raw['this_return_qty'] ?? $raw['return_qty'] ?? 0);
            if ($qty <= 0 || !isset($byId[$itemId])) {
                continue;
            }
            $src = $byId[$itemId];
            if ($qty > (int) $src['max_return_qty']) {
                throw HttpException::validation([
                    'return_qty' => ["Return qty exceeds available for {$src['p_name']}."],
                ]);
            }
            $unit = (float) $src['unit_rate'];
            $disc = (float) $src['disc_per'];
            $amt = round($qty * $unit * (100 - $disc) / 100, 2);
            $returnLines[] = [
                'sale_item_id' => $itemId,
                'product_binding_id' => (int) $src['product_binding_id'],
                'batch_id' => (int) $src['batch_id'],
                'product_name' => (string) $src['p_name'],
                'batch_no' => (string) $src['batch_no'],
                'pack_size' => (string) $src['pack_size'],
                'expiry_date' => $src['exp_date'],
                'sale_qty' => (int) $src['qty'],
                'return_qty' => $qty,
                'return_amt' => $amt,
                'mrp' => (float) $src['mrp'],
                'units_per_pack' => (int) $src['units_per_pack'],
                'discount_pct' => $disc,
                'gst_rate' => (float) $src['gst_rate'],
                'already' => (int) $src['returned_qty'],
                'already_amt' => (float) ($src['returned_amt'] ?? 0),
            ];
            $loggedTotal += $amt;
        }
        if ($returnLines === []) {
            throw HttpException::validation(['products' => ['Enter return quantity for at least one product.']]);
        }
        // Return total is rounded to the rupee like the sale net, but never beyond what is left on the bill.
        $remaining = max(0.0, round((float) $detail['net_amount'] - (float) $detail['return_total'], 2));
        $loggedTotal = min(round(round($loggedTotal, 2), 0), $remaining);

        $db = Connection::ops();
        $db->beginTransaction();
        try {
            $returnNo = $this->nextReturnNo($clientId, $branchId);
            $ins = $db->prepare(
                'INSERT INTO retail_sale_returns
                    (client_id, branch_id, return_no, sale_id, sale_invoice_no, sale_invoice_date,
                     customer_id, customer_name, doctor_name, contact_no, email, return_total, status, created_by, created_at)
                 VALUES
                    (:c, :b, :rn, :sid, :inv, :idt, :cid, :cn, :dn, :ph, :em, :tot, :st, :by, :now)'
            );
            $ins->execute([
                'c' => $clientId,
                'b' => $branchId,
                'rn' => $returnNo,
                'sid' => $saleId,
                'inv' => $detail['invoice_no'],
                'idt' => $detail['invoice_date'],
                'cid' => null,
                'cn' => $detail['customer_name'],
                'dn' => $detail['doctor_name'],
                'ph' => $detail['contact_no'],
                'em' => $detail['email'],
                'tot' => round($loggedTotal, 2),
                'st' => 'ACTIVE',
                'by' => $userId,
                'now' => now(),
            ]);
            $returnId = (int) $db->lastInsertId();

            $updSale = $db->prepare(
                'UPDATE sales SET return_total = COALESCE(return_total,0) + :t WHERE id = :id AND client_id = :c AND branch_id = :b'
            );
            $updSale->execute(['t' => round($loggedTotal, 2), 'id' => $saleId, 'c' => $clientId, 'b' => $branchId]);

            $itemIns = $db->prepare(
                'INSERT INTO retail_sale_return_items
                    (return_id, client_id, branch_id, sale_item_id, product_binding_id, batch_id,
                     product_name, batch_no, pack_size, expiry_date, sale_qty, return_qty, return_amt,
                     mrp, discount_pct, gst_rate, created_at)
                 VALUES
                    (:rid, :c, :b, :si, :pb, :bi, :pn, :bn, :ps, :exp, :sq, :rq, :ra, :mrp, :disc, :gst, :now)'
            );
            $updItem = $db->prepare(
                'UPDATE sale_items SET return_qty = :rq, return_amt = :ra WHERE id = :id'
            );
            $batchStmt = $db->prepare(
                'SELECT mrp, purchase_rate, ptr, pts, expiry_date FROM inventory_batches
                 WHERE client_id = :c AND branch_id = :b AND product_binding_id = :p AND batch_no = :batch
                 LIMIT 1'
            );

            foreach ($returnLines as $line) {
                $itemIns->execute([
                    'rid' => $returnId,
                    'c' => $clientId,
                    'b' => $branchId,
                    'si' => $line['sale_item_id'],
                    'pb' => $line['product_binding_id'] ?: null,
                    'bi' => $line['batch_id'] ?: null,
                    'pn' => $line['product_name'],
                    'bn' => $line['batch_no'],
                    'ps' => $line['pack_size'],
                    'exp' => $line['expiry_date'],
                    'sq' => $line['sale_qty'],
                    'rq' => $line['return_qty'],
                    'ra' => $line['return_amt'],
                    'mrp' => $line['mrp'],
                    'disc' => $line['discount_pct'],
                    'gst' => $line['gst_rate'],
                    'now' => now(),
                ]);
                $itemRowId = (int) $db->lastInsertId();
                $updItem->execute([
                    'rq' => $line['already'] + $line['return_qty'],
                    'ra' => round($line['already_amt'] + $line['return_amt'], 2),
                    'id' => $line['sale_item_id'],
                ]);

                if ($line['product_binding_id'] > 0 && $line['return_qty'] > 0) {
                    $batchNo = $line['batch_no'] !== '' ? $line['batch_no'] : 'NA';
                    $batchStmt->execute([
                        'c' => $clientId, 'b' => $branchId,
                        'p' => $line['product_binding_id'], 'batch' => $batchNo,
                    ]);
                    $batch = $batchStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                    $batchStmt->closeCursor();
                    $this->inventory->addStock(
                        $clientId,
                        $branchId,
                        $line['product_binding_id'],
                        $batchNo,
                        $line['expiry_date'] ? substr((string) $line['expiry_date'], 0, 10) : ($batch['expiry_date'] ?? null),
                        $batch ? (float) $batch['mrp'] : round((float) $line['mrp'] / max(1, $line['units_per_pack']), 4),
                        $batch ? (float) $batch['purchase_rate'] : 0.0,
                        (int) $line['return_qty'],
                        null,
                        $batch ? (float) $batch['ptr'] : 0,
                        $batch ? (float) $batch['pts'] : 0,
                        'sale_return',
                        date('Y-m-d'),
                        'retail_sale_return',
                        $itemRowId,
                        'Retail sale return ' . $returnNo
                    );
                }
            }

            $db->commit();
            return ['id' => $returnId, 'return_no' => $returnNo, 'return_total' => round($loggedTotal, 2)];
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * External retail return — customer had no sale bill in this software.
     * Stock IN (sale_return) so stock reports show return_qty.
     *
     * @param array<string,mixed> $payload
     * @return array{id:int,return_no:string,return_total:float}
     */
    public function saveManual(int $clientId, int $branchId, ?int $userId, array $payload): array
    {
        $this->ensureSchema();
        $customer = trim((string) ($payload['customer_name'] ?? ''));
        if ($customer === '') {
            throw HttpException::validation(['customer_name' => ['Customer name is required.']]);
        }
        $billDateRaw = trim((string) ($payload['bill_date'] ?? $payload['sale_invoice_date'] ?? ''));
        if ($billDateRaw === '') {
            throw HttpException::validation(['bill_date' => ['Sale billing date is required.']]);
        }
        $billTs = strtotime($billDateRaw);
        if ($billTs === false) {
            throw HttpException::validation(['bill_date' => ['Invalid sale billing date.']]);
        }
        $billDate = date('Y-m-d H:i:s', $billTs);
        $contact = trim((string) ($payload['contact_no'] ?? ''));
        $doctor = trim((string) ($payload['doctor_name'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));
        $linesIn = $payload['products'] ?? [];
        if (!is_array($linesIn) || $linesIn === []) {
            throw HttpException::validation(['products' => ['Add at least one product to return.']]);
        }

        $returnLines = [];
        $loggedTotal = 0.0;
        foreach ($linesIn as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $bindingId = (int) ($raw['product_binding_id'] ?? $raw['binding_id'] ?? 0);
            $batchId = (int) ($raw['batch_id'] ?? 0);
            $batchNo = trim((string) ($raw['batch_no'] ?? ''));
            $productName = trim((string) ($raw['product_name'] ?? $raw['p_name'] ?? ''));
            $qty = (int) ($raw['this_return_qty'] ?? $raw['return_qty'] ?? 0);
            $unitRate = (float) ($raw['unit_rate'] ?? $raw['rate'] ?? $raw['sale_rate'] ?? 0);
            $mrp = (float) ($raw['mrp'] ?? 0);
            if ($mrp <= 0 && $unitRate > 0) {
                $mrp = $unitRate;
            }
            if ($unitRate <= 0 && $mrp > 0) {
                $unitRate = $mrp;
            }
            $discPct = (float) ($raw['disc_per'] ?? $raw['discount_pct'] ?? 0);
            $discAmt = (float) ($raw['discount_amt'] ?? $raw['disc_amt'] ?? 0);
            if ($qty <= 0 || $bindingId <= 0 || $productName === '' || $batchNo === '') {
                continue;
            }
            if ($unitRate <= 0) {
                throw HttpException::validation([
                    'unit_rate' => ["Enter sale rate / MRP for {$productName}."],
                ]);
            }
            $discPct = max(0.0, min(100.0, $discPct));
            $gross = round($qty * $unitRate, 2);
            if ($discAmt <= 0 && $discPct > 0) {
                $discAmt = round($gross * $discPct / 100, 2);
            } elseif ($discPct <= 0 && $discAmt > 0 && $gross > 0) {
                $discPct = round(min(100.0, $discAmt / $gross * 100), 2);
            }
            $discAmt = min($gross, max(0.0, $discAmt));
            $amt = round($gross - $discAmt, 2);
            $returnLines[] = [
                'product_binding_id' => $bindingId,
                'batch_id' => $batchId,
                'product_name' => $productName,
                'batch_no' => $batchNo,
                'pack_size' => (string) ($raw['pack_size'] ?? ''),
                'expiry_date' => $raw['expiry_date'] ?? $raw['exp_date'] ?? null,
                'sale_qty' => $qty,
                'return_qty' => $qty,
                'return_amt' => $amt,
                'mrp' => $mrp,
                'unit_rate' => $unitRate,
                'discount_pct' => $discPct,
                'discount_amt' => $discAmt,
                'gst_rate' => (float) ($raw['gst_rate'] ?? $raw['gst'] ?? 0),
                'units_per_pack' => max(1, (int) ($raw['units_per_pack'] ?? 1)),
            ];
            $loggedTotal += $amt;
        }
        if ($returnLines === []) {
            throw HttpException::validation(['products' => ['Add at least one valid product line.']]);
        }
        $loggedTotal = round($loggedTotal, 2);

        $db = Connection::ops();
        $db->beginTransaction();
        try {
            $returnNo = $this->nextReturnNo($clientId, $branchId);
            $ins = $db->prepare(
                'INSERT INTO retail_sale_returns
                    (client_id, branch_id, return_no, sale_id, return_type, sale_invoice_no, sale_invoice_date,
                     customer_id, customer_name, doctor_name, contact_no, email, return_total, status, created_by, created_at)
                 VALUES
                    (:c, :b, :rn, 0, :rtype, :inv, :idt, NULL, :cn, :dn, :ph, :em, :tot, :st, :by, :now)'
            );
            $ins->execute([
                'c' => $clientId,
                'b' => $branchId,
                'rn' => $returnNo,
                'rtype' => 'manual',
                'inv' => 'EXTERNAL',
                'idt' => $billDate,
                'cn' => $customer,
                'dn' => $doctor,
                'ph' => $contact,
                'em' => $email,
                'tot' => $loggedTotal,
                'st' => 'ACTIVE',
                'by' => $userId,
                'now' => now(),
            ]);
            $returnId = (int) $db->lastInsertId();

            $itemIns = $db->prepare(
                'INSERT INTO retail_sale_return_items
                    (return_id, client_id, branch_id, sale_item_id, product_binding_id, batch_id,
                     product_name, batch_no, pack_size, expiry_date, sale_qty, return_qty, return_amt,
                     mrp, unit_rate, discount_pct, discount_amt, gst_rate, created_at)
                 VALUES
                    (:rid, :c, :b, NULL, :pb, :bi, :pn, :bn, :ps, :exp, :sq, :rq, :ra, :mrp, :urate, :disc, :damt, :gst, :now)'
            );
            $batchStmt = $db->prepare(
                'SELECT id, mrp, purchase_rate, ptr, pts, expiry_date, quantity FROM inventory_batches
                 WHERE client_id = :c AND branch_id = :b AND product_binding_id = :p AND batch_no = :batch
                 LIMIT 1'
            );

            foreach ($returnLines as $line) {
                $batchStmt->execute([
                    'c' => $clientId, 'b' => $branchId,
                    'p' => $line['product_binding_id'], 'batch' => $line['batch_no'],
                ]);
                $batch = $batchStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                $batchStmt->closeCursor();
                $batchId = $line['batch_id'] > 0 ? $line['batch_id'] : (int) ($batch['id'] ?? 0);
                $expiry = $line['expiry_date']
                    ? substr((string) $line['expiry_date'], 0, 10)
                    : ($batch['expiry_date'] ?? null);

                $itemIns->execute([
                    'rid' => $returnId,
                    'c' => $clientId,
                    'b' => $branchId,
                    'pb' => $line['product_binding_id'],
                    'bi' => $batchId ?: null,
                    'pn' => $line['product_name'],
                    'bn' => $line['batch_no'],
                    'ps' => $line['pack_size'],
                    'exp' => $expiry,
                    'sq' => $line['sale_qty'],
                    'rq' => $line['return_qty'],
                    'ra' => $line['return_amt'],
                    'mrp' => $line['mrp'],
                    'urate' => $line['unit_rate'],
                    'disc' => $line['discount_pct'],
                    'damt' => $line['discount_amt'],
                    'gst' => $line['gst_rate'],
                    'now' => now(),
                ]);
                $itemRowId = (int) $db->lastInsertId();

                // Stock IN — same as bill-linked retail return (shows under return_qty in stock reports).
                $this->inventory->addStock(
                    $clientId,
                    $branchId,
                    $line['product_binding_id'],
                    $line['batch_no'],
                    $expiry,
                    $batch ? (float) $batch['mrp'] : round((float) $line['mrp'] / max(1, $line['units_per_pack']), 4),
                    $batch ? (float) $batch['purchase_rate'] : 0.0,
                    (int) $line['return_qty'],
                    null,
                    $batch ? (float) $batch['ptr'] : 0,
                    $batch ? (float) $batch['pts'] : 0,
                    'sale_return',
                    date('Y-m-d'),
                    'retail_sale_return',
                    $itemRowId,
                    'External retail sale return ' . $returnNo
                );
            }

            $db->commit();
            return ['id' => $returnId, 'return_no' => $returnNo, 'return_total' => $loggedTotal];
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /** @return list<array<string,mixed>> */
    public function history(int $clientId, int $branchId, ?string $search, int $limit = 200): array
    {
        $this->ensureSchema();
        $limit = max(1, min(500, $limit));
        $params = ['c' => $clientId, 'b' => $branchId];
        $where = 'client_id = :c AND branch_id = :b';
        if ($search !== null && trim($search) !== '') {
            $where .= ' AND (return_no LIKE :q OR sale_invoice_no LIKE :q2 OR customer_name LIKE :q3 OR doctor_name LIKE :q4 OR contact_no LIKE :q5)';
            $like = '%' . trim($search) . '%';
            $params['q'] = $like;
            $params['q2'] = $like;
            $params['q3'] = $like;
            $params['q4'] = $like;
            $params['q5'] = $like;
        }
        $st = Connection::ops()->prepare(
            "SELECT * FROM retail_sale_returns WHERE {$where} ORDER BY id DESC LIMIT {$limit}"
        );
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rtype = (string) ($row['return_type'] ?? 'bill');
            $inv = (string) ($row['sale_invoice_no'] ?? '');
            $out[] = [
                'id' => (int) $row['id'],
                'return_no' => (string) $row['return_no'],
                'sale_id' => (int) $row['sale_id'],
                'return_type' => $rtype,
                'sale_invoice_no' => $rtype === 'manual' ? 'EXTERNAL' : $inv,
                'sale_order_no' => $rtype === 'manual' ? 'EXTERNAL' : $inv,
                'sale_invoice_date' => $row['sale_invoice_date'],
                'customer_name' => (string) ($row['customer_name'] ?? ''),
                'doctor_name' => (string) ($row['doctor_name'] ?? ''),
                'contact_no' => (string) ($row['contact_no'] ?? ''),
                'return_total' => (float) $row['return_total'],
                'status' => (string) ($row['status'] ?? 'ACTIVE'),
                'created_at' => $row['created_at'],
            ];
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public function historyDetails(int $clientId, int $branchId, int $id): array
    {
        $this->ensureSchema();
        $st = Connection::ops()->prepare(
            'SELECT * FROM retail_sale_returns WHERE id = :id AND client_id = :c AND branch_id = :b LIMIT 1'
        );
        $st->execute(['id' => $id, 'c' => $clientId, 'b' => $branchId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw HttpException::notFound('Return not found.');
        }
        $items = Connection::ops()->prepare(
            'SELECT * FROM retail_sale_return_items WHERE return_id = :r ORDER BY id ASC'
        );
        $items->execute(['r' => $id]);
        $products = [];
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $it) {
            $products[] = [
                'p_name' => (string) $it['product_name'],
                'batch_no' => (string) ($it['batch_no'] ?? ''),
                'pack_size' => (string) ($it['pack_size'] ?? ''),
                'exp_date' => $it['expiry_date'],
                'sale_qty' => (int) $it['sale_qty'],
                'return_qty' => (int) $it['return_qty'],
                'return_amt' => (float) $it['return_amt'],
                'mrp' => (float) $it['mrp'],
                'unit_rate' => (float) ($it['unit_rate'] ?? 0),
                'disc_per' => (float) $it['discount_pct'],
                'discount_pct' => (float) $it['discount_pct'],
                'discount_amt' => (float) ($it['discount_amt'] ?? 0),
            ];
        }
        $rtype = (string) ($row['return_type'] ?? 'bill');
        $inv = (string) ($row['sale_invoice_no'] ?? '');
        return [
            'id' => (int) $row['id'],
            'return_no' => (string) $row['return_no'],
            'return_type' => $rtype,
            'sale_invoice_no' => $rtype === 'manual' ? 'EXTERNAL' : $inv,
            'sale_invoice_date' => $row['sale_invoice_date'],
            'customer_name' => (string) ($row['customer_name'] ?? ''),
            'doctor_name' => (string) ($row['doctor_name'] ?? ''),
            'contact_no' => (string) ($row['contact_no'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'return_total' => (float) $row['return_total'],
            'status' => (string) ($row['status'] ?? 'ACTIVE'),
            'created_at' => $row['created_at'],
            'created_by' => $row['created_by'] ?? null,
            'products' => $products,
        ];
    }

    private function nextReturnNo(int $clientId, int $branchId): string
    {
        $yy = date('y');
        $prefix = "RR/{$clientId}/{$yy}/";
        $st = Connection::ops()->prepare(
            "SELECT return_no FROM retail_sale_returns
             WHERE client_id = :c AND branch_id = :b AND return_no LIKE :p
             ORDER BY id DESC LIMIT 1"
        );
        $st->execute(['c' => $clientId, 'b' => $branchId, 'p' => $prefix . '%']);
        $last = (string) ($st->fetchColumn() ?: '');
        $seq = 1;
        if ($last !== '' && preg_match('#/(\d+)$#', $last, $m)) {
            $seq = (int) $m[1] + 1;
        }
        return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
