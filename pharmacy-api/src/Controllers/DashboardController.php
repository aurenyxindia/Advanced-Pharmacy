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

        $payload = [
            'kpis'                 => $this->kpis($clientId, $branchFilter),
            'sales_trend'          => $this->salesTrend($clientId, $branchFilter),
            'purchase_trend'       => $this->purchaseTrend($clientId, $branchFilter),
            'distributor_purchase' => $this->distributorPurchase($clientId, $branchFilter),
            'top_products'         => $this->topProducts($clientId, $branchFilter),
            'category_split'       => $this->categorySplit($clientId, $branchFilter),
            'expiry_buckets'       => $this->expiryBuckets($clientId, $branchFilter),
            'expiry_loss'          => $this->expiryLoss($clientId, $branchFilter),
            'profit_analytics'     => $this->profitAnalytics($clientId, $branchFilter),
            'recent_sales'         => $this->recentSales($clientId, $branchFilter),
            'generated_at'         => now(),
            'scope'                => $scope,
        ];

        if ($scope === 'ho') {
            $payload['store_analytics'] = $this->storeAnalytics($clientId);
        }

        return $this->success($payload, 'Dashboard summary.');
    }

    private function kpis(?int $clientId, ?int $branchId): array
    {
        return [
            'today_sales'      => $this->scalar('ops', "SELECT COALESCE(SUM(net_amount),0) FROM sales WHERE client_id = ? AND DATE(invoice_date) = CURDATE()" . $this->branchClause($branchId), $this->bind($clientId, $branchId)),
            'today_purchase'   => $this->scalar('ops', "SELECT COALESCE(SUM(net_amount),0) FROM purchases WHERE client_id = ? AND DATE(invoice_date) = CURDATE()" . $this->branchClause($branchId), $this->bind($clientId, $branchId)),
            'month_sales'      => $this->scalar('ops', "SELECT COALESCE(SUM(net_amount),0) FROM sales WHERE client_id = ? AND YEAR(invoice_date)=YEAR(CURDATE()) AND MONTH(invoice_date)=MONTH(CURDATE())" . $this->branchClause($branchId), $this->bind($clientId, $branchId)),
            'month_purchase'   => $this->scalar('ops', "SELECT COALESCE(SUM(net_amount),0) FROM purchases WHERE client_id = ? AND YEAR(invoice_date)=YEAR(CURDATE()) AND MONTH(invoice_date)=MONTH(CURDATE())" . $this->branchClause($branchId), $this->bind($clientId, $branchId)),
            'month_profit'     => $this->scalar('ops', "SELECT COALESCE(SUM(profit_amount),0) FROM sales WHERE client_id = ? AND YEAR(invoice_date)=YEAR(CURDATE()) AND MONTH(invoice_date)=MONTH(CURDATE())" . $this->branchClause($branchId), $this->bind($clientId, $branchId)),
            'product_count'    => $this->scalar('ops', "SELECT COUNT(*) FROM product_bindings WHERE client_id = ?" . $this->branchClause($branchId), $this->bind($clientId, $branchId)),
            'low_stock_count'  => $this->scalar('ops', "SELECT COUNT(*) FROM inventory_stock WHERE client_id = ? AND quantity <= reorder_level" . $this->branchClause($branchId), $this->bind($clientId, $branchId)),
            'expiring_count'   => $this->scalar('ops', "SELECT COUNT(*) FROM inventory_batches WHERE client_id = ? AND expiry_date BETWEEN CURDATE() AND (CURDATE() + INTERVAL 90 DAY) AND quantity > 0" . $this->branchClause($branchId), $this->bind($clientId, $branchId)),
            'expired_count'    => $this->scalar('ops', "SELECT COUNT(*) FROM inventory_batches WHERE client_id = ? AND expiry_date < CURDATE() AND quantity > 0" . $this->branchClause($branchId), $this->bind($clientId, $branchId)),
            'customer_count'   => $this->scalar('ops', "SELECT COUNT(*) FROM customers WHERE client_id = ?", [$clientId]),
            'branch_count'     => $this->scalar('ho', "SELECT COUNT(*) FROM branches WHERE client_id = ?", [$clientId]),
            'outstanding_total' => $this->outstanding($clientId, $branchId, false),
            'outstanding_today' => $this->outstanding($clientId, $branchId, true),
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

    private function purchaseTrend(?int $clientId, ?int $branchId): array
    {
        $rows = $this->query('ops',
            "SELECT DATE(invoice_date) AS d, COALESCE(SUM(net_amount),0) AS total
             FROM purchases
             WHERE client_id = ? AND invoice_date >= (CURDATE() - INTERVAL 13 DAY)" . $this->branchClause($branchId) . "
             GROUP BY DATE(invoice_date) ORDER BY d ASC",
            $this->bind($clientId, $branchId)
        );
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

    private function distributorPurchase(?int $clientId, ?int $branchId): array
    {
        return $this->query('ops',
            "SELECT COALESCE(NULLIF(supplier_name,''),'(Unknown)') AS supplier, COALESCE(SUM(net_amount),0) AS total
             FROM purchases
             WHERE client_id = ?" . $this->branchClause($branchId) . "
             GROUP BY supplier ORDER BY total DESC LIMIT 7",
            $this->bind($clientId, $branchId)
        );
    }

    /** Outstanding receivables: unpaid credit sales + open IPD balances. */
    private function outstanding(?int $clientId, ?int $branchId, bool $todayOnly): float
    {
        $dateClause = $todayOnly ? ' AND DATE(invoice_date) = CURDATE()' : '';
        $credit = $this->scalar('ops',
            "SELECT COALESCE(SUM(net_amount),0) FROM sales WHERE client_id = ? AND payment_mode = 'credit'{$dateClause}" . $this->branchClause($branchId),
            $this->bind($clientId, $branchId)
        );
        if ($todayOnly) {
            return round((float) $credit, 2);
        }
        // IPD open balances (billed - paid) for admitted patients.
        $ipd = $this->scalar('ops',
            "SELECT COALESCE(
                 (SELECT SUM(s.net_amount) FROM sales s JOIN ipd_admissions a ON a.id = s.ipd_admission_id WHERE a.client_id = ? AND a.status='admitted'),0)
               - COALESCE(
                 (SELECT SUM(p.amount) FROM ipd_payments p JOIN ipd_admissions a2 ON a2.id = p.admission_id WHERE a2.client_id = ? AND a2.status='admitted'),0)",
            [$clientId, $clientId]
        );
        return round((float) $credit + (float) $ipd, 2);
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

    /**
     * Expiry loss analytics: purchase cost vs potential sale (MRP) for batches
     * that expired in each window and still hold quantity.
     *
     * expiry_loss  = purchase value (dead stock cost)
     * revenue_loss = sale / MRP value (missed revenue)
     * margin_loss  = sale − purchase (missed profit)
     */
    private function expiryLoss(?int $clientId, ?int $branchId): array
    {
        return [
            'last_week'  => $this->expiryLossWindow(
                $clientId,
                $branchId,
                'expiry_date >= (CURDATE() - INTERVAL 7 DAY) AND expiry_date < CURDATE()'
            ),
            'last_month' => $this->expiryLossWindow(
                $clientId,
                $branchId,
                "expiry_date >= DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01')
                 AND expiry_date < DATE_FORMAT(CURDATE(), '%Y-%m-01')"
            ),
            'mtd' => $this->expiryLossWindow(
                $clientId,
                $branchId,
                "expiry_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND expiry_date < CURDATE()"
            ),
            'all_expired' => $this->expiryLossWindow(
                $clientId,
                $branchId,
                'expiry_date < CURDATE()'
            ),
        ];
    }

    /** @return array{batches:int,qty:int,purchase_value:float,sale_value:float,expiry_loss:float,revenue_loss:float,margin_loss:float} */
    private function expiryLossWindow(?int $clientId, ?int $branchId, string $dateFilter): array
    {
        $row = $this->query(
            'ops',
            "SELECT
                COUNT(*) AS batches,
                COALESCE(SUM(quantity), 0) AS qty,
                COALESCE(SUM(quantity * purchase_rate), 0) AS purchase_value,
                COALESCE(SUM(quantity * mrp), 0) AS sale_value
             FROM inventory_batches
             WHERE client_id = ?
               AND expiry_date IS NOT NULL
               AND quantity > 0
               AND ({$dateFilter})" . $this->branchClause($branchId),
            $this->bind($clientId, $branchId)
        )[0] ?? [];

        $purchase = round((float) ($row['purchase_value'] ?? 0), 2);
        $sale = round((float) ($row['sale_value'] ?? 0), 2);

        return [
            'batches'        => (int) ($row['batches'] ?? 0),
            'qty'            => (int) ($row['qty'] ?? 0),
            'purchase_value' => $purchase,
            'sale_value'     => $sale,
            'expiry_loss'    => $purchase,
            'revenue_loss'   => $sale,
            'margin_loss'    => round($sale - $purchase, 2),
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

    /**
     * Profit + discount analytics.
     * expected_profit = realized profit + discount given (what profit would have been without discounts)
     * missed_profit / discount_loss = discount_amount + cash discount
     */
    private function profitAnalytics(?int $clientId, ?int $branchId): array
    {
        $month = $this->profitWindow(
            $clientId,
            $branchId,
            'YEAR(invoice_date) = YEAR(CURDATE()) AND MONTH(invoice_date) = MONTH(CURDATE())'
        );
        $threeMonths = $this->profitWindow(
            $clientId,
            $branchId,
            'invoice_date >= DATE_FORMAT(CURDATE() - INTERVAL 2 MONTH, \'%Y-%m-01\')'
        );

        $salesBranch = str_replace('branch_id', 's.branch_id', $this->branchClause($branchId));
        $monthFilter = 'YEAR(s.invoice_date) = YEAR(CURDATE()) AND MONTH(s.invoice_date) = MONTH(CURDATE())';

        $byChannel = $this->query(
            'ops',
            "SELECT
                CASE WHEN si.schedule_category = 'OTC' THEN 'OTC' ELSE 'Prescription' END AS channel,
                COALESCE(SUM(si.line_total), 0) AS revenue,
                COALESCE(SUM(si.profit), 0) AS profit,
                COALESCE(SUM(si.rate * si.quantity * si.discount_pct / 100), 0) AS discount_loss
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             WHERE s.client_id = ? AND {$monthFilter}{$salesBranch}
             GROUP BY CASE WHEN si.schedule_category = 'OTC' THEN 'OTC' ELSE 'Prescription' END
             ORDER BY profit DESC",
            $this->bind($clientId, $branchId)
        );

        $byProductCategory = $this->query(
            'ops',
            "SELECT
                COALESCE(NULLIF(pb.product_category, ''), 'Other') AS category,
                COALESCE(SUM(si.line_total), 0) AS revenue,
                COALESCE(SUM(si.profit), 0) AS profit,
                COALESCE(SUM(si.rate * si.quantity * si.discount_pct / 100), 0) AS discount_loss
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             LEFT JOIN product_bindings pb ON pb.id = si.product_binding_id
             WHERE s.client_id = ? AND {$monthFilter}{$salesBranch}
             GROUP BY COALESCE(NULLIF(pb.product_category, ''), 'Other')
             ORDER BY profit DESC
             LIMIT 8",
            $this->bind($clientId, $branchId)
        );

        $monthly = $this->query(
            'ops',
            "SELECT DATE_FORMAT(invoice_date, '%Y-%m') AS ym,
                    DATE_FORMAT(invoice_date, '%b %Y') AS label,
                    COALESCE(SUM(profit_amount), 0) AS profit,
                    COALESCE(SUM(discount_amount + cd_amount), 0) AS discount_loss,
                    COALESCE(SUM(profit_amount + discount_amount + cd_amount), 0) AS expected_profit
             FROM sales
             WHERE client_id = ?
               AND invoice_date >= DATE_FORMAT(CURDATE() - INTERVAL 2 MONTH, '%Y-%m-01')"
            . $this->branchClause($branchId)
            . " GROUP BY DATE_FORMAT(invoice_date, '%Y-%m'), DATE_FORMAT(invoice_date, '%b %Y')
             ORDER BY ym ASC",
            $this->bind($clientId, $branchId)
        );

        foreach ($byChannel as &$row) {
            $row['revenue'] = round((float) $row['revenue'], 2);
            $row['profit'] = round((float) $row['profit'], 2);
            $row['discount_loss'] = round((float) $row['discount_loss'], 2);
            $row['expected_profit'] = round((float) $row['profit'] + (float) $row['discount_loss'], 2);
        }
        unset($row);

        foreach ($byProductCategory as &$row) {
            $row['revenue'] = round((float) $row['revenue'], 2);
            $row['profit'] = round((float) $row['profit'], 2);
            $row['discount_loss'] = round((float) $row['discount_loss'], 2);
            $row['expected_profit'] = round((float) $row['profit'] + (float) $row['discount_loss'], 2);
        }
        unset($row);

        foreach ($monthly as &$row) {
            $row['profit'] = round((float) $row['profit'], 2);
            $row['discount_loss'] = round((float) $row['discount_loss'], 2);
            $row['expected_profit'] = round((float) $row['expected_profit'], 2);
        }
        unset($row);

        return [
            'month'               => $month,
            'three_months'        => $threeMonths,
            'by_channel'          => $byChannel,
            'by_product_category' => $byProductCategory,
            'monthly'             => $monthly,
        ];
    }

    /** @return array{sales:float,profit:float,discount_loss:float,expected_profit:float,missed_profit:float} */
    private function profitWindow(?int $clientId, ?int $branchId, string $dateFilter): array
    {
        $row = $this->query(
            'ops',
            "SELECT
                COALESCE(SUM(net_amount), 0) AS sales,
                COALESCE(SUM(profit_amount), 0) AS profit,
                COALESCE(SUM(discount_amount + cd_amount), 0) AS discount_loss
             FROM sales
             WHERE client_id = ? AND ({$dateFilter})" . $this->branchClause($branchId),
            $this->bind($clientId, $branchId)
        )[0] ?? [];

        $profit = round((float) ($row['profit'] ?? 0), 2);
        $discount = round((float) ($row['discount_loss'] ?? 0), 2);

        return [
            'sales'            => round((float) ($row['sales'] ?? 0), 2),
            'profit'           => $profit,
            'discount_loss'    => $discount,
            'expected_profit'  => round($profit + $discount, 2),
            'missed_profit'    => $discount,
        ];
    }

    /** HO-only: per-store expiry loss + month profit / discount snapshot. */
    private function storeAnalytics(?int $clientId): array
    {
        $branches = $this->query(
            'ho',
            "SELECT id, code, name FROM branches
             WHERE client_id = ? AND status = 'active'
             ORDER BY is_head_office DESC, name ASC",
            [$clientId]
        );

        $stores = [];
        foreach ($branches as $branch) {
            $bid = (int) $branch['id'];
            $expiry = $this->expiryLossWindow($clientId, $bid, 'expiry_date < CURDATE()');
            $profit = $this->profitWindow(
                $clientId,
                $bid,
                'YEAR(invoice_date) = YEAR(CURDATE()) AND MONTH(invoice_date) = MONTH(CURDATE())'
            );

            $stores[] = [
                'branch_id'       => $bid,
                'code'            => $branch['code'],
                'name'            => $branch['name'],
                'expired_batches' => $expiry['batches'],
                'expired_qty'     => $expiry['qty'],
                'expiry_loss'     => $expiry['expiry_loss'],
                'revenue_loss'    => $expiry['revenue_loss'],
                'margin_loss'     => $expiry['margin_loss'],
                'month_profit'    => $profit['profit'],
                'discount_loss'   => $profit['discount_loss'],
                'expected_profit' => $profit['expected_profit'],
                'missed_profit'   => $profit['missed_profit'],
                'month_sales'     => $profit['sales'],
            ];
        }

        return $stores;
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
