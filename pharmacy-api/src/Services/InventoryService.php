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
        ?int $refId = null
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
        $this->ledger($clientId, $branchId, $bindingId, $batchId, $txnType, $quantity, 0, $txnDate, $refType, $refId);

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

    /** Deduct stock (used by sales/transfer/expiry) and log an OUT movement. */
    public function reduceBatch(
        int $batchId,
        int $quantity,
        string $txnType = 'sale',
        ?string $txnDate = null,
        ?string $refType = null,
        ?int $refId = null
    ): void {
        $db = Connection::ops();
        $row = $db->prepare('SELECT client_id, branch_id, product_binding_id FROM inventory_batches WHERE id = :id');
        $row->execute(['id' => $batchId]);
        $b = $row->fetch();

        $stmt = $db->prepare('UPDATE inventory_batches SET quantity = quantity - :q, updated_at = :now WHERE id = :id');
        $stmt->execute(['q' => $quantity, 'now' => now(), 'id' => $batchId]);

        if ($b) {
            $this->ledger(
                (int) $b['client_id'], (int) $b['branch_id'], (int) $b['product_binding_id'], $batchId,
                $txnType, 0, $quantity, $txnDate, $refType, $refId
            );
        }
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
}
