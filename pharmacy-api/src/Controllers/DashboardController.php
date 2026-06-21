<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Database\Connection;
use Throwable;

/**
 * Aggregated analytics for the dashboard. Each metric is computed defensively
 * so that modules not yet populated (purchase/sales) return safe zero values.
 */
final class DashboardController extends Controller
{
    public function summary(Request $request): Response
    {
        $clientId = $request->clientId();
        $branchId = $request->branchId();
        $scope    = $request->user()['scope'] ?? 'branch';

        // HO admins see all branches of the client; branch users see their branch only.
        $branchFilter = $scope === 'ho' ? null : $branchId;

        return $this->success([
            'kpis'           => $this->kpis($clientId, $branchFilter),
            'sales_trend'    => $this->salesTrend($clientId, $branchFilter),
            'top_products'   => $this->topProducts($clientId, $branchFilter),
            'category_split' => $this->categorySplit($clientId, $branchFilter),
            'expiry_buckets' => $this->expiryBuckets($clientId, $branchFilter),
            'recent_sales'   => $this->recentSales($clientId, $branchFilter),
            'generated_at'   => now(),
            'scope'          => $scope,
        ], 'Dashboard summary.');
    }

    private function kpis(?int $clientId, ?int $branchId): array
    {
        return [
            'today_sales'      => $this->scalar('ops', "SELECT COALESCE(SUM(net_amount),0) FROM sales WHERE client_id = ? AND DATE(invoice_date) = CURDATE()" . $this->branchClause($branchId), $this->bind($clientId, $branchId)),
            'today_purchase'   => $this->scalar('ops', "SELECT COALESCE(SUM(net_amount),0) FROM purchases WHERE client_id = ? AND DATE(invoice_date) = CURDATE()" . $this->branchClause($branchId), $this->bind($clientId, $branchId)),
            'month_sales'      => $this->scalar('ops', "SELECT COALESCE(SUM(net_amount),0) FROM sales WHERE client_id = ? AND YEAR(invoice_date)=YEAR(CURDATE()) AND MONTH(invoice_date)=MONTH(CURDATE())" . $this->branchClause($branchId), $this->bind($clientId, $branchId)),
            'month_profit'     => $this->scalar('ops', "SELECT COALESCE(SUM(profit_amount),0) FROM sales WHERE client_id = ? AND YEAR(invoice_date)=YEAR(CURDATE()) AND MONTH(invoice_date)=MONTH(CURDATE())" . $this->branchClause($branchId), $this->bind($clientId, $branchId)),
            'product_count'    => $this->scalar('ops', "SELECT COUNT(*) FROM product_bindings WHERE client_id = ?" . $this->branchClause($branchId), $this->bind($clientId, $branchId)),
            'low_stock_count'  => $this->scalar('ops', "SELECT COUNT(*) FROM inventory_stock WHERE client_id = ? AND quantity <= reorder_level" . $this->branchClause($branchId), $this->bind($clientId, $branchId)),
            'expiring_count'   => $this->scalar('ops', "SELECT COUNT(*) FROM inventory_batches WHERE client_id = ? AND expiry_date <= (CURDATE() + INTERVAL 90 DAY) AND quantity > 0" . $this->branchClause($branchId), $this->bind($clientId, $branchId)),
            'customer_count'   => $this->scalar('ops', "SELECT COUNT(*) FROM customers WHERE client_id = ?", [$clientId]),
            'branch_count'     => $this->scalar('ho', "SELECT COUNT(*) FROM branches WHERE client_id = ?", [$clientId]),
        ];
    }

    private function salesTrend(?int $clientId, ?int $branchId): array
    {
        $rows = $this->query('ops',
            "SELECT DATE(invoice_date) AS d, COALESCE(SUM(net_amount),0) AS total
             FROM sales
             WHERE client_id = ? AND invoice_date >= (CURDATE() - INTERVAL 13 DAY)" . $this->branchClause($branchId) . "
             GROUP BY DATE(invoice_date) ORDER BY d ASC",
            $this->bind($clientId, $branchId)
        );

        // Fill the 14-day window so the chart is continuous.
        $map = [];
        foreach ($rows as $r) {
            $map[$r['d']] = (float) $r['total'];
        }
        $series = [];
        for ($i = 13; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-{$i} days"));
            $series[] = ['date' => $day, 'total' => $map[$day] ?? 0];
        }
        return $series;
    }

    private function topProducts(?int $clientId, ?int $branchId): array
    {
        return $this->query('ops',
            "SELECT product_name, COALESCE(SUM(quantity),0) AS qty, COALESCE(SUM(line_total),0) AS revenue
             FROM sale_items si JOIN sales s ON s.id = si.sale_id
             WHERE s.client_id = ?" . str_replace('branch_id', 's.branch_id', $this->branchClause($branchId)) . "
             GROUP BY product_name ORDER BY revenue DESC LIMIT 5",
            $this->bind($clientId, $branchId)
        );
    }

    private function categorySplit(?int $clientId, ?int $branchId): array
    {
        return $this->query('ops',
            "SELECT COALESCE(schedule_category,'OTC') AS category, COUNT(*) AS cnt
             FROM product_bindings WHERE client_id = ?" . $this->branchClause($branchId) . "
             GROUP BY schedule_category",
            $this->bind($clientId, $branchId)
        );
    }

    private function expiryBuckets(?int $clientId, ?int $branchId): array
    {
        return [
            'expired'   => $this->scalar('ops', "SELECT COUNT(*) FROM inventory_batches WHERE client_id = ? AND expiry_date < CURDATE() AND quantity > 0" . $this->branchClause($branchId), $this->bind($clientId, $branchId)),
            'within_30' => $this->scalar('ops', "SELECT COUNT(*) FROM inventory_batches WHERE client_id = ? AND expiry_date BETWEEN CURDATE() AND (CURDATE() + INTERVAL 30 DAY) AND quantity > 0" . $this->branchClause($branchId), $this->bind($clientId, $branchId)),
            'within_90' => $this->scalar('ops', "SELECT COUNT(*) FROM inventory_batches WHERE client_id = ? AND expiry_date BETWEEN (CURDATE() + INTERVAL 31 DAY) AND (CURDATE() + INTERVAL 90 DAY) AND quantity > 0" . $this->branchClause($branchId), $this->bind($clientId, $branchId)),
        ];
    }

    private function recentSales(?int $clientId, ?int $branchId): array
    {
        return $this->query('ops',
            "SELECT invoice_no, invoice_date, customer_name, net_amount
             FROM sales WHERE client_id = ?" . $this->branchClause($branchId) . "
             ORDER BY invoice_date DESC, id DESC LIMIT 8",
            $this->bind($clientId, $branchId)
        );
    }

    // ---- helpers -------------------------------------------------------

    private function branchClause(?int $branchId): string
    {
        return $branchId !== null ? ' AND branch_id = ?' : '';
    }

    private function bind(?int $clientId, ?int $branchId): array
    {
        return $branchId !== null ? [$clientId, $branchId] : [$clientId];
    }

    private function scalar(string $conn, string $sql, array $bindings): float|int
    {
        try {
            $stmt = Connection::get($conn)->prepare($sql);
            $stmt->execute($bindings);
            $value = $stmt->fetchColumn();
            return is_numeric($value) ? (str_contains((string) $value, '.') ? (float) $value : (int) $value) : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    private function query(string $conn, string $sql, array $bindings): array
    {
        try {
            $stmt = Connection::get($conn)->prepare($sql);
            $stmt->execute($bindings);
            return $stmt->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }
}
