<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use PDO;

/**
 * Retail-parity daily closing metrics (drawer cash excludes UPI sales & distributor debit).
 */
final class DailyClosingMetricsService
{
    /**
     * @return array{
     *   sales: array{cash:float,upi:float,card:float,credit:float,total:float,customers:int,return:float,net:float},
     *   customer_payment: array{cash:float,card:float,cheque:float,total:float},
     *   debit_payment: array{cash:float,card:float,cheque:float,total:float},
     *   expected_cash: float
     * }
     */
    public function compute(int $clientId, int $branchId, string $date): array
    {
        $db = Connection::ops();

        $sales = [
            'cash' => 0.0,
            'upi' => 0.0,
            'card' => 0.0,
            'credit' => 0.0,
            'total' => 0.0,
            'customers' => 0,
            'return' => 0.0,
            'net' => 0.0,
        ];

        $splitCols = SalesService::hasSplitPaymentColumns($db)
            ? 'COALESCE(SUM(cash_amount),0) AS split_cash, COALESCE(SUM(upi_amount),0) AS split_upi,'
            : '0 AS split_cash, 0 AS split_upi,';
        $s = $db->prepare(
            "SELECT LOWER(TRIM(payment_mode)) AS m,
                    COALESCE(SUM(net_amount),0) AS amt,
                    {$splitCols}
                    COUNT(*) AS cnt
             FROM sales
             WHERE client_id = :c AND branch_id = :b AND DATE(invoice_date) = :d
             GROUP BY LOWER(TRIM(payment_mode))"
        );
        $s->execute(['c' => $clientId, 'b' => $branchId, 'd' => $date]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $amt = (float) $row['amt'];
            $sales['total'] += $amt;
            $sales['customers'] += (int) $row['cnt'];
            $mode = (string) $row['m'];
            if ($mode === '' || $mode === 'cash') {
                $sales['cash'] += $amt;
            } elseif ($mode === 'cash+upi') {
                $splitCash = (float) $row['split_cash'];
                $sales['cash'] += $splitCash;
                $sales['upi'] += $amt - $splitCash;
            } elseif (in_array($mode, ['upi', 'online'], true)) {
                $sales['upi'] += $amt;
            } elseif (in_array($mode, ['card', 'bank / card', 'bank/card', 'bank'], true)) {
                $sales['card'] += $amt;
            } elseif ($mode === 'credit') {
                $sales['credit'] += $amt;
            } else {
                // Retail: unclassified remainder into cash
                $sales['cash'] += $amt;
            }
        }

        $sales['return'] = $this->retailReturn($db, $clientId, $branchId, $date);
        $sales['net'] = round(max(0, $sales['total'] - $sales['return']), 2);

        $customerPayment = $this->voucherByMode($db, $clientId, $branchId, $date, 'receipt');
        $debitPayment = $this->voucherByMode($db, $clientId, $branchId, $date, 'payment');

        // Retail drawer: sales cash + sales card + all customer payment modes; exclude UPI & debit
        $expectedCash = round(
            $sales['cash']
            + $sales['card']
            + $customerPayment['cash']
            + $customerPayment['card']
            + $customerPayment['cheque'],
            2
        );

        foreach (['cash', 'upi', 'card', 'credit', 'total', 'return', 'net'] as $k) {
            $sales[$k] = round((float) $sales[$k], 2);
        }

        return [
            'sales' => $sales,
            'customer_payment' => $customerPayment,
            'debit_payment' => $debitPayment,
            'expected_cash' => $expectedCash,
        ];
    }

    /** Retail sales returns for the day (return_total). */
    private function retailReturn(PDO $db, int $clientId, int $branchId, string $date): float
    {
        try {
            $stmt = $db->prepare(
                "SELECT COALESCE(SUM(return_total),0) AS amt
                 FROM retail_sale_returns
                 WHERE client_id = :c AND branch_id = :b
                   AND status = 'ACTIVE'
                   AND DATE(created_at) = :d"
            );
            $stmt->execute(['c' => $clientId, 'b' => $branchId, 'd' => $date]);
            return round((float) ($stmt->fetchColumn() ?: 0), 2);
        } catch (\Throwable) {
            return 0.0;
        }
    }

    /** @return array{cash:float,card:float,cheque:float,total:float} */
    private function voucherByMode(PDO $db, int $clientId, int $branchId, string $date, string $type): array
    {
        $out = ['cash' => 0.0, 'card' => 0.0, 'cheque' => 0.0, 'total' => 0.0];
        $stmt = $db->prepare(
            'SELECT LOWER(TRIM(mode)) AS m, COALESCE(SUM(amount),0) AS amt
             FROM payment_vouchers
             WHERE client_id = :c AND branch_id = :b AND voucher_date = :d AND voucher_type = :t
             GROUP BY LOWER(TRIM(mode))'
        );
        $stmt->execute(['c' => $clientId, 'b' => $branchId, 'd' => $date, 't' => $type]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $amt = (float) $row['amt'];
            $out['total'] += $amt;
            $mode = (string) $row['m'];
            if (in_array($mode, ['card', 'bank / card', 'bank/card', 'bank'], true)) {
                $out['card'] += $amt;
            } elseif (in_array($mode, ['cheque', 'check'], true)) {
                $out['cheque'] += $amt;
            } else {
                $out['cash'] += $amt;
            }
        }
        return array_map(static fn ($v) => round((float) $v, 2), $out);
    }
}
