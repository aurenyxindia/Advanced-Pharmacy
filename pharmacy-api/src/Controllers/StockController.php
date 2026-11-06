<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Database\Connection;
use App\Models\ProductBinding;
use App\Models\RateMargin;
use App\Services\InventoryService;
use App\Services\ProductBindingService;
use App\Services\PurchaseService;
use App\Services\StockAdjustmentService;
use App\Support\PackSize;
use PDO;

/**
 * Opening stock entry and expired-stock write-off. Both post to the stock
 * ledger so the stock book and FDA tally stay accurate.
 */
final class StockController extends Controller
{
    public function openingStore(Request $request): Response
    {
        if ($request->branchId() === null) {
            throw HttpException::forbidden('A branch context is required.');
        }
        $data = $this->validate($request, [
            'master_product_id' => 'required|integer',
            'batch_no'          => 'required|string|max:60',
            'quantity'          => 'required|integer|min:1',
            'mrp'               => 'numeric|min:0',
            'purchase_rate'     => 'numeric|min:0',
            'opening_date'      => 'string|max:20',
        ]);

        $clientId = (int) $request->clientId();
        $branchId = (int) $request->branchId();

        $bind = (new ProductBindingService())->bind($clientId, $branchId, (int) $data['master_product_id']);
        $bindingId = (int) $bind['id'];

        $expiry = $this->normalizeExpiry($request->input('expiry_date'));
        (new InventoryService())->addStock(
            $clientId,
            $branchId,
            $bindingId,
            (string) $data['batch_no'],
            $expiry,
            (float) ($data['mrp'] ?? 0),
            (float) ($data['purchase_rate'] ?? 0),
            (int) $data['quantity'],
            $request->input('shelf_location'),
            0,
            0,
            'opening',
            $data['opening_date'] ?? date('Y-m-d'),
            'opening',
            null
        );

        return $this->created(['binding_id' => $bindingId], 'Opening stock added.');
    }

    /**
     * Retail opening stock: save many lines in one document (ref_no + stock ledger).
     * Qty / MRP / purchase_rate are per PACK; converted to base units on save.
     */
    public function openingBulkStore(Request $request): Response
    {
        if ($request->branchId() === null) {
            throw HttpException::forbidden('A branch context is required.');
        }

        $items = $request->input('items', []);
        if (!is_array($items) || $items === []) {
            throw HttpException::validation(['items' => ['At least one product line is required.']]);
        }

        $clientId = (int) $request->clientId();
        $branchId = (int) $request->branchId();
        $openingDate = trim((string) ($request->input('opening_date') ?? date('Y-m-d')));
        if ($openingDate === '') {
            $openingDate = date('Y-m-d');
        }

        $refNo = $this->nextOpeningRef($clientId, $branchId);
        $inv = new InventoryService();
        $binder = new ProductBindingService();
        $saved = 0;
        $totalAmount = 0.0;
        $batchIds = [];

        Connection::transaction('ops', function (PDO $db) use (
            $items, $clientId, $branchId, $openingDate, $refNo, $inv, $binder, &$saved, &$totalAmount, &$batchIds
        ) {
            foreach ($items as $i => $item) {
                $masterId = (int) ($item['master_product_id'] ?? 0);
                $batchNo = trim((string) ($item['batch_no'] ?? ''));
                $qtyPacks = (float) ($item['qty'] ?? 0);
                $packMrp = (float) ($item['mrp'] ?? 0);
                $packRate = (float) ($item['purchase_rate'] ?? 0);

                if ($masterId <= 0) {
                    throw HttpException::validation(["items.$i.master_product_id" => ['Product is required.']]);
                }
                if ($batchNo === '') {
                    throw HttpException::validation(["items.$i.batch_no" => ['Batch is required.']]);
                }
                if ($qtyPacks <= 0) {
                    throw HttpException::validation(["items.$i.qty" => ['Quantity must be greater than zero.']]);
                }
                if ($packMrp <= 0 || $packRate <= 0) {
                    throw HttpException::validation(["items.$i" => ['MRP and Rate must be greater than zero.']]);
                }

                $prod = Connection::master()->prepare(
                    'SELECT id, name, hsn_code, default_gst_rate, pack_size, units_per_pack,
                            product_category, manufacturer_short
                     FROM products WHERE id = :id LIMIT 1'
                );
                $prod->execute(['id' => $masterId]);
                $master = $prod->fetch(PDO::FETCH_ASSOC);
                if (!$master) {
                    throw HttpException::validation(["items.$i.master_product_id" => ['Product not found in master.']]);
                }

                $packRaw = trim((string) ($item['pack_size'] ?? $master['pack_size'] ?? ''));
                $parsed = PackSize::parse($packRaw !== '' ? $packRaw : '1 unit');
                $upp = max(1, (int) ($item['units_per_pack'] ?? $master['units_per_pack'] ?? $parsed['units_per_pack'] ?? 1));
                $qtyUnits = max(1, (int) round($qtyPacks * $upp));
                $unitMrp = round($packMrp / $upp, 4);
                $unitRate = round($packRate / $upp, 4);

                $gstRate = (float) ($item['gst_rate'] ?? $master['default_gst_rate'] ?? 12);
                $hsn = trim((string) ($item['hsn_code'] ?? $master['hsn_code'] ?? ''));

                $bind = $binder->bind($clientId, $branchId, $masterId);
                $bindingId = (int) $bind['id'];

                $packLabel = (string) ($parsed['pack_size'] ?: $packRaw);
                $db->prepare(
                    'UPDATE product_bindings
                     SET pack_size = COALESCE(NULLIF(:pack, ""), pack_size),
                         units_per_pack = CASE WHEN :upp > 0 THEN :upp2 ELSE units_per_pack END,
                         hsn_code = COALESCE(NULLIF(:hsn, ""), hsn_code),
                         gst_rate = CASE WHEN :gst > 0 THEN :gst2 ELSE gst_rate END,
                         updated_at = :now
                     WHERE id = :id'
                )->execute([
                    'pack' => $packLabel !== '' ? mb_substr(strtoupper($packLabel), 0, 80) : null,
                    'upp'  => $upp,
                    'upp2' => $upp,
                    'hsn'  => $hsn,
                    'gst'  => $gstRate,
                    'gst2' => $gstRate,
                    'now'  => now(),
                    'id'   => $bindingId,
                ]);

                [$ptr, $pts] = $this->computeWholesaleRates(
                    $clientId,
                    (string) ($master['product_category'] ?? 'Allopathy'),
                    $packMrp,
                    $gstRate
                );

                $expiry = $this->normalizeExpiry($item['expiry_date'] ?? null);
                $shelf = trim((string) ($item['shelf_location'] ?? '')) ?: null;

                $batchId = $inv->addStock(
                    $clientId,
                    $branchId,
                    $bindingId,
                    $batchNo,
                    $expiry,
                    $unitMrp,
                    $unitRate,
                    $qtyUnits,
                    $shelf,
                    $ptr,
                    $pts,
                    'opening',
                    $openingDate,
                    'opening',
                    null,
                    $refNo
                );

                $batchIds[] = $batchId;
                $lineAmount = round($packRate * $qtyPacks, 2);
                $totalAmount += $lineAmount;
                $saved++;
            }
        });

        return $this->created([
            'ref_no'        => $refNo,
            'lines_saved'   => $saved,
            'total_amount'  => round($totalAmount, 2),
            'batch_ids'     => $batchIds,
        ], 'Opening stock saved.');
    }

    /** Lightweight master list for Excel fuzzy match (Retail getProductsForCsvMatch). */
    public function openingMatchProducts(Request $request): Response
    {
        $clientId = (int) ($request->clientId() ?? 0);
        $hide = $clientId > 0 && (new \App\Services\ClientCatalogPolicy())->hideProducts($clientId);
        $sql = "SELECT id, name, hsn_code, default_gst_rate AS gst_rate, pack_size, units_per_pack,
                    mrp, manufacturer_short, product_category, schedule_category
             FROM products
             WHERE is_active = 1";
        $bindings = [];
        if ($hide) {
            $sql .= ' AND created_by_client = :own_client';
            $bindings['own_client'] = $clientId;
        }
        $sql .= ' ORDER BY name ASC LIMIT 8000';
        $stmt = Connection::master()->prepare($sql);
        $stmt->execute($bindings);
        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $rows[] = [
                'id'                 => (int) $r['id'],
                'name'               => $r['name'],
                'hsn_code'           => $r['hsn_code'],
                'gst_rate'           => (float) ($r['gst_rate'] ?? 12),
                'pack_size'          => $r['pack_size'],
                'units_per_pack'     => (int) ($r['units_per_pack'] ?? 1),
                'mrp'                => $r['mrp'] !== null ? (float) $r['mrp'] : 0,
                'manufacturer_short' => $r['manufacturer_short'],
                'product_category'   => $r['product_category'],
                'schedule_category'  => $r['schedule_category'],
            ];
        }
        return $this->success($rows, 'Products for opening stock match.');
    }

    private function masterDb(): string
    {
        $name = (string) (\App\Support\Env::get('DB_MASTER', 'pharma_master'));
        return preg_replace('/[^a-zA-Z0-9_]/', '', $name) ?: 'pharma_master';
    }

    /** @return array{0: float, 1: float} PTR, PTS per pack */
    private function computeWholesaleRates(int $clientId, string $category, float $packMrp, float $gstRate): array
    {
        if ($packMrp <= 0) {
            return [0.0, 0.0];
        }
        $margin = (new RateMargin())->forCategory($clientId, $category !== '' ? $category : 'Allopathy');
        $ptrMargin = (float) ($margin['ptr_margin'] ?? 20);
        $ptsMargin = (float) ($margin['pts_margin'] ?? 30);
        return [
            round($packMrp * (1 - $ptrMargin / 100), 2),
            round($packMrp * (1 - $ptsMargin / 100), 2),
        ];
    }

    private function nextOpeningRef(int $clientId, int $branchId): string
    {
        $year = date('y');
        $stmt = Connection::ops()->prepare(
            "SELECT COUNT(DISTINCT note) AS c FROM stock_ledger
             WHERE client_id = :c AND branch_id = :b AND txn_type = 'opening'
               AND note LIKE :pfx AND YEAR(txn_date) = YEAR(CURDATE())"
        );
        $pfx = 'OS/' . $branchId . '/' . $year . '/%';
        $stmt->execute(['c' => $clientId, 'b' => $branchId, 'pfx' => $pfx]);
        $seq = (int) ($stmt->fetchColumn() ?: 0) + 1;
        return 'OS/' . $branchId . '/' . $year . '/' . $seq;
    }

    public function openingList(Request $request): Response
    {
        $branchClause = '';
        $bind = ['c' => $request->clientId()];
        if ($request->branchId() !== null) {
            $branchClause = ' AND l.branch_id = :b';
            $bind['b'] = $request->branchId();
        }
        // Show remaining batch qty (not original opening qty_in) so transfers/sales
        // reduce what appears here; fully consumed batches are omitted.
        $stmt = Connection::ops()->prepare(
            "SELECT l.batch_id AS batch_id,
                    MAX(l.txn_date) AS txn_date,
                    pb.product_name,
                    pb.manufacturer_short,
                    ib.batch_no,
                    ib.expiry_date,
                    ib.mrp,
                    ib.purchase_rate AS net_rate,
                    ib.quantity AS quantity,
                    MAX(l.note) AS note
             FROM stock_ledger l
             JOIN product_bindings pb ON pb.id = l.product_binding_id
             JOIN inventory_batches ib ON ib.id = l.batch_id
             WHERE l.client_id = :c {$branchClause} AND l.txn_type = 'opening'
               AND ib.quantity > 0
             GROUP BY l.batch_id, pb.product_name, pb.manufacturer_short,
                      ib.batch_no, ib.expiry_date, ib.mrp, ib.purchase_rate, ib.quantity
             ORDER BY MAX(l.id) DESC
             LIMIT 200"
        );
        $stmt->execute($bind);
        return $this->success($stmt->fetchAll(), 'Opening stock entries.');
    }

    /** Update an opening-stock batch (batch / expiry / MRP / net rate / remaining qty). */
    public function openingUpdate(Request $request): Response
    {
        if ($request->branchId() === null) {
            throw HttpException::forbidden('A branch context is required.');
        }
        $batchId = (int) $request->attribute('id');
        $data = $this->validate($request, [
            'batch_no'      => 'required|string|max:60',
            'quantity'      => 'required|integer|min:0',
            'mrp'           => 'numeric|min:0',
            'purchase_rate' => 'numeric|min:0',
        ]);

        $clientId = (int) $request->clientId();
        $branchId = (int) $request->branchId();
        $batch = $this->findOpeningBatch($batchId, $clientId, $branchId);

        $newQty = (int) $data['quantity'];
        $oldQty = (int) $batch['quantity'];
        $expiry = $this->normalizeExpiry($request->input('expiry_date'));
        $inv = new InventoryService();

        $bindingId = (int) $batch['product_binding_id'];

        $db = Connection::ops();
        $upd = $db->prepare(
            'UPDATE inventory_batches
             SET batch_no = :batch, expiry_date = :exp, mrp = :mrp, purchase_rate = :rate,
                 quantity = :q, updated_at = :now
             WHERE id = :id'
        );
        $upd->execute([
            'batch' => (string) $data['batch_no'],
            'exp'   => $expiry,
            'mrp'   => (float) ($data['mrp'] ?? 0),
            'rate'  => (float) ($data['purchase_rate'] ?? 0),
            'q'     => $newQty,
            'now'   => now(),
            'id'    => $batchId,
        ]);

        $packRaw = trim((string) ($request->input('pack_size') ?? ''));
        $lineUpp = (int) ($request->input('units_per_pack') ?? 0);
        if ($packRaw !== '' || $lineUpp > 0) {
            $parsed = PackSize::parse($packRaw !== '' ? $packRaw : '1 unit');
            $upp = max(1, $lineUpp > 0 ? $lineUpp : (int) ($parsed['units_per_pack'] ?? 1));
            $packLabel = $packRaw !== ''
                ? mb_substr(strtoupper((string) ($parsed['pack_size'] ?: $packRaw)), 0, 80)
                : null;
            $db->prepare(
                'UPDATE product_bindings
                 SET pack_size = COALESCE(NULLIF(:pack, ""), pack_size),
                     units_per_pack = :upp,
                     updated_at = :now
                 WHERE id = :id AND client_id = :c'
            )->execute([
                'pack' => $packLabel,
                'upp'  => $upp,
                'now'  => now(),
                'id'   => $bindingId,
                'c'    => $clientId,
            ]);
        }

        $delta = $newQty - $oldQty;
        if ($delta !== 0) {
            $inv->bumpAggregate($clientId, $branchId, $bindingId, $delta);
            $inv->ledger(
                $clientId,
                $branchId,
                $bindingId,
                date('Y-m-d'),
                'opening',
                $batchId,
                'Opening stock adjusted'
            );
        }

        return $this->success(['batch_id' => $batchId], 'Opening stock updated.');
    }

    /** Remove remaining stock for an opening batch. */
    public function openingDestroy(Request $request): Response
    {
        if ($request->branchId() === null) {
            throw HttpException::forbidden('A branch context is required.');
        }
        $batchId = (int) $request->attribute('id');
        $clientId = (int) $request->clientId();
        $branchId = (int) $request->branchId();
        $batch = $this->findOpeningBatch($batchId, $clientId, $branchId);

        $qty = (int) $batch['quantity'];
        if ($qty > 0) {
            $inv = new InventoryService();
            $inv->reduceBatch($batchId, $qty, 'opening', date('Y-m-d'), 'opening', $batchId);
            $inv->bumpAggregate($clientId, $branchId, (int) $batch['product_binding_id'], -$qty);
        }

        return $this->success(['batch_id' => $batchId], 'Opening stock deleted.');
    }

    /** @return array<string, mixed> */
    private function findOpeningBatch(int $batchId, int $clientId, int $branchId): array
    {
        $stmt = Connection::ops()->prepare(
            'SELECT ib.*
             FROM inventory_batches ib
             WHERE ib.id = :id AND ib.client_id = :c AND ib.branch_id = :b
             LIMIT 1'
        );
        $stmt->execute(['id' => $batchId, 'c' => $clientId, 'b' => $branchId]);
        $batch = $stmt->fetch();
        if (!$batch) {
            throw HttpException::notFound('Opening stock entry not found.');
        }

        $chk = Connection::ops()->prepare(
            "SELECT 1 FROM stock_ledger
             WHERE batch_id = :id AND client_id = :c AND txn_type = 'opening' LIMIT 1"
        );
        $chk->execute(['id' => $batchId, 'c' => $clientId]);
        if (!$chk->fetch()) {
            throw HttpException::validation(['batch' => ['This batch is not an opening stock entry.']]);
        }

        return $batch;
    }

    /** Write off expired batches (expiry < today) -> ledger 'expiry' OUT, qty 0. */
    public function writeOffExpired(Request $request): Response
    {
        $branchId = (int) $request->branchId();
        $clientId = (int) $request->clientId();
        $branchClause = $branchId > 0 ? ' AND branch_id = :b' : '';
        $bind = ['c' => $clientId];
        if ($branchId > 0) {
            $bind['b'] = $branchId;
        }

        $batches = Connection::ops()->prepare(
            "SELECT id, quantity FROM inventory_batches
             WHERE client_id = :c {$branchClause} AND quantity > 0 AND expiry_date IS NOT NULL AND expiry_date < CURDATE()"
        );
        $batches->execute($bind);
        $rows = $batches->fetchAll();

        $inv = new InventoryService();
        $count = 0;
        $qty = 0;
        foreach ($rows as $b) {
            $inv->reduceBatch((int) $b['id'], (int) $b['quantity'], 'expiry', date('Y-m-d'), 'expiry', null);
            // keep aggregate in sync
            $stmt = Connection::ops()->prepare(
                'UPDATE inventory_stock st JOIN inventory_batches ib ON ib.product_binding_id = st.product_binding_id AND ib.branch_id = st.branch_id
                 SET st.quantity = GREATEST(st.quantity - :q, 0) WHERE ib.id = :id'
            );
            $stmt->execute(['q' => (int) $b['quantity'], 'id' => (int) $b['id']]);
            $count++;
            $qty += (int) $b['quantity'];
        }

        return $this->success(['batches' => $count, 'quantity' => $qty], "Wrote off {$count} expired batch(es).");
    }

    /** Retail parity: batches for Stock Adjustment grid. */
    public function adjustmentBatches(Request $request): Response
    {
        if ($request->branchId() === null) {
            throw HttpException::forbidden('A branch context is required.');
        }
        $svc = new StockAdjustmentService();
        $svc->ensureSchema();
        $search = trim((string) $request->query('search', ''));
        $rows = $svc->listBatches((int) $request->clientId(), (int) $request->branchId(), $search);
        return $this->success($rows, 'Adjustment batches loaded.');
    }

    /** Retail parity: physical vs system → ADJUSTMENT IN/OUT on inventory. */
    public function adjustmentStore(Request $request): Response
    {
        if ($request->branchId() === null) {
            throw HttpException::forbidden('A branch context is required.');
        }
        $user = $request->user();
        $entryBy = is_array($user)
            ? trim((string) ($user['name'] ?? $user['email'] ?? 'SYSTEM'))
            : 'SYSTEM';
        $userId = (int) ($user['id'] ?? 0);
        if ($userId > 0) {
            try {
                $st = Connection::ho()->prepare('SELECT name FROM users WHERE id = :id LIMIT 1');
                $st->execute(['id' => $userId]);
                $dbName = trim((string) ($st->fetchColumn() ?: ''));
                if ($dbName !== '') {
                    $entryBy = $dbName;
                }
            } catch (\Throwable) {
                // Fall back to the name in the token.
            }
        }

        $result = (new StockAdjustmentService())->adjust(
            (int) $request->clientId(),
            (int) $request->branchId(),
            [
                'adjustment_type' => $request->input('adjustment_type', 'PHYSICAL_COUNT'),
                'remarks'         => $request->input('remarks', ''),
                'entry_by'        => $entryBy,
                'lines'           => $request->input('lines', []),
            ],
            [
                'user_id'    => $userId > 0 ? $userId : null,
                'user_name'  => $entryBy,
                'user_role'  => (string) ($user['role'] ?? ''),
                'ip_address' => $this->clientIp(),
                'user_agent' => $request->userAgent(),
            ]
        );

        $msg = 'Stock adjustment saved: ' . $result['adjustment_no'];
        if ($result['errors'] !== []) {
            return $this->success($result, $msg . ' (some lines failed)');
        }
        return $this->created($result, $msg);
    }

    /** First valid address from X-Forwarded-For (proxy), else REMOTE_ADDR. */
    private function clientIp(): string
    {
        foreach (explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')) as $part) {
            $ip = trim($part);
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
    }

    /**
     * Repair historical free-pack undercount so sales form stock matches
     * (qty + free) × units_per_pack. Safe to re-run (idempotent).
     */
    public function repairFreePackUnits(Request $request): Response
    {
        $clientId = (int) $request->clientId();
        $branchId = $request->branchId() !== null ? (int) $request->branchId() : null;
        $result = (new PurchaseService())->repairFreePackUndercount($clientId, $branchId);
        return $this->success($result, sprintf(
            'Free-pack stock repair: fixed %d line(s), added %d unit(s).',
            $result['lines_fixed'],
            $result['units_added']
        ));
    }

    private function normalizeExpiry(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('#^(\d{1,2})[/-](\d{2,4})$#', $raw, $m)) {
            $month = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $year = strlen($m[2]) === 2 ? '20' . $m[2] : $m[2];
            return date('Y-m-t', strtotime("$year-$month-01"));
        }
        $ts = strtotime($raw);
        return $ts ? date('Y-m-d', $ts) : null;
    }
}
