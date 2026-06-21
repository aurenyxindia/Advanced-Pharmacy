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
        float $pts = 0
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

        return $batchId;
    }

    /** Deduct stock (used by sales). Returns true on success. */
    public function reduceBatch(int $batchId, int $quantity): void
    {
        $db = Connection::ops();
        $stmt = $db->prepare('UPDATE inventory_batches SET quantity = quantity - :q, updated_at = :now WHERE id = :id');
        $stmt->execute(['q' => $quantity, 'now' => now(), 'id' => $batchId]);
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
