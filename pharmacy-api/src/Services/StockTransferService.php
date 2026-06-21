<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Database\Connection;
use App\Models\Branch;
use PDO;

/**
 * Moves stock between two branches of the same client via a transfer document.
 * Source stock is consumed FEFO; destination is bound (if needed) and credited
 * at the same landed cost.
 */
final class StockTransferService
{
    private ProductBindingService $binder;
    private InventoryService $inventory;

    public function __construct()
    {
        $this->binder = new ProductBindingService();
        $this->inventory = new InventoryService();
    }

    public function transfer(int $clientId, ?int $userId, array $data): array
    {
        $fromBranch = (int) ($data['from_branch_id'] ?? 0);
        $toBranch   = (int) ($data['to_branch_id'] ?? 0);
        $items      = $data['items'] ?? [];

        if ($fromBranch <= 0 || $toBranch <= 0 || $fromBranch === $toBranch) {
            throw HttpException::validation(['branch' => ['Choose two different branches.']]);
        }
        if (!is_array($items) || $items === []) {
            throw HttpException::validation(['items' => ['Add at least one product to transfer.']]);
        }

        // Validate both branches belong to the client (HO database).
        $branches = new Branch();
        foreach ([$fromBranch, $toBranch] as $bid) {
            $b = $branches->find($bid);
            if (!$b || (int) $b['client_id'] !== $clientId) {
                throw HttpException::notFound("Branch {$bid} not found.");
            }
        }

        return Connection::transaction('ops', function (PDO $db) use ($clientId, $userId, $fromBranch, $toBranch, $items, $data) {
            $totalValue = 0.0;
            $computed = [];

            foreach ($items as $i => $item) {
                $sourceBindingId = (int) ($item['product_binding_id'] ?? 0);
                $qty = (int) ($item['quantity'] ?? 0);
                if ($sourceBindingId <= 0 || $qty <= 0) {
                    throw HttpException::validation(["items.$i" => ['Invalid product or quantity.']]);
                }

                // Source binding (must belong to from-branch).
                $bind = $db->prepare(
                    'SELECT * FROM product_bindings WHERE id = :id AND client_id = :c AND branch_id = :b LIMIT 1'
                );
                $bind->execute(['id' => $sourceBindingId, 'c' => $clientId, 'b' => $fromBranch]);
                $binding = $bind->fetch();
                if (!$binding) {
                    throw HttpException::notFound("Source product (binding {$sourceBindingId}) not found in the from-branch.");
                }

                [$batchNo, $cost] = $this->consumeSource($db, $clientId, $fromBranch, $sourceBindingId, $qty);

                // Bind to destination branch and credit stock.
                $destBind = $this->binder->bind($clientId, $toBranch, (int) $binding['master_product_id']);
                $this->inventory->addStock(
                    $clientId,
                    $toBranch,
                    (int) $destBind['id'],
                    (string) ($batchNo ?: 'TRF'),
                    null,
                    0.0,
                    $cost,
                    $qty
                );

                $lineValue = $cost * $qty;
                $totalValue += $lineValue;
                $computed[] = [
                    'master_product_id' => (int) $binding['master_product_id'],
                    'product_name'      => $binding['product_name'],
                    'batch_no'          => $batchNo,
                    'quantity'          => $qty,
                    'cost_per_unit'     => round($cost, 4),
                    'line_value'        => round($lineValue, 2),
                ];
            }

            $transferNo = $data['transfer_no'] ?? ('TRF-' . time());
            $stmt = $db->prepare(
                'INSERT INTO stock_transfers
                    (client_id, from_branch_id, to_branch_id, transfer_no, transfer_date, total_value, notes, created_by, created_at)
                 VALUES (:c, :from, :to, :no, :date, :val, :notes, :by, :now)'
            );
            $stmt->execute([
                'c' => $clientId, 'from' => $fromBranch, 'to' => $toBranch, 'no' => $transferNo,
                'date' => $data['transfer_date'] ?? date('Y-m-d'), 'val' => round($totalValue, 2),
                'notes' => $data['notes'] ?? null, 'by' => $userId, 'now' => now(),
            ]);
            $transferId = (int) $db->lastInsertId();

            $itemStmt = $db->prepare(
                'INSERT INTO stock_transfer_items
                    (transfer_id, master_product_id, product_name, batch_no, quantity, cost_per_unit, line_value)
                 VALUES (:tid, :mp, :name, :batch, :qty, :cost, :val)'
            );
            foreach ($computed as $c) {
                $itemStmt->execute([
                    'tid' => $transferId, 'mp' => $c['master_product_id'], 'name' => $c['product_name'],
                    'batch' => $c['batch_no'], 'qty' => $c['quantity'], 'cost' => $c['cost_per_unit'], 'val' => $c['line_value'],
                ]);
            }

            return [
                'id'          => $transferId,
                'transfer_no' => $transferNo,
                'total_value' => round($totalValue, 2),
                'items'       => count($computed),
            ];
        });
    }

    /** Consume qty from a branch's batches FEFO. Returns [primaryBatchNo, weightedCost]. */
    private function consumeSource(PDO $db, int $clientId, int $branchId, int $bindingId, int $qty): array
    {
        $stmt = $db->prepare(
            'SELECT id, batch_no, quantity, purchase_rate FROM inventory_batches
             WHERE client_id = :c AND branch_id = :b AND product_binding_id = :p AND quantity > 0
             ORDER BY (expiry_date IS NULL), expiry_date ASC, id ASC'
        );
        $stmt->execute(['c' => $clientId, 'b' => $branchId, 'p' => $bindingId]);
        $batches = $stmt->fetchAll();

        $available = array_sum(array_map(static fn ($b) => (int) $b['quantity'], $batches));
        if ($available < $qty) {
            throw HttpException::validation(['stock' => ["Insufficient stock at source (have {$available}, need {$qty})."]]);
        }

        $remaining = $qty;
        $costAccum = 0.0;
        $primaryBatch = null;
        foreach ($batches as $batch) {
            if ($remaining <= 0) break;
            $take = min($remaining, (int) $batch['quantity']);
            $costAccum += $take * (float) $batch['purchase_rate'];
            $primaryBatch ??= $batch['batch_no'];
            $this->inventory->reduceBatch((int) $batch['id'], $take);
            $remaining -= $take;
        }
        $this->inventory->bumpAggregate($clientId, $branchId, $bindingId, -$qty);

        return [$primaryBatch, $qty > 0 ? $costAccum / $qty : 0.0];
    }
}
