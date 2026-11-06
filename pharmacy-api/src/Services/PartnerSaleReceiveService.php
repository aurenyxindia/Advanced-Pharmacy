<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Database\Connection;
use App\Support\Env;
use PDO;
use Throwable;

/**
 * Paperless → Advanced "Share Stock" receiver + Inward Stock approve/reject.
 *
 * Receive (API key): queue bill as pending (idempotent).
 * Approve (JWT): create Purchase (PO = source_order_no, distributor = source_medical_name) + stock IN.
 * Reject (JWT): discard without stock change.
 */
final class PartnerSaleReceiveService
{
    private InventoryService $inventory;
    private bool $schemaReady = false;

    public function __construct()
    {
        $this->inventory = new InventoryService();
    }

    public function ping(): array
    {
        $this->ensureSchema();
        return [
            'status'                 => 'success',
            'service'                => 'partner-sale-receive',
            'target_client_no'       => $this->targetClientNo(),
            'target_client_id'       => $this->targetClientId(),
            'target_branch_id'       => $this->targetBranchId(),
            'allowed_source_clients' => $this->allowedSourceClients(),
        ];
    }

    /**
     * Paperless POST body → pending inward row (no stock yet).
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function receiveSale(array $body): array
    {
        $this->ensureSchema();
        $this->assertTargetConfigured();

        $source = strtolower(trim((string) ($body['source'] ?? '')));
        $sourceClient = strtoupper(trim((string) ($body['source_client_no'] ?? '')));
        $sourceOrder = trim((string) ($body['source_order_no'] ?? ''));
        $items = $body['items'] ?? null;

        if ($source === '' || !in_array($source, $this->allowedSources(), true)) {
            return $this->err('Forbidden source.', 403);
        }
        if ($sourceClient === '' || !in_array($sourceClient, $this->allowedSourceClients(), true)) {
            return $this->err('source_client_no not allowed. Only C414 bills are accepted.', 403);
        }
        if ($sourceOrder === '') {
            return $this->err('source_order_no is required.', 400);
        }
        if (!is_array($items) || $items === []) {
            return $this->err('items[] is required and must not be empty.', 400);
        }

        $db = Connection::ops();
        $existing = $db->prepare(
            'SELECT id, status, target_order_no FROM partner_shared_sales
             WHERE source_host = :h AND source_client_no = :c AND source_order_no = :o
             LIMIT 1'
        );
        $existing->execute(['h' => $source, 'c' => $sourceClient, 'o' => $sourceOrder]);
        $row = $existing->fetch();
        if ($row) {
            $orderNo = (string) ($row['target_order_no'] ?: ('PENDING/' . $row['id']));
            return [
                'status'           => 'success',
                'already_imported' => true,
                'order_no'         => $orderNo,
                'pending_id'       => (int) $row['id'],
                'inward_status'    => (string) $row['status'],
                'message'          => $row['status'] === 'approved'
                    ? 'Bill was already imported. Stock was not deducted again.'
                    : 'Bill was already queued for Inward Stock. Stock was not changed again.',
            ];
        }

        $clientId = $this->targetClientId();
        $branchId = $this->targetBranchId();
        $pendingRef = 'PENDING';

        $ins = $db->prepare(
            'INSERT INTO partner_shared_sales
                (source_host, source_client_no, source_order_no, source_sale_id, source_medical_name,
                 target_client_id, target_branch_id, target_client_no, target_order_no, status,
                 patient_name, patient_contact, doctor_name, cash_credit, order_date,
                 taxable, discount_amt, gst_amt, net_amt, item_count, payload_json, created_at)
             VALUES
                (:h, :sc, :so, :ssid, :smn,
                 :tc, :tb, :tcn, :tono, \'pending\',
                 :pn, :pc, :dn, :cc, :od,
                 :tax, :disc, :gst, :net, :ic, :payload, :now)'
        );
        $ins->execute([
            'h'       => $source,
            'sc'      => $sourceClient,
            'so'      => $sourceOrder,
            'ssid'    => !empty($body['source_sale_id']) ? (int) $body['source_sale_id'] : null,
            'smn'     => substr(trim((string) ($body['source_medical_name'] ?? '')), 0, 150) ?: null,
            'tc'      => $clientId,
            'tb'      => $branchId,
            'tcn'     => $this->targetClientNo(),
            'tono'    => $pendingRef,
            'pn'      => substr(trim((string) ($body['patient_name'] ?? '')), 0, 150) ?: null,
            'pc'      => substr(trim((string) ($body['patient_contact'] ?? '')), 0, 30) ?: null,
            'dn'      => substr(trim((string) ($body['doctor_name'] ?? '')), 0, 150) ?: null,
            'cc'      => substr(trim((string) ($body['cash_credit'] ?? 'Cash')), 0, 20) ?: 'Cash',
            'od'      => $this->normalizeDate((string) ($body['order_date'] ?? date('Y-m-d'))),
            'tax'     => round((float) ($body['taxable'] ?? 0), 2),
            'disc'    => round((float) ($body['discount'] ?? 0), 2),
            'gst'     => round((float) ($body['gst'] ?? 0), 2),
            'net'     => round((float) ($body['net'] ?? 0), 2),
            'ic'      => count($items),
            'payload' => json_encode($body, JSON_UNESCAPED_UNICODE) ?: '{}',
            'now'     => now(),
        ]);
        $id = (int) $db->lastInsertId();
        $orderNo = 'PENDING/' . $id;
        $db->prepare('UPDATE partner_shared_sales SET target_order_no = :o WHERE id = :id')
            ->execute(['o' => $orderNo, 'id' => $id]);

        return [
            'status'           => 'success',
            'already_imported' => false,
            'order_no'         => $orderNo,
            'pending_id'       => $id,
            'client_no'        => $this->targetClientNo(),
            'source_order_no'  => $sourceOrder,
            'inward_status'    => 'pending',
            'warnings'         => [],
            'message'          => 'Bill queued for Inward Stock approval on pp.gmpserver.com as ' . $orderNo,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listForClient(int $clientId, ?string $status = null): array
    {
        $this->ensureSchema();
        $sql = 'SELECT id, source_host, source_client_no, source_order_no, source_medical_name,
                       target_order_no, target_sale_id, target_purchase_id, status, patient_name, patient_contact,
                       doctor_name, cash_credit, order_date, taxable, discount_amt, gst_amt, net_amt,
                       item_count, warnings_json, created_at, decided_at
                FROM partner_shared_sales
                WHERE target_client_id = :c';
        $params = ['c' => $clientId];
        if ($status !== null && $status !== '' && $status !== 'all') {
            $sql .= ' AND status = :s';
            $params['s'] = $status;
        }
        $sql .= ' ORDER BY FIELD(status, \'pending\', \'approved\', \'rejected\'), id DESC LIMIT 300';
        $stmt = Connection::ops()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll() ?: [];
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['item_count'] = (int) $r['item_count'];
            $r['taxable'] = (float) $r['taxable'];
            $r['discount_amt'] = (float) $r['discount_amt'];
            $r['gst_amt'] = (float) $r['gst_amt'];
            $r['net_amt'] = (float) $r['net_amt'];
            // Purchase-log shape: PO No = source_order_no · Distributor = source_medical_name
            $r['po_no'] = (string) $r['source_order_no'];
            $r['invoice_no'] = (string) ($r['target_order_no'] ?: $r['source_order_no']);
            $r['invoice_date'] = $r['order_date'];
            $r['distributor'] = $r['source_medical_name'];
            $r['supplier_name'] = $r['source_medical_name'];
            $isCredit = str_contains(strtolower(trim((string) ($r['cash_credit'] ?? ''))), 'credit');
            $net = (float) $r['net_amt'];
            $r['paid'] = $isCredit ? 0.0 : $net;
            $r['balance'] = $isCredit ? $net : 0.0;
            $r['target_purchase_id'] = $r['target_purchase_id'] !== null ? (int) $r['target_purchase_id'] : null;
            $r['target_sale_id'] = $r['target_sale_id'] !== null ? (int) $r['target_sale_id'] : null;
            $r['warnings'] = $this->decodeWarnings($r['warnings_json'] ?? null);
            unset($r['warnings_json']);
        }
        unset($r);
        return $rows;
    }

    /** @return array<string,mixed> */
    public function show(int $clientId, int $id): array
    {
        $this->ensureSchema();
        $stmt = Connection::ops()->prepare(
            'SELECT * FROM partner_shared_sales WHERE id = :id AND target_client_id = :c LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'c' => $clientId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw HttpException::notFound('Inward stock bill not found.');
        }
        $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $net = (float) $row['net_amt'];
        $isCredit = str_contains(strtolower(trim((string) ($row['cash_credit'] ?? ''))), 'credit');
        return [
            'id'                  => (int) $row['id'],
            'status'              => (string) $row['status'],
            'source_host'         => (string) $row['source_host'],
            'source_client_no'    => (string) $row['source_client_no'],
            'source_order_no'     => (string) $row['source_order_no'],
            'source_medical_name' => $row['source_medical_name'],
            'po_no'               => (string) $row['source_order_no'],
            'invoice_no'          => (string) ($row['target_order_no'] ?: $row['source_order_no']),
            'invoice_date'        => $row['order_date'],
            'distributor'         => $row['source_medical_name'],
            'supplier_name'       => $row['source_medical_name'],
            'target_order_no'     => $row['target_order_no'],
            'target_sale_id'      => $row['target_sale_id'] !== null ? (int) $row['target_sale_id'] : null,
            'target_purchase_id'  => isset($row['target_purchase_id']) && $row['target_purchase_id'] !== null
                ? (int) $row['target_purchase_id'] : null,
            'patient_name'        => $row['patient_name'],
            'patient_contact'     => $row['patient_contact'],
            'doctor_name'         => $row['doctor_name'],
            'cash_credit'         => $row['cash_credit'],
            'order_date'          => $row['order_date'],
            'taxable'             => (float) $row['taxable'],
            'discount_amt'        => (float) $row['discount_amt'],
            'gst_amt'             => (float) $row['gst_amt'],
            'net_amt'             => $net,
            'paid'                => $isCredit ? 0.0 : $net,
            'balance'             => $isCredit ? $net : 0.0,
            'item_count'          => (int) $row['item_count'],
            'warnings'            => $this->decodeWarnings($row['warnings_json'] ?? null),
            'created_at'          => $row['created_at'],
            'decided_at'          => $row['decided_at'],
            'bill_type'           => $payload['bill_type'] ?? null,
            'bill_narration'      => $payload['billNarration'] ?? $payload['bill_narration'] ?? null,
            'patient_no'          => $payload['patient_no'] ?? null,
            'doctor_no'           => $payload['docNo'] ?? $payload['doctor_no'] ?? null,
            'patient_email'       => $payload['patient_email'] ?? null,
            'patient_address'     => $payload['patient_address'] ?? null,
            'items'               => $this->normalizeSharedItems(
                is_array($payload['items'] ?? null) ? $payload['items'] : []
            ),
            'payload'             => $payload,
        ];
    }

    /**
     * Approve pending → create Purchase log entry (stock IN) + appear in Purchase Report.
     * PO No = source_order_no · Distributor = source_medical_name
     *
     * @return array<string,mixed>
     */
    public function approve(int $clientId, int $branchId, ?int $userId, int $id): array
    {
        $this->ensureSchema();
        $db = Connection::ops();

        $stmt = $db->prepare(
            'SELECT * FROM partner_shared_sales WHERE id = :id AND target_client_id = :c LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'c' => $clientId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw HttpException::notFound('Inward stock bill not found.');
        }
        if ($row['status'] === 'approved') {
            return [
                'id'               => (int) $row['id'],
                'status'           => 'approved',
                'already_imported' => true,
                'order_no'         => (string) $row['target_order_no'],
                'po_no'            => (string) $row['target_order_no'],
                'purchase_id'      => $row['target_purchase_id'] !== null ? (int) $row['target_purchase_id'] : null,
                'warnings'         => $this->decodeWarnings($row['warnings_json'] ?? null),
                'message'          => 'Already approved. Purchase was not created again.',
            ];
        }
        if ($row['status'] === 'rejected') {
            throw HttpException::validation(['status' => ['This bill was rejected and cannot be approved.']]);
        }

        $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true);
        if (!is_array($payload)) {
            throw HttpException::validation(['payload' => ['Invalid stored bill payload.']]);
        }
        $items = $payload['items'] ?? [];
        if (!is_array($items) || $items === []) {
            throw HttpException::validation(['items' => ['No items on this shared bill.']]);
        }

        $useBranch = (int) ($row['target_branch_id'] ?: $branchId);
        if ($useBranch <= 0) {
            $useBranch = $this->targetBranchId();
        }
        $sourceOrder = trim((string) $row['source_order_no']);
        $distributor = trim((string) ($row['source_medical_name'] ?? ''));
        if ($distributor === '') {
            $distributor = 'Paperless Share';
        }
        $orderDate = $this->normalizeDate((string) ($row['order_date'] ?: date('Y-m-d')));
        $cashCredit = trim((string) ($row['cash_credit'] ?? 'Cash'));
        if ($cashCredit === '') {
            $cashCredit = 'Cash';
        }
        // Normalize to Purchase form values
        $cashCreditNorm = str_contains(strtolower($cashCredit), 'credit') ? 'Credit' : 'Cash';

        $warnings = [];
        $purchaseItems = [];
        foreach ($items as $line) {
            if (!is_array($line)) {
                continue;
            }
            $norm = $this->normalizeSharedLine($line);
            $pName = (string) $norm['product_name'];
            if ($pName === '') {
                continue;
            }
            $qtyPacks = (int) $norm['quantity'];
            $freeQty = (int) $norm['free_quantity'];
            if ($qtyPacks <= 0 && $freeQty > 0) {
                // Purchase requires billed qty > 0; treat free-only as scheme qty.
                $qtyPacks = $freeQty;
                $freeQty = 0;
                $warnings[] = "{$pName}: free-only line mapped as purchase qty {$qtyPacks}.";
            }
            if ($qtyPacks <= 0) {
                $warnings[] = "Skipped {$pName}: quantity is 0.";
                continue;
            }

            $rate = (float) $norm['purchase_rate'];
            $mrp = (float) $norm['mrp'];
            if ($rate <= 0 && $mrp > 0) {
                $rate = $mrp;
                $warnings[] = "{$pName}: rate missing — used MRP as purchase rate.";
            }
            if ($rate <= 0) {
                $warnings[] = "Skipped {$pName}: purchase rate is 0.";
                continue;
            }
            if ($mrp <= 0) {
                $mrp = $rate;
            }

            $expiry = $norm['expiry_date'];
            if ($expiry === null || $expiry === '') {
                $expiry = date('Y-m-t', strtotime('+24 months'));
                $warnings[] = "{$pName}: expiry missing — set to " . substr((string) $expiry, 0, 7) . '.';
            }

            $batchNo = (string) $norm['batch_no'];
            $match = $this->findBindingAndBatch($db, $clientId, $useBranch, $pName, $batchNo);
            // Paperless taxable is usually after discount; only re-apply when disc amount is present.
            $discAmt = (float) $norm['discount_amt'];
            $discPct = $discAmt > 0 ? (float) $norm['discount_pct'] : 0.0;
            $item = [
                'product_name'  => $pName,
                'batch_no'      => $batchNo,
                'expiry_date'   => $expiry,
                'quantity'      => $qtyPacks,
                'free_quantity' => $freeQty,
                'purchase_rate' => $rate,
                'mrp'           => $mrp,
                'ptr'           => (float) $norm['ptr'],
                'pts'           => (float) $norm['pts'],
                'discount_pct'  => $discPct,
                'discount_amt'  => $discAmt,
                'gst_rate'      => (float) $norm['gst_rate'],
                'hsn_code'      => $norm['hsn_code'] !== '' ? $norm['hsn_code'] : null,
                'pack_size'     => $norm['pack_size'],
                'shelf_location'=> $norm['shelf_location'] !== '' ? $norm['shelf_location'] : null,
            ];

            if ($match['binding_id'] !== null) {
                $item['product_binding_id'] = $match['binding_id'];
            } else {
                // Paperless product not in local catalog — create on approve (same as purchase entry Add Product).
                $item['new_product'] = [
                    'name'               => $pName,
                    'manufacturer'       => $norm['manufacturer'] !== '' ? $norm['manufacturer'] : null,
                    'hsn_code'           => $norm['hsn_code'] !== '' ? $norm['hsn_code'] : null,
                    'default_gst_rate'   => (float) $norm['gst_rate'] > 0 ? (float) $norm['gst_rate'] : 12,
                    'mrp'                => $mrp,
                    'ptr'                => (float) $norm['ptr'],
                    'pts'                => (float) $norm['pts'],
                    'pack_size'          => $norm['pack_size'],
                    'shelf_location'     => $norm['shelf_location'] !== '' ? $norm['shelf_location'] : null,
                    'product_category'   => $norm['product_category'],
                    'schedule_category'  => 'OTC',
                ];
                $warnings[] = "Catalog product created for {$pName}.";
            }
            $purchaseItems[] = $item;
        }

        if ($purchaseItems === []) {
            throw HttpException::validation([
                'items' => ['No valid product lines to convert into a purchase bill.'],
            ]);
        }

        // Invoice No = source order (unique); PO No overwritten to source_order_no after create.
        $invoiceNo = $sourceOrder;
        $dup = $db->prepare('SELECT id, po_no FROM purchases WHERE client_id = :c AND invoice_no = :inv LIMIT 1');
        $dup->execute(['c' => $clientId, 'inv' => $invoiceNo]);
        if ($dupRow = $dup->fetch()) {
            $db->prepare(
                'UPDATE partner_shared_sales
                 SET status = \'approved\', target_order_no = :po, target_purchase_id = :pid,
                     decided_at = :now, decided_by = :uid
                 WHERE id = :id'
            )->execute([
                'po'  => (string) ($dupRow['po_no'] ?: $sourceOrder),
                'pid' => (int) $dupRow['id'],
                'now' => now(),
                'uid' => $userId,
                'id'  => $id,
            ]);
            return [
                'id'               => $id,
                'status'           => 'approved',
                'already_imported' => true,
                'order_no'         => (string) ($dupRow['po_no'] ?: $sourceOrder),
                'po_no'            => (string) ($dupRow['po_no'] ?: $sourceOrder),
                'purchase_id'      => (int) $dupRow['id'],
                'warnings'         => $warnings,
                'message'          => 'Purchase already exists for this invoice. Linked without duplicating.',
            ];
        }

        $purchase = (new PurchaseService())->create($clientId, $useBranch, $userId, [
            'invoice_no'            => $invoiceNo,
            'invoice_date'          => $orderDate,
            'supplier_name'         => $distributor,
            'cash_credit'           => $cashCreditNorm,
            'bill_discount_amount'  => round((float) ($row['discount_amt'] ?? 0), 2),
            'items'                 => $purchaseItems,
        ]);

        $purchaseId = (int) ($purchase['id'] ?? 0);
        $poNo = $sourceOrder;

        // Keep Purchase Report PO No = Paperless source_order_no
        if ($purchaseId > 0 && $poNo !== '') {
            try {
                $db->prepare(
                    'UPDATE purchases SET po_no = :po WHERE id = :id AND client_id = :c'
                )->execute(['po' => $poNo, 'id' => $purchaseId, 'c' => $clientId]);
            } catch (Throwable $e) {
                // Unique conflict — keep generated PO, warn.
                $poNo = (string) ($purchase['po_no'] ?? $poNo);
                $warnings[] = 'Could not set PO to source order no (kept ' . $poNo . ').';
            }
        } else {
            $poNo = (string) ($purchase['po_no'] ?? $sourceOrder);
        }

        $db->prepare(
            'UPDATE partner_shared_sales
             SET status = \'approved\', target_order_no = :po, target_purchase_id = :pid,
                 warnings_json = :w, decided_at = :now, decided_by = :uid
             WHERE id = :id'
        )->execute([
            'po'  => $poNo,
            'pid' => $purchaseId > 0 ? $purchaseId : null,
            'w'   => json_encode($warnings, JSON_UNESCAPED_UNICODE) ?: '[]',
            'now' => now(),
            'uid' => $userId,
            'id'  => $id,
        ]);

        return [
            'id'               => $id,
            'status'           => 'approved',
            'already_imported' => false,
            'order_no'         => $poNo,
            'po_no'            => $poNo,
            'invoice_no'       => $invoiceNo,
            'purchase_id'      => $purchaseId,
            'distributor'      => $distributor,
            'source_order_no'  => $sourceOrder,
            'warnings'         => $warnings,
            'message'          => 'Approved — purchase ' . $poNo . ' added to Purchase Report (stock inward).',
        ];
    }

    /** @return array<string,mixed> */
    public function reject(int $clientId, ?int $userId, int $id, string $reason = ''): array
    {
        $this->ensureSchema();
        $db = Connection::ops();
        $stmt = $db->prepare(
            'SELECT id, status, source_order_no FROM partner_shared_sales
             WHERE id = :id AND target_client_id = :c LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'c' => $clientId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw HttpException::notFound('Inward stock bill not found.');
        }
        if ($row['status'] === 'approved') {
            throw HttpException::validation(['status' => ['Already approved — cannot reject.']]);
        }
        if ($row['status'] === 'rejected') {
            return [
                'id'      => (int) $row['id'],
                'status'  => 'rejected',
                'message' => 'Already rejected.',
            ];
        }
        $note = $reason !== '' ? ['reason' => $reason] : [];
        $db->prepare(
            'UPDATE partner_shared_sales
             SET status = \'rejected\', warnings_json = :w, decided_at = :now, decided_by = :uid
             WHERE id = :id'
        )->execute([
            'w'   => json_encode($note, JSON_UNESCAPED_UNICODE) ?: '{}',
            'now' => now(),
            'uid' => $userId,
            'id'  => $id,
        ]);

        return [
            'id'               => (int) $row['id'],
            'status'           => 'rejected',
            'source_order_no'  => (string) $row['source_order_no'],
            'message'          => 'Rejected — stock was not changed.',
        ];
    }

    public function assertApiKey(?string $key): void
    {
        $expected = trim((string) Env::get('PARTNER_SHARE_API_KEY', 'plp_share_stock_c414_k9m2x7q1'));
        if ($key === null || $key === '' || !hash_equals($expected, $key)) {
            throw HttpException::unauthorized('Unauthorized');
        }
    }

    private function ensureSchema(): void
    {
        if ($this->schemaReady) {
            return;
        }
        Connection::ops()->exec(
            "CREATE TABLE IF NOT EXISTS partner_shared_sales (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                source_host VARCHAR(128) NOT NULL,
                source_client_no VARCHAR(32) NOT NULL,
                source_order_no VARCHAR(80) NOT NULL,
                source_sale_id BIGINT NULL,
                source_medical_name VARCHAR(150) NULL,
                target_client_id BIGINT UNSIGNED NOT NULL,
                target_branch_id BIGINT UNSIGNED NOT NULL,
                target_client_no VARCHAR(32) NOT NULL,
                target_order_no VARCHAR(80) NOT NULL,
                target_sale_id BIGINT UNSIGNED NULL,
                target_purchase_id BIGINT UNSIGNED NULL,
                status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                patient_name VARCHAR(150) NULL,
                patient_contact VARCHAR(30) NULL,
                doctor_name VARCHAR(150) NULL,
                cash_credit VARCHAR(20) NULL,
                order_date DATE NULL,
                taxable DECIMAL(14,2) NOT NULL DEFAULT 0,
                discount_amt DECIMAL(14,2) NOT NULL DEFAULT 0,
                gst_amt DECIMAL(14,2) NOT NULL DEFAULT 0,
                net_amt DECIMAL(14,2) NOT NULL DEFAULT 0,
                item_count INT NOT NULL DEFAULT 0,
                payload_json LONGTEXT NOT NULL,
                warnings_json TEXT NULL,
                created_at DATETIME NOT NULL,
                decided_at DATETIME NULL,
                decided_by BIGINT UNSIGNED NULL,
                UNIQUE KEY uniq_source_bill (source_host, source_client_no, source_order_no),
                KEY idx_partner_share_client_status (target_client_id, status, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        // Existing installs: add purchase link column if missing
        try {
            $cols = Connection::ops()->query("SHOW COLUMNS FROM partner_shared_sales LIKE 'target_purchase_id'")->fetchAll();
            if (!$cols) {
                Connection::ops()->exec(
                    'ALTER TABLE partner_shared_sales ADD COLUMN target_purchase_id BIGINT UNSIGNED NULL AFTER target_sale_id'
                );
            }
        } catch (Throwable $e) {
            // ignore — list/show tolerate missing column via isset
        }
        $this->schemaReady = true;
    }

    private function assertTargetConfigured(): void
    {
        $clientId = $this->targetClientId();
        if ($clientId <= 0) {
            throw new HttpException('Receive target client is not configured.', 503);
        }
        $st = Connection::ho()->prepare('SELECT id FROM clients WHERE id = :id LIMIT 1');
        $st->execute(['id' => $clientId]);
        if (!$st->fetch()) {
            throw new HttpException('Receive target client does not exist in local clients table.', 503);
        }
    }

    private function targetClientId(): int
    {
        return (int) Env::get('PARTNER_SHARE_CLIENT_ID', Env::get('PARTNER_CLIENT_ID', 5));
    }

    private function targetBranchId(): int
    {
        $fromEnv = (int) Env::get('PARTNER_SHARE_BRANCH_ID', 0);
        if ($fromEnv > 0) {
            return $fromEnv;
        }
        $clientId = $this->targetClientId();
        $st = Connection::ho()->prepare(
            'SELECT id FROM branches WHERE client_id = :c ORDER BY id ASC LIMIT 1'
        );
        $st->execute(['c' => $clientId]);
        $id = (int) ($st->fetchColumn() ?: 0);
        return $id > 0 ? $id : 1;
    }

    private function targetClientNo(): string
    {
        return strtoupper(trim((string) Env::get('PARTNER_SHARE_CLIENT_NO', 'C414'))) ?: 'C414';
    }

    /** @return list<string> */
    private function allowedSources(): array
    {
        $raw = trim((string) Env::get('PARTNER_SHARE_ALLOWED_SOURCES', 'paperlesspharmacy.in'));
        return array_values(array_filter(array_map(
            static fn ($s) => strtolower(trim($s)),
            explode(',', $raw)
        )));
    }

    /** @return list<string> */
    private function allowedSourceClients(): array
    {
        $raw = trim((string) Env::get('PARTNER_SHARE_ALLOWED_SOURCE_CLIENTS', 'C414'));
        return array_values(array_filter(array_map(
            static fn ($s) => strtoupper(trim($s)),
            explode(',', $raw)
        )));
    }

    /** @return array{status:string,message:string} */
    private function err(string $message, int $httpStatus): array
    {
        throw new HttpException($httpStatus, $message);
    }

    private function normalizeDate(string $d): string
    {
        $d = trim($d);
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $d, $m)) {
            return substr($m[0], 0, 10);
        }
        $ts = strtotime($d);
        return $ts ? date('Y-m-d', $ts) : date('Y-m-d');
    }

    /**
     * @param list<mixed> $items
     * @return list<array<string,mixed>>
     */
    private function normalizeSharedItems(array $items): array
    {
        $out = [];
        foreach ($items as $line) {
            if (!is_array($line)) {
                continue;
            }
            $norm = $this->normalizeSharedLine($line);
            if ($norm['product_name'] === '') {
                continue;
            }
            $out[] = $norm;
        }
        return $out;
    }

    /**
     * Map Paperless sale_products fields → purchase-line shape for UI + approve.
     *
     * @param array<string,mixed> $line
     * @return array<string,mixed>
     */
    private function normalizeSharedLine(array $line): array
    {
        $pName = trim((string) ($line['p_name'] ?? $line['product_name'] ?? ''));
        $batch = trim((string) ($line['batch_no'] ?? ''));
        $pack = trim((string) ($line['pack_size'] ?? $line['pack'] ?? ''));
        if ($pack === '') {
            $pack = '1 unit';
        }

        $qtyUnits = (int) round((float) ($line['qty'] ?? 0));
        $sQty = (int) round((float) ($line['SQty'] ?? $line['sqty'] ?? 0));
        $qtyPacks = $sQty > 0 ? $sQty : $qtyUnits;

        $free = (int) round((float) (
            $line['freeQty']
            ?? $line['free_qty']
            ?? $line['FQty']
            ?? $line['free']
            ?? $line['free_quantity']
            ?? 0
        ));

        $mrp = round((float) ($line['mrp'] ?? 0), 2);
        $gstRate = round((float) ($line['gst'] ?? $line['gst_rate'] ?? 0), 2);
        $discPct = round((float) ($line['disc_per'] ?? $line['disc'] ?? $line['discount_pct'] ?? 0), 2);
        $discAmt = round((float) ($line['disc_amt'] ?? $line['discount_amt'] ?? 0), 2);
        $taxable = round((float) ($line['taxable'] ?? 0), 2);
        $net = round((float) ($line['net'] ?? $line['line_total'] ?? 0), 2);
        $tax = round((float) ($line['tax'] ?? 0), 2);
        if ($tax <= 0 && $taxable > 0 && $gstRate > 0) {
            $tax = round($taxable * $gstRate / 100, 2);
        }

        // Prefer explicit rates from Paperless; else rebuild from taxable so PurchaseService totals match.
        $rate = round((float) ($line['sale_rate'] ?? $line['purchase_rate'] ?? $line['rate'] ?? $line['othRate'] ?? 0), 4);
        if ($rate <= 0 && $qtyPacks > 0) {
            if ($taxable > 0 || $discAmt > 0) {
                $rate = round(($taxable + $discAmt) / $qtyPacks, 4);
            } elseif ($net > 0) {
                $denom = 1 + ($gstRate / 100);
                $rate = $denom > 0 ? round($net / $qtyPacks / $denom, 4) : 0.0;
            }
        }

        $expRaw = trim((string) ($line['exp_date'] ?? $line['expiry_date'] ?? ''));
        $exp = $this->normalizeExpiry($expRaw);

        $mfg = trim((string) ($line['m_name'] ?? $line['manufacturer'] ?? ''));
        $hsn = trim((string) ($line['hsn'] ?? $line['hsn_code'] ?? ''));
        $shelf = trim((string) ($line['shelf'] ?? $line['shelf_location'] ?? ''));
        $pCat = trim((string) ($line['p_cat'] ?? $line['product_category'] ?? ''));
        $catNorm = $this->mapProductCategory($pCat);

        $ptr = round((float) ($line['ptr'] ?? 0), 2);
        if ($ptr <= 0) {
            $ptr = round($rate, 2);
        }
        $pts = round((float) ($line['pts'] ?? 0), 2);

        $lineTotal = $net > 0 ? $net : round($taxable + $tax, 2);

        return [
            'product_name'     => $pName,
            'manufacturer'     => $mfg,
            'hsn_code'         => $hsn,
            'batch_no'         => $batch !== '' ? $batch : 'NA',
            'expiry_date'      => $exp,
            'expiry_raw'       => $expRaw,
            'pack_size'        => $pack,
            'quantity'         => $qtyPacks,
            'free_quantity'    => $free,
            'total_qty'        => $qtyPacks + $free,
            'purchase_rate'    => $rate,
            'ptr'              => $ptr,
            'pts'              => $pts,
            'mrp'              => $mrp,
            'discount_pct'     => $discPct,
            'discount_amt'     => $discAmt,
            'gst_rate'         => $gstRate,
            'taxable'          => $taxable,
            'tax'              => $tax,
            'sgst'             => round((float) ($line['sgst'] ?? 0), 2),
            'cgst'             => round((float) ($line['cgst'] ?? 0), 2),
            'line_total'       => $lineTotal,
            'shelf_location'   => $shelf,
            'product_category' => $catNorm,
            'p_cat'            => $pCat,
            'profit'           => round((float) ($line['profit'] ?? 0), 2),
            'old_mrp'          => $line['oldMrp'] ?? $line['old_mrp'] ?? null,
            'qty_units'        => $qtyUnits,
            'SQty'             => $sQty,
            // aliases used by older FE helpers
            'p_name'           => $pName,
            'exp_date'         => $expRaw !== '' ? $expRaw : $exp,
            'hsn'              => $hsn,
            'm_name'           => $mfg,
            'disc_per'         => $discPct,
            'disc_amt'         => $discAmt,
            'net'              => $lineTotal,
            'gst'              => $gstRate,
            'freeQty'          => $free,
            'shelf'            => $shelf,
        ];
    }

    private function mapProductCategory(string $raw): string
    {
        $u = strtoupper(trim($raw));
        if ($u === '' || $u === 'ALLOPATHIC' || $u === 'ALLOPATHY') {
            return 'Allopathy';
        }
        if (str_contains($u, 'AYUR')) {
            return 'Ayurvedic';
        }
        if (str_contains($u, 'HOME')) {
            return 'Homeopathic';
        }
        if (str_contains($u, 'GENERA')) {
            return 'General';
        }
        return $raw !== '' ? $raw : 'Allopathy';
    }

    private function normalizeExpiry(string $exp): ?string
    {
        $exp = trim($exp);
        if ($exp === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}$/', $exp)) {
            return date('Y-m-t', strtotime($exp . '-01'));
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $exp)) {
            return substr($exp, 0, 10);
        }
        if (preg_match('#^(\d{1,2})[/-](\d{2,4})$#', $exp, $m)) {
            $month = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $year = strlen($m[2]) === 2 ? '20' . $m[2] : $m[2];
            return date('Y-m-t', strtotime("$year-$month-01"));
        }
        $ts = strtotime($exp);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    /** @return list<string> */
    private function decodeWarnings(mixed $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode((string) $json, true);
        if (is_array($decoded) && array_is_list($decoded)) {
            return array_map('strval', $decoded);
        }
        if (is_array($decoded) && isset($decoded['reason'])) {
            return [(string) $decoded['reason']];
        }
        return [];
    }

    private function nextPartnerInvoiceNo(PDO $db, int $clientId, int $branchId): string
    {
        $yy = date('y');
        $prefix = 'PS/' . $this->targetClientNo() . '/' . $yy . '/';
        $st = $db->prepare(
            'SELECT target_order_no FROM partner_shared_sales
             WHERE target_client_id = :c AND status = \'approved\' AND target_order_no LIKE :p
             ORDER BY id DESC LIMIT 1'
        );
        $st->execute(['c' => $clientId, 'p' => $prefix . '%']);
        $last = (string) ($st->fetchColumn() ?: '');
        $seq = 1;
        if (preg_match('/\/(\d+)$/', $last, $m)) {
            $seq = ((int) $m[1]) + 1;
        }
        // Also avoid colliding with sales.invoice_no
        $candidate = $prefix . $seq;
        $chk = $db->prepare('SELECT id FROM sales WHERE client_id = :c AND invoice_no = :n LIMIT 1');
        while (true) {
            $chk->execute(['c' => $clientId, 'n' => $candidate]);
            if (!$chk->fetch()) {
                break;
            }
            $seq++;
            $candidate = $prefix . $seq;
        }
        return $candidate;
    }

    /**
     * @return array{binding_id:?int,batch_id:?int,schedule:?string}
     */
    private function findBindingAndBatch(PDO $db, int $clientId, int $branchId, string $pName, string $batchNo): array
    {
        $st = $db->prepare(
            'SELECT id, schedule_category FROM product_bindings
             WHERE client_id = :c AND branch_id = :b
               AND UPPER(TRIM(product_name)) = UPPER(TRIM(:n))
             ORDER BY id ASC LIMIT 1'
        );
        $st->execute(['c' => $clientId, 'b' => $branchId, 'n' => $pName]);
        $binding = $st->fetch();
        if (!$binding) {
            // Fallback: any branch for this client
            $st2 = $db->prepare(
                'SELECT id, schedule_category, branch_id FROM product_bindings
                 WHERE client_id = :c AND UPPER(TRIM(product_name)) = UPPER(TRIM(:n))
                 ORDER BY (branch_id = :b) DESC, id ASC LIMIT 1'
            );
            $st2->execute(['c' => $clientId, 'n' => $pName, 'b' => $branchId]);
            $binding = $st2->fetch();
        }
        if (!$binding) {
            return ['binding_id' => null, 'batch_id' => null, 'schedule' => null];
        }
        $bindingId = (int) $binding['id'];
        $schedule = strtoupper((string) ($binding['schedule_category'] ?? 'OTC'));
        $batchId = null;
        if ($batchNo !== '') {
            $bs = $db->prepare(
                'SELECT id FROM inventory_batches
                 WHERE client_id = :c AND product_binding_id = :p AND batch_no = :bn AND quantity > 0
                 ORDER BY (expiry_date IS NULL), expiry_date ASC, id ASC LIMIT 1'
            );
            $bs->execute(['c' => $clientId, 'p' => $bindingId, 'bn' => $batchNo]);
            $bid = $bs->fetchColumn();
            if ($bid) {
                $batchId = (int) $bid;
            }
        }
        return ['binding_id' => $bindingId, 'batch_id' => $batchId, 'schedule' => $schedule];
    }

    private function deductLineStock(
        PDO $db,
        int $clientId,
        int $branchId,
        int $bindingId,
        ?int $preferredBatchId,
        int $qty,
        string $txnDate,
        int $saleId,
        string $sourceOrder,
        string $pName,
        string $batchNo
    ): void {
        if ($preferredBatchId !== null) {
            $row = $db->prepare('SELECT id, quantity FROM inventory_batches WHERE id = :id AND client_id = :c LIMIT 1');
            $row->execute(['id' => $preferredBatchId, 'c' => $clientId]);
            $b = $row->fetch();
            if ($b && (int) $b['quantity'] >= $qty) {
                $this->inventory->reduceBatch($preferredBatchId, $qty, 'sale', $txnDate, 'sale', $saleId);
                $this->inventory->bumpAggregate($clientId, $branchId, $bindingId, -$qty);
                return;
            }
            if (!$b || (int) $b['quantity'] < $qty) {
                throw new \RuntimeException('Insufficient qty on batch ' . $batchNo);
            }
        }

        // FEFO across binding batches
        $stmt = $db->prepare(
            'SELECT id, quantity FROM inventory_batches
             WHERE client_id = :c AND product_binding_id = :p AND quantity > 0
               AND (expiry_date IS NULL OR expiry_date >= CURDATE())
             ORDER BY (expiry_date IS NULL), expiry_date ASC, id ASC'
        );
        $stmt->execute(['c' => $clientId, 'p' => $bindingId]);
        $batches = $stmt->fetchAll() ?: [];
        $available = array_sum(array_map(static fn ($x) => (int) $x['quantity'], $batches));
        if ($available < $qty) {
            throw new \RuntimeException("Insufficient sellable stock (have {$available}, need {$qty})");
        }
        $remaining = $qty;
        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }
            $take = min($remaining, (int) $batch['quantity']);
            $this->inventory->reduceBatch((int) $batch['id'], $take, 'sale', $txnDate, 'sale', $saleId);
            $remaining -= $take;
        }
        $this->inventory->bumpAggregate($clientId, $branchId, $bindingId, -$qty);
    }
}
