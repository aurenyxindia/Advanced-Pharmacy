<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Database\Connection;
use App\Models\PaymentVoucher;
use PDO;

/**
 * Retail-parity bill settlement (distributor / retail customer / wholesale customer).
 * Also writes a payment_vouchers header so cashbook stays connected.
 */
final class PaymentSettlementService
{
    /**
     * Sub-rupee leftovers after nearest-rupee payment (e.g. 0.50) must not linger as ₹1 due.
     * Balances in (0, 1) are treated as round-off residue.
     */
    private const ROUND_OFF_LIMIT = 1.0;

    public function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $db = Connection::ops();
        $db->exec(
            "CREATE TABLE IF NOT EXISTS payment_allocations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                client_id BIGINT UNSIGNED NOT NULL,
                branch_id BIGINT UNSIGNED NOT NULL,
                voucher_id BIGINT UNSIGNED NULL,
                party_type VARCHAR(20) NOT NULL,
                party_name VARCHAR(150) NOT NULL,
                party_phone VARCHAR(20) NULL,
                source VARCHAR(20) NOT NULL,
                bill_id BIGINT UNSIGNED NOT NULL,
                bill_no VARCHAR(60) NULL,
                bill_date DATETIME NULL,
                bill_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                paid DECIMAL(12,2) NOT NULL DEFAULT 0,
                remaining DECIMAL(12,2) NOT NULL DEFAULT 0,
                mode VARCHAR(40) NULL,
                transaction_no VARCHAR(80) NULL,
                entry_date DATETIME NOT NULL,
                created_by BIGINT UNSIGNED NULL,
                PRIMARY KEY (id),
                KEY idx_pa_tenant (client_id, branch_id),
                KEY idx_pa_party (client_id, party_type, party_name),
                KEY idx_pa_bill (source, bill_id),
                KEY idx_pa_voucher (voucher_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /** @return list<array<string,mixed>> */
    public function distributorDues(int $clientId, int $branchId): array
    {
        $this->ensureSchema();
        // Clear leftover 50-paise / sub-rupee balances so they don't show as ₹1 due.
        $this->clearPurchaseRoundOffResidue($clientId, $branchId, null);

        $st = Connection::ops()->prepare(
            "SELECT TRIM(supplier_name) AS distributor_name,
                    SUM(net_amount) AS net,
                    SUM(COALESCE(paid,0)) AS paid,
                    SUM(COALESCE(balance,0)) AS balance,
                    MIN(invoice_date) AS from_date,
                    COUNT(*) AS total_dues
             FROM purchases
             WHERE client_id = :c AND branch_id = :b
               AND COALESCE(balance,0) >= " . self::ROUND_OFF_LIMIT . "
               AND TRIM(COALESCE(supplier_name,'')) <> ''
             GROUP BY TRIM(supplier_name)
             ORDER BY distributor_name ASC"
        );
        $st->execute(['c' => $clientId, 'b' => $branchId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string) $row['distributor_name'];
            $details = $this->distributorBills($clientId, $branchId, $name);
            if ($details === []) {
                continue;
            }
            $bal = 0.0;
            $paid = 0.0;
            $net = 0.0;
            foreach ($details as $d) {
                $bal += (float) $d['balance'];
                $paid += (float) $d['paid'];
                $net += (float) $d['net_total'];
            }
            $dispBal = $this->displayRupee($bal);
            if ($dispBal <= 0) {
                continue;
            }
            $out[] = [
                'distributor_name' => $name,
                'net' => $this->displayRupee($net),
                'paid' => $this->displayRupee($paid),
                'balance' => $dispBal,
                'from_date' => $row['from_date'],
                'total_dues' => count($details),
                'details' => $details,
            ];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function distributorBills(int $clientId, int $branchId, string $name): array
    {
        $st = Connection::ops()->prepare(
            "SELECT id, invoice_no, invoice_date, net_amount AS net_total,
                    COALESCE(paid,0) AS paid, COALESCE(balance,0) AS balance
             FROM purchases
             WHERE client_id = :c AND branch_id = :b
               AND TRIM(supplier_name) = :n
               AND COALESCE(balance,0) >= " . self::ROUND_OFF_LIMIT . "
             ORDER BY invoice_date ASC, id ASC"
        );
        $st->execute(['c' => $clientId, 'b' => $branchId, 'n' => $name]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $bal = $this->displayRupee((float) $row['balance']);
            if ($bal <= 0) {
                continue;
            }
            $out[] = [
                'id' => (int) $row['id'],
                'invoice_no' => (string) $row['invoice_no'],
                'invoice_date' => $row['invoice_date'],
                'net_total' => $this->displayRupee((float) $row['net_total']),
                'paid' => $this->displayRupee((float) $row['paid']),
                'balance' => $bal,
                'payment_amount' => 0,
                'payment_mode' => '',
                'transaction_no' => '',
                'balance_amount' => $bal,
            ];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function customerDues(int $clientId, int $branchId, string $billMode): array
    {
        $this->ensureSchema();
        $mode = strtolower($billMode) === 'wholesale' ? 'wholesale' : 'retail';
        $this->clearSaleRoundOffResidue($clientId, $branchId, $mode, null, null);

        $st = Connection::ops()->prepare(
            "SELECT TRIM(COALESCE(NULLIF(customer_name,''), NULLIF(patient_name,''), 'Walk-in')) AS patient_name,
                    COALESCE(contact_no,'') AS contact_no,
                    MAX(email) AS email,
                    MAX(address) AS place,
                    SUM(net_amount) AS net,
                    MIN(invoice_date) AS from_date,
                    COUNT(*) AS total_dues
             FROM sales
             WHERE client_id = :c AND branch_id = :b
               AND COALESCE(balance,0) >= " . self::ROUND_OFF_LIMIT . "
               AND LOWER(COALESCE(bill_mode,'retail')) " . ($mode === 'wholesale' ? "= 'wholesale'" : "<> 'wholesale'") . "
             GROUP BY patient_name, contact_no
             ORDER BY patient_name ASC"
        );
        $st->execute(['c' => $clientId, 'b' => $branchId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string) $row['patient_name'];
            $phone = (string) $row['contact_no'];
            $details = $this->customerBills($clientId, $branchId, $name, $phone, $mode);
            if ($details === []) {
                continue;
            }
            $bal = 0.0;
            foreach ($details as $d) {
                $bal += (float) $d['balance'];
            }
            $dispBal = $this->displayRupee($bal);
            if ($dispBal <= 0) {
                continue;
            }
            $out[] = [
                'patient_name' => $name,
                'customer_name' => $name,
                'contact_no' => $phone,
                'email' => (string) ($row['email'] ?? ''),
                'place' => (string) ($row['place'] ?? ''),
                'net' => $this->displayRupee((float) $row['net']),
                'balance' => $dispBal,
                'from_date' => $row['from_date'],
                'total_dues' => count($details),
                'details' => $details,
            ];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function customerBills(int $clientId, int $branchId, string $name, string $phone, string $mode): array
    {
        $st = Connection::ops()->prepare(
            "SELECT id, invoice_no, invoice_date, net_amount AS net,
                    COALESCE(paid,0) AS paid, COALESCE(balance,0) AS balance
             FROM sales
             WHERE client_id = :c AND branch_id = :b
               AND COALESCE(balance,0) >= " . self::ROUND_OFF_LIMIT . "
               AND LOWER(COALESCE(bill_mode,'retail')) " . ($mode === 'wholesale' ? "= 'wholesale'" : "<> 'wholesale'") . "
               AND LOWER(TRIM(COALESCE(NULLIF(customer_name,''), NULLIF(patient_name,''), 'Walk-in'))) = LOWER(TRIM(:n))
               AND TRIM(COALESCE(contact_no,'')) = :ph
             ORDER BY invoice_date ASC, id ASC"
        );
        $st->execute(['c' => $clientId, 'b' => $branchId, 'n' => $name, 'ph' => $phone]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $bal = $this->displayRupee((float) $row['balance']);
            if ($bal <= 0) {
                continue;
            }
            $out[] = [
                'id' => (int) $row['id'],
                'order_no' => (string) $row['invoice_no'],
                'invoice_no' => (string) $row['invoice_no'],
                'order_date' => $row['invoice_date'],
                'invoice_date' => $row['invoice_date'],
                'net' => $this->displayRupee((float) $row['net']),
                'net_total' => $this->displayRupee((float) $row['net']),
                'paid' => $this->displayRupee((float) $row['paid']),
                'balance' => $bal,
                'payment_amount' => 0,
                'payment_mode' => '',
                'transaction_no' => '',
                'balance_amount' => $bal,
            ];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{status:string,voucher_id:?int}
     */
    public function settleDistributor(int $clientId, int $branchId, ?int $userId, array $payload): array
    {
        $this->ensureSchema();
        $name = trim((string) ($payload['distributor_name'] ?? ''));
        if ($name === '') {
            throw HttpException::validation(['distributor_name' => ['Distributor required.']]);
        }
        $details = $payload['details'] ?? [];
        if (!is_array($details)) {
            $details = [];
        }
        $mode = trim((string) ($payload['mode'] ?? 'Cash'));
        $txn = trim((string) ($payload['transaction_no'] ?? ''));

        $totalPay = 0.0;
        foreach ($details as $d) {
            $totalPay += (float) ($d['payment_amount'] ?? 0);
        }
        $totalPay = round($totalPay, 2);
        if ($totalPay <= 0) {
            throw HttpException::validation(['amount' => ['Enter payment amount for at least one bill.']]);
        }

        $dueSt = Connection::ops()->prepare(
            "SELECT COALESCE(SUM(balance),0) FROM purchases
             WHERE client_id = :c AND branch_id = :b AND TRIM(supplier_name) = :n AND COALESCE(balance,0) > 0"
        );
        $dueSt->execute(['c' => $clientId, 'b' => $branchId, 'n' => $name]);
        $totalDue = round((float) $dueSt->fetchColumn(), 2);
        $maxDue = $this->maxPayable($totalDue);
        // Nearest-rupee UI: allow paying rounded due (e.g. due 711.50 → pay 712).
        if ($totalPay > $maxDue + 0.009) {
            throw HttpException::validation(['amount' => ["Total payment cannot exceed total due ({$maxDue})."]]);
        }

        $db = Connection::ops();
        $db->beginTransaction();
        try {
            $voucherId = $this->createVoucher($clientId, $branchId, $userId, 'payment', 'supplier', $name, $totalPay, $mode, $txn, 'Distributor payment');
            $applied = 0;
            foreach ($details as $d) {
                $pay = round((float) ($d['payment_amount'] ?? 0), 2);
                if ($pay <= 0) {
                    continue;
                }
                $billId = (int) ($d['id'] ?? 0);
                $bill = $db->prepare(
                    'SELECT id, invoice_no, invoice_date, net_amount, COALESCE(paid,0) AS paid, COALESCE(balance,0) AS balance
                     FROM purchases WHERE id = :id AND client_id = :c AND branch_id = :b LIMIT 1'
                );
                $bill->execute(['id' => $billId, 'c' => $clientId, 'b' => $branchId]);
                $row = $bill->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    continue;
                }
                $curBal = round((float) $row['balance'], 2);
                if ($curBal <= 0) {
                    continue;
                }
                [$pay, $newBal] = $this->applyRoundedPayment($pay, $curBal);
                if ($pay <= 0) {
                    continue;
                }
                $lineMode = trim((string) ($d['payment_mode'] ?? $mode));
                $lineTxn = trim((string) ($d['transaction_no'] ?? $txn));
                $this->insertAllocation(
                    $clientId,
                    $branchId,
                    $voucherId,
                    'distributor',
                    $name,
                    null,
                    'purchase',
                    $billId,
                    (string) $row['invoice_no'],
                    $row['invoice_date'],
                    (float) $row['net_amount'],
                    $pay,
                    $newBal,
                    $lineMode,
                    $lineTxn,
                    $userId
                );
                $status = $newBal <= 0 ? 'paid' : 'partial';
                $db->prepare(
                    'UPDATE purchases SET paid = COALESCE(paid,0) + :p, balance = :bal, payment_status = :st WHERE id = :id'
                )->execute(['p' => $pay, 'bal' => $newBal, 'st' => $status, 'id' => $billId]);
                $applied++;
            }
            if ($applied === 0) {
                throw HttpException::validation(['amount' => ['Enter payment amount for at least one bill.']]);
            }
            $this->clearPurchaseRoundOffResidue($clientId, $branchId, $name);
            $db->commit();
            return ['status' => 'success', 'voucher_id' => $voucherId];
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Retail customer: lump-sum FIFO across oldest bills.
     * Wholesale: can use details[] per-bill or lump amount.
     *
     * @param array<string,mixed> $payload
     * @return array{status:string,voucher_id:?int,applied?:list<array<string,mixed>>}
     */
    public function settleCustomer(int $clientId, int $branchId, ?int $userId, array $payload, string $billMode): array
    {
        $this->ensureSchema();
        $modeKey = strtolower($billMode) === 'wholesale' ? 'wholesale' : 'retail';
        $name = trim((string) ($payload['patient_name'] ?? $payload['customer_name'] ?? ''));
        $phone = trim((string) ($payload['contact_no'] ?? ''));
        if ($name === '') {
            throw HttpException::validation(['patient_name' => ['Customer required.']]);
        }
        $payMode = trim((string) ($payload['mode'] ?? 'Cash'));
        $txn = trim((string) ($payload['transaction_no'] ?? ''));
        $details = $payload['details'] ?? null;

        $db = Connection::ops();
        $db->beginTransaction();
        try {
            $bills = $this->customerBills($clientId, $branchId, $name, $phone, $modeKey);
            if ($bills === []) {
                throw HttpException::validation(['amount' => ['No pending balance found.']]);
            }

            $allocations = [];
            if (is_array($details) && $details !== []) {
                foreach ($details as $d) {
                    $pay = round((float) ($d['payment_amount'] ?? 0), 2);
                    if ($pay <= 0) {
                        continue;
                    }
                    $allocations[] = [
                        'id' => (int) ($d['id'] ?? 0),
                        'pay' => $pay,
                        'mode' => trim((string) ($d['payment_mode'] ?? $payMode)),
                        'txn' => trim((string) ($d['transaction_no'] ?? $txn)),
                    ];
                }
            } else {
                $amount = round((float) ($payload['amount'] ?? 0), 2);
                if ($amount <= 0) {
                    throw HttpException::validation(['amount' => ['Invalid amount.']]);
                }
                $totalDue = 0.0;
                foreach ($bills as $b) {
                    $totalDue += (float) $b['balance'];
                }
                $totalDue = round($totalDue, 2);
                $maxDue = $this->maxPayable($totalDue);
                if ($amount > $maxDue + 0.009) {
                    throw HttpException::validation(['amount' => ["Amount cannot be more than total due ({$maxDue})."]]);
                }
                $left = $amount;
                foreach ($bills as $b) {
                    if ($left <= 0) {
                        break;
                    }
                    $bal = (float) $b['balance'];
                    $pay = min($left, $bal);
                    $pay = round($pay, 2);
                    if ($pay <= 0) {
                        continue;
                    }
                    $allocations[] = ['id' => (int) $b['id'], 'pay' => $pay, 'mode' => $payMode, 'txn' => $txn];
                    $left = round($left - $pay, 2);
                }
            }

            if ($allocations === []) {
                throw HttpException::validation(['amount' => ['Enter payment amount.']]);
            }

            $totalPay = round(array_sum(array_column($allocations, 'pay')), 2);
            $voucherType = 'receipt';
            $partyType = 'customer';
            $voucherId = $this->createVoucher(
                $clientId,
                $branchId,
                $userId,
                $voucherType,
                $partyType,
                $name,
                $totalPay,
                $payMode,
                $txn,
                $modeKey === 'wholesale' ? 'Wholesale customer payment' : 'Retail customer payment'
            );

            $applied = [];
            $byId = [];
            foreach ($bills as $b) {
                $byId[(int) $b['id']] = $b;
            }
            foreach ($allocations as $a) {
                $billId = (int) $a['id'];
                if (!isset($byId[$billId])) {
                    // reload current
                    $st = $db->prepare(
                        'SELECT id, invoice_no, invoice_date, net_amount AS net, COALESCE(paid,0) AS paid, COALESCE(balance,0) AS balance
                         FROM sales WHERE id = :id AND client_id = :c AND branch_id = :b LIMIT 1'
                    );
                    $st->execute(['id' => $billId, 'c' => $clientId, 'b' => $branchId]);
                    $row = $st->fetch(PDO::FETCH_ASSOC);
                    if (!$row) {
                        continue;
                    }
                } else {
                    $row = [
                        'id' => $billId,
                        'invoice_no' => $byId[$billId]['invoice_no'],
                        'invoice_date' => $byId[$billId]['invoice_date'],
                        'net' => $byId[$billId]['net'],
                        'paid' => $byId[$billId]['paid'],
                        'balance' => $byId[$billId]['balance'],
                    ];
                    // Fresh balance
                    $fresh = $db->prepare('SELECT COALESCE(balance,0), COALESCE(paid,0), net_amount, invoice_no, invoice_date FROM sales WHERE id = :id');
                    $fresh->execute(['id' => $billId]);
                    $f = $fresh->fetch(PDO::FETCH_NUM);
                    if ($f) {
                        $row['balance'] = $f[0];
                        $row['paid'] = $f[1];
                        $row['net'] = $f[2];
                        $row['invoice_no'] = $f[3];
                        $row['invoice_date'] = $f[4];
                    }
                }
                $curBal = round((float) $row['balance'], 2);
                if ($curBal <= 0) {
                    continue;
                }
                [$pay, $newBal] = $this->applyRoundedPayment((float) $a['pay'], $curBal);
                if ($pay <= 0) {
                    continue;
                }
                $this->insertAllocation(
                    $clientId,
                    $branchId,
                    $voucherId,
                    $modeKey === 'wholesale' ? 'wcustomer' : 'customer',
                    $name,
                    $phone,
                    'sale',
                    $billId,
                    (string) $row['invoice_no'],
                    $row['invoice_date'],
                    (float) $row['net'],
                    $pay,
                    $newBal,
                    (string) $a['mode'],
                    (string) $a['txn'],
                    $userId
                );
                $allocId = (int) $db->lastInsertId();
                $db->prepare(
                    'UPDATE sales SET paid = COALESCE(paid,0) + :p, balance = :bal WHERE id = :id'
                )->execute(['p' => $pay, 'bal' => $newBal, 'id' => $billId]);
                $applied[] = [
                    'id' => $billId,
                    'allocation_id' => $allocId,
                    'paid_now' => $pay,
                    'previous_balance' => $curBal,
                    'new_balance' => $newBal,
                ];
            }
            if ($applied === []) {
                throw HttpException::validation(['amount' => ['No payment applied.']]);
            }
            $this->clearSaleRoundOffResidue($clientId, $branchId, $modeKey, $name, $phone);
            $db->commit();
            return [
                'status' => 'success',
                'voucher_id' => $voucherId,
                'applied' => $applied,
                'allocation_ids' => array_values(array_filter(array_column($applied, 'allocation_id'))),
            ];
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /** @return list<array<string,mixed>> */
    public function history(int $clientId, int $branchId, string $partyType, ?string $partyName = null, ?string $phone = null): array
    {
        $this->ensureSchema();
        $params = ['c' => $clientId, 'b' => $branchId, 'pt' => $partyType];
        $where = 'client_id = :c AND branch_id = :b AND party_type = :pt';
        if ($partyName !== null && trim($partyName) !== '') {
            $where .= ' AND LOWER(TRIM(party_name)) = LOWER(TRIM(:n))';
            $params['n'] = trim($partyName);
        }
        if ($phone !== null && $phone !== '') {
            $where .= ' AND TRIM(COALESCE(party_phone,\'\')) = :ph';
            $params['ph'] = $phone;
        }
        $st = Connection::ops()->prepare(
            "SELECT * FROM payment_allocations WHERE {$where} ORDER BY id DESC LIMIT 500"
        );
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'party_name' => (string) $row['party_name'],
                'distributor_name' => (string) $row['party_name'],
                'patient_name' => (string) $row['party_name'],
                'contact_no' => (string) ($row['party_phone'] ?? ''),
                'bill_no' => (string) ($row['bill_no'] ?? ''),
                'bill_date' => $row['bill_date'],
                // Nearest-rupee display; sub-₹1 remaining (0.29 etc.) shows as 0.
                'bill_amount' => $this->displayRupee((float) $row['bill_amount']),
                'paid' => $this->displayRupee((float) $row['paid']),
                'remaining' => $this->displayRupee((float) $row['remaining']),
                'mode' => (string) ($row['mode'] ?? ''),
                'transaction_no' => (string) ($row['transaction_no'] ?? ''),
                'entry_date' => $row['entry_date'],
            ];
        }
        return $out;
    }

    private function createVoucher(
        int $clientId,
        int $branchId,
        ?int $userId,
        string $voucherType,
        string $partyType,
        string $partyName,
        float $amount,
        string $mode,
        string $ref,
        string $narration
    ): int {
        $modeNorm = strtolower($mode);
        if (str_contains($modeNorm, 'upi')) {
            $modeNorm = 'upi';
        } elseif (str_contains($modeNorm, 'cheque')) {
            $modeNorm = 'cheque';
        } elseif (str_contains($modeNorm, 'card') || str_contains($modeNorm, 'bank')) {
            $modeNorm = 'bank';
        } else {
            $modeNorm = 'cash';
        }
        $prefix = $voucherType === 'receipt' ? 'PV-R-' : 'PV-P-';
        $model = new PaymentVoucher();
        return (int) $model->insert([
            'client_id' => $clientId,
            'branch_id' => $branchId,
            'voucher_no' => $prefix . time() . '-' . random_int(10, 99),
            'voucher_type' => $voucherType,
            'party_type' => $partyType,
            'party_name' => $partyName,
            'amount' => round($amount, 2),
            'mode' => $modeNorm,
            'ref_no' => $ref !== '' ? $ref : null,
            'voucher_date' => date('Y-m-d'),
            'narration' => $narration,
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertAllocation(
        int $clientId,
        int $branchId,
        int $voucherId,
        string $partyType,
        string $partyName,
        ?string $phone,
        string $source,
        int $billId,
        string $billNo,
        mixed $billDate,
        float $billAmount,
        float $paid,
        float $remaining,
        string $mode,
        string $txn,
        ?int $userId
    ): void {
        Connection::ops()->prepare(
            'INSERT INTO payment_allocations
                (client_id, branch_id, voucher_id, party_type, party_name, party_phone, source, bill_id, bill_no, bill_date,
                 bill_amount, paid, remaining, mode, transaction_no, entry_date, created_by)
             VALUES
                (:c, :b, :v, :pt, :pn, :ph, :src, :bid, :bno, :bdt, :bamt, :paid, :rem, :mode, :txn, :ed, :by)'
        )->execute([
            'c' => $clientId,
            'b' => $branchId,
            'v' => $voucherId,
            'pt' => $partyType,
            'pn' => $partyName,
            'ph' => $phone,
            'src' => $source,
            'bid' => $billId,
            'bno' => $billNo,
            'bdt' => $billDate,
            'bamt' => round($billAmount, 2),
            'paid' => round($paid, 2),
            'rem' => round($remaining, 2),
            'mode' => $mode,
            'txn' => $txn,
            'ed' => now(),
            'by' => $userId,
        ]);
    }

    /** Nearest-rupee (same as payments UI / purchase credit dues). */
    private function rupeeRound(float $amount): float
    {
        return (float) round($amount, 0);
    }

    /**
     * Display/list amount: nearest rupee, but sub-rupee leftovers (&lt; ₹1) show as 0
     * so 0.50 does not appear as ₹1 after dues are cleared.
     */
    private function displayRupee(float $amount): float
    {
        $amount = round($amount, 2);
        if ($this->isRoundOffResidue($amount)) {
            return 0.0;
        }

        return $this->rupeeRound($amount);
    }

    private function isRoundOffResidue(float $balance): bool
    {
        $balance = abs(round($balance, 2));

        return $balance > 0.009 && $balance < self::ROUND_OFF_LIMIT;
    }

    /**
     * Max amount the UI may pay against a due: exact due or nearest-rupee round-off (whichever is higher).
     * Example: due 711.50 → max 712.
     */
    private function maxPayable(float $due): float
    {
        $due = round($due, 2);

        return max($due, $this->rupeeRound($due));
    }

    /**
     * Apply a payment that may include nearest-rupee round-off.
     *
     * @return array{0:float,1:float} [pay_recorded, new_balance]
     */
    private function applyRoundedPayment(float $pay, float $curBal): array
    {
        $pay = round($pay, 2);
        $curBal = round($curBal, 2);
        if ($pay <= 0 || $curBal <= 0) {
            return [0.0, $this->isRoundOffResidue($curBal) ? 0.0 : $curBal];
        }

        $max = $this->maxPayable($curBal);
        if ($pay > $max + 0.009) {
            $pay = $max;
        }

        // Paying the rounded due (or exact balance) clears the bill; round-off residue is absorbed.
        if ($pay + 0.009 >= $curBal || abs($pay - $this->rupeeRound($curBal)) < 0.009) {
            return [$pay, 0.0];
        }

        $newBal = round($curBal - $pay, 2);
        // Absorb leftover &lt; ₹1 (e.g. 0.50) so dues list does not show ₹1.
        if ($newBal < self::ROUND_OFF_LIMIT) {
            $newBal = 0.0;
        }

        return [$pay, $newBal];
    }

    /** Write off purchase balances in (0, 1) left after nearest-rupee settlement. */
    private function clearPurchaseRoundOffResidue(int $clientId, int $branchId, ?string $supplierName): void
    {
        $sql = "UPDATE purchases
                SET balance = 0,
                    payment_status = 'paid',
                    paid = CASE
                        WHEN COALESCE(paid, 0) < COALESCE(net_amount, 0) THEN COALESCE(net_amount, 0)
                        ELSE paid
                    END
                WHERE client_id = :c AND branch_id = :b
                  AND COALESCE(balance, 0) > 0.009
                  AND COALESCE(balance, 0) < " . self::ROUND_OFF_LIMIT;
        $bind = ['c' => $clientId, 'b' => $branchId];
        if ($supplierName !== null && trim($supplierName) !== '') {
            $sql .= ' AND TRIM(supplier_name) = :n';
            $bind['n'] = trim($supplierName);
        }
        Connection::ops()->prepare($sql)->execute($bind);
    }

    /** Write off sale balances in (0, 1) left after nearest-rupee settlement. */
    private function clearSaleRoundOffResidue(
        int $clientId,
        int $branchId,
        string $mode,
        ?string $customerName,
        ?string $phone,
    ): void {
        $modeSql = $mode === 'wholesale' ? "= 'wholesale'" : "<> 'wholesale'";
        $sql = "UPDATE sales
                SET balance = 0,
                    paid = CASE
                        WHEN COALESCE(paid, 0) < COALESCE(net_amount, 0) THEN COALESCE(net_amount, 0)
                        ELSE paid
                    END
                WHERE client_id = :c AND branch_id = :b
                  AND LOWER(COALESCE(bill_mode, 'retail')) {$modeSql}
                  AND COALESCE(balance, 0) > 0.009
                  AND COALESCE(balance, 0) < " . self::ROUND_OFF_LIMIT;
        $bind = ['c' => $clientId, 'b' => $branchId];
        if ($customerName !== null && trim($customerName) !== '') {
            $sql .= " AND LOWER(TRIM(COALESCE(NULLIF(customer_name,''), NULLIF(patient_name,''), 'Walk-in'))) = LOWER(TRIM(:n))";
            $bind['n'] = trim($customerName);
        }
        if ($phone !== null) {
            $sql .= " AND TRIM(COALESCE(contact_no,'')) = :ph";
            $bind['ph'] = trim($phone);
        }
        Connection::ops()->prepare($sql)->execute($bind);
    }
}
