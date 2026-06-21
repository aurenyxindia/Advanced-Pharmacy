<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use TCPDF;

/**
 * Renders a GST tax invoice PDF for a sale using TCPDF.
 */
final class InvoiceService
{
    public function render(array $sale, array $branch, array $client): string
    {
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->SetCreator('Advanced Pharmacy Suite');
        $pdf->SetAuthor($client['business_name'] ?? 'Pharmacy');
        $pdf->SetTitle('Invoice ' . ($sale['invoice_no'] ?? ''));
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(12, 12, 12);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage();

        $business = htmlspecialchars((string) ($client['business_name'] ?? 'Pharmacy'));
        $addr = htmlspecialchars(trim(($branch['address'] ?? '') . ', ' . ($branch['city'] ?? '') . ' ' . ($branch['pincode'] ?? '')));
        $dl = htmlspecialchars((string) ($branch['drug_license_no'] ?? ($client['drug_license_no'] ?? '')));
        $gstin = htmlspecialchars((string) ($branch['gstin'] ?? ($client['gstin'] ?? '')));

        $header = '
        <table cellpadding="2">
            <tr>
                <td width="60%">
                    <span style="font-size:16px;font-weight:bold;color:#0d9488;">' . $business . '</span><br/>
                    <span style="font-size:9px;">' . $addr . '</span><br/>
                    <span style="font-size:9px;">DL No: ' . $dl . ' &nbsp; GSTIN: ' . $gstin . '</span>
                </td>
                <td width="40%" style="text-align:right;">
                    <span style="font-size:14px;font-weight:bold;">TAX INVOICE</span><br/>
                    <span style="font-size:9px;">Invoice: ' . htmlspecialchars((string) $sale['invoice_no']) . '</span><br/>
                    <span style="font-size:9px;">Date: ' . htmlspecialchars((string) $sale['invoice_date']) . '</span>
                </td>
            </tr>
        </table>
        <hr/>
        <table cellpadding="2">
            <tr>
                <td width="60%"><span style="font-size:9px;">Customer: <b>' . htmlspecialchars((string) ($sale['customer_name'] ?? 'Walk-in')) . '</b></span></td>
                <td width="40%"><span style="font-size:9px;">Doctor: ' . htmlspecialchars((string) ($sale['doctor_name'] ?? '-')) . '</span></td>
            </tr>
        </table>';
        $pdf->writeHTML($header, true, false, true, false, '');

        $rows = '';
        $idx = 1;
        foreach ($sale['items'] as $item) {
            $rows .= '<tr>
                <td width="5%">' . $idx++ . '</td>
                <td width="29%">' . htmlspecialchars((string) $item['product_name']) . '</td>
                <td width="9%">' . htmlspecialchars((string) ($item['manufacturer_short'] ?? '-')) . '</td>
                <td width="13%">' . htmlspecialchars((string) ($item['batch_no'] ?? '-')) . '</td>
                <td width="7%" align="center">' . $item['schedule_category'] . '</td>
                <td width="7%" align="right">' . $item['quantity'] . '</td>
                <td width="10%" align="right">' . number_format((float) $item['rate'], 2) . '</td>
                <td width="8%" align="right">' . number_format((float) $item['gst_rate'], 0) . '%</td>
                <td width="12%" align="right">' . number_format((float) $item['line_total'], 2) . '</td>
            </tr>';
        }

        $table = '
        <table border="0.5" cellpadding="3" style="font-size:9px;">
            <thead>
                <tr style="background-color:#f1f5f9;font-weight:bold;">
                    <td width="5%">#</td><td width="29%">Product</td><td width="9%">Mfg</td><td width="13%">Batch</td>
                    <td width="7%" align="center">Sch</td><td width="7%" align="right">Qty</td>
                    <td width="10%" align="right">Rate</td><td width="8%" align="right">GST</td>
                    <td width="12%" align="right">Amount</td>
                </tr>
            </thead>
            <tbody>' . $rows . '</tbody>
        </table>
        <br/>
        <table cellpadding="2" style="font-size:10px;">
            <tr><td width="70%"></td><td width="18%" align="right">Gross:</td><td width="12%" align="right">' . number_format((float) $sale['gross_amount'], 2) . '</td></tr>
            <tr><td></td><td align="right">Discount:</td><td align="right">' . number_format((float) $sale['discount_amount'], 2) . '</td></tr>
            <tr><td></td><td align="right">GST (incl):</td><td align="right">' . number_format((float) $sale['gst_amount'], 2) . '</td></tr>
            <tr style="font-weight:bold;"><td></td><td align="right">Net Payable:</td><td align="right">' . number_format((float) $sale['net_amount'], 2) . '</td></tr>
        </table>
        <br/><br/>
        <span style="font-size:8px;color:#64748b;">This is a computer-generated GST invoice. Schedule H/H1/X/G &amp; restricted drugs dispensed against a valid prescription and are non-returnable.</span>';
        $pdf->writeHTML($table, true, false, true, false, '');

        return $pdf->Output('invoice.pdf', 'S');
    }

    public function forSale(array $sale): string
    {
        $branch = Connection::ho()->query(
            'SELECT * FROM branches WHERE id = ' . (int) $sale['branch_id']
        )->fetch() ?: [];
        $client = Connection::ho()->query(
            'SELECT * FROM clients WHERE id = ' . (int) $sale['client_id']
        )->fetch() ?: [];

        return $this->render($sale, $branch, $client);
    }
}
