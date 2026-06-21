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

    /** Schedule-specific sales register (H1 / X / etc.). */
    public function scheduleSales(Request $request, string $schedule): Response
    {
        $branchClause = $this->branchClause($request, 's');
        [$dateClause, $dateBind] = $this->dateRange($request, 's.invoice_date');
        $rows = $this->run(
            "SELECT s.invoice_no, s.invoice_date, s.customer_name, s.patient_name, s.doctor_name, s.has_prescription,
                    si.product_name, si.manufacturer_short, si.batch_no, si.quantity, si.line_total
             FROM sale_items si JOIN sales s ON s.id = si.sale_id
             WHERE s.client_id = :c {$branchClause} AND si.schedule_category = :sch {$dateClause}
             ORDER BY s.invoice_date DESC, s.id DESC",
            array_merge(['c' => $request->clientId(), 'sch' => $schedule], $dateBind)
        );
        return $this->success([
            'rows'  => $rows,
            'totals' => ['count' => count($rows), 'qty' => array_sum(array_map(static fn ($r) => (int) $r['quantity'], $rows))],
        ], "Schedule {$schedule} sales register.");
    }

    public function h1Sales(Request $request): Response { return $this->scheduleSales($request, 'H1'); }
    public function xSales(Request $request): Response { return $this->scheduleSales($request, 'X'); }

    /** Doctor-wise sales summary. */
    public function doctorWise(Request $request): Response
    {
        [$where, $bind] = $this->scope($request, 'invoice_date');
        $rows = $this->run(
            "SELECT COALESCE(NULLIF(doctor_name,''),'(No Doctor)') AS doctor_name,
                    COUNT(*) AS bills, COALESCE(SUM(net_amount),0) AS net, COALESCE(SUM(profit_amount),0) AS profit
             FROM sales WHERE {$where} GROUP BY doctor_name ORDER BY net DESC",
            $bind
        );
        return $this->success(['rows' => $rows], 'Doctor-wise sales.');
    }

    /** Non-moving stock: in stock but not sold within N days (default 90). */
    public function nonMoving(Request $request): Response
    {
        $days = (int) $request->query('days', 90);
        $branchClause = $this->branchClause($request, 'st');
        $rows = $this->run(
            "SELECT pb.product_name, pb.manufacturer_short, st.quantity, pb.schedule_category,
                    (SELECT MAX(s.invoice_date) FROM sale_items si JOIN sales s ON s.id = si.sale_id
                       WHERE si.product_binding_id = pb.id) AS last_sold
             FROM inventory_stock st
             JOIN product_bindings pb ON pb.id = st.product_binding_id
             WHERE st.client_id = :c {$branchClause} AND st.quantity > 0
               AND pb.id NOT IN (
                   SELECT DISTINCT si.product_binding_id FROM sale_items si JOIN sales s ON s.id = si.sale_id
                   WHERE s.invoice_date >= (CURDATE() - INTERVAL {$days} DAY)
               )
             ORDER BY st.quantity DESC LIMIT 500",
            ['c' => $request->clientId()]
        );
        return $this->success(['rows' => $rows, 'days' => $days], 'Non-moving stock.');
    }

    /** Expiry alert with day-bucket and quantity. */
    public function expiryAlert(Request $request): Response
    {
        $days = (int) $request->query('days', 90);
        $branchClause = $this->branchClause($request, 'ib');
        $rows = $this->run(
            "SELECT pb.product_name, pb.manufacturer_short, ib.batch_no, ib.expiry_date, ib.quantity, ib.mrp,
                    DATEDIFF(ib.expiry_date, CURDATE()) AS days_to_expiry
             FROM inventory_batches ib JOIN product_bindings pb ON pb.id = ib.product_binding_id
             WHERE ib.client_id = :c {$branchClause} AND ib.quantity > 0 AND ib.expiry_date IS NOT NULL
               AND ib.expiry_date <= (CURDATE() + INTERVAL {$days} DAY)
             ORDER BY ib.expiry_date ASC",
            ['c' => $request->clientId()]
        );
        return $this->success(['rows' => $rows, 'days' => $days], 'Expiry alert.');
    }

    /** GST summary grouped by tax rate (HSN-style). */
    public function gstHsn(Request $request): Response
    {
        $branchClause = $this->branchClause($request, 's');
        [$dateClause, $dateBind] = $this->dateRange($request, 's.invoice_date');
        $rows = $this->run(
            "SELECT si.gst_rate, COALESCE(SUM(si.taxable),0) AS taxable,
                    COALESCE(SUM(si.taxable * si.gst_rate / 100),0) AS gst,
                    COALESCE(SUM(si.line_total),0) AS total
             FROM sale_items si JOIN sales s ON s.id = si.sale_id
             WHERE s.client_id = :c {$branchClause} {$dateClause}
             GROUP BY si.gst_rate ORDER BY si.gst_rate ASC",
            array_merge(['c' => $request->clientId()], $dateBind)
        );
        foreach ($rows as &$r) {
            $r['cgst'] = round((float) $r['gst'] / 2, 2);
            $r['sgst'] = round((float) $r['gst'] / 2, 2);
        }
        unset($r);
        return $this->success(['rows' => $rows], 'GST rate-wise (HSN) summary.');
    }

    /** Cash book: cash sales + receipt/payment vouchers by day. */
    public function cashBook(Request $request): Response
    {
        [$where, $bind] = $this->scope($request, 'invoice_date');
        $cashSales = $this->run(
            "SELECT DATE(invoice_date) d, COALESCE(SUM(net_amount),0) amt FROM sales
             WHERE {$where} AND payment_mode = 'cash' GROUP BY DATE(invoice_date)",
            $bind
        );
        $vBranch = $this->branchClause($request, 'pv');
        [$vDate, $vBind] = $this->dateRange($request, 'pv.voucher_date');
        $vouchers = $this->run(
            "SELECT DATE(pv.voucher_date) d, pv.voucher_type,
                    COALESCE(SUM(pv.amount),0) amt
             FROM payment_vouchers pv WHERE pv.client_id = :c {$vBranch} {$vDate}
             GROUP BY DATE(pv.voucher_date), pv.voucher_type",
            array_merge(['c' => $request->clientId()], $vBind)
        );

        $book = [];
        foreach ($cashSales as $r) {
            $book[$r['d']] = ($book[$r['d']] ?? ['date' => $r['d'], 'in' => 0, 'out' => 0]);
            $book[$r['d']]['in'] += (float) $r['amt'];
        }
        foreach ($vouchers as $v) {
            $book[$v['d']] = ($book[$v['d']] ?? ['date' => $v['d'], 'in' => 0, 'out' => 0]);
            if ($v['voucher_type'] === 'receipt') {
                $book[$v['d']]['in'] += (float) $v['amt'];
            } else {
                $book[$v['d']]['out'] += (float) $v['amt'];
            }
        }
        ksort($book);
        $rows = array_values($book);
        foreach ($rows as &$r) {
            $r['net'] = round($r['in'] - $r['out'], 2);
        }
        unset($r);

        return $this->success([
            'rows'   => $rows,
            'totals' => [
                'in'  => round(array_sum(array_column($rows, 'in')), 2),
                'out' => round(array_sum(array_column($rows, 'out')), 2),
            ],
        ], 'Cash book.');
    }

    /** Online (ePharmacy) sales report. */
    public function onlineSales(Request $request): Response
    {
        $branchClause = $this->branchClause($request, 'o');
        [$dateClause, $dateBind] = $this->dateRange($request, 'o.created_at');
        $rows = $this->run(
            "SELECT o.order_no, o.created_at, o.customer_name, o.phone, o.status, o.total_estimate
             FROM online_orders o WHERE o.client_id = :c {$branchClause} {$dateClause}
             ORDER BY o.created_at DESC LIMIT 500",
            array_merge(['c' => $request->clientId()], $dateBind)
        );
        return $this->success([
            'rows'   => $rows,
            'totals' => ['count' => count($rows), 'value' => round(array_sum(array_map(static fn ($r) => (float) $r['total_estimate'], $rows)), 2)],
        ], 'Online sales.');
    }

    /** Chronic patient management: customers with repeat purchases / active refills. */
    public function chronic(Request $request): Response
    {
        $branchClause = $this->branchClause($request, 's');
        $rows = $this->run(
            "SELECT COALESCE(NULLIF(s.patient_name,''), s.customer_name) AS patient,
                    s.contact_no, COUNT(*) AS visits, COALESCE(SUM(s.net_amount),0) AS spend,
                    MAX(s.invoice_date) AS last_visit
             FROM sales s
             WHERE s.client_id = :c {$branchClause} AND COALESCE(NULLIF(s.patient_name,''), s.customer_name) IS NOT NULL
             GROUP BY patient, s.contact_no
             HAVING visits >= 2
             ORDER BY visits DESC, spend DESC LIMIT 300",
            ['c' => $request->clientId()]
        );
        return $this->success(['rows' => $rows], 'Chronic / repeat patients.');
    }

    /** Branches list for the HO report branch selector. */
    public function branches(Request $request): Response
    {
        $rows = Connection::ho()->prepare('SELECT id, code, name FROM branches WHERE client_id = :c ORDER BY name');
        $rows->execute(['c' => $request->clientId()]);
        return $this->success($rows->fetchAll(), 'Branches.');
    }

    // ---- helpers -------------------------------------------------------

    /**
     * Resolve which branch to report on:
     *  - branch users: always their own branch
     *  - HO users: the ?branch_id= filter, or NULL (= all branches)
     */
    private function resolveBranch(Request $request): ?int
    {
        if (($request->user()['scope'] ?? 'branch') !== 'ho') {
            return $request->branchId();
        }
        $b = (int) $request->query('branch_id', 0);
        return $b > 0 ? $b : null;
    }

    /** Build a WHERE for sales/purchases with client + optional branch + date range. */
    private function scope(Request $request, string $dateColumn): array
    {
        $where = 'client_id = :c';
        $bind = ['c' => $request->clientId()];

        $branch = $this->resolveBranch($request);
        if ($branch !== null) {
            $where .= ' AND branch_id = :b';
            $bind['b'] = $branch;
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
        $branch = $this->resolveBranch($request);
        return $branch !== null ? " AND {$alias}.branch_id = " . $branch : '';
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
