<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Database\Connection;
use PDO;

/**
 * Wholesale / retail customer payment receipt HTML (Retail wholesale_customer_payment_print parity).
 */
final class PaymentReceiptService
{
    public function html(int $clientId, int $branchId, int $allocationId): string
    {
        (new PaymentSettlementService())->ensureSchema();
        $st = Connection::ops()->prepare(
            'SELECT * FROM payment_allocations
             WHERE id = :id AND client_id = :c AND branch_id = :b LIMIT 1'
        );
        $st->execute(['id' => $allocationId, 'c' => $clientId, 'b' => $branchId]);
        $pay = $st->fetch(PDO::FETCH_ASSOC);
        if (!$pay) {
            throw HttpException::notFound('Payment receipt not found.');
        }

        $sale = null;
        if (($pay['source'] ?? '') === 'sale' && (int) ($pay['bill_id'] ?? 0) > 0) {
            $s = Connection::ops()->prepare('SELECT * FROM sales WHERE id = :id LIMIT 1');
            $s->execute(['id' => (int) $pay['bill_id']]);
            $sale = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $client = [];
        try {
            (new ClientProfileService())->ensureProfileColumns();
            $c = Connection::ho()->prepare('SELECT * FROM clients WHERE id = :id LIMIT 1');
            $c->execute(['id' => $clientId]);
            $client = $c->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
        }

        $customer = [];
        $partyName = (string) ($pay['party_name'] ?? '');
        $phone = (string) ($pay['party_phone'] ?? '');
        try {
            if ($phone !== '') {
                $cu = Connection::ops()->prepare(
                    'SELECT * FROM customers WHERE client_id = :c AND phone = :p LIMIT 1'
                );
                $cu->execute(['c' => $clientId, 'p' => $phone]);
                $customer = $cu->fetch(PDO::FETCH_ASSOC) ?: [];
            }
            if ($customer === [] && $partyName !== '') {
                $cu = Connection::ops()->prepare(
                    'SELECT * FROM customers WHERE client_id = :c AND LOWER(TRIM(name)) = LOWER(TRIM(:n)) LIMIT 1'
                );
                $cu->execute(['c' => $clientId, 'n' => $partyName]);
                $customer = $cu->fetch(PDO::FETCH_ASSOC) ?: [];
            }
        } catch (\Throwable) {
        }

        $business = htmlspecialchars((string) ($client['business_name'] ?? $client['medical'] ?? 'Pharmacy'));
        $addr = htmlspecialchars((string) ($client['address'] ?? ''));
        $gst = htmlspecialchars((string) ($client['gstin'] ?? $client['gst_no'] ?? ''));
        $mobile = htmlspecialchars((string) ($client['phone'] ?? $client['mobile'] ?? ''));
        $dlParts = [];
        foreach (['dl1', 'dl2', 'dl3', 'dl4', 'drug_licence'] as $k) {
            $v = trim((string) ($client[$k] ?? ''));
            if ($v !== '') {
                $dlParts[] = $v;
            }
        }
        $dl = htmlspecialchars($dlParts ? implode(', ', $dlParts) : '-');

        $custName = htmlspecialchars((string) ($customer['name'] ?? $partyName));
        $custAddr = htmlspecialchars((string) ($customer['address'] ?? ($sale['address'] ?? '')));
        $custGst = htmlspecialchars((string) ($customer['gstin'] ?? ''));
        $custPhone = htmlspecialchars((string) ($customer['phone'] ?? $phone));

        $invoiceNo = htmlspecialchars((string) ($sale['invoice_no'] ?? $pay['bill_no'] ?? ''));
        $billDate = !empty($pay['bill_date']) ? date('d-m-Y', strtotime((string) $pay['bill_date'])) : '-';
        $payDate = !empty($pay['entry_date']) ? date('d-m-Y h:i A', strtotime((string) $pay['entry_date'])) : '-';
        $amount = number_format((float) ($pay['paid'] ?? 0), 2);
        $billAmt = number_format((float) ($pay['bill_amount'] ?? 0), 2);
        $remaining = number_format((float) ($pay['remaining'] ?? 0), 2);
        $mode = htmlspecialchars((string) ($pay['mode'] ?? '-'));
        $txn = htmlspecialchars((string) ($pay['transaction_no'] ?? '-'));
        $salePaid = $sale ? number_format((float) ($sale['paid'] ?? 0), 2) : $amount;
        $saleBal = $sale ? number_format((float) ($sale['balance'] ?? 0), 2) : $remaining;

        $partyType = (string) ($pay['party_type'] ?? 'customer');
        $title = $partyType === 'wcustomer' ? 'Wholesale Payment Receipt' : 'Payment Receipt';

        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . $title . '</title>
<style>
@page { size: A4; margin: 12mm; }
body { font-family: "Segoe UI", Arial, sans-serif; color: #0f172a; font-size: 13px; }
.box { border: 1px solid #0f172a; padding: 16px; max-width: 800px; margin: 0 auto; }
.hdr { text-align: center; margin-bottom: 12px; }
.hdr h1 { margin: 0; font-size: 20px; }
.hdr .sub { color: #475569; font-size: 12px; }
.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 24px; margin: 14px 0; }
.lbl { color: #64748b; font-size: 11px; text-transform: uppercase; }
.val { font-weight: 600; }
.amt { font-size: 22px; color: #0f766e; font-weight: 700; }
table { width: 100%; border-collapse: collapse; margin-top: 12px; }
th, td { border: 1px solid #cbd5e1; padding: 8px; text-align: left; }
th { background: #f1f5f9; font-size: 12px; }
.foot { margin-top: 18px; display: flex; justify-content: space-between; }
@media print { .no-print { display: none !important; } body { margin: 0; } }
</style></head><body>
<div class="no-print" style="text-align:center;margin:12px">
  <button onclick="window.print()" style="padding:8px 16px;cursor:pointer">Print</button>
</div>
<div class="box">
  <div class="hdr">
    <h1>' . $business . '</h1>
    <div class="sub">' . $addr . '</div>
    <div class="sub">GSTIN: ' . $gst . ' · DL: ' . $dl . ' · Ph: ' . $mobile . '</div>
    <div style="margin-top:10px;font-weight:700;letter-spacing:.04em">' . strtoupper($title) . '</div>
  </div>
  <div class="grid">
    <div><div class="lbl">Customer</div><div class="val">' . $custName . '</div></div>
    <div><div class="lbl">Payment Date</div><div class="val">' . htmlspecialchars($payDate) . '</div></div>
    <div><div class="lbl">Address</div><div class="val">' . ($custAddr !== '' ? $custAddr : '—') . '</div></div>
    <div><div class="lbl">Contact</div><div class="val">' . ($custPhone !== '' ? $custPhone : '—') . '</div></div>
    <div><div class="lbl">GSTIN</div><div class="val">' . ($custGst !== '' ? $custGst : '—') . '</div></div>
    <div><div class="lbl">Mode / Txn</div><div class="val">' . $mode . ' / ' . $txn . '</div></div>
  </div>
  <div style="text-align:center;margin:16px 0">
    <div class="lbl">Amount Received</div>
    <div class="amt">₹ ' . $amount . '</div>
  </div>
  <table>
    <thead>
      <tr><th>Invoice No</th><th>Bill Date</th><th>Bill Amount</th><th>Paid Now</th><th>Bill Paid (Total)</th><th>Balance</th></tr>
    </thead>
    <tbody>
      <tr>
        <td>' . $invoiceNo . '</td>
        <td>' . htmlspecialchars($billDate) . '</td>
        <td>₹ ' . $billAmt . '</td>
        <td>₹ ' . $amount . '</td>
        <td>₹ ' . $salePaid . '</td>
        <td>₹ ' . $saleBal . '</td>
      </tr>
    </tbody>
  </table>
  <div class="foot">
    <div>Generated by Advanced Pharmacy</div>
    <div style="text-align:right"><div class="lbl">Authorized Signatory</div><div style="margin-top:36px">________________</div></div>
  </div>
</div>
<script>window.addEventListener("load",function(){setTimeout(function(){window.print()},300)});</script>
</body></html>';
    }
}
