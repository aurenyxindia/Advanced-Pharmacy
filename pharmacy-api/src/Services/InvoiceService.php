<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use TCPDF;

/**
 * Renders a GST tax invoice PDF (retail or wholesale) for a sale using TCPDF.
 * Layout: header + business info box, patient/invoice block, item grid
 * (Product/HSN/B.No/Exp/Qty/Pack/MRP/Gross/Disc/GST/Net), amount-in-words,
 * totals box, terms & conditions, QR code and authorised signatory.
 */
final class InvoiceService
{
    public function render(array $sale, array $branch, array $client): string
    {
        $business = htmlspecialchars((string) ($client['business_name'] ?? 'Pharmacy'));
        $isWholesale = ($sale['bill_mode'] ?? 'retail') === 'wholesale';

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->SetCreator('Advanced Pharmacy Suite');
        $pdf->SetAuthor($business);
        $pdf->SetTitle('Invoice ' . ($sale['invoice_no'] ?? ''));
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(8, 8, 8);
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->AddPage();

        $addr   = htmlspecialchars(trim(($branch['address'] ?? '') . ', ' . ($branch['city'] ?? '') . ' ' . ($branch['pincode'] ?? ''), ', '));
        $dl     = htmlspecialchars((string) ($branch['drug_license_no'] ?? ($client['drug_license_no'] ?? '-')));
        $gstin  = htmlspecialchars((string) ($branch['gstin'] ?? ($client['gstin'] ?? '-')));
        $phone  = htmlspecialchars((string) ($branch['phone'] ?? ($client['phone'] ?? '-')));
        $email  = htmlspecialchars((string) ($client['email'] ?? '-'));
        $initials = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $business) ?: 'PH', 0, 2));

        // ---------- Header ----------
        $header = '
        <table cellpadding="3">
            <tr>
                <td width="16%" style="border:0.5px solid #0d9488;background-color:#0d9488;color:#ffffff;text-align:center;">
                    <span style="font-size:22px;font-weight:bold;">' . $initials . '</span>
                </td>
                <td width="54%" align="center">
                    <span style="font-size:13px;font-weight:bold;">GST TAX INVOICE</span><br/>
                    <span style="font-size:15px;font-weight:bold;color:#0d9488;">' . $business . '</span><br/>
                    <span style="font-size:8px;">' . $addr . '</span>
                </td>
                <td width="30%">
                    <table cellpadding="2" style="font-size:8px;" border="0.5">
                        <tr><td width="32%"><b>GST No.</b></td><td>: ' . $gstin . '</td></tr>
                        <tr><td><b>DL No.</b></td><td>: ' . $dl . '</td></tr>
                        <tr><td><b>Ph. No.</b></td><td>: ' . $phone . '</td></tr>
                        <tr><td><b>Email</b></td><td>: ' . $email . '</td></tr>
                    </table>
                </td>
            </tr>
        </table>';
        $pdf->writeHTML($header, true, false, true, false, '');
        $this->hr($pdf);

        // ---------- Patient / Invoice block ----------
        $nameLabel = $isWholesale ? 'Customer Name' : 'Patient Name';
        $custName = htmlspecialchars((string) ($sale['patient_name'] ?: ($sale['customer_name'] ?? '-')));
        $block = '
        <table cellpadding="2" style="font-size:9px;">
            <tr>
                <td width="12%"><b>' . $nameLabel . '</b></td><td width="38%">: ' . $custName . '</td>
                <td width="14%"><b>Invoice No.</b></td><td width="36%">: ' . htmlspecialchars((string) $sale['invoice_no']) . '</td>
            </tr>
            <tr>
                <td><b>Dr. Name</b></td><td>: ' . htmlspecialchars((string) ($sale['doctor_name'] ?: 'NA')) . '</td>
                <td><b>Inv. Date</b></td><td>: ' . htmlspecialchars((string) date('d-m-Y', strtotime((string) $sale['invoice_date']))) . '</td>
            </tr>
            <tr>
                <td><b>Contact</b></td><td>: ' . htmlspecialchars((string) ($sale['contact_no'] ?? '-')) . '</td>
                <td><b>Address</b></td><td>: ' . htmlspecialchars((string) ($sale['address'] ?? '-')) . '</td>
            </tr>
        </table>';
        $pdf->writeHTML($block, true, false, true, false, '');

        // ---------- Items ----------
        $rows = '';
        foreach ($sale['items'] as $item) {
            $label = htmlspecialchars((string) $item['product_name']);
            if (!empty($item['manufacturer_short'])) {
                $label .= ' - ' . htmlspecialchars((string) $item['manufacturer_short']);
            }
            $exp = !empty($item['expiry_date']) ? date('m/y', strtotime((string) $item['expiry_date'])) : '-';
            $gross = (float) ($item['taxable'] ?? 0);
            if ($gross <= 0) {
                $gross = (float) $item['line_total'] - ((float) $item['line_total'] * (float) $item['gst_rate'] / (100 + (float) $item['gst_rate']));
            }
            $disc = (float) $item['rate'] * (int) $item['quantity'] * (float) $item['discount_pct'] / 100;

            $rows .= '<tr>
                <td width="26%" style="font-size:8px;">' . $label . '</td>
                <td width="7%" style="font-size:8px;">' . htmlspecialchars((string) ($item['hsn_code'] ?? '')) . '</td>
                <td width="9%" style="font-size:8px;">' . htmlspecialchars((string) ($item['batch_no'] ?? '-')) . '</td>
                <td width="7%" style="font-size:8px;">' . $exp . '</td>
                <td width="7%" align="right" style="font-size:8px;">' . (int) $item['quantity'] . '</td>
                <td width="8%" style="font-size:8px;">' . htmlspecialchars((string) ($item['pack'] ?? '-')) . '</td>
                <td width="9%" align="right" style="font-size:8px;color:#16a34a;">' . number_format((float) $item['mrp'], 2) . '</td>
                <td width="9%" align="right" style="font-size:8px;">' . number_format($gross, 2) . '</td>
                <td width="6%" align="right" style="font-size:8px;">' . number_format($disc, 2) . '</td>
                <td width="6%" align="right" style="font-size:8px;">' . number_format((float) $item['gst_rate'], 2) . '</td>
                <td width="11%" align="right" style="font-size:8px;">' . number_format((float) $item['line_total'], 2) . '</td>
            </tr>';
        }

        $table = '
        <table border="0.5" cellpadding="3">
            <thead>
                <tr style="background-color:#f1f5f9;font-weight:bold;font-size:8px;">
                    <td width="26%">Product</td><td width="7%">HSN</td><td width="9%">B.No</td>
                    <td width="7%">Exp.</td><td width="7%" align="right">Qty</td><td width="8%">Pack</td>
                    <td width="9%" align="right">MRP</td><td width="9%" align="right">Gross</td>
                    <td width="6%" align="right">Disc</td><td width="6%" align="right">GST</td>
                    <td width="11%" align="right">Net</td>
                </tr>
            </thead>
            <tbody>' . $rows . '</tbody>
        </table>';
        $pdf->writeHTML($table, true, false, true, false, '');

        // ---------- Words + Terms (left) and Totals (right) ----------
        $gross    = (float) ($sale['taxable_amount'] ?? 0);
        $discount = (float) ($sale['discount_amount'] ?? 0);
        $tax      = (float) ($sale['gst_amount'] ?? 0);
        $net      = (float) ($sale['net_amount'] ?? 0);
        if ($gross <= 0) {
            $gross = $net - $tax + $discount;
        }
        $words = $this->amountInWords($net);

        $taxRows = '';
        if ($isWholesale && (float) ($sale['igst'] ?? 0) > 0) {
            $taxRows .= '<tr><td align="right">IGST :</td><td align="right">' . number_format((float) $sale['igst'], 2) . '</td></tr>';
        } elseif ($isWholesale) {
            $taxRows .= '<tr><td align="right">CGST :</td><td align="right">' . number_format((float) ($sale['cgst'] ?? 0), 2) . '</td></tr>';
            $taxRows .= '<tr><td align="right">SGST :</td><td align="right">' . number_format((float) ($sale['sgst'] ?? 0), 2) . '</td></tr>';
        } else {
            $taxRows .= '<tr><td align="right">TAX :</td><td align="right">' . number_format($tax, 2) . '</td></tr>';
        }
        if ($isWholesale && (float) ($sale['cd_amount'] ?? 0) > 0) {
            $taxRows .= '<tr><td align="right">CD Disc :</td><td align="right">' . number_format((float) $sale['cd_amount'], 2) . '</td></tr>';
        }

        $bottom = '
        <table cellpadding="3">
            <tr>
                <td width="62%" style="vertical-align:top;">
                    <span style="font-size:8px;"><b>Amt. In Words :</b> <span style="color:#16a34a;font-weight:bold;">' . $words . '</span></span><br/>
                    <span style="font-size:8px;"><b>Credit Bal. :</b> 0.00</span><br/><br/>
                    <span style="font-size:8px;font-weight:bold;">Terms &amp; Conditions</span><br/>
                    <span style="font-size:7.5px;">1. Medicine return shall be done within 7 days of purchase.<br/>
                    2. Cut strips, Refrigerated, OTC/FMCG shall not be refunded/exchanged.<br/>
                    3. No return/Exchange will be processed without presenting the original bill.</span>
                </td>
                <td width="38%">
                    <table cellpadding="3" border="0.5" style="font-size:9px;">
                        <tr><td width="55%" align="right">GROSS :</td><td align="right">' . number_format($gross, 2) . '</td></tr>
                        <tr><td align="right">DISCOUNT :</td><td align="right">' . number_format($discount, 2) . '</td></tr>
                        ' . $taxRows . '
                        <tr style="background-color:#0d9488;color:#ffffff;font-weight:bold;font-size:11px;">
                            <td align="right">NET AMT. :</td><td align="right">' . number_format($net, 2) . '</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>';
        $pdf->writeHTML($bottom, true, false, true, false, '');
        $this->hr($pdf);

        // ---------- Footer (business + QR + signatory) ----------
        $footerY = $pdf->GetY() + 2;
        $footer = '
        <table cellpadding="2">
            <tr>
                <td width="60%" style="vertical-align:top;">
                    <span style="font-size:11px;font-weight:bold;">' . $business . '</span><br/>
                    <span style="font-size:8px;">' . $addr . '</span><br/><br/>
                    <span style="font-size:8px;font-weight:bold;">Customer Care : ' . $phone . '</span>
                </td>
                <td width="20%"></td>
                <td width="20%" align="center" style="vertical-align:bottom;">
                    <span style="font-size:8px;">For ' . $business . '</span><br/><br/><br/>
                    <span style="font-size:8px;font-weight:bold;">Authorised Signatory</span>
                </td>
            </tr>
        </table>';
        $pdf->writeHTML($footer, true, false, true, false, '');

        // QR code with key invoice details, placed in the footer band.
        $qrData = "Invoice: {$sale['invoice_no']}\nNet: " . number_format($net, 2) . "\n{$business}\nGSTIN: {$gstin}";
        $style = ['border' => false, 'padding' => 0, 'fgcolor' => [15, 23, 42], 'bgcolor' => false];
        $pdf->write2DBarcode($qrData, 'QRCODE,M', 92, $footerY + 2, 26, 26, $style, 'N');

        return $pdf->Output('invoice.pdf', 'S');
    }

    public function forSale(array $sale): string
    {
        $branch = Connection::ho()->query('SELECT * FROM branches WHERE id = ' . (int) $sale['branch_id'])->fetch() ?: [];
        $client = Connection::ho()->query('SELECT * FROM clients WHERE id = ' . (int) $sale['client_id'])->fetch() ?: [];
        return $this->render($sale, $branch, $client);
    }

    private function hr(TCPDF $pdf): void
    {
        $y = $pdf->GetY() + 1;
        $pdf->Line(8, $y, 202, $y);
        $pdf->SetY($y + 1);
    }

    /** Indian-format amount in words with paise. */
    private function amountInWords(float $amount): string
    {
        $rupees = (int) floor($amount);
        $paise = (int) round(($amount - $rupees) * 100);

        $words = $this->numToWords($rupees) . ' Rupees';
        if ($paise > 0) {
            $words .= ' and ' . $this->numToWords($paise) . ' Paise';
        }
        return strtoupper($words . ' Only');
    }

    private function numToWords(int $n): string
    {
        if ($n === 0) {
            return 'Zero';
        }
        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
            'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

        $twoDigits = function (int $num) use ($ones, $tens): string {
            if ($num < 20) {
                return $ones[$num];
            }
            return trim($tens[intdiv($num, 10)] . ' ' . $ones[$num % 10]);
        };

        $threeDigits = function (int $num) use ($ones, $twoDigits): string {
            $h = intdiv($num, 100);
            $rest = $num % 100;
            $out = '';
            if ($h > 0) {
                $out .= $ones[$h] . ' Hundred';
                if ($rest > 0) {
                    $out .= ' ';
                }
            }
            if ($rest > 0) {
                $out .= $twoDigits($rest);
            }
            return $out;
        };

        $parts = [];
        $crore = intdiv($n, 10000000);
        $n %= 10000000;
        $lakh = intdiv($n, 100000);
        $n %= 100000;
        $thousand = intdiv($n, 1000);
        $n %= 1000;
        $hundred = $n;

        if ($crore > 0) {
            $parts[] = $threeDigits($crore) . ' Crore';
        }
        if ($lakh > 0) {
            $parts[] = $twoDigits($lakh) . ' Lakh';
        }
        if ($thousand > 0) {
            $parts[] = $twoDigits($thousand) . ' Thousand';
        }
        if ($hundred > 0) {
            $parts[] = $threeDigits($hundred);
        }

        return trim(implode(' ', $parts));
    }
}
