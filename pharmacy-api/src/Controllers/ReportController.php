<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Database\Connection;
use App\Services\MinInventoryService;
use App\Services\StockAdjustmentService;
use App\Services\DispensingService;
use App\Services\ReportAnalyticsService;
use App\Services\SalesService;
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
        $branch = $this->resolveBranch($request);
        $branchSqlIb = $branch !== null ? ' AND ib.branch_id = ' . (int) $branch : '';
        $branchSqlL = $branch !== null ? ' AND l.branch_id = ' . (int) $branch : '';
        $branchSqlP = $branch !== null ? ' AND p.branch_id = ' . (int) $branch : '';
        $businessType = $this->clientBusinessType((int) $request->clientId());
        return $this->success(
            (new ReportAnalyticsService())->stockReport([
                'branchSqlIb' => $branchSqlIb,
                'branchSqlL' => $branchSqlL,
                'branchSqlP' => $branchSqlP,
                'bind' => ['c' => $request->clientId()],
                'business_type' => $businessType,
            ]),
            'Stock report.'
        );
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
            "SELECT s.invoice_no, DATE(s.invoice_date) AS invoice_date, s.customer_name,
                    COALESCE(NULLIF(TRIM(s.patient_name), ''), s.customer_name) AS patient_name,
                    s.doctor_name, s.has_prescription,
                    si.product_name, si.manufacturer_short, si.schedule_category, si.batch_no, si.quantity
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             WHERE s.client_id = :c {$branchFilter} AND si.schedule_category <> 'OTC' {$dateClause}
             ORDER BY s.invoice_date DESC",
            array_merge($this->bind($request), $dateBind)
        );
        foreach ($rows as &$row) {
            if (isset($row['product_name'])) {
                $row['product_name'] = \App\Support\ProductName::clean((string) $row['product_name']);
            }
        }
        unset($row);
        return $this->success(['rows' => $rows], 'Schedule-wise register.');
    }

    /** Schedule-specific sales register (H1 / X / etc.). */
    public function scheduleSales(Request $request, string $schedule): Response
    {
        $branchClause = $this->branchClause($request, 's');
        [$dateClause, $dateBind] = $this->dateRange($request, 's.invoice_date');
        $rows = $this->run(
            "SELECT s.invoice_no, DATE(s.invoice_date) AS invoice_date, s.customer_name, s.patient_name, s.doctor_name, s.has_prescription,
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

    /** Doctor-wise sales: summary all doctors, or line detail when doctor_name / ps_doct set. */
    public function doctorWise(Request $request): Response
    {
        $doctor = trim((string) ($request->query('doctor_name') ?? $request->query('ps_doct') ?? ''));
        $scope = $this->tableScope($request, 's', 'invoice_date');
        if ($doctor !== '') {
            $scope['where'] .= ' AND TRIM(s.doctor_name) = :doctor_name';
            $scope['bind']['doctor_name'] = $doctor;
            return $this->success(
                (new ReportAnalyticsService())->doctorWiseDetail($scope),
                'Doctor-wise sales.'
            );
        }
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

    /** Cash/Counter memos without a patient name (walk-in), with day totals. */
    public function cashCounterDay(Request $request): Response
    {
        $from = trim((string) ($request->query('from') ?: $request->query('date') ?: ''));
        $to = trim((string) ($request->query('to') ?: $request->query('date') ?: ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $from = date('Y-m-d');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $to = $from;
        }

        $where = 'client_id = :c AND DATE(invoice_date) >= :from AND DATE(invoice_date) <= :to';
        $bind = ['c' => $request->clientId(), 'from' => $from, 'to' => $to];
        $branch = $this->resolveBranch($request);
        if ($branch !== null) {
            $where .= ' AND branch_id = :b';
            $bind['b'] = $branch;
        }
        $where .= " AND UPPER(COALESCE(sale_type, 'Regular')) LIKE 'CASH%'
                    AND LOWER(COALESCE(bill_mode, 'retail')) = 'retail'
                    AND (
                      patient_name IS NULL
                      OR TRIM(patient_name) = ''
                      OR UPPER(REPLACE(TRIM(patient_name), ' ', '')) IN ('WALK-IN','WALKIN','CASH','CASHCUSTOMER')
                    )";

        $rows = $this->run(
            "SELECT id, invoice_no, invoice_date, counter, payment_mode, patient_name,
                    gross_amount, discount_amount, gst_amount, net_amount,
                    (SELECT COUNT(*) FROM sale_items si WHERE si.sale_id = sales.id) AS item_count
             FROM sales
             WHERE {$where}
             ORDER BY invoice_date ASC, id ASC",
            $bind
        );

        $count = count($rows);
        $items = 0;
        $gross = 0.0;
        $discount = 0.0;
        $gst = 0.0;
        $net = 0.0;
        foreach ($rows as $r) {
            $items += (int) ($r['item_count'] ?? 0);
            $gross += (float) ($r['gross_amount'] ?? 0);
            $discount += (float) ($r['discount_amount'] ?? 0);
            $gst += (float) ($r['gst_amount'] ?? 0);
            $net += (float) ($r['net_amount'] ?? 0);
        }

        return $this->success([
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'totals' => [
                'count' => $count,
                'items' => $items,
                'gross' => round($gross, 2),
                'discount' => round($discount, 2),
                'gst' => round($gst, 2),
                'net' => round($net, 2),
            ],
        ], 'Cash/Counter bill day report.');
    }

    /** Cash book: cash sales + receipt/payment vouchers by day. */
    public function cashBook(Request $request): Response
    {
        [$where, $bind] = $this->scope($request, 'invoice_date');
        $cashSales = SalesService::hasSplitPaymentColumns(Connection::ops())
            ? $this->run(
                "SELECT DATE(invoice_date) d,
                        COALESCE(SUM(CASE WHEN payment_mode = 'cash+upi' THEN cash_amount ELSE net_amount END),0) amt
                 FROM sales
                 WHERE {$where} AND payment_mode IN ('cash', 'cash+upi') GROUP BY DATE(invoice_date)",
                $bind
            )
            : $this->run(
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

    /**
     * Stock Book: movement ledger with running + closing balance.
     * For a single product (binding_id) gives a true running balance; otherwise
     * lists recent movements across products.
     */
    public function stockBook(Request $request): Response
    {
        $clientId = (int) $request->clientId();
        $branch = $this->resolveBranch($request);
        $bindingId = (int) $request->query('binding_id', 0);
        $from = $request->query('from') ?: date('Y-m-01');
        $to = $request->query('to') ?: date('Y-m-d');

        $branchClause = $branch !== null ? ' AND l.branch_id = ' . $branch : '';

        if ($bindingId > 0) {
            $openRow = $this->run(
                "SELECT COALESCE(SUM(qty_in - qty_out),0) bal FROM stock_ledger
                 WHERE client_id = :c {$branchClause} AND product_binding_id = :p AND txn_date < :from",
                ['c' => $clientId, 'p' => $bindingId, 'from' => $from]
            );
            $opening = (int) ($openRow[0]['bal'] ?? 0);

            $rows = $this->run(
                "SELECT l.txn_date, l.txn_type, l.qty_in, l.qty_out, l.batch_id, pb.product_name, pb.manufacturer_short
                 FROM stock_ledger l JOIN product_bindings pb ON pb.id = l.product_binding_id
                 WHERE l.client_id = :c {$branchClause} AND l.product_binding_id = :p
                   AND l.txn_date BETWEEN :from AND :to
                 ORDER BY l.txn_date ASC, l.id ASC",
                ['c' => $clientId, 'p' => $bindingId, 'from' => $from, 'to' => $to]
            );

            $bal = $opening;
            foreach ($rows as &$r) {
                $bal += (int) $r['qty_in'] - (int) $r['qty_out'];
                $r['balance'] = $bal;
            }
            unset($r);

            return $this->success([
                'opening_balance' => $opening,
                'closing_balance' => $bal,
                'rows'            => $rows,
                'from'            => $from,
                'to'              => $to,
            ], 'Stock book.');
        }

        $rows = $this->run(
            "SELECT l.txn_date, l.txn_type, l.qty_in, l.qty_out, pb.product_name, pb.manufacturer_short
             FROM stock_ledger l JOIN product_bindings pb ON pb.id = l.product_binding_id
             WHERE l.client_id = :c {$branchClause} AND l.txn_date BETWEEN :from AND :to
             ORDER BY l.txn_date DESC, l.id DESC LIMIT 1000",
            ['c' => $clientId, 'from' => $from, 'to' => $to]
        );
        return $this->success(['rows' => $rows, 'from' => $from, 'to' => $to], 'Stock movements.');
    }

    /** Closing stock balance as of a given date (per product). */
    public function stockAsOf(Request $request): Response
    {
        $clientId = (int) $request->clientId();
        $branch = $this->resolveBranch($request);
        $asOf = $request->query('date') ?: date('Y-m-d');
        $branchClause = $branch !== null ? ' AND l.branch_id = ' . $branch : '';
        $search = $request->query('search');
        $searchClause = '';
        $bind = ['c' => $clientId, 'd' => $asOf];
        if ($search) {
            $searchClause = ' AND pb.product_name LIKE :s';
            $bind['s'] = '%' . $search . '%';
        }

        $rows = $this->run(
            "SELECT pb.product_name, pb.manufacturer_short, pb.schedule_category,
                    SUM(l.qty_in - l.qty_out) AS balance
             FROM stock_ledger l JOIN product_bindings pb ON pb.id = l.product_binding_id
             WHERE l.client_id = :c {$branchClause} AND l.txn_date <= :d {$searchClause}
             GROUP BY l.product_binding_id HAVING balance <> 0
             ORDER BY pb.product_name ASC LIMIT 1000",
            $bind
        );
        return $this->success(['rows' => $rows, 'as_of' => $asOf], 'Stock as of ' . $asOf);
    }

    /** FDA tally: opening + purchase + sale + expired => closing, per product. */
    public function fdaTally(Request $request): Response
    {
        $clientId = (int) $request->clientId();
        $branch = $this->resolveBranch($request);
        $from = $request->query('from') ?: date('Y-m-01');
        $to = $request->query('to') ?: date('Y-m-d');
        $branchClause = $branch !== null ? ' AND l.branch_id = ' . $branch : '';
        $search = $request->query('search');
        $searchClause = '';
        // Native prepared statements require a unique placeholder per occurrence.
        $bind = [
            'c' => $clientId,
            'f1' => $from, 'f2' => $from, 'f3' => $from, 'f4' => $from,
            't1' => $to, 't2' => $to, 't3' => $to, 't4' => $to,
        ];
        if ($search) {
            $searchClause = ' AND pb.product_name LIKE :s';
            $bind['s'] = '%' . $search . '%';
        }

        $rows = $this->run(
            "SELECT pb.product_name, pb.manufacturer_short,
                    SUM(CASE WHEN l.txn_date < :f1 THEN l.qty_in - l.qty_out ELSE 0 END) AS opening,
                    SUM(CASE WHEN l.txn_date BETWEEN :f2 AND :t1 AND l.txn_type IN ('purchase','transfer_in','opening','sale_return') THEN l.qty_in ELSE 0 END) AS purchase,
                    SUM(CASE WHEN l.txn_date BETWEEN :f3 AND :t2 AND l.txn_type IN ('sale','transfer_out','purchase_return') THEN l.qty_out ELSE 0 END) AS sale,
                    SUM(CASE WHEN l.txn_date BETWEEN :f4 AND :t3 AND l.txn_type = 'expiry' THEN l.qty_out ELSE 0 END) AS expired,
                    SUM(CASE WHEN l.txn_date <= :t4 THEN l.qty_in - l.qty_out ELSE 0 END) AS closing
             FROM stock_ledger l JOIN product_bindings pb ON pb.id = l.product_binding_id
             WHERE l.client_id = :c {$branchClause} {$searchClause}
             GROUP BY l.product_binding_id
             HAVING opening <> 0 OR purchase <> 0 OR sale <> 0 OR expired <> 0 OR closing <> 0
             ORDER BY pb.product_name ASC LIMIT 1000",
            $bind
        );
        return $this->success(['rows' => $rows, 'from' => $from, 'to' => $to], 'FDA tally report.');
    }

    /** Branches list for the HO report branch selector. */
    public function branches(Request $request): Response
    {
        $rows = Connection::ho()->prepare('SELECT id, code, name FROM branches WHERE client_id = :c ORDER BY name');
        $rows->execute(['c' => $request->clientId()]);
        return $this->success($rows->fetchAll(), 'Branches.');
    }

    /**
     * Dropdown options for entity-filtered reports (Retail parity).
     * Product / manufacturer / category lists include product_bindings so catalog
     * and opening-stock items appear even without a purchase/sale line yet.
     */
    public function filterOptions(Request $request): Response
    {
        $source = trim((string) $request->query('source', ''));
        $clientId = $request->clientId();
        $branch = $this->resolveBranch($request);
        $branchSql = $branch !== null ? ' AND branch_id = ' . (int) $branch : '';
        $branchSqlP = $branch !== null ? ' AND p.branch_id = ' . (int) $branch : '';
        $branchSqlS = $branch !== null ? ' AND s.branch_id = ' . (int) $branch : '';
        $branchSqlIb = $branch !== null ? ' AND ib.branch_id = ' . (int) $branch : '';
        $bind = ['c' => $clientId];
        $svc = new ReportAnalyticsService();

        // Master distributors catalog (shared across purchase/sale/stock filters).
        $masterDistributors = static function () use ($clientId): array {
            try {
                $st = Connection::master()->prepare(
                    "SELECT TRIM(name) AS value, TRIM(name) AS label
                     FROM distributors
                     WHERE client_id = :c
                       AND TRIM(COALESCE(name,'')) <> ''
                       AND COALESCE(is_active, 1) = 1
                     ORDER BY name ASC
                     LIMIT 5000"
                );
                $st->execute(['c' => $clientId]);
                return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (\Throwable) {
                // Fallback without is_active (older schemas).
                try {
                    $st = Connection::master()->prepare(
                        "SELECT TRIM(name) AS value, TRIM(name) AS label
                         FROM distributors
                         WHERE client_id = :c
                           AND TRIM(COALESCE(name,'')) <> ''
                         ORDER BY name ASC
                         LIMIT 5000"
                    );
                    $st->execute(['c' => $clientId]);
                    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                } catch (\Throwable) {
                    return [];
                }
            }
        };

        // Ops purchase supplier names. Note: purchases has no is_deleted column.
        $purchaseDistributors = static function () use ($svc, $bind): array {
            try {
                return $svc->rows(
                    "SELECT DISTINCT TRIM(p.supplier_name) AS value, TRIM(p.supplier_name) AS label
                     FROM purchases p
                     WHERE p.client_id = :c
                       AND TRIM(COALESCE(p.supplier_name,'')) <> ''
                     ORDER BY label ASC
                     LIMIT 5000",
                    $bind
                ) ?: [];
            } catch (\Throwable) {
                return [];
            }
        };

        // Deduplicate case-insensitively. Opening-stock products get " (Opening Stock)" in the label.
        $dedupeNames = static function (array $rows): array {
            $map = [];
            foreach ($rows as $row) {
                $value = trim((string) ($row['value'] ?? ''));
                if ($value === '') {
                    continue;
                }
                $key = mb_strtolower($value);
                $fromOpening = !empty($row['from_opening']) || (int) ($row['from_opening'] ?? 0) === 1;
                $baseLabel = trim((string) ($row['label'] ?? $value));
                if ($baseLabel === '') {
                    $baseLabel = $value;
                }
                $baseLabel = preg_replace('/\s*\(Opening Stock\)\s*$/i', '', $baseLabel) ?: $baseLabel;

                if (!isset($map[$key])) {
                    $map[$key] = [
                        'value' => $value,
                        'label' => $baseLabel,
                        'from_opening' => $fromOpening,
                    ];
                } elseif ($fromOpening) {
                    $map[$key]['from_opening'] = true;
                }
            }
            $out = [];
            foreach ($map as $item) {
                if (!empty($item['from_opening'])) {
                    $item['label'] .= ' (Opening Stock)';
                }
                $out[] = [
                    'value' => $item['value'],
                    'label' => $item['label'],
                    'from_opening' => !empty($item['from_opening']),
                ];
            }
            usort($out, static fn ($a, $b) => strcasecmp($a['label'], $b['label']));
            return $out;
        };

        $options = match ($source) {
            'purchase-products' => $dedupeNames($svc->rows(
                "SELECT name AS value, name AS label, MAX(from_opening) AS from_opening FROM (
                    SELECT TRIM(pi.product_name) AS name, 0 AS from_opening
                    FROM purchase_items pi
                    JOIN purchases p ON p.id = pi.purchase_id
                    WHERE p.client_id = :c AND TRIM(COALESCE(pi.product_name,'')) <> ''
                    UNION ALL
                    SELECT TRIM(pb.product_name) AS name, 0 AS from_opening
                    FROM product_bindings pb
                    WHERE pb.client_id = :c2 AND TRIM(COALESCE(pb.product_name,'')) <> ''
                    UNION ALL
                    SELECT TRIM(si.product_name) AS name, 0 AS from_opening
                    FROM sale_items si
                    JOIN sales s ON s.id = si.sale_id
                    WHERE s.client_id = :c3 AND TRIM(COALESCE(si.product_name,'')) <> ''
                    UNION ALL
                    SELECT TRIM(pb.product_name) AS name, 1 AS from_opening
                    FROM stock_ledger l
                    JOIN product_bindings pb ON pb.id = l.product_binding_id
                    WHERE l.client_id = :c4 AND l.txn_type = 'opening'
                      AND TRIM(COALESCE(pb.product_name,'')) <> ''
                 ) x
                 WHERE name <> ''
                 GROUP BY name
                 ORDER BY name ASC
                 LIMIT 20000",
                ['c' => $clientId, 'c2' => $clientId, 'c3' => $clientId, 'c4' => $clientId]
            )),
            'purchase-categories' => $dedupeNames($svc->rows(
                "SELECT name AS value, name AS label FROM (
                    SELECT COALESCE(NULLIF(TRIM(pb.product_category),''),'Allopathy') AS name
                    FROM product_bindings pb
                    WHERE pb.client_id = :c
                    UNION
                    SELECT COALESCE(NULLIF(TRIM(pb.product_category),''),'Allopathy') AS name
                    FROM purchase_items pi
                    JOIN purchases p ON p.id = pi.purchase_id
                    LEFT JOIN product_bindings pb ON pb.id = pi.product_binding_id
                    WHERE p.client_id = :c2
                 ) x
                 ORDER BY name ASC
                 LIMIT 1000",
                ['c' => $clientId, 'c2' => $clientId]
            )),
            'purchase-distributors' => $dedupeNames(array_merge(
                $purchaseDistributors(),
                $masterDistributors()
            )),
            'purchase-manufacturers' => $dedupeNames($svc->rows(
                "SELECT name AS value, name AS label FROM (
                    SELECT COALESCE(NULLIF(TRIM(pb.manufacturer_short),''), NULLIF(TRIM(pb.manufacturer),'')) AS name
                    FROM product_bindings pb
                    WHERE pb.client_id = :c
                    UNION
                    SELECT COALESCE(NULLIF(TRIM(pb.manufacturer_short),''), NULLIF(TRIM(pb.manufacturer),'')) AS name
                    FROM purchase_items pi
                    JOIN purchases p ON p.id = pi.purchase_id
                    LEFT JOIN product_bindings pb ON pb.id = pi.product_binding_id
                    WHERE p.client_id = :c2
                    UNION
                    SELECT NULLIF(TRIM(si.manufacturer_short),'') AS name
                    FROM sale_items si
                    JOIN sales s ON s.id = si.sale_id
                    WHERE s.client_id = :c3
                 ) x
                 WHERE name IS NOT NULL AND name <> ''
                 ORDER BY name ASC
                 LIMIT 5000",
                ['c' => $clientId, 'c2' => $clientId, 'c3' => $clientId]
            )),
            'sale-products' => $dedupeNames($svc->rows(
                "SELECT name AS value, name AS label, MAX(from_opening) AS from_opening FROM (
                    SELECT TRIM(si.product_name) AS name, 0 AS from_opening
                    FROM sale_items si
                    JOIN sales s ON s.id = si.sale_id
                    WHERE s.client_id = :c AND TRIM(COALESCE(si.product_name,'')) <> ''
                    UNION ALL
                    SELECT TRIM(pi.product_name) AS name, 0 AS from_opening
                    FROM purchase_items pi
                    JOIN purchases p ON p.id = pi.purchase_id
                    WHERE p.client_id = :c2 AND TRIM(COALESCE(pi.product_name,'')) <> ''
                    UNION ALL
                    SELECT TRIM(pb.product_name) AS name, 0 AS from_opening
                    FROM product_bindings pb
                    WHERE pb.client_id = :c3 AND TRIM(COALESCE(pb.product_name,'')) <> ''
                    UNION ALL
                    SELECT TRIM(pb.product_name) AS name, 1 AS from_opening
                    FROM stock_ledger l
                    JOIN product_bindings pb ON pb.id = l.product_binding_id
                    WHERE l.client_id = :c4 AND l.txn_type = 'opening'
                      AND TRIM(COALESCE(pb.product_name,'')) <> ''
                 ) x
                 WHERE name <> ''
                 GROUP BY name
                 ORDER BY name ASC
                 LIMIT 20000",
                ['c' => $clientId, 'c2' => $clientId, 'c3' => $clientId, 'c4' => $clientId]
            )),
            'sale-categories' => $dedupeNames($svc->rows(
                "SELECT name AS value, name AS label FROM (
                    SELECT COALESCE(NULLIF(TRIM(pb.product_category),''),'Allopathy') AS name
                    FROM product_bindings pb
                    WHERE pb.client_id = :c
                    UNION
                    SELECT COALESCE(NULLIF(TRIM(pb.product_category),''),'Allopathy') AS name
                    FROM sale_items si
                    JOIN sales s ON s.id = si.sale_id
                    LEFT JOIN product_bindings pb ON pb.id = si.product_binding_id
                    WHERE s.client_id = :c2
                 ) x
                 ORDER BY name ASC
                 LIMIT 1000",
                ['c' => $clientId, 'c2' => $clientId]
            )),
            'sale-distributors' => $dedupeNames(array_merge(
                $purchaseDistributors(),
                $masterDistributors()
            )),
            'sale-manufacturers' => $dedupeNames($svc->rows(
                "SELECT name AS value, name AS label FROM (
                    SELECT COALESCE(NULLIF(TRIM(pb.manufacturer_short),''), NULLIF(TRIM(pb.manufacturer),'')) AS name
                    FROM product_bindings pb
                    WHERE pb.client_id = :c
                    UNION
                    SELECT NULLIF(TRIM(si.manufacturer_short),'') AS name
                    FROM sale_items si
                    JOIN sales s ON s.id = si.sale_id
                    WHERE s.client_id = :c2
                 ) x
                 WHERE name IS NOT NULL AND name <> ''
                 ORDER BY name ASC
                 LIMIT 5000",
                ['c' => $clientId, 'c2' => $clientId]
            )),
            'dispensing-hospitals' => (static function () use ($clientId, $branch): array {
                try {
                    DispensingService::ensureSchema();
                    $sql = "SELECT DISTINCT TRIM(name) AS value, TRIM(name) AS label
                            FROM (
                                SELECT name, client_id, branch_id FROM hospitals
                                UNION
                                SELECT hospital_name AS name, client_id, branch_id FROM h_dispensing
                            ) h
                            WHERE client_id = :c" . ($branch !== null ? ' AND branch_id = ' . (int) $branch : '') . "
                              AND TRIM(COALESCE(name,'')) <> ''
                            ORDER BY label ASC";
                    $st = Connection::ops()->prepare($sql);
                    $st->execute(['c' => $clientId]);
                    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                } catch (\Throwable) {
                    return [];
                }
            })(),
            'sale-doctors' => $dedupeNames($svc->rows(
                "SELECT DISTINCT TRIM(s.doctor_name) AS value, TRIM(s.doctor_name) AS label
                 FROM sales s
                 WHERE s.client_id = :c
                   AND TRIM(COALESCE(s.doctor_name,'')) <> ''
                 ORDER BY label ASC
                 LIMIT 5000",
                $bind
            )),
            'sale-customers' => $dedupeNames($svc->rows(
                "SELECT DISTINCT
                        COALESCE(CAST(NULLIF(s.customer_id,0) AS CHAR), TRIM(COALESCE(NULLIF(s.customer_name,''), NULLIF(s.patient_name,'')))) AS value,
                        TRIM(CONCAT(
                          COALESCE(NULLIF(s.customer_name,''), NULLIF(s.patient_name,''), 'Walk-in'),
                          CASE WHEN s.customer_id IS NOT NULL AND s.customer_id > 0
                               THEN CONCAT(' (#', s.customer_id, ')') ELSE '' END
                        )) AS label
                 FROM sales s
                 WHERE s.client_id = :c
                   AND (
                     (s.customer_id IS NOT NULL AND s.customer_id > 0)
                     OR TRIM(COALESCE(s.customer_name,'')) <> ''
                     OR TRIM(COALESCE(s.patient_name,'')) <> ''
                   )
                 ORDER BY label ASC
                 LIMIT 10000",
                $bind
            )),
            'stock-distributors' => $dedupeNames(array_merge(
                (static function () use ($svc, $bind, $branchSqlIb): array {
                    try {
                        return $svc->rows(
                            "SELECT DISTINCT TRIM(d.supplier_name) AS value, TRIM(d.supplier_name) AS label
                             FROM inventory_batches ib
                             INNER JOIN (
                                 SELECT pi.product_binding_id, pi.batch_no, p.client_id,
                                        MIN(p.supplier_name) AS supplier_name
                                 FROM purchase_items pi
                                 JOIN purchases p ON p.id = pi.purchase_id
                                 WHERE TRIM(COALESCE(p.supplier_name,'')) <> ''
                                 GROUP BY pi.product_binding_id, pi.batch_no, p.client_id
                             ) d ON d.product_binding_id = ib.product_binding_id
                                AND d.batch_no <=> ib.batch_no
                                AND d.client_id = ib.client_id
                             WHERE ib.client_id = :c {$branchSqlIb} AND ib.quantity > 0
                             ORDER BY label ASC
                             LIMIT 5000",
                            $bind
                        ) ?: [];
                    } catch (\Throwable) {
                        return [];
                    }
                })(),
                $purchaseDistributors(),
                $masterDistributors()
            )),
            'stock-manufacturers' => $dedupeNames($svc->rows(
                "SELECT name AS value, name AS label FROM (
                    SELECT COALESCE(NULLIF(TRIM(pb.manufacturer_short),''), NULLIF(TRIM(pb.manufacturer),'')) AS name
                    FROM inventory_batches ib
                    JOIN product_bindings pb ON pb.id = ib.product_binding_id
                    WHERE ib.client_id = :c {$branchSqlIb} AND ib.quantity > 0
                    UNION
                    SELECT COALESCE(NULLIF(TRIM(pb.manufacturer_short),''), NULLIF(TRIM(pb.manufacturer),'')) AS name
                    FROM product_bindings pb
                    WHERE pb.client_id = :c2
                 ) x
                 WHERE name IS NOT NULL AND name <> ''
                 ORDER BY name ASC
                 LIMIT 5000",
                ['c' => $clientId, 'c2' => $clientId]
            )),
            default => null,
        };

        if ($options === null) {
            return $this->error('Unknown filter source.', 422);
        }

        // Already cleaned by $dedupeNames for most sources; keep a final pass for safety.
        $clean = [];
        foreach ($options as $row) {
            $value = trim((string) ($row['value'] ?? ''));
            $label = trim((string) ($row['label'] ?? $value));
            if ($value === '') {
                continue;
            }
            $item = [
                'value' => $value,
                'label' => $label !== '' ? $label : $value,
            ];
            if (!empty($row['from_opening'])) {
                $item['from_opening'] = true;
            }
            $clean[] = $item;
        }

        return $this->success($clean, 'Filter options.');
    }

    // ---- Retail parity analytical reports --------------------------------

    public function dateWisePurchase(Request $request): Response
    {
        return $this->success(
            (new ReportAnalyticsService())->dateWisePurchase($this->tableScope($request, 'p', 'invoice_date')),
            'Date-wise purchase.'
        );
    }

    public function allPurchase(Request $request): Response
    {
        return $this->success(
            (new ReportAnalyticsService())->allPurchase($this->tableScope($request, 'p', 'invoice_date')),
            'All purchase report.'
        );
    }

    public function distributorWisePurchase(Request $request): Response
    {
        $scope = $this->tableScope($request, 'p', 'invoice_date');
        $name = trim((string) ($request->query('distributor_name') ?? ''));
        if ($name !== '') {
            $scope['distributor_name'] = $name;
        }
        return $this->success(
            (new ReportAnalyticsService())->distributorWisePurchase($scope),
            'Distributor-wise purchase.'
        );
    }

    public function productWisePurchase(Request $request): Response
    {
        $scope = $this->tableScope($request, 'p', 'invoice_date');
        $name = trim((string) ($request->query('product_name') ?? $request->query('p_name') ?? ''));
        if ($name !== '') {
            $scope['product_name'] = $name;
        }
        return $this->success(
            (new ReportAnalyticsService())->productWisePurchase($scope),
            'Product-wise purchase.'
        );
    }

    public function manufacturerWisePurchase(Request $request): Response
    {
        $scope = $this->tableScope($request, 'p', 'invoice_date');
        $name = trim((string) ($request->query('manufacturer') ?? $request->query('m_name') ?? ''));
        if ($name !== '') {
            $scope['manufacturer'] = $name;
        }
        return $this->success(
            (new ReportAnalyticsService())->manufacturerWisePurchase($scope),
            'Manufacturer-wise purchase.'
        );
    }

    public function categoryWisePurchase(Request $request): Response
    {
        $scope = $this->tableScope($request, 'p', 'invoice_date');
        $name = trim((string) ($request->query('category') ?? $request->query('p_cat') ?? ''));
        if ($name !== '') {
            $scope['category'] = $name;
        }
        return $this->success(
            (new ReportAnalyticsService())->categoryWisePurchase($scope),
            'Category-wise purchase.'
        );
    }

    public function dateWiseSale(Request $request): Response
    {
        return $this->success(
            (new ReportAnalyticsService())->dateWiseSale($this->tableScope($request, 's', 'invoice_date')),
            'Date-wise sale.'
        );
    }

    public function allProductWiseSale(Request $request): Response
    {
        return $this->success(
            (new ReportAnalyticsService())->allProductWiseSale($this->tableScope($request, 's', 'invoice_date')),
            'All product-wise sale.'
        );
    }

    public function productWiseSale(Request $request): Response
    {
        $scope = $this->tableScope($request, 's', 'invoice_date');
        $name = trim((string) ($request->query('product_name') ?? $request->query('p_name') ?? ''));
        if ($name !== '') {
            $scope['product_name'] = $name;
        }
        return $this->success(
            (new ReportAnalyticsService())->productWiseSale($scope),
            'Product-wise sale.'
        );
    }

    public function manufacturerWiseSale(Request $request): Response
    {
        $scope = $this->tableScope($request, 's', 'invoice_date');
        $name = trim((string) ($request->query('manufacturer') ?? $request->query('m_name') ?? ''));
        if ($name !== '') {
            $scope['manufacturer'] = $name;
        }
        return $this->success(
            (new ReportAnalyticsService())->manufacturerWiseSale($scope),
            'Manufacturer-wise sale.'
        );
    }

    public function categoryWiseSale(Request $request): Response
    {
        $scope = $this->tableScope($request, 's', 'invoice_date');
        $name = trim((string) ($request->query('category') ?? $request->query('p_cat') ?? ''));
        if ($name !== '') {
            $scope['category'] = $name;
        }
        return $this->success(
            (new ReportAnalyticsService())->categoryWiseSale($scope),
            'Category-wise sale.'
        );
    }

    public function customerWiseSale(Request $request): Response
    {
        $scope = $this->tableScope($request, 's', 'invoice_date');
        $customer = trim((string) ($request->query('customer') ?? $request->query('patient_no') ?? $request->query('customer_id') ?? ''));
        if ($customer !== '') {
            $scope['customer'] = $customer;
        }
        return $this->success(
            (new ReportAnalyticsService())->customerWiseSale($scope),
            'Customer-wise sale.'
        );
    }

    public function freeQty(Request $request): Response
    {
        return $this->success(
            (new ReportAnalyticsService())->freeQty($this->tableScope($request, 'p', 'invoice_date')),
            'Free qty report (from purchase scheme).'
        );
    }

    public function purchaseGst(Request $request): Response
    {
        return $this->success(
            (new ReportAnalyticsService())->purchaseGst($this->tableScope($request, 'p', 'invoice_date')),
            'Purchase GST.'
        );
    }

    public function salesGst(Request $request): Response
    {
        return $this->success(
            (new ReportAnalyticsService())->salesGst($this->tableScope($request, 's', 'invoice_date')),
            'Sales GST.'
        );
    }

    public function saleGstSlabWise(Request $request): Response
    {
        return $this->success(
            (new ReportAnalyticsService())->saleGstSlabWise($this->tableScope($request, 's', 'invoice_date')),
            'Sale GST slab-wise.'
        );
    }

    public function purchaseGstSlabWise(Request $request): Response
    {
        return $this->success(
            (new ReportAnalyticsService())->purchaseGstSlabWise($this->tableScope($request, 'p', 'invoice_date')),
            'Purchase GST slab-wise.'
        );
    }

    public function allProductSaleGst(Request $request): Response
    {
        return $this->success(
            (new ReportAnalyticsService())->allProductSaleGst($this->tableScope($request, 's', 'invoice_date')),
            'All product sale GST.'
        );
    }

    public function allProductPurchaseGst(Request $request): Response
    {
        return $this->success(
            (new ReportAnalyticsService())->allProductPurchaseGst($this->tableScope($request, 'p', 'invoice_date')),
            'All product purchase GST.'
        );
    }

    public function distributorPayments(Request $request): Response
    {
        return $this->success(
            (new ReportAnalyticsService())->distributorPayments($this->tableScope($request, 'p', 'invoice_date')),
            'Distributor payments.'
        );
    }

    public function customerPayments(Request $request): Response
    {
        return $this->success(
            (new ReportAnalyticsService())->customerPayments($this->tableScope($request, 's', 'invoice_date')),
            'Customer payments.'
        );
    }

    public function profitLoss(Request $request): Response
    {
        return $this->success(
            (new ReportAnalyticsService())->profitLoss($this->tableScope($request, 's', 'invoice_date')),
            'Profit / Loss.'
        );
    }

    public function distributorProfitLoss(Request $request): Response
    {
        $sale = $this->tableScope($request, 's', 'invoice_date');
        $pur = $this->tableScope($request, 'p', 'invoice_date');
        // Remap binds so UNION-style dual scopes don't collide on :c/:b/:from/:to
        $bindSale = $this->prefixBind($sale['bind'], 's_');
        $bindPur = $this->prefixBind($pur['bind'], 'p_');
        $whereSale = $this->prefixPlaceholders($sale['where'], 's_', array_keys($sale['bind']));
        $wherePur = $this->prefixPlaceholders($pur['where'], 'p_', array_keys($pur['bind']));
        return $this->success(
            (new ReportAnalyticsService())->distributorProfitLoss([
                'whereSale' => $whereSale,
                'bindSale' => $bindSale,
                'wherePur' => $wherePur,
                'bindPur' => $bindPur,
            ]),
            'Distributor profit / loss.'
        );
    }

    public function expiredStock(Request $request): Response
    {
        $branch = $this->resolveBranch($request);
        $branchSql = $branch !== null ? ' AND ib.branch_id = ' . (int) $branch : '';
        return $this->success(
            (new ReportAnalyticsService())->expiredStock([
                'branchSql' => $branchSql,
                'bind' => ['c' => $request->clientId()],
            ]),
            'Expired stock.'
        );
    }

    /** Stock below its minimum (MinInventoryService), most-sold first. */
    public function shortStock(Request $request): Response
    {
        $rows = (new MinInventoryService())->shortStockReport(
            (int) $request->clientId(),
            (int) ($this->resolveBranch($request) ?? 0),
        );
        return $this->success(['rows' => $rows, 'totals' => ['count' => count($rows)]], 'Short stock.');
    }

    /** Who adjusted stock, when, from which IP, and why. */
    public function stockAdjustmentAudit(Request $request): Response
    {
        $from = (string) $request->query('from', '');
        $to = (string) $request->query('to', '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $from = date('Y-m-01');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $to = date('Y-m-d');
        }
        $rows = (new StockAdjustmentService())->auditLog(
            (int) $request->clientId(),
            (int) ($this->resolveBranch($request) ?? 0),
            $from,
            $to,
        );
        return $this->success(['rows' => $rows, 'totals' => ['count' => count($rows)]], 'Stock adjustment audit log.');
    }

    /** Purchase Edit Audit Log: who edited which purchase line, sold qty at that time, stock before / after. */
    public function purchaseEditAudit(Request $request): Response
    {
        $from = (string) $request->query('from', '');
        $to = (string) $request->query('to', '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $from = date('Y-m-01');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $to = date('Y-m-d');
        }
        $rows = (new \App\Services\PurchaseService())->editAuditLog(
            (int) $request->clientId(),
            (int) ($this->resolveBranch($request) ?? 0),
            $from,
            $to,
        );
        return $this->success(['rows' => $rows, 'totals' => ['count' => count($rows)]], 'Purchase edit audit log.');
    }

    /** Hospital Dispensing: stock issued to attached hospitals (amount on record, no payment). */
    public function hospitalDispensing(Request $request): Response
    {
        $from = (string) $request->query('from', '');
        $to = (string) $request->query('to', '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $from = date('Y-m-01');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $to = date('Y-m-d');
        }
        $rows = (new DispensingService())->report(
            (int) $request->clientId(),
            (int) ($this->resolveBranch($request) ?? 0),
            $from,
            $to,
            trim((string) $request->query('hospital_name', '')),
        );
        return $this->success([
            'rows' => $rows,
            'totals' => [
                'count' => count($rows),
                'qty' => array_sum(array_column($rows, 'qty')),
                'amount' => round(array_sum(array_column($rows, 'amount')), 2),
            ],
        ], 'Hospital dispensing.');
    }

    public function nearExpiry(Request $request): Response
    {
        $branch = $this->resolveBranch($request);
        $branchSql = $branch !== null ? ' AND ib.branch_id = ' . (int) $branch : '';
        $months = (int) $request->query('months', 3);
        return $this->success(
            (new ReportAnalyticsService())->nearExpiry([
                'branchSql' => $branchSql,
                'bind' => ['c' => $request->clientId()],
                'months' => $months,
            ]),
            'Near expiry.'
        );
    }

    public function manufacturerWiseStock(Request $request): Response
    {
        $branch = $this->resolveBranch($request);
        $branchSqlIb = $branch !== null ? ' AND ib.branch_id = ' . (int) $branch : '';
        $branchSqlL = $branch !== null ? ' AND l.branch_id = ' . (int) $branch : '';
        $branchSqlP = $branch !== null ? ' AND p.branch_id = ' . (int) $branch : '';
        $manufacturer = trim((string) ($request->query('manufacturer') ?? $request->query('m_name') ?? ''));
        $payload = [
            'branchSqlIb' => $branchSqlIb,
            'branchSqlL' => $branchSqlL,
            'branchSqlP' => $branchSqlP,
            'bind' => ['c' => $request->clientId()],
            'business_type' => $this->clientBusinessType((int) $request->clientId()),
        ];
        if ($manufacturer !== '') {
            $payload['manufacturer'] = $manufacturer;
        }
        return $this->success(
            (new ReportAnalyticsService())->manufacturerWiseStock($payload),
            'Manufacturer-wise stock.'
        );
    }

    public function productTransaction(Request $request): Response
    {
        $sale = $this->tableScope($request, 's', 'invoice_date');
        $pur = $this->tableScope($request, 'p', 'invoice_date');
        $product = trim((string) ($request->query('product_name') ?? $request->query('p_name') ?? ''));
        if ($product !== '') {
            $sale['where'] .= ' AND (
                TRIM(si.product_name) = :product_name
                OR EXISTS (
                    SELECT 1 FROM product_bindings pbx
                    WHERE pbx.id = si.product_binding_id AND TRIM(pbx.product_name) = :product_name2
                )
            )';
            $sale['bind']['product_name'] = $product;
            $sale['bind']['product_name2'] = $product;
            $pur['where'] .= ' AND (
                TRIM(pi.product_name) = :product_name
                OR EXISTS (
                    SELECT 1 FROM product_bindings pbx
                    WHERE pbx.id = pi.product_binding_id AND TRIM(pbx.product_name) = :product_name2
                )
            )';
            $pur['bind']['product_name'] = $product;
            $pur['bind']['product_name2'] = $product;
        }
        $bindSale = $this->prefixBind($sale['bind'], 's_');
        $bindPur = $this->prefixBind($pur['bind'], 'p_');
        return $this->success(
            (new ReportAnalyticsService())->productTransaction([
                'whereSale' => $this->prefixPlaceholders($sale['where'], 's_', array_keys($sale['bind'])),
                'bindSale' => $bindSale,
                'wherePur' => $this->prefixPlaceholders($pur['where'], 'p_', array_keys($pur['bind'])),
                'bindPur' => $bindPur,
            ]),
            'Product transaction.'
        );
    }

    public function saleGstSlabBillWise(Request $request): Response
    {
        return $this->success(
            (new ReportAnalyticsService())->saleGstSlabBillWise($this->tableScope($request, 's', 'invoice_date')),
            'Sale GST slab bill-wise.'
        );
    }

    public function purchaseGstSlabBillWise(Request $request): Response
    {
        return $this->success(
            (new ReportAnalyticsService())->purchaseGstSlabBillWise($this->tableScope($request, 'p', 'invoice_date')),
            'Purchase GST slab bill-wise.'
        );
    }

    public function hsnB2c(Request $request): Response
    {
        return $this->success(
            (new ReportAnalyticsService())->hsnGstPortal($this->monthScope($request, 's'), 'b2c'),
            'HSN B2C GST portal.'
        );
    }

    public function hsnB2b(Request $request): Response
    {
        return $this->success(
            (new ReportAnalyticsService())->hsnGstPortal($this->monthScope($request, 's'), 'b2b'),
            'HSN B2B GST portal.'
        );
    }

    public function b2bInvoices(Request $request): Response
    {
        return $this->success(
            (new ReportAnalyticsService())->b2bInvoiceGst($this->monthScope($request, 's')),
            'B2B invoices GST.'
        );
    }

    public function b2cSmall(Request $request): Response
    {
        $pos = trim((string) $request->query('place_of_supply', ''));
        return $this->success(
            (new ReportAnalyticsService())->b2cSmallGst($this->monthScope($request, 's'), $pos),
            'B2C small GST.'
        );
    }

    public function documentIssued(Request $request): Response
    {
        $month = (string) $request->query('month', date('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = date('Y-m');
        }
        $from = $month . '-01';
        $to = date('Y-m-d', strtotime($from . ' +1 month -1 day'));
        return $this->success(
            (new ReportAnalyticsService())->documentIssuedGst([
                'client_id' => $request->clientId(),
                'branch_id' => $this->resolveBranch($request),
                'from' => $from,
                'to' => $to,
            ]),
            'Document issued GST.'
        );
    }

    public function distributorWiseSale(Request $request): Response
    {
        $scope = $this->tableScope($request, 's', 'invoice_date');
        $name = trim((string) ($request->query('distributor_name') ?? ''));
        if ($name !== '') {
            $scope['distributor_name'] = $name;
        }
        return $this->success(
            (new ReportAnalyticsService())->distributorWiseSale($scope),
            'Distributor-wise sale.'
        );
    }

    public function distributorWiseStock(Request $request): Response
    {
        $branch = $this->resolveBranch($request);
        $branchSql = $branch !== null ? ' AND ib.branch_id = ' . (int) $branch : '';
        $name = trim((string) ($request->query('distributor_name') ?? $request->query('dist_name') ?? ''));
        $payload = [
            'branchSql' => $branchSql,
            'bind' => ['c' => $request->clientId()],
        ];
        if ($name !== '' && strcasecmp($name, 'All') !== 0) {
            $payload['distributor_name'] = $name;
        }
        return $this->success(
            (new ReportAnalyticsService())->distributorWiseStock($payload),
            'Distributor-wise stock.'
        );
    }

    public function saleStock(Request $request): Response
    {
        return $this->success(
            (new ReportAnalyticsService())->saleStock($this->tableScope($request, 's', 'invoice_date')),
            'Sale stock report.'
        );
    }

    public function doctorMargin(Request $request): Response
    {
        $margin = (float) $request->query('margin_pct', 0);
        return $this->success(
            (new ReportAnalyticsService())->doctorMargin($this->tableScope($request, 's', 'invoice_date'), $margin),
            'Doctor margin report.'
        );
    }

    public function areaWisePayment(Request $request): Response
    {
        return $this->success(
            (new ReportAnalyticsService())->areaWisePayment($this->tableScope($request, 's', 'invoice_date')),
            'Area-wise payment report.'
        );
    }

    public function accurateProfitLoss(Request $request): Response
    {
        return $this->success(
            (new ReportAnalyticsService())->accurateProfitLoss($this->tableScope($request, 's', 'invoice_date')),
            'Updated profit / loss.'
        );
    }

    /**
     * Month filter for GST portal reports (YYYY-MM → from/to on alias.invoice_date).
     * @return array{where:string,bind:array<string,mixed>,branchSql:string}
     */
    private function monthScope(Request $request, string $alias): array
    {
        $month = (string) $request->query('month', date('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = date('Y-m');
        }
        $from = $month . '-01';
        $to = date('Y-m-d', strtotime($from . ' +1 month -1 day'));

        $where = "{$alias}.client_id = :c";
        $bind = ['c' => $request->clientId(), 'from' => $from, 'to' => $to];
        $branch = $this->resolveBranch($request);
        $branchSql = '';
        if ($branch !== null) {
            $where .= " AND {$alias}.branch_id = :b";
            $bind['b'] = $branch;
            $branchSql = " AND {$alias}.branch_id = " . (int) $branch;
        }
        $where .= " AND DATE({$alias}.invoice_date) >= :from AND DATE({$alias}.invoice_date) <= :to";
        return ['where' => $where, 'bind' => $bind, 'branchSql' => $branchSql];
    }

    // ---- helpers -------------------------------------------------------

    /**
     * Scoped WHERE for aliased tables (p / s) used by analytics SQL.
     *
     * @return array{where:string,bind:array<string,mixed>,branchSql:string}
     */
    private function tableScope(Request $request, string $alias, string $dateColumn): array
    {
        $where = "{$alias}.client_id = :c";
        $bind = ['c' => $request->clientId()];
        $branch = $this->resolveBranch($request);
        $branchSql = '';
        if ($branch !== null) {
            $where .= " AND {$alias}.branch_id = :b";
            $bind['b'] = $branch;
            $branchSql = " AND {$alias}.branch_id = " . (int) $branch;
        }
        [$dateClause, $dateBind] = $this->dateRange($request, "{$alias}.{$dateColumn}");
        $where .= $dateClause;
        $bind = array_merge($bind, $dateBind);
        return ['where' => $where, 'bind' => $bind, 'branchSql' => $branchSql];
    }

    /** @param array<string,mixed> $bind @return array<string,mixed> */
    private function prefixBind(array $bind, string $prefix): array
    {
        $out = [];
        foreach ($bind as $k => $v) {
            $out[$prefix . $k] = $v;
        }
        return $out;
    }

    /** @param list<string|int> $keys */
    private function prefixPlaceholders(string $where, string $prefix, array $keys): string
    {
        // Replace longer keys first to avoid :c eating :cgst-style collisions (none here).
        $keys = array_map('strval', $keys);
        usort($keys, static fn ($a, $b) => strlen($b) <=> strlen($a));
        foreach ($keys as $k) {
            $where = str_replace(':' . $k, ':' . $prefix . $k, $where);
        }
        return $where;
    }

    /**
     * Resolve which branch to report on:
     *  - branch users: always their own branch
     *  - HO users: the ?branch_id= filter, or NULL (= all branches)
     */
    private function clientBusinessType(int $clientId): string
    {
        try {
            $st = Connection::ho()->prepare('SELECT business_type FROM clients WHERE id = :id LIMIT 1');
            $st->execute(['id' => $clientId]);
            $bt = strtolower(trim((string) ($st->fetchColumn() ?: 'both')));
            return in_array($bt, ['retail', 'wholesale', 'both'], true) ? $bt : 'both';
        } catch (\Throwable) {
            return 'both';
        }
    }

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
