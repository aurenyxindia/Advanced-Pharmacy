<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Database\Connection;
use App\Models\Branch;
use App\Services\StockTransferService;

/**
 * Head-Office centralized views: branch performance roll-up, central product
 * portfolio, and interbranch stock transfers.
 */
final class HoController extends Controller
{
    /** Per-branch sales / purchase / profit roll-up for the client. */
    public function branchPerformance(Request $request): Response
    {
        $clientId = (int) $request->clientId();
        $branches = (new Branch())->forClient($clientId);

        $rows = [];
        foreach ($branches as $branch) {
            $bid = (int) $branch['id'];
            $sales = $this->scalar("SELECT COALESCE(SUM(net_amount),0) FROM sales WHERE client_id=:c AND branch_id=:b", $clientId, $bid);
            $profit = $this->scalar("SELECT COALESCE(SUM(profit_amount),0) FROM sales WHERE client_id=:c AND branch_id=:b", $clientId, $bid);
            $purchase = $this->scalar("SELECT COALESCE(SUM(net_amount),0) FROM purchases WHERE client_id=:c AND branch_id=:b", $clientId, $bid);
            $skus = $this->scalar("SELECT COUNT(*) FROM product_bindings WHERE client_id=:c AND branch_id=:b", $clientId, $bid);
            $stockValue = $this->scalar(
                "SELECT COALESCE(SUM(ib.quantity * ib.purchase_rate),0) FROM inventory_batches ib WHERE ib.client_id=:c AND ib.branch_id=:b AND ib.quantity>0",
                $clientId,
                $bid
            );

            $rows[] = [
                'branch_id'      => $bid,
                'code'           => $branch['code'],
                'name'           => $branch['name'],
                'is_head_office' => (int) $branch['is_head_office'],
                'sales'          => round($sales, 2),
                'purchase'       => round($purchase, 2),
                'profit'         => round($profit, 2),
                'skus'           => (int) $skus,
                'stock_value'    => round($stockValue, 2),
            ];
        }

        return $this->success([
            'branches' => $rows,
            'totals'   => [
                'sales'       => round(array_sum(array_column($rows, 'sales')), 2),
                'purchase'    => round(array_sum(array_column($rows, 'purchase')), 2),
                'profit'      => round(array_sum(array_column($rows, 'profit')), 2),
                'stock_value' => round(array_sum(array_column($rows, 'stock_value')), 2),
            ],
        ], 'Branch performance.');
    }

    /** Central portfolio: every product bound by any branch of the client. */
    public function portfolio(Request $request): Response
    {
        $stmt = Connection::ops()->prepare(
            'SELECT pb.master_product_id, pb.product_name, pb.schedule_category,
                    COUNT(DISTINCT pb.branch_id) AS branch_count,
                    COALESCE(SUM(st.quantity),0) AS total_stock
             FROM product_bindings pb
             LEFT JOIN inventory_stock st ON st.product_binding_id = pb.id
             WHERE pb.client_id = :c
             GROUP BY pb.master_product_id, pb.product_name, pb.schedule_category
             ORDER BY pb.product_name ASC'
        );
        $stmt->execute(['c' => $request->clientId()]);
        return $this->success($stmt->fetchAll(), 'Central portfolio.');
    }

    public function transfer(Request $request): Response
    {
        $result = (new StockTransferService())->transfer(
            (int) $request->clientId(),
            $request->user()['id'] ?? null,
            $request->all()
        );
        return $this->created($result, 'Stock transferred between branches.');
    }

    public function transfers(Request $request): Response
    {
        $stmt = Connection::ops()->prepare(
            'SELECT t.*, COUNT(ti.id) AS item_count
             FROM stock_transfers t
             LEFT JOIN stock_transfer_items ti ON ti.transfer_id = t.id
             WHERE t.client_id = :c
             GROUP BY t.id ORDER BY t.transfer_date DESC, t.id DESC LIMIT 100'
        );
        $stmt->execute(['c' => $request->clientId()]);
        return $this->success($stmt->fetchAll(), 'Transfers.');
    }

    private function scalar(string $sql, int $clientId, int $branchId): float
    {
        $stmt = Connection::ops()->prepare($sql);
        $stmt->execute(['c' => $clientId, 'b' => $branchId]);
        return (float) $stmt->fetchColumn();
    }
}
