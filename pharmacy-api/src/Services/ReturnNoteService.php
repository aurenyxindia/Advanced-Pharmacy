<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Database\Connection;
use PDO;

/**
 * Purchase Return / Debit Note (Retail "Return Note") — line items, stock OUT,
 * Open → Cleared when applied on a purchase bill.
 */
final class ReturnNoteService
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

        $cols = [];
        $r = $db->query('SHOW COLUMNS FROM debit_notes');
        if ($r) {
            foreach ($r->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $cols[strtolower((string) $row['Field'])] = true;
            }
        }

        $alters = [
            'taxable' => 'ADD COLUMN taxable DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER gst_amount',
            'deduction_status' => "ADD COLUMN deduction_status VARCHAR(20) NOT NULL DEFAULT 'Open' AFTER reason",
            'applied_po_no' => 'ADD COLUMN applied_po_no VARCHAR(60) NULL AFTER deduction_status',
            'applied_on' => 'ADD COLUMN applied_on DATETIME NULL AFTER applied_po_no',
            'distributor_id' => 'ADD COLUMN distributor_id BIGINT UNSIGNED NULL AFTER party_name',
            'party_phone' => 'ADD COLUMN party_phone VARCHAR(20) NULL AFTER distributor_id',
            'party_email' => 'ADD COLUMN party_email VARCHAR(150) NULL AFTER party_phone',
            'party_address' => 'ADD COLUMN party_address VARCHAR(255) NULL AFTER party_email',
            'party_gstin' => 'ADD COLUMN party_gstin VARCHAR(20) NULL AFTER party_address',
            'party_state' => 'ADD COLUMN party_state VARCHAR(80) NULL AFTER party_gstin',
        ];
        foreach ($alters as $col => $sql) {
            if (!isset($cols[$col])) {
                try {
                    $db->exec("ALTER TABLE debit_notes {$sql}");
                } catch (\Throwable) {
                    // Column may exist under race / older dialect.
                }
            }
        }

        $db->exec(
            "CREATE TABLE IF NOT EXISTS debit_note_items (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                debit_note_id BIGINT UNSIGNED NOT NULL,
                client_id BIGINT UNSIGNED NOT NULL,
                branch_id BIGINT UNSIGNED NOT NULL,
                product_binding_id BIGINT UNSIGNED NULL,
                batch_id BIGINT UNSIGNED NULL,
                product_name VARCHAR(200) NOT NULL,
                batch_no VARCHAR(60) NULL,
                hsn VARCHAR(20) NULL,
                pack_size VARCHAR(60) NULL,
                expiry_date DATE NULL,
                invoice_no VARCHAR(60) NULL,
                invoice_date DATE NULL,
                purchase_id BIGINT UNSIGNED NULL,
                po_no VARCHAR(60) NULL,
                stock_qty INT NOT NULL DEFAULT 0,
                return_qty INT NOT NULL DEFAULT 0,
                purchase_rate DECIMAL(12,2) NOT NULL DEFAULT 0,
                gst_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
                taxable DECIMAL(12,2) NOT NULL DEFAULT 0,
                tax DECIMAL(12,2) NOT NULL DEFAULT 0,
                cgst DECIMAL(12,2) NOT NULL DEFAULT 0,
                sgst DECIMAL(12,2) NOT NULL DEFAULT 0,
                igst DECIMAL(12,2) NOT NULL DEFAULT 0,
                net_total DECIMAL(12,2) NOT NULL DEFAULT 0,
                reason VARCHAR(80) NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_dni_note (debit_note_id),
                KEY idx_dni_tenant (client_id, branch_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        try {
            $db->exec("UPDATE debit_notes SET deduction_status='Cleared' WHERE deduction_status='Applied'");
            $db->exec("UPDATE debit_notes SET deduction_status='Open' WHERE deduction_status IS NULL OR deduction_status=''");
            $db->exec("UPDATE debit_notes SET taxable = GREATEST(0, amount - gst_amount) WHERE taxable = 0 AND amount > 0");
        } catch (\Throwable) {
        }
    }

    /** @return list<array{value:string,label:string}> */
    public function productsWithStock(int $clientId, int $branchId): array
    {
        $stmt = Connection::ops()->prepare(
            "SELECT DISTINCT TRIM(pb.product_name) AS name
             FROM inventory_batches ib
             JOIN product_bindings pb ON pb.id = ib.product_binding_id
             WHERE ib.client_id = :c AND ib.branch_id = :b AND ib.quantity > 0
               AND TRIM(COALESCE(pb.product_name,'')) <> ''
             ORDER BY name ASC
             LIMIT 20000"
        );
        $stmt->execute(['c' => $clientId, 'b' => $branchId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $n = trim((string) ($row['name'] ?? ''));
            if ($n === '') {
                continue;
            }
            $out[] = ['value' => $n, 'label' => $n];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function batchesForProduct(int $clientId, int $branchId, string $productName): array
    {
        $productName = trim($productName);
        if ($productName === '') {
            return [];
        }
        $stmt = Connection::ops()->prepare(
            "SELECT ib.id AS batch_id, ib.product_binding_id, ib.batch_no, ib.quantity AS stock_qty,
                    ib.expiry_date, ib.purchase_rate AS net_rate, ib.mrp,
                    pb.product_name, pb.hsn_code AS hsn, pb.pack_size,
                    COALESCE(pb.gst_rate, 0) AS gst_per
             FROM inventory_batches ib
             JOIN product_bindings pb ON pb.id = ib.product_binding_id
             WHERE ib.client_id = :c AND ib.branch_id = :b AND ib.quantity > 0
               AND LOWER(TRIM(pb.product_name)) = LOWER(TRIM(:n))
             ORDER BY ib.expiry_date IS NULL, ib.expiry_date ASC, ib.batch_no ASC
             LIMIT 500"
        );
        $stmt->execute(['c' => $clientId, 'b' => $branchId, 'n' => $productName]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'batch_id' => (int) $row['batch_id'],
                'product_binding_id' => (int) $row['product_binding_id'],
                'batch_no' => (string) ($row['batch_no'] ?? ''),
                'label' => (string) ($row['batch_no'] ?? ''),
                'value' => (string) ($row['batch_no'] ?? ''),
                'stock_qty' => (int) $row['stock_qty'],
                'wAvailableQty' => (int) $row['stock_qty'],
                'expiry' => $row['expiry_date'],
                'exp_date' => $row['expiry_date'],
                'net_rate' => (float) $row['net_rate'],
                'mrp' => (float) $row['mrp'],
                'hsn' => (string) ($row['hsn'] ?? ''),
                'pack_size' => (string) ($row['pack_size'] ?? ''),
                'gst_per' => (float) $row['gst_per'],
                'p_name' => (string) ($row['product_name'] ?? $productName),
            ];
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public function suppliersForBatch(int $clientId, int $branchId, string $productName, string $batchNo): array
    {
        $productName = trim($productName);
        $batchNo = trim($batchNo);
        if ($productName === '' || $batchNo === '') {
            return [];
        }

        $stmt = Connection::ops()->prepare(
            "SELECT p.id AS purchase_id, p.po_no, p.invoice_no, p.invoice_date, p.supplier_id,
                    TRIM(p.supplier_name) AS distributor_name,
                    pi.purchase_rate, pi.gst_rate, pi.product_binding_id
             FROM purchase_items pi
             JOIN purchases p ON p.id = pi.purchase_id
             WHERE p.client_id = :c AND p.branch_id = :b
               AND LOWER(TRIM(pi.product_name)) = LOWER(TRIM(:n))
               AND LOWER(TRIM(COALESCE(pi.batch_no,''))) = LOWER(TRIM(:batch))
               AND TRIM(COALESCE(p.supplier_name,'')) <> ''
             ORDER BY p.invoice_date DESC, p.id DESC
             LIMIT 50"
        );
        $stmt->execute(['c' => $clientId, 'b' => $branchId, 'n' => $productName, 'batch' => $batchNo]);
        $purchaseRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        $seen = [];
        foreach ($purchaseRows as $row) {
            $name = trim((string) ($row['distributor_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $dist = $this->distributorMeta($clientId, $name, (int) ($row['supplier_id'] ?? 0));
            $out[] = array_merge($dist, [
                'distributor_name' => $name,
                'invoice_no' => (string) ($row['invoice_no'] ?? ''),
                'invoice_date' => $row['invoice_date'],
                'purchase_id' => (int) ($row['purchase_id'] ?? 0),
                'po_no' => (string) ($row['po_no'] ?? ''),
                'purchase_rate' => (float) ($row['purchase_rate'] ?? 0),
                'gst_rate' => (float) ($row['gst_rate'] ?? 0),
                'product_binding_id' => (int) ($row['product_binding_id'] ?? 0),
            ]);
        }

        // Fallback: any purchase supplier for this product if batch match missed.
        if ($out === []) {
            $stmt2 = Connection::ops()->prepare(
                "SELECT p.id AS purchase_id, p.po_no, p.invoice_no, p.invoice_date, p.supplier_id,
                        TRIM(p.supplier_name) AS distributor_name,
                        pi.purchase_rate, pi.gst_rate, pi.product_binding_id
                 FROM purchase_items pi
                 JOIN purchases p ON p.id = pi.purchase_id
                 WHERE p.client_id = :c AND p.branch_id = :b
                   AND LOWER(TRIM(pi.product_name)) = LOWER(TRIM(:n))
                   AND TRIM(COALESCE(p.supplier_name,'')) <> ''
                 ORDER BY p.invoice_date DESC
                 LIMIT 20"
            );
            $stmt2->execute(['c' => $clientId, 'b' => $branchId, 'n' => $productName]);
            foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $name = trim((string) ($row['distributor_name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $key = mb_strtolower($name);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $dist = $this->distributorMeta($clientId, $name, (int) ($row['supplier_id'] ?? 0));
                $out[] = array_merge($dist, [
                    'distributor_name' => $name,
                    'invoice_no' => (string) ($row['invoice_no'] ?? ''),
                    'invoice_date' => $row['invoice_date'],
                    'purchase_id' => (int) ($row['purchase_id'] ?? 0),
                    'po_no' => (string) ($row['po_no'] ?? ''),
                    'purchase_rate' => (float) ($row['purchase_rate'] ?? 0),
                    'gst_rate' => (float) ($row['gst_rate'] ?? 0),
                    'product_binding_id' => (int) ($row['product_binding_id'] ?? 0),
                ]);
            }
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function distributorMeta(int $clientId, string $name, int $supplierId): array
    {
        try {
            if ($supplierId > 0) {
                $st = Connection::master()->prepare(
                    'SELECT id, name, phone, email, address, gstin, state FROM distributors WHERE id = :id LIMIT 1'
                );
                $st->execute(['id' => $supplierId]);
            } else {
                $st = Connection::master()->prepare(
                    'SELECT id, name, phone, email, address, gstin, state FROM distributors
                     WHERE client_id = :c AND TRIM(name) = :n LIMIT 1'
                );
                $st->execute(['c' => $clientId, 'n' => $name]);
            }
            $d = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($d) {
                return [
                    'distributor_id' => (int) $d['id'],
                    'd_cont' => (string) ($d['phone'] ?? ''),
                    'd_mail' => (string) ($d['email'] ?? ''),
                    'd_add' => (string) ($d['address'] ?? ''),
                    'd_gst' => (string) ($d['gstin'] ?? ''),
                    'state' => (string) ($d['state'] ?? ''),
                    'd_no' => (string) ($d['id'] ?? ''),
                ];
            }
        } catch (\Throwable) {
        }
        return [
            'distributor_id' => $supplierId ?: null,
            'd_cont' => '',
            'd_mail' => '',
            'd_add' => '',
            'd_gst' => '',
            'state' => '',
            'd_no' => '',
        ];
    }

    public function pharmacyState(int $clientId, ?int $branchId): string
    {
        try {
            if ($branchId) {
                $st = Connection::master()->prepare('SELECT state FROM branches WHERE id = :id LIMIT 1');
                $st->execute(['id' => $branchId]);
                $s = trim((string) ($st->fetchColumn() ?: ''));
                if ($s !== '') {
                    return $s;
                }
            }
            $st = Connection::master()->prepare('SELECT state FROM clients WHERE id = :id LIMIT 1');
            $st->execute(['id' => $clientId]);
            return trim((string) ($st->fetchColumn() ?: ''));
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{id:int,note_no:string}
     */
    public function saveReturn(int $clientId, int $branchId, ?int $userId, array $payload): array
    {
        $this->ensureSchema();
        $products = $payload['products'] ?? [];
        if (!is_array($products) || $products === []) {
            throw HttpException::validation(['products' => ['Add at least one return line.']]);
        }
        $party = trim((string) ($payload['distributor_name'] ?? $payload['party_name'] ?? ''));
        if ($party === '') {
            throw HttpException::validation(['distributor_name' => ['Distributor is required.']]);
        }

        $pharmacyState = $this->pharmacyState($clientId, $branchId);
        $partyState = trim((string) ($payload['state'] ?? $payload['party_state'] ?? ''));

        $lines = [];
        $sumTaxable = 0.0;
        $sumTax = 0.0;
        $sumNet = 0.0;
        foreach ($products as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $line = $this->normalizeLine($raw, $pharmacyState, $partyState);
            if ($line['return_qty'] <= 0) {
                continue;
            }
            $lines[] = $line;
            $sumTaxable += $line['taxable'];
            $sumTax += $line['tax'];
            $sumNet += $line['net_total'];
        }
        if ($lines === []) {
            throw HttpException::validation(['products' => ['Return qty must be greater than 0.']]);
        }

        $db = Connection::ops();
        $db->beginTransaction();
        try {
            $noteNo = $this->nextNoteNo($db, $clientId, $branchId);
            $noteDate = trim((string) ($payload['note_date'] ?? $payload['order_date'] ?? date('Y-m-d')));
            if ($noteDate === '') {
                $noteDate = date('Y-m-d');
            }
            $now = now();

            $ins = $db->prepare(
                "INSERT INTO debit_notes
                    (client_id, branch_id, note_no, party_name, distributor_id, party_phone, party_email,
                     party_address, party_gstin, party_state, against_invoice, amount, gst_amount, taxable,
                     reason, deduction_status, note_date, created_by, created_at, updated_at)
                 VALUES
                    (:c, :b, :no, :party, :did, :phone, :email, :addr, :gstin, :state, :against,
                     :amt, :gst, :taxable, :reason, 'Open', :nd, :by, :ca, :ua)"
            );
            $against = trim((string) ($lines[0]['invoice_no'] ?? ''));
            $ins->execute([
                'c' => $clientId,
                'b' => $branchId,
                'no' => $noteNo,
                'party' => $party,
                'did' => $payload['distributor_id'] ?? ($payload['d_no'] ?? null) ?: null,
                'phone' => trim((string) ($payload['d_cont'] ?? $payload['party_phone'] ?? '')) ?: null,
                'email' => trim((string) ($payload['d_mail'] ?? $payload['party_email'] ?? '')) ?: null,
                'addr' => trim((string) ($payload['d_add'] ?? $payload['party_address'] ?? '')) ?: null,
                'gstin' => trim((string) ($payload['d_gst'] ?? $payload['party_gstin'] ?? '')) ?: null,
                'state' => $partyState ?: null,
                'against' => $against !== '' ? $against : null,
                'amt' => round($sumNet, 2),
                'gst' => round($sumTax, 2),
                'taxable' => round($sumTaxable, 2),
                'reason' => trim((string) ($lines[0]['reason'] ?? 'Expiry')) ?: 'Expiry',
                'nd' => $noteDate,
                'by' => $userId,
                'ca' => $now,
                'ua' => $now,
            ]);
            $noteId = (int) $db->lastInsertId();

            foreach ($lines as $line) {
                $this->insertLineAndStock($db, $clientId, $branchId, $noteId, $noteNo, $noteDate, $line);
            }

            $db->commit();
            return ['id' => $noteId, 'note_no' => $noteNo];
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{id:int,note_no:string}
     */
    public function updateReturn(int $clientId, int $branchId, int $noteId, array $payload): array
    {
        $this->ensureSchema();
        $db = Connection::ops();
        $st = $db->prepare(
            'SELECT * FROM debit_notes WHERE id = :id AND client_id = :c AND branch_id = :b LIMIT 1'
        );
        $st->execute(['id' => $noteId, 'c' => $clientId, 'b' => $branchId]);
        $note = $st->fetch(PDO::FETCH_ASSOC);
        if (!$note) {
            throw HttpException::notFound('Debit note not found.');
        }
        $status = trim((string) ($note['deduction_status'] ?? 'Open'));
        if (strcasecmp($status, 'Open') !== 0) {
            throw HttpException::validation(['note' => ['Cleared debit notes cannot be edited.']]);
        }

        $products = $payload['products'] ?? [];
        if (!is_array($products) || $products === []) {
            throw HttpException::validation(['products' => ['Add at least one return line.']]);
        }

        $pharmacyState = $this->pharmacyState($clientId, $branchId);
        $partyState = trim((string) ($payload['state'] ?? $payload['party_state'] ?? $note['party_state'] ?? ''));
        $party = trim((string) ($payload['distributor_name'] ?? $payload['party_name'] ?? $note['party_name'] ?? ''));

        $lines = [];
        $sumTaxable = 0.0;
        $sumTax = 0.0;
        $sumNet = 0.0;
        foreach ($products as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $line = $this->normalizeLine($raw, $pharmacyState, $partyState);
            if ($line['return_qty'] <= 0) {
                continue;
            }
            $lines[] = $line;
            $sumTaxable += $line['taxable'];
            $sumTax += $line['tax'];
            $sumNet += $line['net_total'];
        }
        if ($lines === []) {
            throw HttpException::validation(['products' => ['Return qty must be greater than 0.']]);
        }

        $db->beginTransaction();
        try {
            // Reverse previous stock OUT by adding back.
            $old = $db->prepare('SELECT * FROM debit_note_items WHERE debit_note_id = :id');
            $old->execute(['id' => $noteId]);
            foreach ($old->fetchAll(PDO::FETCH_ASSOC) as $ol) {
                $batchId = (int) ($ol['batch_id'] ?? 0);
                $qty = (int) ($ol['return_qty'] ?? 0);
                $bindingId = (int) ($ol['product_binding_id'] ?? 0);
                if ($batchId > 0 && $qty > 0 && $bindingId > 0) {
                    $db->prepare(
                        'UPDATE inventory_batches SET quantity = quantity + :q, updated_at = :now WHERE id = :id'
                    )->execute(['q' => $qty, 'now' => now(), 'id' => $batchId]);
                    $this->inventory->ledger(
                        $clientId,
                        $branchId,
                        $bindingId,
                        $batchId,
                        'adjustment',
                        $qty,
                        0,
                        date('Y-m-d'),
                        'debit_note',
                        $noteId,
                        'Return note edit reverse'
                    );
                    $this->inventory->bumpAggregate($clientId, $branchId, $bindingId, $qty);
                }
            }
            $db->prepare('DELETE FROM debit_note_items WHERE debit_note_id = :id')->execute(['id' => $noteId]);

            $noteNo = (string) $note['note_no'];
            $noteDate = trim((string) ($payload['note_date'] ?? $note['note_date'] ?? date('Y-m-d')));
            $against = trim((string) ($lines[0]['invoice_no'] ?? $note['against_invoice'] ?? ''));

            $upd = $db->prepare(
                "UPDATE debit_notes SET
                    party_name = :party, distributor_id = :did, party_phone = :phone, party_email = :email,
                    party_address = :addr, party_gstin = :gstin, party_state = :state,
                    against_invoice = :against, amount = :amt, gst_amount = :gst, taxable = :taxable,
                    reason = :reason, note_date = :nd, updated_at = :ua
                 WHERE id = :id"
            );
            $upd->execute([
                'party' => $party,
                'did' => $payload['distributor_id'] ?? $note['distributor_id'] ?? null,
                'phone' => trim((string) ($payload['d_cont'] ?? $payload['party_phone'] ?? $note['party_phone'] ?? '')) ?: null,
                'email' => trim((string) ($payload['d_mail'] ?? $payload['party_email'] ?? $note['party_email'] ?? '')) ?: null,
                'addr' => trim((string) ($payload['d_add'] ?? $payload['party_address'] ?? $note['party_address'] ?? '')) ?: null,
                'gstin' => trim((string) ($payload['d_gst'] ?? $payload['party_gstin'] ?? $note['party_gstin'] ?? '')) ?: null,
                'state' => $partyState ?: null,
                'against' => $against !== '' ? $against : null,
                'amt' => round($sumNet, 2),
                'gst' => round($sumTax, 2),
                'taxable' => round($sumTaxable, 2),
                'reason' => trim((string) ($lines[0]['reason'] ?? $note['reason'] ?? 'Expiry')) ?: 'Expiry',
                'nd' => $noteDate,
                'ua' => now(),
                'id' => $noteId,
            ]);

            foreach ($lines as $line) {
                $this->insertLineAndStock($db, $clientId, $branchId, $noteId, $noteNo, $noteDate, $line);
            }

            $db->commit();
            return ['id' => $noteId, 'note_no' => $noteNo];
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string,mixed> $line */
    private function insertLineAndStock(
        PDO $db,
        int $clientId,
        int $branchId,
        int $noteId,
        string $noteNo,
        string $noteDate,
        array $line
    ): void {
        $batchId = (int) ($line['batch_id'] ?? 0);
        $bindingId = (int) ($line['product_binding_id'] ?? 0);
        $returnQty = (int) $line['return_qty'];

        if ($batchId <= 0 || $bindingId <= 0) {
            throw HttpException::validation(['batch' => ['Invalid stock batch for ' . $line['product_name']]]);
        }

        $chk = $db->prepare(
            'SELECT id, quantity FROM inventory_batches WHERE id = :id AND client_id = :c AND branch_id = :b LIMIT 1'
        );
        $chk->execute(['id' => $batchId, 'c' => $clientId, 'b' => $branchId]);
        $batch = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$batch) {
            throw HttpException::validation(['batch' => ['Batch not found for ' . $line['product_name']]]);
        }
        if ((int) $batch['quantity'] < $returnQty) {
            throw HttpException::validation([
                'return_qty' => ["Return qty exceeds stock for {$line['product_name']} / {$line['batch_no']}"],
            ]);
        }

        $ins = $db->prepare(
            "INSERT INTO debit_note_items
                (debit_note_id, client_id, branch_id, product_binding_id, batch_id, product_name, batch_no,
                 hsn, pack_size, expiry_date, invoice_no, invoice_date, purchase_id, po_no,
                 stock_qty, return_qty, purchase_rate, gst_rate, taxable, tax, cgst, sgst, igst, net_total,
                 reason, created_at)
             VALUES
                (:nid, :c, :b, :pb, :bid, :pn, :bn, :hsn, :pack, :exp, :inv, :idt, :pid, :po,
                 :sq, :rq, :rate, :gst, :taxable, :tax, :cgst, :sgst, :igst, :net, :reason, :ca)"
        );
        $ins->execute([
            'nid' => $noteId,
            'c' => $clientId,
            'b' => $branchId,
            'pb' => $bindingId,
            'bid' => $batchId,
            'pn' => $line['product_name'],
            'bn' => $line['batch_no'],
            'hsn' => $line['hsn'] ?: null,
            'pack' => $line['pack_size'] ?: null,
            'exp' => $line['expiry_date'] ?: null,
            'inv' => $line['invoice_no'] ?: null,
            'idt' => $line['invoice_date'] ?: null,
            'pid' => $line['purchase_id'] ?: null,
            'po' => $line['po_no'] ?: null,
            'sq' => $line['stock_qty'],
            'rq' => $returnQty,
            'rate' => $line['purchase_rate'],
            'gst' => $line['gst_rate'],
            'taxable' => $line['taxable'],
            'tax' => $line['tax'],
            'cgst' => $line['cgst'],
            'sgst' => $line['sgst'],
            'igst' => $line['igst'],
            'net' => $line['net_total'],
            'reason' => $line['reason'],
            'ca' => now(),
        ]);
        $itemId = (int) $db->lastInsertId();

        $this->inventory->reduceBatch(
            $batchId,
            $returnQty,
            'purchase_return',
            $noteDate,
            'debit_note',
            $noteId
        );
        $this->inventory->bumpAggregate($clientId, $branchId, $bindingId, -1 * $returnQty);
        // reduceBatch already wrote ledger; note item id is tracked via ref debit_note.
        unset($itemId, $noteNo);
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private function normalizeLine(array $raw, string $pharmacyState, string $partyState): array
    {
        $qty = (int) ($raw['return_qty'] ?? 0);
        $rate = (float) ($raw['net_rate'] ?? $raw['purchase_rate'] ?? 0);
        $gst = (float) ($raw['gst_per'] ?? $raw['gst_rate'] ?? $raw['gst'] ?? 0);
        $taxable = round($rate * $qty, 2);
        $tax = round($taxable * ($gst / 100), 2);
        $sameState = $pharmacyState !== '' && $partyState !== ''
            && strcasecmp($pharmacyState, $partyState) === 0;
        if ($sameState || $partyState === '' || $pharmacyState === '') {
            $cgst = round($tax / 2, 2);
            $sgst = round($tax - $cgst, 2);
            $igst = 0.0;
        } else {
            $cgst = 0.0;
            $sgst = 0.0;
            $igst = $tax;
        }
        $net = round($taxable + $tax, 2);

        return [
            'product_name' => trim((string) ($raw['p_name'] ?? $raw['product_name'] ?? '')),
            'batch_no' => trim((string) ($raw['batch_no'] ?? '')),
            'batch_id' => (int) ($raw['batch_id'] ?? $raw['id'] ?? 0),
            'product_binding_id' => (int) ($raw['product_binding_id'] ?? $raw['pID'] ?? 0),
            'hsn' => trim((string) ($raw['hsn'] ?? '')),
            'pack_size' => trim((string) ($raw['pack_size'] ?? '')),
            'expiry_date' => $this->normalizeDate($raw['exp_date'] ?? $raw['expiry_date'] ?? null),
            'invoice_no' => trim((string) ($raw['invoice_no'] ?? '')),
            'invoice_date' => $this->normalizeDate($raw['invoice_date'] ?? null),
            'purchase_id' => (int) ($raw['purchase_id'] ?? 0),
            'po_no' => trim((string) ($raw['po_no'] ?? '')),
            'stock_qty' => (int) ($raw['saleQty'] ?? $raw['stock_qty'] ?? $raw['availableQty'] ?? 0),
            'return_qty' => $qty,
            'purchase_rate' => $rate,
            'gst_rate' => $gst,
            'taxable' => $taxable,
            'tax' => $tax,
            'cgst' => $cgst,
            'sgst' => $sgst,
            'igst' => $igst,
            'net_total' => $net,
            'reason' => trim((string) ($raw['reason'] ?? 'Expiry')) ?: 'Expiry',
        ];
    }

    private function normalizeDate(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));
        if ($s === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) {
            return substr($s, 0, 10);
        }
        if (preg_match('/^\d{4}-\d{2}$/', $s)) {
            return $s . '-01';
        }
        $ts = strtotime($s);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    private function nextNoteNo(PDO $db, int $clientId, int $branchId): string
    {
        $yy = date('y');
        $prefix = "DR/{$clientId}/{$yy}/";
        $stmt = $db->prepare(
            "SELECT note_no FROM debit_notes
             WHERE client_id = :c AND branch_id = :b AND note_no LIKE :p
             ORDER BY id DESC LIMIT 1 FOR UPDATE"
        );
        $stmt->execute(['c' => $clientId, 'b' => $branchId, 'p' => $prefix . '%']);
        $last = (string) ($stmt->fetchColumn() ?: '');
        $seq = 1;
        if ($last !== '' && preg_match('#/(\d+)$#', $last, $m)) {
            $seq = (int) $m[1] + 1;
        }
        return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    /** @return list<array<string,mixed>> */
    public function listNotes(int $clientId, int $branchId, ?string $search = null): array
    {
        $this->ensureSchema();
        $sql = 'SELECT * FROM debit_notes WHERE client_id = :c AND branch_id = :b';
        $bind = ['c' => $clientId, 'b' => $branchId];
        if ($search !== null && trim($search) !== '') {
            $sql .= ' AND (note_no LIKE :q OR party_name LIKE :q2 OR against_invoice LIKE :q3)';
            $like = '%' . trim($search) . '%';
            $bind['q'] = $like;
            $bind['q2'] = $like;
            $bind['q3'] = $like;
        }
        $sql .= ' ORDER BY note_date DESC, id DESC LIMIT 500';
        $stmt = Connection::ops()->prepare($sql);
        $stmt->execute($bind);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row = $this->presentNote($row);
        }
        return $rows;
    }

    /** @return array<string,mixed>|null */
    public function getNote(int $clientId, int $branchId, int $id): ?array
    {
        $this->ensureSchema();
        $st = Connection::ops()->prepare(
            'SELECT * FROM debit_notes WHERE id = :id AND client_id = :c AND branch_id = :b LIMIT 1'
        );
        $st->execute(['id' => $id, 'c' => $clientId, 'b' => $branchId]);
        $note = $st->fetch(PDO::FETCH_ASSOC);
        if (!$note) {
            return null;
        }
        $note = $this->presentNote($note);
        $it = Connection::ops()->prepare(
            'SELECT * FROM debit_note_items WHERE debit_note_id = :id ORDER BY id ASC'
        );
        $it->execute(['id' => $id]);
        $products = [];
        foreach ($it->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $products[] = [
                'id' => (int) $p['id'],
                'p_name' => $p['product_name'],
                'product_name' => $p['product_name'],
                'batch_no' => $p['batch_no'],
                'batch_id' => (int) ($p['batch_id'] ?? 0),
                'product_binding_id' => (int) ($p['product_binding_id'] ?? 0),
                'saleQty' => (int) $p['stock_qty'],
                'stock_qty' => (int) $p['stock_qty'],
                'net_rate' => (float) $p['purchase_rate'],
                'purchase_rate' => (float) $p['purchase_rate'],
                'gst' => (float) $p['gst_rate'],
                'gst_per' => (float) $p['gst_rate'],
                'exp_date' => $p['expiry_date'],
                'return_qty' => (int) $p['return_qty'],
                'reason' => $p['reason'],
                'taxable' => (float) $p['taxable'],
                'tax' => (float) $p['tax'],
                'igst' => (float) $p['igst'],
                'cgst' => (float) $p['cgst'],
                'sgst' => (float) $p['sgst'],
                'netTotal' => (float) $p['net_total'],
                'net_total' => (float) $p['net_total'],
                'invoice_no' => $p['invoice_no'],
                'invoice_date' => $p['invoice_date'],
                'hsn' => $p['hsn'],
                'pack_size' => $p['pack_size'],
                'po_no' => $p['po_no'],
            ];
        }
        $note['products'] = $products;
        return $note;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function presentNote(array $row): array
    {
        $status = trim((string) ($row['deduction_status'] ?? 'Open'));
        if ($status === '' || strcasecmp($status, 'Open') === 0) {
            $status = 'Open';
        } elseif (strcasecmp($status, 'Applied') === 0 || strcasecmp($status, 'Cleared') === 0) {
            $status = 'Cleared';
        }
        $row['deduction_status'] = $status;
        $row['distributor_name'] = $row['party_name'] ?? '';
        $row['order_no'] = $row['note_no'] ?? '';
        $row['order_date'] = $row['note_date'] ?? '';
        $row['taxable'] = (float) ($row['taxable'] ?? max(0, (float) ($row['amount'] ?? 0) - (float) ($row['gst_amount'] ?? 0)));
        $row['tax'] = (float) ($row['gst_amount'] ?? 0);
        $row['netTotal'] = (float) ($row['amount'] ?? 0);
        $row['d_cont'] = $row['party_phone'] ?? '';
        $row['d_mail'] = $row['party_email'] ?? '';
        $row['d_gst'] = $row['party_gstin'] ?? '';
        $row['d_add'] = $row['party_address'] ?? '';
        $row['party_address'] = $row['party_address'] ?? '';
        $row['state'] = $row['party_state'] ?? '';
        $row['editable'] = $status === 'Open';
        return $row;
    }

    /** @return list<array{note_no:string,label:string,amount:float,id:int}> */
    public function openNotesForParty(int $clientId, int $branchId, string $partyName): array
    {
        $this->ensureSchema();
        $partyName = trim($partyName);
        if ($partyName === '') {
            return [];
        }
        $stmt = Connection::ops()->prepare(
            "SELECT id, note_no, amount, gst_amount, party_name
             FROM debit_notes
             WHERE client_id = :c AND branch_id = :b
               AND TRIM(party_name) = :p
               AND (deduction_status IS NULL OR deduction_status = '' OR deduction_status = 'Open')
               AND amount > 0
             ORDER BY note_date DESC, id DESC
             LIMIT 100"
        );
        $stmt->execute(['c' => $clientId, 'b' => $branchId, 'p' => $partyName]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $amt = round((float) ($row['amount'] ?? 0), 2);
            $no = (string) ($row['note_no'] ?? '');
            $out[] = [
                'id' => (int) $row['id'],
                'note_no' => $no,
                'amount' => $amt,
                'label' => $no . ' — ₹' . number_format($amt, 2),
            ];
        }
        return $out;
    }

    /** Mark note Cleared when applied on purchase. */
    public function applyToPurchase(
        int $clientId,
        int $branchId,
        string $noteNo,
        string $poNo,
        ?float $expectedAmt = null
    ): void {
        $this->ensureSchema();
        $noteNo = trim($noteNo);
        if ($noteNo === '') {
            return;
        }
        $db = Connection::ops();
        $st = $db->prepare(
            "SELECT id, amount, deduction_status FROM debit_notes
             WHERE client_id = :c AND branch_id = :b AND note_no = :n LIMIT 1 FOR UPDATE"
        );
        $st->execute(['c' => $clientId, 'b' => $branchId, 'n' => $noteNo]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw HttpException::validation(['debit_note_no' => ['Debit note not found.']]);
        }
        $status = trim((string) ($row['deduction_status'] ?? 'Open'));
        if ($status !== '' && strcasecmp($status, 'Open') !== 0) {
            throw HttpException::validation(['debit_note_no' => ['Debit note already cleared.']]);
        }
        if ($expectedAmt !== null && abs((float) $row['amount'] - $expectedAmt) > 0.05) {
            // Allow apply anyway but keep soft check — Retail compares loosely.
        }
        $upd = $db->prepare(
            "UPDATE debit_notes SET deduction_status = 'Cleared', applied_po_no = :po, applied_on = :on, updated_at = :ua
             WHERE id = :id"
        );
        $upd->execute([
            'po' => $poNo,
            'on' => now(),
            'ua' => now(),
            'id' => (int) $row['id'],
        ]);
    }
}
