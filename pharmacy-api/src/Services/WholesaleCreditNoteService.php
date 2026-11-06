<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Database\Connection;
use PDO;

/**
 * Wholesale Sales Return / Credit Note (Retail wSalesReturn).
 * Stock IN on save; Open until applied on wholesale sale → Cleared.
 */
final class WholesaleCreditNoteService
{
    private InventoryService $inventory;
    private ReturnDeductionService $deductions;

    public function __construct(?InventoryService $inventory = null, ?ReturnDeductionService $deductions = null)
    {
        $this->inventory = $inventory ?? new InventoryService();
        $this->deductions = $deductions ?? new ReturnDeductionService();
    }

    public function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $db = Connection::ops();
        $cols = [];
        $r = $db->query('SHOW COLUMNS FROM credit_notes');
        if ($r) {
            foreach ($r->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $cols[strtolower((string) $row['Field'])] = true;
            }
        }
        $alters = [
            'taxable' => 'ADD COLUMN taxable DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER gst_amount',
            'deduction_status' => "ADD COLUMN deduction_status VARCHAR(20) NOT NULL DEFAULT 'Open' AFTER reason",
            'applied_sale_no' => 'ADD COLUMN applied_sale_no VARCHAR(60) NULL AFTER deduction_status',
            'applied_on' => 'ADD COLUMN applied_on DATETIME NULL AFTER applied_sale_no',
            'customer_id' => 'ADD COLUMN customer_id BIGINT UNSIGNED NULL AFTER party_name',
            'party_phone' => 'ADD COLUMN party_phone VARCHAR(20) NULL AFTER customer_id',
            'party_email' => 'ADD COLUMN party_email VARCHAR(150) NULL AFTER party_phone',
            'party_address' => 'ADD COLUMN party_address VARCHAR(255) NULL AFTER party_email',
            'party_gstin' => 'ADD COLUMN party_gstin VARCHAR(20) NULL AFTER party_address',
            'party_state' => 'ADD COLUMN party_state VARCHAR(80) NULL AFTER party_gstin',
        ];
        foreach ($alters as $col => $sql) {
            if (!isset($cols[$col])) {
                try {
                    $db->exec("ALTER TABLE credit_notes {$sql}");
                } catch (\Throwable) {
                }
            }
        }

        $this->addColumnIfMissing($db, 'sales', 'credit_note_no', 'ADD COLUMN credit_note_no VARCHAR(40) NULL AFTER payment_mode');
        $this->addColumnIfMissing($db, 'sales', 'credit_deduction_amt', 'ADD COLUMN credit_deduction_amt DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER credit_note_no');
        $this->addColumnIfMissing($db, 'sale_items', 'return_qty', 'ADD COLUMN return_qty INT NOT NULL DEFAULT 0 AFTER quantity');

        $db->exec(
            "CREATE TABLE IF NOT EXISTS credit_note_items (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                credit_note_id BIGINT UNSIGNED NOT NULL,
                client_id BIGINT UNSIGNED NOT NULL,
                branch_id BIGINT UNSIGNED NOT NULL,
                sale_item_id BIGINT UNSIGNED NULL,
                product_binding_id BIGINT UNSIGNED NULL,
                batch_id BIGINT UNSIGNED NULL,
                product_name VARCHAR(200) NOT NULL,
                batch_no VARCHAR(60) NULL,
                hsn VARCHAR(20) NULL,
                pack_size VARCHAR(60) NULL,
                expiry_date DATE NULL,
                sale_qty INT NOT NULL DEFAULT 0,
                return_qty INT NOT NULL DEFAULT 0,
                sale_rate DECIMAL(12,2) NOT NULL DEFAULT 0,
                gst_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
                deduction_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
                taxable DECIMAL(12,2) NOT NULL DEFAULT 0,
                tax DECIMAL(12,2) NOT NULL DEFAULT 0,
                cgst DECIMAL(12,2) NOT NULL DEFAULT 0,
                sgst DECIMAL(12,2) NOT NULL DEFAULT 0,
                igst DECIMAL(12,2) NOT NULL DEFAULT 0,
                net_total DECIMAL(12,2) NOT NULL DEFAULT 0,
                reason VARCHAR(80) NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_cni_note (credit_note_id),
                KEY idx_cni_tenant (client_id, branch_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        try {
            $db->exec("UPDATE credit_notes SET deduction_status='Cleared' WHERE deduction_status='Applied'");
            $db->exec("UPDATE credit_notes SET deduction_status='Open' WHERE deduction_status IS NULL OR deduction_status=''");
        } catch (\Throwable) {
        }

        $this->deductions->ensureSchema();
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
    public function listNotes(int $clientId, int $branchId, ?string $search = null): array
    {
        $this->ensureSchema();
        $params = ['c' => $clientId, 'b' => $branchId];
        $where = 'client_id = :c AND branch_id = :b';
        if ($search !== null && trim($search) !== '') {
            $where .= ' AND (note_no LIKE :q OR party_name LIKE :q2 OR party_phone LIKE :q3)';
            $like = '%' . trim($search) . '%';
            $params['q'] = $like;
            $params['q2'] = $like;
            $params['q3'] = $like;
        }
        $st = Connection::ops()->prepare(
            "SELECT * FROM credit_notes WHERE {$where} ORDER BY note_date DESC, id DESC LIMIT 500"
        );
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = $this->mapNote($row, false);
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public function getNote(int $clientId, int $branchId, int $id): ?array
    {
        $this->ensureSchema();
        $st = Connection::ops()->prepare(
            'SELECT * FROM credit_notes WHERE id = :id AND client_id = :c AND branch_id = :b LIMIT 1'
        );
        $st->execute(['id' => $id, 'c' => $clientId, 'b' => $branchId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $note = $this->mapNote($row, true);
        $items = Connection::ops()->prepare(
            'SELECT * FROM credit_note_items WHERE credit_note_id = :n ORDER BY id ASC'
        );
        $items->execute(['n' => $id]);
        $products = [];
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $products[] = [
                'p_name' => (string) $p['product_name'],
                'product_name' => (string) $p['product_name'],
                'batch_no' => (string) ($p['batch_no'] ?? ''),
                'saleQty' => (int) $p['sale_qty'],
                'sale_rate' => (float) $p['sale_rate'],
                'gst' => (float) $p['gst_rate'],
                'gst_per' => (float) $p['gst_rate'],
                'exp_date' => $p['expiry_date'],
                'diduction' => (float) $p['deduction_pct'],
                'deduction_pct' => (float) $p['deduction_pct'],
                'return_qty' => (int) $p['return_qty'],
                'reason' => (string) ($p['reason'] ?? ''),
                'taxable' => (float) $p['taxable'],
                'tax' => (float) $p['tax'],
                'cgst' => (float) $p['cgst'],
                'sgst' => (float) $p['sgst'],
                'igst' => (float) $p['igst'],
                'netTotal' => (float) $p['net_total'],
                'hsn' => (string) ($p['hsn'] ?? ''),
                'pack_size' => (string) ($p['pack_size'] ?? ''),
                'sale_item_id' => (int) ($p['sale_item_id'] ?? 0),
                'product_binding_id' => (int) ($p['product_binding_id'] ?? 0),
                'batch_id' => (int) ($p['batch_id'] ?? 0),
            ];
        }
        $note['products'] = $products;
        return $note;
    }

    /** Customers who have wholesale sales. */
    /** @return list<array{value:string,label:string,customer_id:?int,phone:string,email:string,address:string,gstin:string,state:string}> */
    public function customers(int $clientId, int $branchId): array
    {
        $this->ensureSchema();
        $st = Connection::ops()->prepare(
            "SELECT DISTINCT TRIM(COALESCE(NULLIF(s.customer_name,''), NULLIF(s.patient_name,''), 'Walk-in')) AS name,
                    MAX(s.customer_id) AS customer_id,
                    MAX(s.contact_no) AS phone,
                    MAX(s.email) AS email,
                    MAX(s.address) AS address
             FROM sales s
             WHERE s.client_id = :c AND s.branch_id = :b
               AND LOWER(COALESCE(s.bill_mode,'retail')) = 'wholesale'
             GROUP BY name
             ORDER BY name ASC
             LIMIT 5000"
        );
        $st->execute(['c' => $clientId, 'b' => $branchId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $meta = $this->customerMeta($clientId, (int) ($row['customer_id'] ?? 0), $name);
            $out[] = array_merge($meta, [
                'value' => $name,
                'label' => $name,
                'customer_name' => $name,
            ]);
        }
        return $out;
    }

    /** @return list<array{value:string,label:string}> */
    public function productsForCustomer(int $clientId, int $branchId, string $customerName): array
    {
        $customerName = trim($customerName);
        if ($customerName === '') {
            return [];
        }
        $st = Connection::ops()->prepare(
            "SELECT DISTINCT TRIM(si.product_name) AS name
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             WHERE s.client_id = :c AND s.branch_id = :b
               AND LOWER(COALESCE(s.bill_mode,'retail')) = 'wholesale'
               AND LOWER(TRIM(COALESCE(NULLIF(s.customer_name,''), NULLIF(s.patient_name,''), ''))) = LOWER(TRIM(:n))
               AND TRIM(COALESCE(si.product_name,'')) <> ''
             ORDER BY name ASC
             LIMIT 5000"
        );
        $st->execute(['c' => $clientId, 'b' => $branchId, 'n' => $customerName]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $n = trim((string) $row['name']);
            if ($n !== '') {
                $out[] = ['value' => $n, 'label' => $n];
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function batchesForCustomer(int $clientId, int $branchId, string $customerName, string $productName): array
    {
        $customerName = trim($customerName);
        $productName = trim($productName);
        if ($customerName === '' || $productName === '') {
            return [];
        }
        $st = Connection::ops()->prepare(
            "SELECT si.id AS sale_item_id, si.product_binding_id, si.batch_id, si.batch_no, si.expiry_date,
                    si.quantity AS sale_qty, COALESCE(si.return_qty,0) AS returned_qty,
                    si.rate AS sale_rate, si.mrp, si.gst_rate, si.hsn_code AS hsn, si.pack AS pack_size,
                    s.invoice_no
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             WHERE s.client_id = :c AND s.branch_id = :b
               AND LOWER(COALESCE(s.bill_mode,'retail')) = 'wholesale'
               AND LOWER(TRIM(COALESCE(NULLIF(s.customer_name,''), NULLIF(s.patient_name,''), ''))) = LOWER(TRIM(:cust))
               AND LOWER(TRIM(si.product_name)) = LOWER(TRIM(:pn))
             ORDER BY s.invoice_date DESC, si.id DESC
             LIMIT 200"
        );
        $st->execute(['c' => $clientId, 'b' => $branchId, 'cust' => $customerName, 'pn' => $productName]);
        $out = [];
        $seen = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $batch = trim((string) ($row['batch_no'] ?? ''));
            $key = mb_strtolower($batch) . '|' . (int) $row['sale_item_id'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $saleQty = (int) $row['sale_qty'];
            $returned = (int) $row['returned_qty'];
            $avail = max(0, $saleQty - $returned);
            if ($avail <= 0) {
                continue;
            }
            $exp = $row['expiry_date'];
            $ded = $this->deductions->deductionForExpiry($clientId, $exp ? (string) $exp : null);
            $out[] = [
                'sale_item_id' => (int) $row['sale_item_id'],
                'id' => (int) $row['sale_item_id'],
                'product_binding_id' => (int) ($row['product_binding_id'] ?? 0),
                'batch_id' => (int) ($row['batch_id'] ?? 0),
                'batch_no' => $batch,
                'label' => $batch !== '' ? $batch : '(no batch)',
                'value' => $batch,
                'saleQty' => $avail,
                'sale_qty' => $avail,
                'exp_date' => $exp,
                'expiry' => $exp,
                'sale_rate' => (float) $row['sale_rate'],
                'mrp' => (float) $row['mrp'],
                'gst' => (float) $row['gst_rate'],
                'gst_per' => (float) $row['gst_rate'],
                'hsn' => (string) ($row['hsn'] ?? ''),
                'pack_size' => (string) ($row['pack_size'] ?? ''),
                'invoice_no' => (string) ($row['invoice_no'] ?? ''),
                'diduction' => $ded,
                'deduction_pct' => $ded,
            ];
        }
        return $out;
    }

    /** @return list<array{note_no:string,label:string,amount:float}> */
    public function openNotesForParty(int $clientId, int $branchId, string $party): array
    {
        $this->ensureSchema();
        $party = trim($party);
        if ($party === '') {
            return [];
        }
        $st = Connection::ops()->prepare(
            "SELECT note_no, amount, gst_amount, taxable
             FROM credit_notes
             WHERE client_id = :c AND branch_id = :b
               AND LOWER(TRIM(party_name)) = LOWER(TRIM(:p))
               AND (deduction_status IS NULL OR deduction_status = '' OR deduction_status = 'Open')
             ORDER BY note_date ASC, id ASC"
        );
        $st->execute(['c' => $clientId, 'b' => $branchId, 'p' => $party]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $amt = (float) $row['amount'];
            $no = (string) $row['note_no'];
            $out[] = [
                'note_no' => $no,
                'label' => $no . ' · ₹' . number_format($amt, 2, '.', ''),
                'amount' => $amt,
            ];
        }
        return $out;
    }

    public function applyToSale(int $clientId, int $branchId, string $noteNo, string $saleInvoiceNo): void
    {
        $this->ensureSchema();
        $noteNo = trim($noteNo);
        if ($noteNo === '') {
            return;
        }
        $st = Connection::ops()->prepare(
            "UPDATE credit_notes
             SET deduction_status = 'Cleared', applied_sale_no = :inv, applied_on = :now, updated_at = :now2
             WHERE client_id = :c AND branch_id = :b AND note_no = :n
               AND (deduction_status IS NULL OR deduction_status = '' OR deduction_status = 'Open')"
        );
        $st->execute([
            'inv' => $saleInvoiceNo,
            'now' => now(),
            'now2' => now(),
            'c' => $clientId,
            'b' => $branchId,
            'n' => $noteNo,
        ]);
    }

    /** @param array<string,mixed> $payload */
    public function saveReturn(int $clientId, int $branchId, ?int $userId, array $payload): array
    {
        $this->ensureSchema();
        $products = $payload['products'] ?? [];
        if (!is_array($products) || $products === []) {
            throw HttpException::validation(['products' => ['Add at least one return line.']]);
        }
        $party = trim((string) ($payload['customer_name'] ?? $payload['party_name'] ?? $payload['custName'] ?? ''));
        if ($party === '') {
            throw HttpException::validation(['customer_name' => ['Customer is required.']]);
        }
        $partyState = trim((string) ($payload['state'] ?? $payload['party_state'] ?? ''));
        $pharmacyState = (new ReturnNoteService())->pharmacyState($clientId, $branchId);

        $lines = [];
        $taxableSum = 0.0;
        $taxSum = 0.0;
        $netSum = 0.0;
        foreach ($products as $raw) {
            $line = $this->normalizeLine($raw, $pharmacyState, $partyState);
            if ($line['return_qty'] <= 0) {
                continue;
            }
            $lines[] = $line;
            $taxableSum += $line['taxable'];
            $taxSum += $line['tax'];
            $netSum += $line['net_total'];
        }
        if ($lines === []) {
            throw HttpException::validation(['products' => ['Enter return qty on at least one line.']]);
        }

        $db = Connection::ops();
        $db->beginTransaction();
        try {
            $noteNo = $this->nextNoteNo($clientId, $branchId);
            $ins = $db->prepare(
                'INSERT INTO credit_notes
                    (client_id, branch_id, note_no, party_name, customer_id, against_invoice, amount, gst_amount, taxable,
                     reason, note_date, deduction_status, party_phone, party_email, party_address, party_gstin, party_state,
                     created_by, created_at, updated_at)
                 VALUES
                    (:c, :b, :nn, :pn, :cid, :ai, :amt, :gst, :taxable, :reason, :nd, :st,
                     :ph, :em, :ad, :gstin, :pstate, :by, :now, :now2)'
            );
            $ins->execute([
                'c' => $clientId,
                'b' => $branchId,
                'nn' => $noteNo,
                'pn' => $party,
                'cid' => (int) ($payload['customer_id'] ?? $payload['d_no'] ?? 0) ?: null,
                'ai' => trim((string) ($payload['against_invoice'] ?? '')),
                'amt' => round($netSum, 2),
                'gst' => round($taxSum, 2),
                'taxable' => round($taxableSum, 2),
                'reason' => 'Wholesale sales return',
                'nd' => substr((string) ($payload['note_date'] ?? date('Y-m-d')), 0, 10),
                'st' => 'Open',
                'ph' => trim((string) ($payload['c_cont'] ?? $payload['party_phone'] ?? '')),
                'em' => trim((string) ($payload['c_mail'] ?? $payload['party_email'] ?? '')),
                'ad' => trim((string) ($payload['c_add'] ?? $payload['party_address'] ?? '')),
                'gstin' => trim((string) ($payload['gst_no'] ?? $payload['party_gstin'] ?? '')),
                'pstate' => $partyState,
                'by' => $userId,
                'now' => now(),
                'now2' => now(),
            ]);
            $noteId = (int) $db->lastInsertId();
            $this->insertLinesAndStock($clientId, $branchId, $noteId, $noteNo, $lines);
            $db->commit();
            return ['id' => $noteId, 'note_no' => $noteNo];
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /** @param array<string,mixed> $payload */
    public function updateReturn(int $clientId, int $branchId, int $id, array $payload): array
    {
        $this->ensureSchema();
        $existing = $this->getNote($clientId, $branchId, $id);
        if (!$existing) {
            throw HttpException::notFound('Credit note not found.');
        }
        if (($existing['deduction_status'] ?? 'Open') === 'Cleared') {
            throw HttpException::validation(['note' => ['Cleared credit notes cannot be edited.']]);
        }
        $products = $payload['products'] ?? [];
        if (!is_array($products) || $products === []) {
            throw HttpException::validation(['products' => ['Add at least one return line.']]);
        }
        $party = trim((string) ($payload['customer_name'] ?? $payload['party_name'] ?? $existing['party_name']));
        $partyState = trim((string) ($payload['state'] ?? $payload['party_state'] ?? $existing['state'] ?? ''));
        $pharmacyState = (new ReturnNoteService())->pharmacyState($clientId, $branchId);

        $lines = [];
        $taxableSum = 0.0;
        $taxSum = 0.0;
        $netSum = 0.0;
        foreach ($products as $raw) {
            $line = $this->normalizeLine($raw, $pharmacyState, $partyState);
            if ($line['return_qty'] <= 0) {
                continue;
            }
            $lines[] = $line;
            $taxableSum += $line['taxable'];
            $taxSum += $line['tax'];
            $netSum += $line['net_total'];
        }
        if ($lines === []) {
            throw HttpException::validation(['products' => ['Enter return qty on at least one line.']]);
        }

        $db = Connection::ops();
        $db->beginTransaction();
        try {
            // Reverse previous stock IN (qty_out via purchase_return style — use reduceStock if batch known)
            $oldItems = Connection::ops()->prepare(
                'SELECT * FROM credit_note_items WHERE credit_note_id = :n'
            );
            $oldItems->execute(['n' => $id]);
            foreach ($oldItems->fetchAll(PDO::FETCH_ASSOC) as $old) {
                $qty = (int) $old['return_qty'];
                $batchId = (int) ($old['batch_id'] ?? 0);
                if ($qty > 0 && $batchId > 0) {
                    $this->inventory->reduceBatch(
                        $batchId,
                        $qty,
                        'sale',
                        date('Y-m-d'),
                        'credit_note_edit',
                        $id
                    );
                    $bindingId = (int) ($old['product_binding_id'] ?? 0);
                    if ($bindingId > 0) {
                        $this->inventory->bumpAggregate($clientId, $branchId, $bindingId, -$qty);
                    }
                }
                $saleItemId = (int) ($old['sale_item_id'] ?? 0);
                if ($saleItemId > 0) {
                    $db->prepare(
                        'UPDATE sale_items SET return_qty = GREATEST(0, COALESCE(return_qty,0) - :q) WHERE id = :id'
                    )->execute(['q' => $qty, 'id' => $saleItemId]);
                }
            }
            $db->prepare('DELETE FROM credit_note_items WHERE credit_note_id = :n')->execute(['n' => $id]);

            $upd = $db->prepare(
                'UPDATE credit_notes SET party_name = :pn, amount = :amt, gst_amount = :gst, taxable = :taxable,
                    party_phone = :ph, party_email = :em, party_address = :ad, party_gstin = :gstin, party_state = :pstate,
                    updated_at = :now
                 WHERE id = :id AND client_id = :c AND branch_id = :b'
            );
            $upd->execute([
                'pn' => $party,
                'amt' => round($netSum, 2),
                'gst' => round($taxSum, 2),
                'taxable' => round($taxableSum, 2),
                'ph' => trim((string) ($payload['c_cont'] ?? $payload['party_phone'] ?? $existing['d_cont'] ?? '')),
                'em' => trim((string) ($payload['c_mail'] ?? $payload['party_email'] ?? $existing['d_mail'] ?? '')),
                'ad' => trim((string) ($payload['c_add'] ?? $payload['party_address'] ?? '')),
                'gstin' => trim((string) ($payload['gst_no'] ?? $payload['party_gstin'] ?? $existing['d_gst'] ?? '')),
                'pstate' => $partyState,
                'now' => now(),
                'id' => $id,
                'c' => $clientId,
                'b' => $branchId,
            ]);

            $this->insertLinesAndStock($clientId, $branchId, $id, (string) $existing['note_no'], $lines);
            $db->commit();
            return ['id' => $id, 'note_no' => (string) $existing['note_no']];
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * @param list<array<string,mixed>> $lines
     */
    private function insertLinesAndStock(int $clientId, int $branchId, int $noteId, string $noteNo, array $lines): void
    {
        $db = Connection::ops();
        $ins = $db->prepare(
            'INSERT INTO credit_note_items
                (credit_note_id, client_id, branch_id, sale_item_id, product_binding_id, batch_id,
                 product_name, batch_no, hsn, pack_size, expiry_date, sale_qty, return_qty, sale_rate, gst_rate,
                 deduction_pct, taxable, tax, cgst, sgst, igst, net_total, reason, created_at)
             VALUES
                (:nid, :c, :b, :si, :pb, :bi, :pn, :bn, :hsn, :ps, :exp, :sq, :rq, :rate, :gst,
                 :ded, :taxable, :tax, :cgst, :sgst, :igst, :net, :reason, :now)'
        );
        foreach ($lines as $line) {
            $ins->execute([
                'nid' => $noteId,
                'c' => $clientId,
                'b' => $branchId,
                'si' => $line['sale_item_id'] ?: null,
                'pb' => $line['product_binding_id'] ?: null,
                'bi' => $line['batch_id'] ?: null,
                'pn' => $line['product_name'],
                'bn' => $line['batch_no'],
                'hsn' => $line['hsn'],
                'ps' => $line['pack_size'],
                'exp' => $line['expiry_date'],
                'sq' => $line['sale_qty'],
                'rq' => $line['return_qty'],
                'rate' => $line['sale_rate'],
                'gst' => $line['gst_rate'],
                'ded' => $line['deduction_pct'],
                'taxable' => $line['taxable'],
                'tax' => $line['tax'],
                'cgst' => $line['cgst'],
                'sgst' => $line['sgst'],
                'igst' => $line['igst'],
                'net' => $line['net_total'],
                'reason' => $line['reason'],
                'now' => now(),
            ]);
            $itemId = (int) $db->lastInsertId();
            if ($line['sale_item_id'] > 0) {
                $db->prepare(
                    'UPDATE sale_items SET return_qty = COALESCE(return_qty,0) + :q WHERE id = :id'
                )->execute(['q' => $line['return_qty'], 'id' => $line['sale_item_id']]);
            }
            if ($line['product_binding_id'] > 0 && $line['return_qty'] > 0) {
                $this->inventory->addStock(
                    $clientId,
                    $branchId,
                    $line['product_binding_id'],
                    $line['batch_no'] !== '' ? $line['batch_no'] : 'NA',
                    $line['expiry_date'] ? substr((string) $line['expiry_date'], 0, 10) : null,
                    0.0,
                    (float) $line['sale_rate'],
                    (int) $line['return_qty'],
                    null,
                    0,
                    0,
                    'sale_return',
                    date('Y-m-d'),
                    'credit_note',
                    $itemId,
                    'Wholesale return ' . $noteNo
                );
            }
        }
    }

    /** @param array<string,mixed> $raw */
    private function normalizeLine(array $raw, string $pharmacyState, string $partyState): array
    {
        $qty = (int) ($raw['return_qty'] ?? 0);
        $rate = (float) ($raw['sale_rate'] ?? $raw['net_rate'] ?? 0);
        $gst = (float) ($raw['gst'] ?? $raw['gst_per'] ?? $raw['gst_rate'] ?? 0);
        $ded = (float) ($raw['diduction'] ?? $raw['deduction_pct'] ?? 0);
        $taxable = round($rate * $qty * (100 - $ded) / 100, 2);
        $tax = round($taxable * $gst / 100, 2);
        $same = $pharmacyState !== '' && $partyState !== ''
            && strcasecmp($pharmacyState, $partyState) === 0;
        if ($same || $pharmacyState === '' || $partyState === '') {
            $cgst = round($tax / 2, 2);
            $sgst = round($tax - $cgst, 2);
            $igst = 0.0;
        } else {
            $igst = $tax;
            $cgst = 0.0;
            $sgst = 0.0;
        }
        return [
            'product_name' => trim((string) ($raw['p_name'] ?? $raw['product_name'] ?? '')),
            'batch_no' => trim((string) ($raw['batch_no'] ?? '')),
            'sale_item_id' => (int) ($raw['id'] ?? $raw['sale_item_id'] ?? $raw['pID'] ?? 0),
            'product_binding_id' => (int) ($raw['product_binding_id'] ?? 0),
            'batch_id' => (int) ($raw['batch_id'] ?? 0),
            'sale_qty' => (int) ($raw['saleQty'] ?? $raw['sale_qty'] ?? 0),
            'return_qty' => $qty,
            'sale_rate' => $rate,
            'gst_rate' => $gst,
            'deduction_pct' => $ded,
            'taxable' => $taxable,
            'tax' => $tax,
            'cgst' => $cgst,
            'sgst' => $sgst,
            'igst' => $igst,
            'net_total' => round($taxable + $tax, 2),
            'reason' => trim((string) ($raw['reason'] ?? 'Expiry')),
            'hsn' => trim((string) ($raw['hsn'] ?? '')),
            'pack_size' => trim((string) ($raw['pack_size'] ?? '')),
            'expiry_date' => $raw['exp_date'] ?? $raw['expiry_date'] ?? null,
        ];
    }

    /** @param array<string,mixed> $row */
    private function mapNote(array $row, bool $full): array
    {
        $status = trim((string) ($row['deduction_status'] ?? 'Open'));
        if ($status === 'Applied' || $status === 'Cleared') {
            $status = 'Cleared';
        } else {
            $status = $status !== '' ? $status : 'Open';
        }
        $taxable = (float) ($row['taxable'] ?? 0);
        $tax = (float) ($row['gst_amount'] ?? 0);
        $net = (float) ($row['amount'] ?? 0);
        return [
            'id' => (int) $row['id'],
            'note_no' => (string) ($row['note_no'] ?? ''),
            'order_no' => (string) ($row['note_no'] ?? ''),
            'party_name' => (string) ($row['party_name'] ?? ''),
            'customer_name' => (string) ($row['party_name'] ?? ''),
            'distributor_name' => (string) ($row['party_name'] ?? ''),
            'note_date' => (string) ($row['note_date'] ?? ''),
            'order_date' => (string) ($row['note_date'] ?? ''),
            'taxable' => $taxable,
            'tax' => $tax,
            'gst_amount' => $tax,
            'netTotal' => $net,
            'amount' => $net,
            'deduction_status' => $status,
            'applied_sale_no' => $row['applied_sale_no'] ?? null,
            'applied_po_no' => $row['applied_sale_no'] ?? null,
            'applied_on' => $row['applied_on'] ?? null,
            'editable' => $status === 'Open',
            'd_cont' => (string) ($row['party_phone'] ?? ''),
            'd_mail' => (string) ($row['party_email'] ?? ''),
            'd_gst' => (string) ($row['party_gstin'] ?? ''),
            'd_add' => (string) ($row['party_address'] ?? ''),
            'party_address' => (string) ($row['party_address'] ?? ''),
            'state' => (string) ($row['party_state'] ?? ''),
            'against_invoice' => (string) ($row['against_invoice'] ?? ''),
        ];
    }

    /** @return array<string,mixed> */
    private function customerMeta(int $clientId, int $customerId, string $name): array
    {
        try {
            if ($customerId > 0) {
                $st = Connection::ops()->prepare(
                    'SELECT id, name, phone, email, address, gstin FROM customers WHERE id = :id LIMIT 1'
                );
                $st->execute(['id' => $customerId]);
            } else {
                $st = Connection::ops()->prepare(
                    'SELECT id, name, phone, email, address, gstin FROM customers
                     WHERE client_id = :c AND LOWER(TRIM(name)) = LOWER(TRIM(:n)) LIMIT 1'
                );
                $st->execute(['c' => $clientId, 'n' => $name]);
            }
            $d = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($d) {
                return [
                    'customer_id' => (int) $d['id'],
                    'phone' => (string) ($d['phone'] ?? ''),
                    'email' => (string) ($d['email'] ?? ''),
                    'address' => (string) ($d['address'] ?? ''),
                    'gstin' => (string) ($d['gstin'] ?? ''),
                    'state' => '',
                    'c_cont' => (string) ($d['phone'] ?? ''),
                    'c_mail' => (string) ($d['email'] ?? ''),
                    'c_add' => (string) ($d['address'] ?? ''),
                    'gst_no' => (string) ($d['gstin'] ?? ''),
                ];
            }
        } catch (\Throwable) {
        }
        return [
            'customer_id' => $customerId ?: null,
            'phone' => '',
            'email' => '',
            'address' => '',
            'gstin' => '',
            'state' => '',
            'c_cont' => '',
            'c_mail' => '',
            'c_add' => '',
            'gst_no' => '',
        ];
    }

    private function nextNoteNo(int $clientId, int $branchId): string
    {
        $yy = date('y');
        $prefix = "CR/{$clientId}/{$yy}/";
        $st = Connection::ops()->prepare(
            "SELECT note_no FROM credit_notes
             WHERE client_id = :c AND branch_id = :b AND note_no LIKE :p
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
