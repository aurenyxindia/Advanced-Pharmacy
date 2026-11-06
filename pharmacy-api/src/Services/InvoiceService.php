<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Support\Env;
use App\Support\InvoiceProfileAssets;
use TCPDF;

/**
 * GST tax invoice PDF — Retail sales_print / sales_print_dual layout parity.
 */
final class InvoiceService
{
    private function isWholesaleSale(array $sale): bool
    {
        return strtolower(trim((string) ($sale['bill_mode'] ?? 'retail'))) === 'wholesale';
    }

    public function forSale(array $sale): string
    {
        if ($this->isWholesaleSale($sale)) {
            return (new WholesaleInvoiceService())->forSale($sale);
        }
        [$branch, $client, $salesman] = $this->resolveContext($sale);
        return $this->renderSingle($sale, $branch, $client, $salesman);
    }

    public function forSaleDual(array $sale): string
    {
        if ($this->isWholesaleSale($sale)) {
            return (new WholesaleInvoiceService())->forSaleDual($sale);
        }
        [$branch, $client, $salesman] = $this->resolveContext($sale);
        return $this->renderDual($sale, $branch, $client, $salesman);
    }

    /** Dot matrix (impact printer) bill page — Retail sales_print_dotmatrix. */
    public function forSaleDotMatrix(array $sale): string
    {
        [$branch, $client] = $this->resolveContext($sale);
        $isWholesale = $this->isWholesaleSale($sale);
        $display = new SaleDisplayService();
        $items = [];
        $itemsNetTotal = 0.0;
        $lineDiscTotal = 0.0;
        foreach ((array) ($sale['items'] ?? []) as $raw) {
            $item = $display->enrichItem($raw, $isWholesale);
            $itemsNetTotal += (float) ($item['line_total'] ?? $item['display_net'] ?? 0);
            $lineDiscTotal += (float) ($item['display_disc_amt'] ?? 0);
            $items[] = $item;
        }
        $net = $isWholesale
            ? round((float) ($sale['net_amount'] ?? 0), 2)
            : $this->retailTotals($sale, round($itemsNetTotal, 2), round($lineDiscTotal, 2))['net'];
        $net = round($net - (float) ($sale['return_total'] ?? 0), 2);

        return (new DotMatrixInvoiceService())->page($sale, $items, $branch, $client, $net, $isWholesale);
    }

    /** @return array{0:array,1:array,2:?string} */
    private function resolveContext(array $sale): array
    {
        (new ClientProfileService())->ensureProfileColumns();
        $branch = Connection::ho()->query('SELECT * FROM branches WHERE id = ' . (int) $sale['branch_id'])->fetch() ?: [];
        $client = Connection::ho()->query('SELECT * FROM clients WHERE id = ' . (int) $sale['client_id'])->fetch() ?: [];
        $salesman = null;
        if (!empty($sale['created_by'])) {
            $stmt = Connection::ho()->prepare('SELECT name FROM users WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => (int) $sale['created_by']]);
            $salesman = $stmt->fetchColumn() ?: null;
        }
        return [$branch, $client, is_string($salesman) ? $salesman : null];
    }

    private function renderSingle(array $sale, array $branch, array $client, ?string $salesman): string
    {
        $business = (string) ($client['business_name'] ?? 'Pharmacy');
        $pdf = $this->newPdf($business, (string) ($sale['invoice_no'] ?? ''));
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->AddPage();

        $html = $this->buildBillHtml($sale, $branch, $client, $salesman, false);
        $pdf->writeHTML($html, true, false, true, false, '');

        return $pdf->Output('invoice.pdf', 'S');
    }

    private function renderDual(array $sale, array $branch, array $client, ?string $salesman): string
    {
        $business = (string) ($client['business_name'] ?? 'Pharmacy');
        $pdf = $this->newPdf($business, (string) ($sale['invoice_no'] ?? '') . ' dual');
        $pdf->SetPrintHeader(false);
        $pdf->SetPrintFooter(false);
        $pdf->SetMargins(10, 20, 10);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->AddPage('P');

        $html = $this->buildBillHtml($sale, $branch, $client, $salesman, true);

        $slotX = 10;
        $slotW = 190;
        $halfH = 148.5;
        $topY = 8;
        $topH = 136;
        $bottomY = 156;
        $bottomH = 136;

        // Labels + cut line (Retail DualBillTcpdf)
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetFillColor(238, 238, 238);
        $pdf->SetXY($slotX, 3);
        $pdf->Cell($slotW, 4, 'CUSTOMER COPY', 0, 0, 'C', true);

        $pdf->SetLineStyle(['width' => 0.25, 'dash' => '3,2', 'color' => [0, 0, 0]]);
        $pdf->Line($slotX, $halfH - 1, $slotX + $slotW, $halfH - 1);
        $pdf->Line($slotX, $halfH + 1, $slotX + $slotW, $halfH + 1);
        $pdf->SetFont('helvetica', 'B', 6);
        $pdf->SetFillColor(255, 255, 255);
        $pdf->SetXY($slotX, $halfH - 2.5);
        $pdf->Cell($slotW, 3, '--- CUT HERE ---', 0, 0, 'C', true);

        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetFillColor(238, 238, 238);
        $pdf->SetXY($slotX, $halfH + 3);
        $pdf->Cell($slotW, 4, 'SHOP COPY', 0, 0, 'C', true);

        $this->renderInSlot($pdf, $slotX, $topY, $slotW, $topH, $html);
        $this->renderInSlot($pdf, $slotX, $bottomY, $slotW, $bottomH, $html);

        return $pdf->Output('invoice-dual.pdf', 'S');
    }

    private function newPdf(string $business, string $title): TCPDF
    {
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->SetCreator('Advanced Pharmacy Suite');
        $pdf->SetAuthor($business);
        $pdf->SetTitle('Invoice ' . $title);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        return $pdf;
    }

    private function renderInSlot(TCPDF $pdf, float $x, float $y, float $w, float $h, string $html): void
    {
        $page = $pdf->getPage();
        $pdf->setPage($page);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->StartTransform();
        $pdf->Rect($x, $y, $w, $h, 'CNZ');
        $pdf->writeHTMLCell($w, $h, $x, $y + 0.5, $html, 0, 1, false, true, 'T', false);
        $pdf->StopTransform();
        $pdf->setPage($page);
    }

    /**
     * Retail-matching bill HTML (single or compact dual).
     * Footer: terms | profile QR/scanner | authorized signatory + stamp.
     */
    private function buildBillHtml(array $sale, array $branch, array $client, ?string $salesman, bool $dual): string
    {
        $fs = $dual ? '6.5' : '7.5';
        $fsTitle = $dual ? '8' : '10';
        $fsName = $dual ? '9' : '12';
        $fsNet = $dual ? '7' : '9';
        $pad = $dual ? '2' : '3';

        $business = htmlspecialchars((string) ($client['business_name'] ?? 'Pharmacy'));
        $isWholesale = ($sale['bill_mode'] ?? 'retail') === 'wholesale';
        $addr = htmlspecialchars(trim(
            ($branch['address'] ?? $client['address'] ?? '')
            . ', ' . ($branch['city'] ?? $client['city'] ?? '')
            . ' ' . ($branch['pincode'] ?? $client['pincode'] ?? ''),
            ', '
        ));
        // My Profile fields on clients; empty branch values must not hide them.
        $dlRaw = trim((string) ($client['drug_license_no'] ?? ''));
        if ($dlRaw === '') {
            $dlRaw = trim((string) ($branch['drug_license_no'] ?? ''));
        }
        $dl = htmlspecialchars($dlRaw !== '' ? $dlRaw : '-');
        $gstinRaw = trim((string) ($client['gstin'] ?? ''));
        if ($gstinRaw === '') {
            $gstinRaw = trim((string) ($branch['gstin'] ?? ''));
        }
        $gstin = htmlspecialchars($gstinRaw !== '' ? $gstinRaw : '-');
        $phoneRaw = trim((string) ($client['phone'] ?? ''));
        if ($phoneRaw === '') {
            $phoneRaw = trim((string) ($branch['phone'] ?? ''));
        }
        $phone = htmlspecialchars($phoneRaw !== '' ? $phoneRaw : '-');
        $email = htmlspecialchars((string) ($client['email'] ?? '-'));
        $logoHtml = $this->logoCellHtml($client, $dual);
        $scannerHtml = InvoiceProfileAssets::imgHtml(
            $client,
            'scanner',
            $dual ? 70 : 85,
            $dual ? 45 : 55
        );
        $stampHtml = InvoiceProfileAssets::imgHtml(
            $client,
            'stamp',
            $dual ? 90 : 100,
            $dual ? 45 : 55
        );

        $nameLabel = $isWholesale ? 'Customer Name' : 'Patient Name';
        $custName = htmlspecialchars((string) ($sale['patient_name'] ?: ($sale['customer_name'] ?? '-')));
        $paymentRaw = trim((string) ($sale['payment_mode'] ?? '-'));
        if (strtolower($paymentRaw) === 'cash+upi') {
            $payment = htmlspecialchars(sprintf(
                'Cash %s + UPI %s',
                number_format((float) ($sale['cash_amount'] ?? 0), 2),
                number_format((float) ($sale['upi_amount'] ?? 0), 2)
            ));
        } else {
            $payment = htmlspecialchars($paymentRaw !== '' ? ucfirst(strtolower($paymentRaw)) : '-');
        }
        $salesmanSafe = htmlspecialchars((string) ($salesman ?: '-'));
        $invDate = !empty($sale['invoice_date'])
            ? date('d-m-Y', strtotime((string) $sale['invoice_date']))
            : '-';
        $doctor = htmlspecialchars((string) (($sale['doctor_name'] ?? '') !== '' ? $sale['doctor_name'] : 'NA'));

        $items = is_array($sale['items'] ?? null) ? $sale['items'] : [];
        $rows = '';
        $itemsNetTotal = 0.0;
        $lineDiscTotal = 0.0;
        $display = new SaleDisplayService();
        foreach ($items as $rawItem) {
            $item = $display->enrichItem($rawItem, $isWholesale);
            $label = (string) ($item['product_name'] ?? '');
            $mfg = trim((string) ($item['manufacturer_short'] ?? ($item['mfg'] ?? '')));
            if ($mfg !== '') {
                $label .= ' - ' . $mfg;
            }
            $exp = !empty($item['expiry_date']) ? date('m/y', strtotime((string) $item['expiry_date'])) : '-';
            $pack = preg_replace('/\s*[xX×]\s*/u', 'x', trim((string) ($item['pack'] ?? ($item['pack_size'] ?? '-')))) ?: '-';
            $printQty = (float) ($item['display_qty'] ?? $item['quantity'] ?? 0);
            $printMrp = (float) ($item['display_mrp'] ?? $this->displayPackMrp($item, $isWholesale));
            // Saved line_total matches billing grid; display_net can differ after batch overlay.
            $printNet = (float) ($item['line_total'] ?? $item['display_net'] ?? 0);
            $itemsNetTotal += $printNet;
            $lineDiscTotal += (float) ($item['display_disc_amt'] ?? 0);
            $qtyCell = abs($printQty - (int) $printQty) < 0.001
                ? (string) (int) $printQty
                : number_format($printQty, 2);
            $rows .= '<tr>
                <td width="28%" style="font-size:' . $fs . 'px;border:1px solid #000;">' . htmlspecialchars($label) . '</td>
                <td width="13%" align="center" style="font-size:' . $fs . 'px;border:1px solid #000;">' . htmlspecialchars((string) ($item['hsn_code'] ?? '')) . '</td>
                <td width="14%" align="center" style="font-size:' . $fs . 'px;border:1px solid #000;">' . htmlspecialchars((string) ($item['batch_no'] ?? '-')) . '</td>
                <td width="7%" align="center" style="font-size:' . $fs . 'px;border:1px solid #000;">' . $exp . '</td>
                <td width="6%" align="center" style="font-size:' . $fs . 'px;border:1px solid #000;">' . $qtyCell . '</td>
                <td width="8%" align="center" style="font-size:' . $fs . 'px;border:1px solid #000;">' . htmlspecialchars((string) $pack) . '</td>
                <td width="9%" align="right" style="font-size:' . $fs . 'px;border:1px solid #000;">' . number_format($printMrp, 2) . '</td>
                <td width="15%" align="right" style="font-size:' . $fs . 'px;border:1px solid #000;">' . number_format($printNet, 2) . '</td>
            </tr>';
        }

        $productCount = count($items);
        $itemsNetTotal = round($itemsNetTotal, 2);
        $lineDiscTotal = round($lineDiscTotal, 2);
        ['bulk' => $bulkBillDisc, 'net' => $netDisplay, 'discount_print' => $discountPrint, 'round_off' => $roundOff]
            = $this->retailTotals($sale, $itemsNetTotal, $lineDiscTotal);
        $netRounded = (int) round($netDisplay);
        $words = strtoupper($this->amountInWordsRetail($netRounded));
        $creditBal = number_format((float) ($sale['balance'] ?? 0), 2);

        $extraTax = '';
        if ($bulkBillDisc > 0.004) {
            $extraTax .= '<tr><td style="font-size:' . $fs . 'px;border:1px solid #000;font-weight:bold;" align="right">Items total</td>'
                . '<td style="font-size:' . $fs . 'px;border:1px solid #000;" align="right">' . number_format($itemsNetTotal, 2) . '</td></tr>';
            $extraTax .= '<tr><td style="font-size:' . $fs . 'px;border:1px solid #000;font-weight:bold;" align="right">Bulk disc</td>'
                . '<td style="font-size:' . $fs . 'px;border:1px solid #000;" align="right">-' . number_format($bulkBillDisc, 2) . '</td></tr>';
        }
        if ($isWholesale) {
            if ((float) ($sale['igst'] ?? 0) > 0) {
                $extraTax .= '<tr><td style="font-size:' . $fs . 'px;border:1px solid #000;font-weight:bold;" align="right">IGST</td><td style="font-size:' . $fs . 'px;border:1px solid #000;" align="right">' . number_format((float) $sale['igst'], 2) . '</td></tr>';
            } else {
                $extraTax .= '<tr><td style="font-size:' . $fs . 'px;border:1px solid #000;font-weight:bold;" align="right">CGST</td><td style="font-size:' . $fs . 'px;border:1px solid #000;" align="right">' . number_format((float) ($sale['cgst'] ?? 0), 2) . '</td></tr>';
                $extraTax .= '<tr><td style="font-size:' . $fs . 'px;border:1px solid #000;font-weight:bold;" align="right">SGST</td><td style="font-size:' . $fs . 'px;border:1px solid #000;" align="right">' . number_format((float) ($sale['sgst'] ?? 0), 2) . '</td></tr>';
            }
            if ((float) ($sale['cd_amount'] ?? 0) > 0) {
                $extraTax .= '<tr><td style="font-size:' . $fs . 'px;border:1px solid #000;font-weight:bold;" align="right">CD Disc</td><td style="font-size:' . $fs . 'px;border:1px solid #000;" align="right">' . number_format((float) $sale['cd_amount'], 2) . '</td></tr>';
            }
        }
        if (abs($roundOff) > 0.004) {
            $extraTax .= '<tr><td style="font-size:' . $fs . 'px;border:1px solid #000;font-weight:bold;" align="right">Round off</td>'
                . '<td style="font-size:' . $fs . 'px;border:1px solid #000;" align="right">' . ($roundOff > 0 ? '+' : '') . number_format($roundOff, 2) . '</td></tr>';
        }
        $footerRows = 2 + substr_count($extraTax, '<tr>');

        $narration = trim((string) ($sale['narration'] ?? ''));
        $noteHtml = '';
        if ($narration !== '' && !preg_match('/^[-–—.\s]+$/u', $narration)) {
            $noteHtml = '<br/><b>Note</b> ' . htmlspecialchars($narration);
        }

        $b = 'border:1px solid #000000;';

        return '
        <style>
            table.inv { width:100%; border-collapse:collapse; table-layout:fixed; ' . $b . ' }
            table.inv td, table.inv th { vertical-align:middle; }
            .g { color:#2E7D32; font-weight:bold; }
            .lbl { font-weight:bold; }
        </style>
        <table class="inv" cellpadding="' . $pad . '" cellspacing="0" width="100%">
            <tr>
                <td width="18%" rowspan="4" align="center" style="' . $b . '">' . $logoHtml . '</td>
                <td width="52%" align="center" style="font-size:' . $fsTitle . 'px;font-weight:bold;' . $b . '">GST TAX INVOICE</td>
                <td width="30%" style="font-size:' . $fs . 'px;' . $b . '"><span class="lbl">GST No.</span> - ' . $gstin . '</td>
            </tr>
            <tr>
                <td align="center" style="font-size:' . $fsName . 'px;font-weight:bold;' . $b . '">' . $business . '</td>
                <td style="font-size:' . $fs . 'px;' . $b . '"><span class="lbl">DL No.</span> - ' . $dl . '</td>
            </tr>
            <tr>
                <td rowspan="2" align="center" style="font-size:' . $fs . 'px;' . $b . '">' . $addr . '</td>
                <td style="font-size:' . $fs . 'px;' . $b . '"><span class="lbl">PH. No.</span> - ' . $phone . '</td>
            </tr>
            <tr>
                <td style="font-size:' . $fs . 'px;' . $b . '"><span class="lbl">Email</span> - ' . $email . '</td>
            </tr>
        </table>
        <table class="inv" cellpadding="' . $pad . '" cellspacing="0" width="100%">
            <tr>
                <td width="14%" class="lbl" style="font-size:' . $fs . 'px;' . $b . '">' . $nameLabel . '</td>
                <td width="36%" style="font-size:' . $fs . 'px;' . $b . '">' . $custName . '</td>
                <td width="14%" class="lbl" style="font-size:' . $fs . 'px;' . $b . '">Invoice No.</td>
                <td width="36%" style="font-size:' . $fs . 'px;' . $b . '">' . htmlspecialchars((string) ($sale['invoice_no'] ?? '')) . '</td>
            </tr>
            <tr>
                <td class="lbl" style="font-size:' . $fs . 'px;' . $b . '">Dr. Name</td>
                <td style="font-size:' . $fs . 'px;' . $b . '">' . $doctor . '</td>
                <td class="lbl" style="font-size:' . $fs . 'px;' . $b . '">Inv. Date</td>
                <td style="font-size:' . $fs . 'px;' . $b . '">' . htmlspecialchars($invDate) . '</td>
            </tr>
            <tr>
                <td class="lbl" style="font-size:' . $fs . 'px;' . $b . '">' . ($isWholesale ? 'Contact' : 'Patient No.') . '</td>
                <td style="font-size:' . $fs . 'px;' . $b . '">' . htmlspecialchars((string) ($sale['contact_no'] ?? '-')) . '</td>
                <td class="lbl" style="font-size:' . $fs . 'px;' . $b . '">' . ($isWholesale ? 'Address' : 'Pat. Address') . '</td>
                <td style="font-size:' . $fs . 'px;' . $b . '">' . htmlspecialchars((string) ($sale['address'] ?? '-')) . '</td>
            </tr>
            <tr>
                <td class="lbl" style="font-size:' . $fs . 'px;' . $b . '">Payment Mode</td>
                <td class="g" style="font-size:' . $fs . 'px;' . $b . '">' . $payment . '</td>
                <td class="lbl" style="font-size:' . $fs . 'px;' . $b . '">Salesman</td>
                <td style="font-size:' . $fs . 'px;' . $b . '">' . $salesmanSafe . '</td>
            </tr>
        </table>
        <table class="inv" cellpadding="' . $pad . '" cellspacing="0" width="100%">
            <tr style="background-color:#1E3A5F;color:#ffffff;font-weight:bold;">
                <td width="28%" style="font-size:' . $fs . 'px;' . $b . '">Product</td>
                <td width="13%" align="center" style="font-size:' . $fs . 'px;' . $b . '">HSN</td>
                <td width="14%" align="center" style="font-size:' . $fs . 'px;' . $b . '">B.No</td>
                <td width="7%" align="center" style="font-size:' . $fs . 'px;' . $b . '">Exp.</td>
                <td width="6%" align="center" style="font-size:' . $fs . 'px;' . $b . '">Qty</td>
                <td width="8%" align="center" style="font-size:' . $fs . 'px;' . $b . '">Pack</td>
                <td width="9%" align="right" style="font-size:' . $fs . 'px;' . $b . '">MRP</td>
                <td width="15%" align="right" style="font-size:' . $fs . 'px;' . $b . '">Net</td>
            </tr>
            ' . $rows . '
            <tr>
                <td colspan="8" style="font-size:' . $fs . 'px;font-weight:bold;background-color:#E3F2FD;color:#1565C0;' . $b . '">
                    Total No. of Products :- <span style="color:#2E7D32;">' . $productCount . '</span>
                </td>
            </tr>
        </table>
        <table class="inv" cellpadding="' . $pad . '" cellspacing="0" width="100%">
            <tr>
                <td width="70%" rowspan="' . max(2, $footerRows) . '" style="vertical-align:top;font-size:' . $fs . 'px;' . $b . '">
                    <span class="lbl">Amt. In Words :</span>
                    <span class="g" style="text-transform:uppercase;">' . htmlspecialchars($words) . '</span><br/>
                    <span class="lbl">Paid :</span> ' . number_format((float) ($sale['paid'] ?? 0), 2) . '
                    &nbsp;&nbsp;<span class="lbl">Balance :</span> ' . number_format((float) ($sale['balance'] ?? 0), 2) . '<br/>
                    <span class="lbl">Credit Bal. :</span> ' . $creditBal . '<br/>
                    <span class="lbl">Payment Mode :</span> <span class="g">' . $payment . '</span>
                </td>
                <td width="15%" class="lbl" style="font-size:' . $fs . 'px;' . $b . '" align="right">DISCOUNT</td>
                <td width="15%" style="font-size:' . $fs . 'px;' . $b . '" align="right">' . number_format($discountPrint, 2) . '</td>
            </tr>
            ' . $extraTax . '
            <tr>
                <td class="lbl" style="font-size:' . $fsNet . 'px;' . $b . '" align="right">NET AMT.</td>
                <td class="g" style="font-size:' . $fsNet . 'px;' . $b . '" align="right">' . number_format($netDisplay, 2) . '</td>
            </tr>
        </table>
        <table class="inv" cellpadding="' . $pad . '" cellspacing="0" width="100%">
            <tr>
                <td width="52%" style="vertical-align:top;font-size:' . $fs . 'px;' . $b . '">
                    <b>Terms &amp; Conditions</b>
                    <ol style="margin:2px 0 0 12px;padding:0;">
                        <li>Medicine return shall be done within 7 days of purchase.</li>
                        <li>Cut strips, Refrigerated, OTC/FMCG shall not be refunded/exchanged.</li>
                        <li>No return/Exchange will be processed without presenting the original bill.</li>
                    </ol>
                    ' . $noteHtml . '
                </td>
                <td width="18%" align="center" style="vertical-align:middle;font-size:' . $fs . 'px;' . $b . '">' . $scannerHtml . '</td>
                <td width="30%" align="center" style="vertical-align:middle;font-weight:bold;font-size:' . $fs . 'px;' . $b . '">
                    Authorized Signatory<br/>' . $salesmanSafe . '<br/>' . $stampHtml . '
                </td>
            </tr>
        </table>';
    }

    /**
     * Retail bill net: bulk bill discount, printed NET AMT and DISCOUNT (line disc only when bulk is shown).
     *
     * @return array{bulk:float,net:float,discount_print:float,round_off:float}
     */
    private function retailTotals(array $sale, float $itemsNetTotal, float $lineDiscTotal): array
    {
        $discount = (float) ($sale['discount_amount'] ?? 0);
        $roundOff = round((float) ($sale['round_off'] ?? 0), 2);
        $netStored = round((float) ($sale['net_amount'] ?? 0), 2);
        if ($netStored > 0) {
            $netStored = round($netStored - $roundOff, 2);
        }
        $paidStored = round((float) ($sale['paid'] ?? 0), 2);
        if ($paidStored > 0) {
            $paidStored = round($paidStored - $roundOff, 2);
        }
        $paymentModeRaw = strtolower(trim((string) ($sale['payment_mode'] ?? 'cash')));

        $bulkBillDisc = (float) ($sale['bill_bulk_discount_amount'] ?? 0);
        if ($bulkBillDisc <= 0 && $itemsNetTotal > $netStored + 0.004) {
            $bulkBillDisc = round($itemsNetTotal - $netStored, 2);
        }
        if ($bulkBillDisc <= 0 && $discount > $lineDiscTotal + 0.004) {
            $bulkBillDisc = round($discount - $lineDiscTotal, 2);
        }
        if ($bulkBillDisc <= 0 && $paidStored > 0 && $paidStored < $itemsNetTotal - 0.004 && $paymentModeRaw !== 'credit') {
            $bulkBillDisc = round($itemsNetTotal - $paidStored, 2);
        }

        $netDisplay = $netStored > 0 ? $netStored : $itemsNetTotal;
        if ($bulkBillDisc > 0.004) {
            $derivedNet = round($itemsNetTotal - $bulkBillDisc, 2);
            if ($netStored <= 0 || abs($netStored - $itemsNetTotal) < 0.004 || $netStored > $derivedNet + 0.004) {
                $netDisplay = $derivedNet;
            }
        } elseif ($paidStored > 0 && $paidStored < $itemsNetTotal - 0.004 && $paymentModeRaw !== 'credit') {
            $netDisplay = $paidStored;
        }

        // DB discount_amount = line disc + bulk; bulk is shown separately — avoid duplicate on print.
        if ($bulkBillDisc > 0.004) {
            $discountPrint = $lineDiscTotal > 0.004
                ? $lineDiscTotal
                : max(0.0, round($discount - $bulkBillDisc, 2));
        } else {
            $discountPrint = round($discount, 2);
        }

        return [
            'bulk' => $bulkBillDisc,
            'net' => round($netDisplay + $roundOff, 2),
            'discount_print' => $discountPrint,
            'round_off' => $roundOff,
        ];
    }

    /** @param array<string,mixed> $client */
    private function logoCellHtml(array $client, bool $dual): string
    {
        $w = $dual ? 48 : 68;
        $fromProfile = InvoiceProfileAssets::imgHtml($client, 'logo', $w);
        if (str_contains($fromProfile, '<img')) {
            return $fromProfile;
        }
        $logoUrl = trim((string) Env::get('INVOICE_LOGO_URL', ''));
        if ($logoUrl !== '') {
            return '<img src="' . htmlspecialchars($logoUrl) . '" style="width:' . $w . 'px;height:auto;" />';
        }
        return '&nbsp;';
    }

    /** Retail-style words for rounded rupees (integer). */
    private function amountInWordsRetail(int $rupees): string
    {
        if ($rupees === 0) {
            return 'Zero Rupees Only';
        }
        return $this->numToWords($rupees) . ' Rupees Only';
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
            $t = $tens[intdiv($num, 10)];
            $u = $ones[$num % 10];
            return $u !== '' ? $t . '-' . $u : $t;
        };

        $threeDigits = function (int $num) use ($ones, $twoDigits): string {
            $h = intdiv($num, 100);
            $rest = $num % 100;
            $out = '';
            if ($h > 0) {
                $out .= $ones[$h] . ' Hundred';
                if ($rest > 0) {
                    $out .= ' and ';
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

        if ($crore > 0) {
            $parts[] = $threeDigits($crore) . ' Crore';
        }
        if ($lakh > 0) {
            $parts[] = $twoDigits($lakh) . ' Lakh';
        }
        if ($thousand > 0) {
            $parts[] = $twoDigits($thousand) . ' Thousand';
        }
        if ($n > 0) {
            $parts[] = $threeDigits($n);
        }

        return trim(implode(' ', $parts));
    }

    /** Strip/pack MRP for invoice print (Retail stores pack MRP, not per-tablet). */
    private function displayPackMrp(array $item, bool $isWholesale): float
    {
        $mrp = (float) ($item['mrp'] ?? 0);
        $rate = (float) ($item['rate'] ?? 0);
        $upp = max(1, (int) ($item['units_per_pack'] ?? 1));

        // Legacy rows saved unit MRP in the mrp column — upgrade for print.
        if (!$isWholesale && $upp > 1 && $rate > 0 && abs($mrp - $rate) < 0.02) {
            return round($mrp * $upp, 2);
        }

        return round($mrp, 2);
    }
}
