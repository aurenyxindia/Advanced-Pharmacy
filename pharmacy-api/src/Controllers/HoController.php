<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Database\Connection;
use App\Models\Branch;
use App\Services\InventoryService;
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

    /** Products with live stock for a branch (used by HO stock transfer picker). */
    public function branchStock(Request $request): Response
    {
        $clientId = (int) $request->clientId();
        $branchId = (int) $request->query('branch_id', 0);
        if ($branchId <= 0) {
            return $this->success([], 'Select a branch.');
        }

        $branch = (new Branch())->find($branchId);
        if (!$branch || (int) $branch['client_id'] !== $clientId) {
            throw \App\Core\HttpException::notFound('Branch not found.');
        }

        $q = trim((string) $request->query('q', ''));
        $avail = InventoryService::availableQtyExpr('pb');
        $sql = "SELECT pb.id AS binding_id, pb.master_product_id, pb.product_name,
                       pb.manufacturer_short, pb.pack_size, pb.schedule_category,
                       {$avail} AS available_qty
                FROM product_bindings pb
                WHERE pb.client_id = :c AND pb.branch_id = :b AND {$avail} > 0";
        $bindings = ['c' => $clientId, 'b' => $branchId];

        if ($q !== '') {
            $sql .= ' AND (pb.product_name LIKE :q1 OR pb.manufacturer_short LIKE :q2 OR pb.pack_size LIKE :q3)';
            $like = '%' . $q . '%';
            $bindings['q1'] = $like;
            $bindings['q2'] = $like;
            $bindings['q3'] = $like;
        }

        $sql .= ' ORDER BY pb.product_name ASC LIMIT 300';
        $stmt = Connection::ops()->prepare($sql);
        $stmt->execute($bindings);

        return $this->success($stmt->fetchAll(), 'Branch stock.');
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

    /** Single transfer log with line items. */
    public function transferShow(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $clientId = (int) $request->clientId();

        $stmt = Connection::ops()->prepare(
            'SELECT * FROM stock_transfers WHERE id = :id AND client_id = :c LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'c' => $clientId]);
        $transfer = $stmt->fetch();
        if (!$transfer) {
            throw \App\Core\HttpException::notFound('Transfer not found.');
        }

        $items = Connection::ops()->prepare(
            'SELECT id, master_product_id, product_name, batch_no, quantity, cost_per_unit, line_value
             FROM stock_transfer_items WHERE transfer_id = :tid ORDER BY id ASC'
        );
        $items->execute(['tid' => $id]);
        $transfer['items'] = $items->fetchAll();

        return $this->success($transfer, 'Transfer log.');
    }

    private function scalar(string $sql, int $clientId, int $branchId): float
    {
        $stmt = Connection::ops()->prepare($sql);
        $stmt->execute(['c' => $clientId, 'b' => $branchId]);
        return (float) $stmt->fetchColumn();
    }
}
