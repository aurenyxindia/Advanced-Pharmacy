<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Database\Connection;
use PDO;

/**
 * Operational and compliance reports. HO-scope users see all branches of the
 * client; branch users are restricted to their own branch.
 */
final class ReportController extends Controller
{
    public function salesRegister(Request $request): Response
    {
        [$where, $bind] = $this->scope($request, 'invoice_date');
        $rows = $this->run(
            "SELECT id, invoice_no, invoice_date, customer_name, doctor_name, payment_mode,
                    gross_amount, discount_amount, gst_amount, net_amount, profit_amount, is_locked
             FROM sales WHERE {$where} ORDER BY invoice_date DESC, id DESC",
            $bind
        );
        return $this->success([
            'rows'    => $rows,
            'totals'  => [
                'count'    => count($rows),
                'net'      => array_sum(array_map(static fn ($r) => (float) $r['net_amount'], $rows)),
                'gst'      => array_sum(array_map(static fn ($r) => (float) $r['gst_amount'], $rows)),
                'profit'   => array_sum(array_map(static fn ($r) => (float) $r['profit_amount'], $rows)),
                'discount' => array_sum(array_map(static fn ($r) => (float) $r['discount_amount'], $rows)),
            ],
        ], 'Sales register.');
    }

    public function purchaseRegister(Request $request): Response
    {
        [$where, $bind] = $this->scope($request, 'invoice_date');
        $rows = $this->run(
            "SELECT id, invoice_no, invoice_date, supplier_name, gross_amount, scheme_amount,
                    discount_amount, gst_amount, net_amount
             FROM purchases WHERE {$where} ORDER BY invoice_date DESC, id DESC",
            $bind
        );
        return $this->success([
            'rows'   => $rows,
            'totals' => [
                'count' => count($rows),
                'net'   => array_sum(array_map(static fn ($r) => (float) $r['net_amount'], $rows)),
                'gst'   => array_sum(array_map(static fn ($r) => (float) $r['gst_amount'], $rows)),
            ],
        ], 'Purchase register.');
    }

    public function gstSummary(Request $request): Response
    {
        [$salesWhere, $salesBind] = $this->scope($request, 'invoice_date');
        [$purWhere, $purBind] = $this->scope($request, 'invoice_date');

        $output = (float) ($this->run("SELECT COALESCE(SUM(gst_amount),0) g FROM sales WHERE {$salesWhere}", $salesBind)[0]['g'] ?? 0);
        $input = (float) ($this->run("SELECT COALESCE(SUM(gst_amount),0) g FROM purchases WHERE {$purWhere}", $purBind)[0]['g'] ?? 0);

        return $this->success([
            'output_gst'  => round($output, 2),
            'input_gst'   => round($input, 2),
            'net_payable' => round($output - $input, 2),
        ], 'GST summary.');
    }

    public function stock(Request $request): Response
    {
        $branchFilter = $this->branchClause($request, 'st');
        $rows = $this->run(
            "SELECT pb.product_name, pb.schedule_category, st.quantity, st.reorder_level,
                    (SELECT ib.mrp FROM inventory_batches ib WHERE ib.product_binding_id = pb.id AND ib.quantity > 0
                       ORDER BY (ib.expiry_date IS NULL), ib.expiry_date ASC LIMIT 1) AS mrp,
                    (SELECT ib.purchase_rate FROM inventory_batches ib WHERE ib.product_binding_id = pb.id AND ib.quantity > 0
                       ORDER BY (ib.expiry_date IS NULL), ib.expiry_date ASC LIMIT 1) AS cost
             FROM inventory_stock st
             JOIN product_bindings pb ON pb.id = st.product_binding_id
             WHERE st.client_id = :c {$branchFilter} AND st.quantity > 0
             ORDER BY pb.product_name ASC",
            $this->bind($request)
        );
        $value = 0.0;
        foreach ($rows as &$r) {
            $r['stock_value'] = round((float) $r['quantity'] * (float) ($r['cost'] ?? 0), 2);
            $value += $r['stock_value'];
        }
        unset($r);
        return $this->success([
            'rows'        => $rows,
            'total_value' => round($value, 2),
            'total_items' => count($rows),
        ], 'Stock report.');
    }

    public function expiry(Request $request): Response
    {
        $days = (int) $request->query('days', 90);
        $branchFilter = $this->branchClause($request, 'ib');
        $rows = $this->run(
            "SELECT pb.product_name, ib.batch_no, ib.expiry_date, ib.quantity, ib.mrp,
                    DATEDIFF(ib.expiry_date, CURDATE()) AS days_to_expiry
             FROM inventory_batches ib
             JOIN product_bindings pb ON pb.id = ib.product_binding_id
             WHERE ib.client_id = :c {$branchFilter} AND ib.quantity > 0
               AND ib.expiry_date IS NOT NULL
               AND ib.expiry_date <= (CURDATE() + INTERVAL {$days} DAY)
             ORDER BY ib.expiry_date ASC",
            $this->bind($request)
        );
        return $this->success(['rows' => $rows, 'days' => $days], 'Expiry report.');
    }

    public function profit(Request $request): Response
    {
        [$where, $bind] = $this->scope($request, 'invoice_date');
        $daily = $this->run(
            "SELECT DATE(invoice_date) AS d, COALESCE(SUM(net_amount),0) sales,
                    COALESCE(SUM(profit_amount),0) profit
             FROM sales WHERE {$where} GROUP BY DATE(invoice_date) ORDER BY d ASC",
            $bind
        );
        $byProduct = $this->run(
            "SELECT si.product_name, SUM(si.quantity) qty, SUM(si.line_total) revenue, SUM(si.profit) profit
             FROM sale_items si JOIN sales s ON s.id = si.sale_id
             WHERE {$this->replaceAlias($where)} GROUP BY si.product_name ORDER BY profit DESC LIMIT 15",
            $bind
        );
        return $this->success([
            'daily'      => $daily,
            'by_product' => $byProduct,
            'total_profit' => round(array_sum(array_map(static fn ($r) => (float) $r['profit'], $daily)), 2),
        ], 'Profit report.');
    }

    /** Compliance register for scheduled drugs (H/H1/X/G/Restricted). */
    public function scheduleRegister(Request $request): Response
    {
        $branchFilter = $this->branchClause($request, 's');
        [$dateClause, $dateBind] = $this->dateRange($request, 's.invoice_date');
        $rows = $this->run(
            "SELECT s.invoice_no, s.invoice_date, s.customer_name, s.doctor_name, s.has_prescription,
                    si.product_name, si.schedule_category, si.batch_no, si.quantity
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             WHERE s.client_id = :c {$branchFilter} AND si.schedule_category <> 'OTC' {$dateClause}
             ORDER BY s.invoice_date DESC",
            array_merge($this->bind($request), $dateBind)
        );
        return $this->success(['rows' => $rows], 'Schedule-wise register.');
    }

    // ---- helpers -------------------------------------------------------

    /** Build a WHERE for sales/purchases with client + optional branch + date range. */
    private function scope(Request $request, string $dateColumn): array
    {
        $where = 'client_id = :c';
        $bind = ['c' => $request->clientId()];

        if (($request->user()['scope'] ?? 'branch') !== 'ho' && $request->branchId() !== null) {
            $where .= ' AND branch_id = :b';
            $bind['b'] = $request->branchId();
        }

        [$dateClause, $dateBind] = $this->dateRange($request, $dateColumn);
        $where .= $dateClause;
        $bind = array_merge($bind, $dateBind);

        return [$where, $bind];
    }

    private function dateRange(Request $request, string $column): array
    {
        $clause = '';
        $bind = [];
        if ($from = $request->query('from')) {
            $clause .= " AND DATE({$column}) >= :from";
            $bind['from'] = $from;
        }
        if ($to = $request->query('to')) {
            $clause .= " AND DATE({$column}) <= :to";
            $bind['to'] = $to;
        }
        return [$clause, $bind];
    }

    private function branchClause(Request $request, string $alias): string
    {
        if (($request->user()['scope'] ?? 'branch') !== 'ho' && $request->branchId() !== null) {
            return " AND {$alias}.branch_id = " . (int) $request->branchId();
        }
        return '';
    }

    private function bind(Request $request): array
    {
        return ['c' => $request->clientId()];
    }

    /** profit by_product uses the same scope built for "sales" but aliased as s. */
    private function replaceAlias(string $where): string
    {
        return str_replace(['client_id', 'branch_id', 'invoice_date'], ['s.client_id', 's.branch_id', 's.invoice_date'], $where);
    }

    private function run(string $sql, array $bind): array
    {
        $stmt = Connection::ops()->prepare($sql);
        $stmt->execute($bind);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
