<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Database\Connection;

/**
 * Daily Closing: end-of-day cash reconciliation.
 *
 * - summary : live figures for a given date (sales by mode, customer/debit
 *             payments, expected cash in drawer).
 * - dayEnd  : persist the closing with the counted available cash + difference.
 * - report  : list recorded closings within a date range.
 */
final class DailyClosingController extends Controller
{
    public function summary(Request $request): Response
    {
        $date = $this->validDate($request->query('date')) ?? date('Y-m-d');
        $clientId = (int) $request->clientId();
        $branchId = (int) $request->branchId();

        $data = $this->compute($clientId, $branchId, $date);
        $data['date'] = $date;
        $data['closing'] = $this->existingClosing($clientId, $branchId, $date);

        return $this->success($data, 'Daily closing summary.');
    }

    public function dayEnd(Request $request): Response
    {
        $date = $this->validDate($request->input('date')) ?? date('Y-m-d');
        $clientId = (int) $request->clientId();
        $branchId = (int) $request->branchId();
        $availableCash = round((float) $request->input('available_cash', 0), 2);
        $userId = $request->user()['id'] ?? null;

        $c = $this->compute($clientId, $branchId, $date);
        $difference = round($availableCash - (float) $c['expected_cash'], 2);

        $stmt = Connection::ops()->prepare(
            'INSERT INTO daily_closings
                (client_id, branch_id, closing_date, sales_cash, sales_upi, sales_card, sales_credit,
                 total_sales, customer_payment, debit_payment, today_customers, expected_cash,
                 available_cash, difference, closed_by, created_at, updated_at)
             VALUES
                (:cid, :bid, :d, :scash, :supi, :scard, :scredit,
                 :total, :custpay, :debit, :cust, :expected,
                 :avail, :diff, :uid, :ca, :ua)
             ON DUPLICATE KEY UPDATE
                sales_cash = VALUES(sales_cash), sales_upi = VALUES(sales_upi),
                sales_card = VALUES(sales_card), sales_credit = VALUES(sales_credit),
                total_sales = VALUES(total_sales), customer_payment = VALUES(customer_payment),
                debit_payment = VALUES(debit_payment), today_customers = VALUES(today_customers),
                expected_cash = VALUES(expected_cash), available_cash = VALUES(available_cash),
                difference = VALUES(difference), closed_by = VALUES(closed_by), updated_at = VALUES(updated_at)'
        );
        $stmt->execute([
            'cid' => $clientId, 'bid' => $branchId, 'd' => $date,
            'scash' => $c['sales']['cash'], 'supi' => $c['sales']['upi'],
            'scard' => $c['sales']['card'], 'scredit' => $c['sales']['credit'],
            'total' => $c['sales']['total'], 'custpay' => $c['customer_payment']['total'],
            'debit' => $c['debit_payment']['total'], 'cust' => $c['sales']['customers'],
            'expected' => $c['expected_cash'], 'avail' => $availableCash, 'diff' => $difference,
            'uid' => $userId, 'ca' => now(), 'ua' => now(),
        ]);

        return $this->success([
            'date' => $date,
            'available_cash' => $availableCash,
            'expected_cash' => $c['expected_cash'],
            'difference' => $difference,
        ], 'Day closed successfully.');
    }

    public function report(Request $request): Response
    {
        $from = $this->validDate($request->query('from')) ?? date('Y-m-01');
        $to = $this->validDate($request->query('to')) ?? date('Y-m-d');
        $clientId = (int) $request->clientId();
        $branchId = (int) $request->branchId();

        $stmt = Connection::ops()->prepare(
            'SELECT closing_date, sales_cash, sales_upi, sales_card, sales_credit, total_sales,
                    customer_payment, debit_payment, today_customers, expected_cash,
                    available_cash, difference
             FROM daily_closings
             WHERE client_id = :cid AND branch_id = :bid AND closing_date BETWEEN :f AND :t
             ORDER BY closing_date DESC'
        );
        $stmt->execute(['cid' => $clientId, 'bid' => $branchId, 'f' => $from, 't' => $to]);

        return $this->success(['rows' => $stmt->fetchAll(), 'from' => $from, 'to' => $to], 'Daily closing report.');
    }

    // ---- helpers -------------------------------------------------------

    /** Live figures for the given date. */
    private function compute(int $clientId, int $branchId, string $date): array
    {
        $db = Connection::ops();

        // Sales grouped by payment mode.
        $sales = ['cash' => 0.0, 'upi' => 0.0, 'card' => 0.0, 'credit' => 0.0, 'total' => 0.0, 'customers' => 0];
        $s = $db->prepare(
            'SELECT LOWER(payment_mode) AS m, COALESCE(SUM(net_amount),0) AS amt, COUNT(*) AS cnt
             FROM sales WHERE client_id = :c AND branch_id = :b AND DATE(invoice_date) = :d
             GROUP BY LOWER(payment_mode)'
        );
        $s->execute(['c' => $clientId, 'b' => $branchId, 'd' => $date]);
        foreach ($s->fetchAll() as $row) {
            $amt = (float) $row['amt'];
            $sales['total'] += $amt;
            $sales['customers'] += (int) $row['cnt'];
            $mode = $row['m'];
            if (isset($sales[$mode])) {
                $sales[$mode] += $amt;
            } elseif ($mode === 'upi' || $mode === 'online') {
                $sales['upi'] += $amt;
            } else {
                $sales['cash'] += $amt; // default unknown modes into cash
            }
        }

        $customerPayment = $this->voucherByMode($db, $clientId, $branchId, $date, 'receipt');
        $debitPayment = $this->voucherByMode($db, $clientId, $branchId, $date, 'payment');

        // Expected cash in the drawer = cash sales + cash received - cash paid out.
        $expectedCash = round($sales['cash'] + $customerPayment['cash'] - $debitPayment['cash'], 2);

        return [
            'sales' => array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $sales),
            'customer_payment' => $customerPayment,
            'debit_payment' => $debitPayment,
            'expected_cash' => $expectedCash,
        ];
    }

    /** @return array{cash:float,card:float,cheque:float,total:float} */
    private function voucherByMode(\PDO $db, int $clientId, int $branchId, string $date, string $type): array
    {
        $out = ['cash' => 0.0, 'card' => 0.0, 'cheque' => 0.0, 'total' => 0.0];
        $stmt = $db->prepare(
            'SELECT LOWER(mode) AS m, COALESCE(SUM(amount),0) AS amt
             FROM payment_vouchers
             WHERE client_id = :c AND branch_id = :b AND voucher_date = :d AND voucher_type = :t
             GROUP BY LOWER(mode)'
        );
        $stmt->execute(['c' => $clientId, 'b' => $branchId, 'd' => $date, 't' => $type]);
        foreach ($stmt->fetchAll() as $row) {
            $amt = (float) $row['amt'];
            $out['total'] += $amt;
            $mode = $row['m'];
            if (isset($out[$mode])) {
                $out[$mode] += $amt;
            } else {
                $out['cash'] += $amt;
            }
        }
        return array_map(fn ($v) => round($v, 2), $out);
    }

    private function existingClosing(int $clientId, int $branchId, string $date): ?array
    {
        $stmt = Connection::ops()->prepare(
            'SELECT available_cash, difference, closed_by, updated_at
             FROM daily_closings WHERE client_id = :c AND branch_id = :b AND closing_date = :d LIMIT 1'
        );
        $stmt->execute(['c' => $clientId, 'b' => $branchId, 'd' => $date]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private function validDate(mixed $value): ?string
    {
        $v = trim((string) $value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }
}
