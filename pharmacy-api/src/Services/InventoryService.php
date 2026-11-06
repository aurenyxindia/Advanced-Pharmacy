<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;

/**
 * Maintains batch-wise stock (inventory_batches) and the aggregate
 * per-product stock (inventory_stock). All writes target the ops connection
 * so they participate in any surrounding ops transaction.
 */
final class InventoryService
{
    /**
     * Add stock from a purchase line into a specific batch and bump the aggregate.
     *
     * @return int inventory_batches.id
     */
    public function addStock(
        int $clientId,
        int $branchId,
        int $bindingId,
        string $batchNo,
        ?string $expiryDate,
        float $mrp,
        float $costPerUnit,
        int $quantity,
        ?string $shelf = null,
        float $ptr = 0,
        float $pts = 0,
        string $txnType = 'purchase',
        ?string $txnDate = null,
        ?string $refType = null,
        ?int $refId = null,
        ?string $note = null
    ): int {
        $db = Connection::ops();

        // Merge into an existing identical batch if present.
        $stmt = $db->prepare(
            'SELECT id, quantity FROM inventory_batches
             WHERE client_id = :c AND branch_id = :b AND product_binding_id = :p AND batch_no = :batch
             LIMIT 1'
        );
        $stmt->execute(['c' => $clientId, 'b' => $branchId, 'p' => $bindingId, 'batch' => $batchNo]);
        $existing = $stmt->fetch();

        if ($existing) {
            $batchId = (int) $existing['id'];
            $upd = $db->prepare(
                'UPDATE inventory_batches
                 SET quantity = quantity + :q, mrp = :mrp, purchase_rate = :rate, expiry_date = :exp,
                     shelf_location = COALESCE(:shelf, shelf_location), ptr = :ptr, pts = :pts, updated_at = :now
                 WHERE id = :id'
            );
            $upd->execute([
                'q' => $quantity, 'mrp' => $mrp, 'rate' => $costPerUnit,
                'exp' => $expiryDate, 'shelf' => $shelf, 'ptr' => $ptr, 'pts' => $pts, 'now' => now(), 'id' => $batchId,
            ]);
        } else {
            $ins = $db->prepare(
                'INSERT INTO inventory_batches
                    (client_id, branch_id, product_binding_id, batch_no, expiry_date, shelf_location, mrp, ptr, pts, purchase_rate, quantity, created_at, updated_at)
                 VALUES (:c, :b, :p, :batch, :exp, :shelf, :mrp, :ptr, :pts, :rate, :q, :now, :now2)'
            );
            $ins->execute([
                'c' => $clientId, 'b' => $branchId, 'p' => $bindingId, 'batch' => $batchNo,
                'exp' => $expiryDate, 'shelf' => $shelf, 'mrp' => $mrp, 'ptr' => $ptr, 'pts' => $pts,
                'rate' => $costPerUnit, 'q' => $quantity, 'now' => now(), 'now2' => now(),
            ]);
            $batchId = (int) $db->lastInsertId();
        }

        $this->bumpAggregate($clientId, $branchId, $bindingId, $quantity);
        $this->ledger($clientId, $branchId, $bindingId, $batchId, $txnType, $quantity, 0, $txnDate, $refType, $refId, $note);

        return $batchId;
    }

    /** Records a stock movement into the ledger (basis for stock book / FDA tally). */
    public function ledger(
        int $clientId,
        int $branchId,
        int $bindingId,
        ?int $batchId,
        string $txnType,
        int $qtyIn,
        int $qtyOut,
        ?string $txnDate = null,
        ?string $refType = null,
        ?int $refId = null,
        ?string $note = null
    ): void {
        $stmt = Connection::ops()->prepare(
            'INSERT INTO stock_ledger
                (client_id, branch_id, product_binding_id, batch_id, txn_date, txn_type, qty_in, qty_out, ref_type, ref_id, note, created_at)
             VALUES (:c, :b, :p, :batch, :date, :type, :in, :out, :rt, :rid, :note, :now)'
        );
        $stmt->execute([
            'c' => $clientId, 'b' => $branchId, 'p' => $bindingId, 'batch' => $batchId,
            'date' => $txnDate ?: date('Y-m-d'), 'type' => $txnType, 'in' => $qtyIn, 'out' => $qtyOut,
            'rt' => $refType, 'rid' => $refId, 'note' => $note, 'now' => now(),
        ]);
    }

    /**
     * Deduct stock (sales / transfer / purchase reverse / adjustment) and log OUT.
     * Never drives batch qty below zero — returns the units actually removed
     * (may be less than requested when stock was already sold or adjusted down).
     */
    public function reduceBatch(
        int $batchId,
        int $quantity,
        string $txnType = 'sale',
        ?string $txnDate = null,
        ?string $refType = null,
        ?int $refId = null
    ): int {
        $db = Connection::ops();
        $row = $db->prepare(
            'SELECT client_id, branch_id, product_binding_id, quantity FROM inventory_batches WHERE id = :id LIMIT 1'
        );
        $row->execute(['id' => $batchId]);
        $b = $row->fetch();
        if (!$b) {
            return 0;
        }

        $want = max(0, $quantity);
        $have = (int) $b['quantity'];
        // Heal legacy negative rows so later maths stay sane.
        if ($have < 0) {
            $db->prepare('UPDATE inventory_batches SET quantity = 0, updated_at = :now WHERE id = :id')
                ->execute(['now' => now(), 'id' => $batchId]);
            $have = 0;
        }
        $take = min($want, $have);
        if ($take <= 0) {
            return 0;
        }

        $stmt = $db->prepare(
            'UPDATE inventory_batches
             SET quantity = GREATEST(0, quantity - :q), updated_at = :now
             WHERE id = :id'
        );
        $stmt->execute(['q' => $take, 'now' => now(), 'id' => $batchId]);

        $this->ledger(
            (int) $b['client_id'],
            (int) $b['branch_id'],
            (int) $b['product_binding_id'],
            $batchId,
            $txnType,
            0,
            $take,
            $txnDate,
            $refType,
            $refId
        );

        return $take;
    }

    /**
     * Apply an exact signed unit delta to a batch (purchase edit pack/qty).
     * stock_after = stock_before + delta — preserves sales and physical adjustments.
     *
     * @return int applied delta (0 if no-op / missing batch)
     * @throws \RuntimeException when delta would drive on-hand below zero
     */
    public function adjustBatchByDelta(
        int $batchId,
        int $delta,
        string $txnType = 'adjustment',
        ?string $txnDate = null,
        ?string $refType = null,
        ?int $refId = null,
        ?string $note = null,
        bool $allowNegativeFloor = false
    ): int {
        if ($delta === 0) {
            return 0;
        }
        $db = Connection::ops();
        $row = $db->prepare(
            'SELECT client_id, branch_id, product_binding_id, quantity
             FROM inventory_batches WHERE id = :id LIMIT 1 FOR UPDATE'
        );
        $row->execute(['id' => $batchId]);
        $b = $row->fetch();
        if (!$b) {
            return 0;
        }

        $have = (int) $b['quantity'];
        $newQty = $have + $delta;
        if ($newQty < 0) {
            if (!$allowNegativeFloor) {
                throw new \RuntimeException(
                    "Insufficient on-hand for stock delta (have {$have}, delta {$delta})."
                );
            }
            // Last-resort floor (confirmed sold edit): remove only what is left.
            $delta = -$have;
            $newQty = 0;
            if ($delta === 0) {
                return 0;
            }
        }

        $db->prepare(
            'UPDATE inventory_batches SET quantity = :q, updated_at = :now WHERE id = :id'
        )->execute(['q' => $newQty, 'now' => now(), 'id' => $batchId]);

        $qtyIn = $delta > 0 ? $delta : 0;
        $qtyOut = $delta < 0 ? -$delta : 0;
        $this->ledger(
            (int) $b['client_id'],
            (int) $b['branch_id'],
            (int) $b['product_binding_id'],
            $batchId,
            $txnType,
            $qtyIn,
            $qtyOut,
            $txnDate,
            $refType,
            $refId,
            $note
        );

        $this->bumpAggregate(
            (int) $b['client_id'],
            (int) $b['branch_id'],
            (int) $b['product_binding_id'],
            $delta
        );

        return $delta;
    }

    public function bumpAggregate(int $clientId, int $branchId, int $bindingId, int $delta): void
    {
        $db = Connection::ops();
        $stmt = $db->prepare(
            'INSERT INTO inventory_stock (client_id, branch_id, product_binding_id, quantity, reorder_level, updated_at)
             VALUES (:c, :b, :p, :q, 0, :now)
             ON DUPLICATE KEY UPDATE quantity = quantity + :q2, updated_at = :now2'
        );
        $stmt->execute([
            'c' => $clientId, 'b' => $branchId, 'p' => $bindingId, 'q' => $delta,
            'q2' => $delta, 'now' => now(), 'now2' => now(),
        ]);
    }

    /** SQL fragment: live units available from batch rows (source of truth for billing UI). */
    public static function availableQtyExpr(string $bindingAlias = 'pb'): string
    {
        return "COALESCE((
            SELECT SUM(ib.quantity)
            FROM inventory_batches ib
            WHERE ib.client_id = {$bindingAlias}.client_id
              AND ib.branch_id = {$bindingAlias}.branch_id
              AND ib.product_binding_id = {$bindingAlias}.id
        ), 0)";
    }

    /**
     * Sellable units only — Retail parity: expired batches must not be sold.
     * Expiry is end-of-month DATE; expired when expiry_date &lt; today.
     */
    public static function sellableQtyExpr(string $bindingAlias = 'pb'): string
    {
        return "COALESCE((
            SELECT SUM(ib.quantity)
            FROM inventory_batches ib
            WHERE ib.client_id = {$bindingAlias}.client_id
              AND ib.branch_id = {$bindingAlias}.branch_id
              AND ib.product_binding_id = {$bindingAlias}.id
              AND (ib.expiry_date IS NULL OR ib.expiry_date >= CURDATE())
        ), 0)";
    }

    /** Expired units still on shelf (for Expired mark on sale search). */
    public static function expiredQtyExpr(string $bindingAlias = 'pb'): string
    {
        return "COALESCE((
            SELECT SUM(ib.quantity)
            FROM inventory_batches ib
            WHERE ib.client_id = {$bindingAlias}.client_id
              AND ib.branch_id = {$bindingAlias}.branch_id
              AND ib.product_binding_id = {$bindingAlias}.id
              AND ib.expiry_date IS NOT NULL
              AND ib.expiry_date < CURDATE()
        ), 0)";
    }

    /** True when batch expiry date is before today (null expiry = not expired). */
    public static function isBatchExpired(?string $expiryDate): bool
    {
        if ($expiryDate === null || trim($expiryDate) === '') {
            return false;
        }
        $ts = strtotime(substr($expiryDate, 0, 10));
        if ($ts === false) {
            return false;
        }
        return date('Y-m-d', $ts) < date('Y-m-d');
    }

    /** Set aggregate stock = sum of batch quantities (fixes drift after purchase edit / sale). */
    public function reconcileAggregate(int $clientId, int $branchId, int $bindingId): void
    {
        if ($bindingId <= 0) {
            return;
        }
        $db = Connection::ops();
        $sumStmt = $db->prepare(
            'SELECT COALESCE(SUM(quantity), 0)
             FROM inventory_batches
             WHERE client_id = :c AND branch_id = :b AND product_binding_id = :p'
        );
        $sumStmt->execute(['c' => $clientId, 'b' => $branchId, 'p' => $bindingId]);
        $qty = (int) $sumStmt->fetchColumn();

        $stmt = $db->prepare(
            'INSERT INTO inventory_stock (client_id, branch_id, product_binding_id, quantity, reorder_level, updated_at)
             VALUES (:c, :b, :p, :q, 0, :now)
             ON DUPLICATE KEY UPDATE quantity = :q2, updated_at = :now2'
        );
        $stmt->execute([
            'c' => $clientId,
            'b' => $branchId,
            'p' => $bindingId,
            'q' => $qty,
            'q2' => $qty,
            'now' => now(),
            'now2' => now(),
        ]);
    }

    /** @param int[] $bindingIds */
    public function reconcileAggregates(int $clientId, int $branchId, array $bindingIds): void
    {
        foreach (array_unique(array_values(array_filter(
            array_map('intval', $bindingIds),
            static fn (int $id): bool => $id > 0
        ))) as $bindingId) {
            $this->reconcileAggregate($clientId, $branchId, $bindingId);
        }
    }
}
