<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Support\Env;
use App\Support\InvoiceProfileAssets;
use TCPDF;

/**
 * Wholesale GST tax invoice PDF — Retail sales_print_wholesale.php layout parity.
 */
final class WholesaleInvoiceService
{
    private const BORDER = '1px solid #000000';
    private const HDR_BG_L = '#D6EAF8';
    private const HDR_BG_M = '#FFFFFF';
    private const HDR_BG_R = '#D5F5E3';
    private const PROD_HDR_BG = '#E3F2FD';
    private const PROD_HDR_COLOR = '#1565C0';
     /** Dual copy body / product table font (px). */
    private const DUAL_FS = 6;
    /** Dual copy header KV font (px). */
    private const DUAL_FS_HDR = 5;
    /** Dual copy title font (px). */
    private const DUAL_FS_TITLE = 6;

    public function forSale(array $sale): string
    {
        [$branch, $client, $salesman, $customer] = $this->resolveContext($sale);
        $business = (string) ($client['business_name'] ?? 'Pharmacy');
        $pdf = $this->newPdf($business, (string) ($sale['invoice_no'] ?? ''));
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->AddPage();
        $pdf->SetFont('dejavusans', '', 7);
        $html = $this->buildBillHtml($sale, $branch, $client, $customer, $salesman, false);
        $pdf->writeHTML($html, true, false, true, false, '');
        return $pdf->Output('invoice.pdf', 'S');
    }

    public function forSaleDual(array $sale): string
    {
        [$branch, $client, $salesman, $customer] = $this->resolveContext($sale);
        $business = (string) ($client['business_name'] ?? 'Pharmacy');
        $pdf = $this->newPdf($business, (string) ($sale['invoice_no'] ?? '') . ' dual');
        $pdf->SetPrintHeader(false);
        $pdf->SetPrintFooter(false);
        $pdf->SetMargins(10, 5, 10);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->AddPage('P');

        $html = $this->buildBillHtml($sale, $branch, $client, $customer, $salesman, true);
        $slotX = 10.0;
        $slotW = 190.0;
        $halfH = 148.5;
        $topY = 6.0;
        $topH = 142.5;
        $bottomY = 152.5;
        $bottomH = 144.5;

        $pdf->SetFont('helvetica', 'B', 7);
        $pdf->SetFillColor(238, 238, 238);
        $pdf->SetXY($slotX, 2);
        $pdf->Cell($slotW, 4, 'CUSTOMER COPY', 0, 0, 'C', true);
        $pdf->SetLineStyle(['width' => 0.25, 'dash' => '3,2', 'color' => [0, 0, 0]]);
        $pdf->Line($slotX, $halfH - 1, $slotX + $slotW, $halfH - 1);
        $pdf->Line($slotX, $halfH + 1, $slotX + $slotW, $halfH + 1);
        $pdf->SetFont('helvetica', 'B', 6);
        $pdf->SetFillColor(255, 255, 255);
        $pdf->SetXY($slotX, $halfH - 2.5);
        $pdf->Cell($slotW, 3, '--- CUT HERE ---', 0, 0, 'C', true);
        $pdf->SetFont('helvetica', 'B', 7);
        $pdf->SetFillColor(238, 238, 238);
        $pdf->SetXY($slotX, 148.5);
        $pdf->Cell($slotW, 4, 'SHOP COPY', 0, 0, 'C', true);

        $this->renderInSlot($pdf, $slotX, $topY, $slotW, $topH, $html);
        $this->renderInSlot($pdf, $slotX, $bottomY, $slotW, $bottomH, $html);

        return $pdf->Output('invoice-dual.pdf', 'S');
    }

    /** @return array{0:array,1:array,2:?string,3:?array} */
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
        $customer = null;
        if (!empty($sale['customer_id'])) {
            $stmt = Connection::ops()->prepare('SELECT * FROM customers WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => (int) $sale['customer_id']]);
            $customer = $stmt->fetch() ?: null;
        }
        return [$branch, $client, is_string($salesman) ? $salesman : null, is_array($customer) ? $customer : null];
    }

    private function newPdf(string $business, string $title): TCPDF
    {
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->SetCreator('Advanced Pharmacy Suite');
        $pdf->SetAuthor($business);
        $pdf->SetTitle('Wholesale Invoice ' . $title);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        return $pdf;
    }

    private function renderInSlot(TCPDF $pdf, float $x, float $y, float $w, float $h, string $html): void
    {
        $page = $pdf->getPage();
        $pdf->setPage($page);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->SetFont('dejavusans', '', self::DUAL_FS);
        $pdf->StartTransform();
        $pdf->Rect($x, $y, $w, $h, 'CNZ');
        $pdf->writeHTMLCell($w, $h, $x, $y, $html, 0, 1, false, true, 'T', false);
        $pdf->StopTransform();
        $pdf->setPage($page);
    }

    private function buildBillHtml(
        array $sale,
        array $branch,
        array $client,
        ?array $customer,
        ?string $salesman,
        bool $dual
    ): string {
        $fs = $dual ? self::DUAL_FS : 7;
        $items = is_array($sale['items'] ?? null) ? $sale['items'] : [];
        $rateType = strtoupper((string) ($sale['rate_type'] ?? 'PTR'));
        $isInterstate = (int) ($sale['is_interstate'] ?? 0) === 1;
        $printRows = [];
        foreach ($items as $item) {
            $printRows[] = $this->mapPrintRow($item, $rateType, $isInterstate);
        }

        $totals = [
            'gross'    => round((float) ($sale['taxable_amount'] ?? 0), 2),
            'discount' => round((float) ($sale['discount_amount'] ?? 0), 2),
            'net'      => round((float) ($sale['net_amount'] ?? 0), 2),
            'round_off' => round((float) ($sale['round_off'] ?? 0), 2),
        ];
        $taxTotal = $this->taxSlabsTotal($printRows);

        return $this->stylesHtml()
            . $this->headerHtml($sale, $branch, $client, $customer, $salesman, $dual)
            . $this->productTableHtml($printRows, count($items), $rateType, $dual, $fs)
            . $this->footerHtml($sale, $client, $salesman, $totals, $printRows, $taxTotal, $dual, $fs);
    }

    /** @return array<string, mixed> */
    private function mapPrintRow(array $item, string $rateType, bool $isInterstate): array
    {
        $upp = max(1, (int) ($item['units_per_pack'] ?? 1));
        $qtyUnits = (int) ($item['quantity'] ?? 0);
        $freeUnits = max(0, (int) ($item['free_quantity'] ?? 0));
        $stripQty = $upp > 1 ? $qtyUnits / $upp : (float) $qtyUnits;
        $freeStripQty = $upp > 1 ? $freeUnits / $upp : (float) $freeUnits;

        $unitRate = (float) ($item['rate'] ?? 0);
        $packRate = round($unitRate * $upp, 2);
        $batchPtr = round((float) ($item['batch_ptr'] ?? 0), 2);
        $batchPts = round((float) ($item['batch_pts'] ?? 0), 2);
        if ($batchPtr <= 0) {
            $batchPtr = $packRate;
        }
        if ($batchPts <= 0) {
            $batchPts = $batchPtr;
        }

        $billingRate = $rateType === 'PTS' ? $batchPts : $batchPtr;
        if ($packRate > 0) {
            $billingRate = $packRate;
        }

        $taxable = (float) ($item['taxable'] ?? 0);
        $lineTotal = (float) ($item['line_total'] ?? 0);
        $totalAmt = (float) ($item['total_amt'] ?? 0);
        $discAmt = max(0, round($totalAmt - $taxable, 2));
        $gstAmt = max(0, round($lineTotal - $taxable, 2));
        $gstRate = (float) ($item['gst_rate'] ?? 0);

        $igst = 0.0;
        $cgst = 0.0;
        $sgst = 0.0;
        if ($isInterstate) {
            $igst = $gstAmt;
        } else {
            $cgst = round($gstAmt / 2, 2);
            $sgst = round($gstAmt - $cgst, 2);
        }

        $mfg = trim((string) ($item['manufacturer_short'] ?? ''));
        $label = (string) ($item['product_name'] ?? '');
        if ($mfg !== '') {
            $label .= ' - ' . substr($mfg, 0, 3);
        }

        return [
            'product'  => $label,
            'hsn'      => (string) ($item['hsn_code'] ?? ''),
            'batch'    => (string) ($item['batch_no'] ?? ''),
            'exp'      => !empty($item['expiry_date']) ? $this->formatExpiry((string) $item['expiry_date']) : '',
            'qty'      => $this->formatQty($stripQty),
            'free'     => $this->formatQty($freeStripQty),
            'pack'     => $this->formatPack((string) ($item['pack'] ?? '')),
            'rate'     => $this->money($billingRate),
            'mrp'      => $this->money((float) ($item['mrp'] ?? 0)),
            'gross'    => $this->money($taxable),
            'disc'     => $this->money($discAmt),
            'gst'      => $this->money($gstAmt),
            'net'      => $this->money($lineTotal),
            'gst_rate' => $gstRate,
            'igst'     => $igst,
            'cgst'     => $cgst,
            'sgst'     => $sgst,
        ];
    }

    private function headerHtml(
        array $sale,
        array $branch,
        array $client,
        ?array $customer,
        ?string $salesman,
        bool $dual
    ): string {
        $fs = $dual ? self::DUAL_FS_HDR : 7;
        $fsTitle = $dual ? self::DUAL_FS_TITLE : 8;
        $business = htmlspecialchars((string) ($client['business_name'] ?? ($branch['name'] ?? 'Pharmacy')));
        // My Profile (clients) preferred; empty branch must not hide profile values.
        $phoneRaw = trim((string) ($client['phone'] ?? ''));
        if ($phoneRaw === '') {
            $phoneRaw = trim((string) ($branch['phone'] ?? ''));
        }
        $phone = htmlspecialchars($phoneRaw);
        $email = htmlspecialchars((string) ($client['email'] ?? ''));
        $gstinRaw = trim((string) ($client['gstin'] ?? ''));
        if ($gstinRaw === '') {
            $gstinRaw = trim((string) ($branch['gstin'] ?? ''));
        }
        $gstin = htmlspecialchars($gstinRaw);
        $dlRaw = trim((string) ($client['drug_license_no'] ?? ''));
        if ($dlRaw === '') {
            $dlRaw = trim((string) ($branch['drug_license_no'] ?? ''));
        }
        $dl = htmlspecialchars($dlRaw);

        $custName = htmlspecialchars((string) ($sale['patient_name'] ?: ($sale['customer_name'] ?? '-')));
        $custPhone = htmlspecialchars((string) ($sale['contact_no'] ?? ($customer['phone'] ?? '')));
        $custAddrRaw = trim((string) ($sale['address'] ?? ($customer['address'] ?? '')));
        $custGst = htmlspecialchars((string) ($customer['gstin'] ?? ''));
        $custDl = htmlspecialchars((string) ($customer['dl_no'] ?? ''));
        $fssai = trim((string) Env::get('INVOICE_FSSAI_NO', ''));
        $dlLine = $dl;
        if ($fssai !== '') {
            $dlLine = $dl !== '' ? $dl . ' / FSSAI No. : ' . htmlspecialchars($fssai) : 'FSSAI No. : ' . htmlspecialchars($fssai);
        }

        $invNo = htmlspecialchars((string) ($sale['invoice_no'] ?? ''));
        $invDate = !empty($sale['invoice_date'])
            ? date('d-m-Y', strtotime((string) $sale['invoice_date']))
            : '-';
        $salesmanSafe = htmlspecialchars((string) ($salesman ?: '-'));
        $outstanding = $this->money($this->customerOutstanding($sale));
        $paymentRaw = ucfirst(strtolower(trim((string) ($sale['payment_mode'] ?? '-'))));
        $memo = $this->memoLabel($paymentRaw);
        $memoHtml = $memo !== ''
            ? ' <span style="font-weight:bold;color:#1565C0;font-size:' . $fs . 'px;">(' . htmlspecialchars($memo) . ')</span>'
            : '';
        $logoHtml = '<div style="text-align:center;margin-top:2px;">' . $this->logoHtml($client, $dual) . '</div>';

        $left = '<b style="font-size:' . $fsTitle . 'px;">' . $business . '</b><br>'
            . $this->hdrTextLines($branch, $client)
            . $this->hdrKv('Phone', $phone, 'left', $dual)
            . $this->hdrKv('Email', $email, 'left', $dual)
            . $this->hdrKv('GSTIN', $gstin, 'left', $dual)
            . $this->hdrKv('DL No.', $dlLine, 'left', $dual);

        $mid = '<div style="font-weight:bold;text-align:center;font-size:' . $fsTitle . 'px;">GST TAX INVOICE</div>'
            . $this->hdrKv('Bill No.', $invNo, 'mid', $dual)
            . $this->hdrKv('Bill Date', $invDate, 'mid', $dual)
            . $this->hdrKv('Salesman', $salesmanSafe, 'mid', $dual)
            . $this->hdrKv('Outstanding', $outstanding, 'mid', $dual)
            . $this->hdrKvHtml('Payment', htmlspecialchars($paymentRaw) . $memoHtml, 'mid', $dual)
            . $logoHtml;

        $right = '<b>TO,</b><br><b>' . $custName . '</b><br>'
            . $this->hdrTextLinesRaw($custAddrRaw)
            . $this->hdrKv('Phone', $custPhone, 'right', $dual)
            . $this->hdrKv('GSTIN', $custGst, 'right', $dual)
            . $this->hdrKv('DL No.', $custDl, 'right', $dual);

        return '<table border="1" cellpadding="2" cellspacing="0" width="100%" style="border-collapse:collapse;width:100%;table-layout:fixed;">'
            . '<tr>'
            . '<td width="38%" bgcolor="' . self::HDR_BG_L . '" style="font-size:' . $fs . 'px;vertical-align:top;border:' . self::BORDER . ';">' . $left . '</td>'
            . '<td width="27%" bgcolor="' . self::HDR_BG_M . '" style="font-size:' . $fs . 'px;vertical-align:top;border:' . self::BORDER . ';">' . $mid . '</td>'
            . '<td width="35%" bgcolor="' . self::HDR_BG_R . '" style="font-size:' . $fs . 'px;vertical-align:top;border:' . self::BORDER . ';">' . $right . '</td>'
            . '</tr></table>';
    }

    /** Retail ws_hdr_text_lines — address lines from branch/client. */
    private function hdrTextLines(array $branch, array $client): string
    {
        $raw = trim((string) ($branch['address'] ?? $client['address'] ?? ''));
        $city = trim((string) ($branch['city'] ?? $client['city'] ?? ''));
        $pin = trim((string) ($branch['pincode'] ?? $client['pincode'] ?? ''));
        $tail = trim($city . ($pin !== '' ? ' ' . $pin : ''));
        if ($tail !== '' && stripos($raw, $city) === false) {
            $raw = trim($raw . ($raw !== '' ? ', ' : '') . $tail);
        }
        return $this->hdrTextLinesRaw($raw);
    }

    private function hdrTextLinesRaw(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        $parts = preg_split('/\r\n|\n|\r/', $text) ?: [];
        $out = '';
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $out .= htmlspecialchars($part) . '<br>';
            }
        }
        return $out;
    }

    /**
     * Retail ws_product_columns — fixed % widths (TCPDF needs table-layout:fixed + colgroup).
     *
     * @return list<array{key:string,label:string,pct:int,align:string,wrap:bool}>
     */
    private function productColumnDefs(bool $dual, string $rateType): array
    {
        $rateLabel = $dual ? 'Rate' : ($rateType === 'PTS' ? 'Rate/PTS' : 'Rate/PTR');
        $defs = [
            ['key' => 'product', 'label' => 'Product', 'pct' => 20, 'align' => 'left', 'wrap' => true],
            ['key' => 'hsn', 'label' => 'HSN', 'pct' => 6, 'align' => 'center', 'wrap' => false],
            ['key' => 'batch', 'label' => 'Batch', 'pct' => 8, 'align' => 'center', 'wrap' => false],
            ['key' => 'exp', 'label' => 'Exp', 'pct' => 6, 'align' => 'center', 'wrap' => false],
            ['key' => 'qty', 'label' => 'Qty', 'pct' => 4, 'align' => 'center', 'wrap' => false],
            ['key' => 'free', 'label' => 'Free', 'pct' => 5, 'align' => 'center', 'wrap' => false],
            ['key' => 'pack', 'label' => 'Pack', 'pct' => 6, 'align' => 'center', 'wrap' => false],
            ['key' => 'rate', 'label' => $rateLabel, 'pct' => 8, 'align' => 'center', 'wrap' => false],
            ['key' => 'mrp', 'label' => 'MRP', 'pct' => 7, 'align' => 'center', 'wrap' => false],
            ['key' => 'gross', 'label' => 'Gross', 'pct' => 8, 'align' => 'center', 'wrap' => false],
            ['key' => 'disc', 'label' => 'Disc', 'pct' => 5, 'align' => 'center', 'wrap' => false],
            ['key' => 'gst', 'label' => 'GST', 'pct' => 7, 'align' => 'center', 'wrap' => false],
            ['key' => 'net', 'label' => 'Net', 'pct' => 10, 'align' => 'center', 'wrap' => false],
        ];
        if ($dual) {
            // Dual: 12 cols — Exp+GST merged; net absorbs remainder to full width (sum = 100).
            return [
                ['key' => 'product', 'label' => 'Product', 'pct' => 24, 'align' => 'left', 'wrap' => true],
                ['key' => 'hsn', 'label' => 'HSN', 'pct' => 5, 'align' => 'center', 'wrap' => false],
                ['key' => 'batch', 'label' => 'Batch', 'pct' => 8, 'align' => 'center', 'wrap' => false],
                ['key' => 'exp_gst', 'label' => 'Exp/GST', 'pct' => 8, 'align' => 'center', 'wrap' => false],
                ['key' => 'qty', 'label' => 'Qty', 'pct' => 4, 'align' => 'center', 'wrap' => false],
                ['key' => 'free', 'label' => 'Free', 'pct' => 5, 'align' => 'center', 'wrap' => false],
                ['key' => 'pack', 'label' => 'Pack', 'pct' => 6, 'align' => 'center', 'wrap' => false],
                ['key' => 'rate', 'label' => $rateLabel, 'pct' => 7, 'align' => 'center', 'wrap' => false],
                ['key' => 'mrp', 'label' => 'MRP', 'pct' => 7, 'align' => 'center', 'wrap' => false],
                ['key' => 'gross', 'label' => 'Gross', 'pct' => 8, 'align' => 'center', 'wrap' => false],
                ['key' => 'disc', 'label' => 'Disc', 'pct' => 5, 'align' => 'center', 'wrap' => false],
                ['key' => 'net', 'label' => 'Net', 'pct' => 13, 'align' => 'center', 'wrap' => false],
            ];
        }
        // Single: Exp/MRP/GST wide enough that TCPDF cannot wrap amounts (sum = 100).
        $pcts = [20, 6, 8, 6, 4, 5, 6, 8, 7, 8, 5, 7, 10];
        foreach ($defs as $i => &$def) {
            $def['pct'] = $pcts[$i] ?? $def['pct'];
        }
        unset($def);
        return $defs;
    }

    /**
     * Widen product column when names are long; trim HSN/Net only (sums stay 100%).
     *
     * @param list<array<string,mixed>> $rows
     * @param list<array{key:string,label:string,pct:int,align:string,wrap:bool}> $cols
     * @return list<array{key:string,label:string,pct:int,align:string,wrap:bool}>
     */
    private function productColumnsForRows(array $rows, array $cols): array
    {
        $maxProductLen = 0;
        $maxBatchLen = 0;
        foreach ($rows as $row) {
            $maxProductLen = max($maxProductLen, mb_strlen((string) ($row['product'] ?? '')));
            $maxBatchLen = max($maxBatchLen, mb_strlen((string) ($row['batch'] ?? '')));
        }

        if ($maxBatchLen > 8) {
            $batchExtra = min(3, (int) (($maxBatchLen - 8) / 2) + 1);
            foreach ($cols as &$col) {
                if ($col['key'] === 'batch') {
                    $col['pct'] += $batchExtra;
                } elseif ($col['key'] === 'hsn') {
                    $col['pct'] = max(4, $col['pct'] - min(2, $batchExtra));
                } elseif ($col['key'] === 'net') {
                    $col['pct'] = max(8, $col['pct'] - ($batchExtra - min(2, $batchExtra)));
                }
            }
            unset($col);
        }

        if ($maxProductLen <= 32) {
            return $this->normalizeProductColumnPcts($cols);
        }
        $extra = min(6, (int) (($maxProductLen - 32) / 6) + 1);
        $fromHsn = min($extra, 2);
        $fromNet = $extra - $fromHsn;
        foreach ($cols as &$col) {
            if ($col['key'] === 'product') {
                $col['pct'] += $extra;
            } elseif ($col['key'] === 'hsn') {
                $col['pct'] = max(4, $col['pct'] - $fromHsn);
            } elseif ($col['key'] === 'net') {
                $col['pct'] = max(8, $col['pct'] - $fromNet);
            }
        }
        unset($col);
        return $this->normalizeProductColumnPcts($cols);
    }

    /**
     * Ensure column % totals exactly 100 — remainder goes to Net (fills right edge).
     *
     * @param list<array{key:string,label:string,pct:int,align:string,wrap:bool}> $cols
     * @return list<array{key:string,label:string,pct:int,align:string,wrap:bool}>
     */
    private function normalizeProductColumnPcts(array $cols): array
    {
        $sum = 0;
        foreach ($cols as $col) {
            $sum += (int) $col['pct'];
        }
        if ($sum === 100) {
            return $cols;
        }
        $delta = 100 - $sum;
        $netIdx = null;
        foreach ($cols as $i => $col) {
            if ($col['key'] === 'net') {
                $netIdx = $i;
                break;
            }
        }
        if ($netIdx !== null) {
            $cols[$netIdx]['pct'] = max(8, (int) $cols[$netIdx]['pct'] + $delta);
        } else {
            $cols[0]['pct'] = max(10, (int) $cols[0]['pct'] + $delta);
        }
        return $cols;
    }

    /** @param list<array<string,mixed>> $rows */
    private function productTableHtml(array $rows, int $productCount, string $rateType, bool $dual, int $fs): string
    {
        $cols = $this->productColumnsForRows($rows, $this->productColumnDefs($dual, $rateType));
        $dualClass = $dual ? ' ws-products-dual' : '';
        $cellPad = $dual ? 0 : 4;

        $html = '<table class="ws-products' . $dualClass . '" border="1" cellpadding="' . $cellPad . '" cellspacing="0" width="100%"'
            . ' style="width:100%;border-collapse:collapse;table-layout:fixed;border:' . self::BORDER . ';">';
        $html .= '<colgroup>';
        foreach ($cols as $col) {
            $html .= '<col width="' . (int) $col['pct'] . '%" />';
        }
        $html .= '</colgroup><tr>';
        foreach ($cols as $col) {
            $key = (string) $col['key'];
            $align = (string) $col['align'];
            $hdrStyle = $this->productCellStyle($col, $fs, true, $dual, $align);
            $hdrLabel = $key === 'exp_gst'
                ? 'Exp<br/><span style="font-size:' . max(4, $fs - 1) . 'px;">GST</span>'
                : '<nobr>' . htmlspecialchars((string) $col['label']) . '</nobr>';
            $html .= '<th width="' . (int) $col['pct'] . '%" class="ws-col-' . $key . '" valign="middle" style="' . $hdrStyle . '">'
                . $hdrLabel . '</th>';
        }
        $html .= '</tr>';

        foreach ($rows as $row) {
            $html .= '<tr' . ($dual ? ' class="ws-prod-row"' : '') . '>';
            foreach ($cols as $col) {
                $key = (string) $col['key'];
                $align = (string) $col['align'];
                if ($key === 'exp_gst') {
                    $align = 'center';
                } elseif (in_array($key, ['rate', 'mrp', 'gross', 'disc', 'gst', 'net'], true)) {
                    $align = 'right';
                }
                $cellStyle = $this->productCellStyle($col, $fs, false, $dual, $align);
                if ($key === 'exp_gst') {
                    $content = $this->productCellContentExpGst((string) ($row['exp'] ?? ''), (string) ($row['gst'] ?? ''));
                } else {
                    $content = $this->productCellContent($key, (string) ($row[$key] ?? ''), !empty($col['wrap']));
                }
                $html .= '<td class="ws-col-' . $key . '" valign="middle" style="' . $cellStyle . '">' . $content . '</td>';
            }
            $html .= '</tr>';
        }

        $countPad = $dual ? '4px 8px 4px 20px' : '6px 10px 6px 28px';
        $html .= '<tr><td colspan="' . count($cols) . '" style="font-size:' . $fs . 'px;font-weight:bold;background-color:#E3F2FD;color:#1565C0;border:' . self::BORDER . ';padding:' . $countPad . ';">'
            . 'Total No. of Products :- <span style="color:#2E7D32;">' . $productCount . '</span></td></tr>';
        $html .= '</table>';
        return $html;
    }

    /**
     * @param array{key:string,label:string,pct:int,align:string,wrap:bool} $col
     */
    private function productCellStyle(array $col, int $fs, bool $isHeader, bool $dual, string $align): string
    {
        $key = (string) $col['key'];
        $wrap = !empty($col['wrap']);
        $tight = in_array($key, ['batch', 'exp', 'exp_gst', 'qty', 'free', 'pack', 'hsn', 'mrp', 'gst', 'net', 'disc', 'rate', 'gross'], true);

        if ($dual) {
            $padV = $isHeader ? 4 : 5;
            if ($key === 'product') {
                $padL = 8;
                $padR = 4;
            } elseif ($tight) {
                $padL = 2;
                $padR = 2;
            } else {
                $padL = 3;
                $padR = 3;
            }
        } elseif ($align === 'right') {
            $padV = $isHeader ? 5 : 4;
            $padL = 2;
            $padR = 6;
        } elseif ($key === 'product') {
            $padV = $isHeader ? 5 : 4;
            $padL = 22;
            $padR = 6;
        } elseif ($tight) {
            $padV = $isHeader ? 4 : 3;
            $padL = 1;
            $padR = 1;
        } else {
            $padV = $isHeader ? 5 : 4;
            $padL = 4;
            $padR = 4;
        }

        $style = 'font-size:' . $fs . 'px;border:' . self::BORDER . ';text-align:' . $align . ';'
            . 'padding:' . $padV . 'px ' . $padR . 'px ' . $padV . 'px ' . $padL . 'px;'
            . 'vertical-align:middle;line-height:' . ($dual ? '1.2' : '1.35') . ';';

        if ($isHeader) {
            $style .= 'background-color:' . self::PROD_HDR_BG . ';color:' . self::PROD_HDR_COLOR . ';font-weight:normal;';
        } else {
            $style .= 'color:#212121;';
            if (in_array($key, ['rate', 'mrp', 'gross', 'disc', 'gst', 'net'], true)) {
                $style .= 'font-variant-numeric:tabular-nums;';
            }
        }

        if ($wrap) {
            $style .= 'white-space:normal;word-wrap:break-word;word-break:break-word;';
        } elseif ($key === 'exp_gst') {
            $style .= 'white-space:normal;line-height:1.15;';
        } else {
            $style .= 'white-space:nowrap !important;word-wrap:normal !important;word-break:keep-all !important;';
        }

        return $style;
    }

    private function productCellContent(string $key, string $value, bool $wrap): string
    {
        $value = trim($value);
        if ($value === '') {
            return '&nbsp;';
        }
        $safe = htmlspecialchars($value);
        if ($wrap) {
            return $safe;
        }
        // TCPDF often ignores CSS nowrap on fixed % columns — <nobr> is reliable.
        return '<nobr>' . $safe . '</nobr>';
    }

    /** Dual only — expiry on top, GST amount below in one column. */
    private function productCellContentExpGst(string $exp, string $gst): string
    {
        $expSafe = trim($exp) !== '' ? htmlspecialchars($exp) : '&nbsp;';
        $gstSafe = trim($gst) !== '' ? htmlspecialchars($gst) : '&nbsp;';
        return '<nobr style="display:block;text-align:center;line-height:1.15;">' . $expSafe . '</nobr>'
            . '<nobr style="display:block;text-align:right;line-height:1.15;font-variant-numeric:tabular-nums;">' . $gstSafe . '</nobr>';
    }

    /** @param list<array<string,mixed>> $rows */
    private function footerHtml(
        array $sale,
        array $client,
        ?string $salesman,
        array $totals,
        array $rows,
        float $taxTotal,
        bool $dual,
        int $fs
    ): string {
        $netRounded = (int) round((float) $totals['net']);
        $words = strtoupper($this->amountInWords($netRounded));
        $paidAmt = $this->money((float) ($sale['paid'] ?? 0));
        $balAmt = $this->money((float) ($sale['balance'] ?? 0));
        $paidBalLine = '<b>Paid</b> : ' . $paidAmt . ' &nbsp;&nbsp; <b>Balance</b> : ' . $balAmt;
        $narration = trim((string) ($sale['narration'] ?? ''));
        $noteHtml = ($narration !== '' && !preg_match('/^[-–—.\s]+$/u', $narration))
            ? '<br><b>Note</b> ' . htmlspecialchars($narration)
            : '';
        $salesmanSafe = htmlspecialchars((string) ($salesman ?: ''));
        $taxTable = $this->taxBreakupHtml($rows, $dual ? self::DUAL_FS : 6);
        $bankHtml = $this->bankDetailsHtml($fs);
        $scannerHtml = $this->assetImgHtml($client, 'scanner', $dual ? 60 : 80, $dual ? 36 : 50);
        $stampHtml = $this->assetImgHtml($client, 'stamp', $dual ? 75 : 95, $dual ? 36 : 50);
        $roundOff = (float) ($totals['round_off'] ?? 0);
        $hasRoundOff = abs($roundOff) > 0.004;
        $roundOffText = ($roundOff > 0 ? '+' : '') . $this->money($roundOff);

        if ($dual) {
            return '<table class="ws-block" cellpadding="1" cellspacing="0" width="100%" style="width:100%;border-collapse:collapse;border:' . self::BORDER . ';">'
                . '<tr><td width="58%" style="vertical-align:top;font-size:' . $fs . 'px;border:' . self::BORDER . ';">'
                . '<b>Amt. In Words:</b> <span style="color:#2E7D32;font-weight:bold;">' . htmlspecialchars($words) . '</span><br>'
                . $paidBalLine . '<br>' . $taxTable
                . '</td><td width="42%" style="vertical-align:top;font-size:' . $fs . 'px;border:' . self::BORDER . ';">'
                . '<b>GROSS</b> : ' . $this->money($totals['gross']) . '<br>'
                . '<b>DISCOUNT</b> : ' . $this->money($totals['discount']) . '<br>'
                . '<b>TAX</b> : ' . $this->money($taxTotal) . '<br>'
                . ($hasRoundOff ? '<b>ROUND OFF</b> : ' . $roundOffText . '<br>' : '')
                . '<b>NET AMT.</b> : <span style="color:#2E7D32;font-weight:bold;">' . $this->money($netRounded) . '</span>'
                . '</td></tr>'
                . '<tr><td colspan="2" style="font-size:' . max(5, $fs - 1) . 'px;border:' . self::BORDER . ';">'
                . 'Terms: Return within 7 days. Cut strips / refrigerated / OTC not returnable. Original bill required.'
                . $noteHtml
                . '</td></tr>'
                . '<tr><td width="50%" align="center" style="border:' . self::BORDER . ';vertical-align:middle;">' . $scannerHtml . '</td>'
                . '<td width="50%" align="center" style="border:' . self::BORDER . ';vertical-align:middle;font-weight:bold;font-size:' . $fs . 'px;">'
                . 'Authorized Signatory<br>' . $salesmanSafe . '<br>' . $stampHtml
                . '</td></tr></table>';
        }

        return '<table class="ws-block" cellpadding="3" cellspacing="0" width="100%" style="width:100%;border-collapse:collapse;border:' . self::BORDER . ';">'
            . '<tr><td width="68%" rowspan="' . ($hasRoundOff ? 5 : 4) . '" style="vertical-align:top;font-size:' . $fs . 'px;border:' . self::BORDER . ';">'
            . '<b>Amt. In Words:</b> <span style="color:#2E7D32;font-weight:bold;">' . htmlspecialchars($words) . '</span><br>'
            . $paidBalLine . '<br>'
            . $taxTable
            . '</td><td width="16%" class="ws-total-label" style="font-weight:bold;text-align:right;border:' . self::BORDER . ';">GROSS</td>'
            . '<td width="16%" class="ws-total-value" style="text-align:right;border:' . self::BORDER . ';">' . $this->money($totals['gross']) . '</td></tr>'
            . '<tr><td class="ws-total-label" style="font-weight:bold;text-align:right;border:' . self::BORDER . ';">DISCOUNT</td>'
            . '<td class="ws-total-value" style="text-align:right;border:' . self::BORDER . ';">' . $this->money($totals['discount']) . '</td></tr>'
            . '<tr><td class="ws-total-label" style="font-weight:bold;text-align:right;border:' . self::BORDER . ';">TAX</td>'
            . '<td class="ws-total-value" style="text-align:right;border:' . self::BORDER . ';">' . $this->money($taxTotal) . '</td></tr>'
            . ($hasRoundOff
                ? '<tr><td class="ws-total-label" style="font-weight:bold;text-align:right;border:' . self::BORDER . ';">ROUND OFF</td>'
                    . '<td class="ws-total-value" style="text-align:right;border:' . self::BORDER . ';">' . $roundOffText . '</td></tr>'
                : '')
            . '<tr><td class="ws-total-label" style="font-weight:bold;text-align:right;font-size:8px;border:' . self::BORDER . ';">NET AMT.</td>'
            . '<td class="ws-total-value" style="text-align:right;color:#2E7D32;font-weight:bold;font-size:8px;border:' . self::BORDER . ';">' . $this->money($netRounded) . '</td></tr>'
            . '<tr><td width="50%" style="vertical-align:top;font-size:' . $fs . 'px;border:' . self::BORDER . ';">'
            . $this->termsHtml($fs) . $bankHtml . $noteHtml
            . '</td><td width="18%" align="center" style="border:' . self::BORDER . ';vertical-align:middle;">' . $scannerHtml . '</td>'
            . '<td width="32%" align="center" style="border:' . self::BORDER . ';vertical-align:middle;font-weight:bold;font-size:' . $fs . 'px;">'
            . 'Authorized Signatory<br>' . $salesmanSafe . '<br>' . $stampHtml
            . '</td></tr></table>';
    }

    /** @param list<array<string,mixed>> $rows */
    private function taxBreakupHtml(array $rows, int $fs): string
    {
        $slabs = $this->taxSlabs($rows);
        $cell = 'font-size:' . $fs . 'px;border:1px solid #000;padding:3px 5px;';
        $head = $cell . 'font-weight:bold;background-color:#f5f5f5;';
        $html = '<table cellpadding="0" cellspacing="0" width="100%" style="width:100%;border-collapse:collapse;margin-top:4px;border:1px solid #000;">';
        $html .= '<tr><th style="' . $head . '">Tax %</th><th style="' . $head . '">IGST</th><th style="' . $head . '">CGST</th><th style="' . $head . '">SGST</th></tr>';
        $tI = $tC = $tS = 0.0;
        foreach ($slabs as $item) {
            $html .= '<tr><td style="' . $cell . '">' . $this->money($item['taxPer']) . '</td>'
                . '<td style="' . $cell . '">' . $this->money($item['igst']) . '</td>'
                . '<td style="' . $cell . '">' . $this->money($item['cgst']) . '</td>'
                . '<td style="' . $cell . '">' . $this->money($item['sgst']) . '</td></tr>';
            $tI += $item['igst'];
            $tC += $item['cgst'];
            $tS += $item['sgst'];
        }
        $html .= '<tr><td style="' . $cell . 'font-weight:bold;">Total</td>'
            . '<td style="' . $cell . 'font-weight:bold;">' . $this->money($tI) . '</td>'
            . '<td style="' . $cell . 'font-weight:bold;">' . $this->money($tC) . '</td>'
            . '<td style="' . $cell . 'font-weight:bold;">' . $this->money($tS) . '</td></tr></table>';
        return $html;
    }

    /** @param list<array<string,mixed>> $rows
     *  @return list<array{taxPer:float,igst:float,cgst:float,sgst:float}>
     */
    private function taxSlabs(array $rows): array
    {
        $slabs = [];
        foreach ([0, 5, 18, 40] as $rate) {
            $slabs[(string) $rate] = ['taxPer' => (float) $rate, 'igst' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0];
        }
        foreach ($rows as $row) {
            $taxPer = (float) ($row['gst_rate'] ?? 0);
            $key = (string) (int) round($taxPer);
            if (!isset($slabs[$key])) {
                $slabs[$key] = ['taxPer' => $taxPer, 'igst' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0];
            }
            $slabs[$key]['igst'] += (float) ($row['igst'] ?? 0);
            $slabs[$key]['cgst'] += (float) ($row['cgst'] ?? 0);
            $slabs[$key]['sgst'] += (float) ($row['sgst'] ?? 0);
        }

        $ordered = [];
        foreach ([0, 5, 18, 40] as $rate) {
            $k = (string) $rate;
            $ordered[] = [
                'taxPer' => $slabs[$k]['taxPer'],
                'igst'   => round($slabs[$k]['igst'], 2),
                'cgst'   => round($slabs[$k]['cgst'], 2),
                'sgst'   => round($slabs[$k]['sgst'], 2),
            ];
        }
        foreach ($slabs as $k => $item) {
            if (in_array((int) $k, [0, 5, 18, 40], true)) {
                continue;
            }
            if ($item['igst'] + $item['cgst'] + $item['sgst'] <= 0) {
                continue;
            }
            $ordered[] = [
                'taxPer' => $item['taxPer'],
                'igst'   => round($item['igst'], 2),
                'cgst'   => round($item['cgst'], 2),
                'sgst'   => round($item['sgst'], 2),
            ];
        }
        return $ordered;
    }

    /** @param list<array<string,mixed>> $rows */
    private function taxSlabsTotal(array $rows): float
    {
        $total = 0.0;
        foreach ($this->taxSlabs($rows) as $item) {
            $total += $item['igst'] + $item['cgst'] + $item['sgst'];
        }
        return round($total, 2);
    }

    /** @return list<int> */
    private function productColumns(bool $dual): array
    {
        return array_column($this->productColumnDefs($dual, 'PTR'), 'pct');
    }

    private function hdrLabelWidth(string $col, bool $dual): int
    {
        if ($dual) {
            return match ($col) {
                'mid'   => 36,
                'right' => 30,
                default => 32,
            };
        }
        return match ($col) {
            'mid'   => 58,
            'right' => 44,
            default => 48,
        };
    }

    private function customerOutstanding(array $sale): float
    {
        if (empty($sale['customer_id'])) {
            return 0.0;
        }
        $stmt = Connection::ops()->prepare(
            'SELECT COALESCE(SUM(balance), 0) FROM sales
             WHERE client_id = :c AND customer_id = :cid AND balance > 0'
        );
        $stmt->execute([
            'c'   => (int) $sale['client_id'],
            'cid' => (int) $sale['customer_id'],
        ]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    private function memoLabel(string $paymentMode): string
    {
        if (strcasecmp($paymentMode, 'Cash') === 0) {
            return 'CASH MEMO';
        }
        if (strcasecmp($paymentMode, 'Credit') === 0) {
            return 'CREDIT MEMO';
        }
        return '';
    }

    /** @param array<string,mixed> $client */
    private function logoHtml(array $client, bool $dual): string
    {
        $w = $dual ? 42 : 72;
        $h = $dual ? 12 : 18;
        $fromProfile = InvoiceProfileAssets::imgHtml($client, 'logo', $w, $h);
        if (str_contains($fromProfile, '<img')) {
            return $fromProfile;
        }
        $logoUrl = trim((string) Env::get('INVOICE_LOGO_URL', ''));
        if ($logoUrl !== '') {
            return '<img src="' . htmlspecialchars($logoUrl) . '" style="width:' . $w . 'px;height:' . $h . 'px;" />';
        }
        return '&nbsp;';
    }

    private function stylesHtml(): string
    {
        return '<style>
            body, table, td, div, span { font-family:dejavusans,helvetica,sans-serif;color:#1a1a1a; }
            table.ws-block td, table.ws-block th { border:' . self::BORDER . '; }
            table.ws-products { width:100%;border-collapse:collapse;table-layout:fixed; }
            table.ws-products td, table.ws-products th { border:' . self::BORDER . '; }
            table.ws-products th { background-color:' . self::PROD_HDR_BG . ';color:' . self::PROD_HDR_COLOR . ';font-weight:normal; }
            table.ws-products th:not(.ws-col-product),
            table.ws-products td:not(.ws-col-product) { white-space:nowrap !important;word-wrap:normal !important;word-break:keep-all !important; }
            table.ws-products.ws-products-dual th.ws-col-product,
            table.ws-products.ws-products-dual td.ws-col-product { white-space:normal !important;word-wrap:break-word; }
            table.ws-products.ws-products-dual th:not(.ws-col-product):not(.ws-col-exp_gst),
            table.ws-products.ws-products-dual td:not(.ws-col-product):not(.ws-col-exp_gst) { white-space:nowrap !important;padding-left:2px !important;padding-right:2px !important; }
            table.ws-products.ws-products-dual th.ws-col-exp_gst,
            table.ws-products.ws-products-dual td.ws-col-exp_gst { white-space:normal !important;padding-left:2px !important;padding-right:2px !important; }
            table.ws-hdr-inner, table.ws-hdr-inner td { border:0 none !important; }
            .ws-total-label { font-weight:bold;color:#111111;padding:4px 6px 4px 14px; }
            .ws-total-value { padding:4px 6px 4px 10px;font-variant-numeric:tabular-nums;color:#111111; }
        </style>';
    }

    private function termsHtml(int $fs): string
    {
        return '<b style="font-size:' . $fs . 'px;">Terms &amp; Conditions</b>'
            . '<ol style="margin:2px 0 0 12px;padding:0;font-size:' . $fs . 'px;">'
            . '<li>Medicine return shall be done within 7 days of purchase.</li>'
            . '<li>Cut strips, Refrigerated, OTC/FMCG shall not be refunded/exchanged.</li>'
            . '<li>No return/Exchange will be processed without presenting the original bill.</li>'
            . '</ol>';
    }

    private function kv(string $label, string $value, int $fs): string
    {
        if (trim($value) === '') {
            return '';
        }
        return '<b>' . htmlspecialchars($label) . ' :</b> ' . $value . '<br>';
    }

    private function hdrKv(string $label, string $value, string $col, bool $dual): string
    {
        $labelW = $this->hdrLabelWidth($col, $dual);
        $val = trim($value) !== '' ? $value : '&nbsp;';
        return '<table class="ws-hdr-inner" border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%;table-layout:fixed;border:0 none;border-collapse:collapse;margin:0;padding:0;"><tr>'
            . '<td width="' . $labelW . '" style="border:0 none;white-space:nowrap;vertical-align:top;line-height:1.15;"><b>' . htmlspecialchars($label) . ' :</b></td>'
            . '<td style="border:0 none;vertical-align:top;line-height:1.15;font-weight:normal;">' . $val . '</td></tr></table>';
    }

    private function hdrKvHtml(string $label, string $valueHtml, string $col, bool $dual): string
    {
        $labelW = $this->hdrLabelWidth($col, $dual);
        $val = trim($valueHtml) !== '' ? $valueHtml : '&nbsp;';
        return '<table class="ws-hdr-inner" border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%;table-layout:fixed;border:0 none;border-collapse:collapse;margin:0;padding:0;"><tr>'
            . '<td width="' . $labelW . '" style="border:0 none;white-space:nowrap;vertical-align:top;line-height:1.15;"><b>' . htmlspecialchars($label) . ' :</b></td>'
            . '<td style="border:0 none;vertical-align:top;line-height:1.15;font-weight:normal;">' . $val . '</td></tr></table>';
    }

    private function kvHtml(string $label, string $valueHtml, int $fs): string
    {
        if (trim(strip_tags($valueHtml)) === '') {
            return '';
        }
        return '<b>' . htmlspecialchars($label) . ' :</b> ' . $valueHtml . '<br>';
    }

    private function bankDetailsHtml(int $fs): string
    {
        $bank = trim((string) Env::get('INVOICE_BANK_NAME', ''));
        $acc = trim((string) Env::get('INVOICE_BANK_ACCOUNT', ''));
        $ifsc = trim((string) Env::get('INVOICE_BANK_IFSC', ''));
        if ($bank === '' && $acc === '' && $ifsc === '') {
            return '';
        }
        return '<div style="margin-top:4px;font-size:' . $fs . 'px;"><b>Bank Details</b><br>'
            . '<b>' . htmlspecialchars($bank) . '</b>'
            . ($acc !== '' ? ' | A/C: ' . htmlspecialchars($acc) : '')
            . ($ifsc !== '' ? ' | IFSC: ' . htmlspecialchars($ifsc) : '')
            . '</div>';
    }

    /** @param array<string,mixed> $client */
    private function assetImgHtml(array $client, string $kind, int $w, int $h): string
    {
        return InvoiceProfileAssets::imgHtml($client, $kind, $w, $h);
    }

    private function formatPack(string $pack): string
    {
        $pack = trim($pack);
        if ($pack === '') {
            return '';
        }
        // "1x10 Tablets", "1 X 10 CAP", "10*15 Strip" → "1 x 10" / "10 x 15"
        if (preg_match('/(\d+)\s*[xX×*]\s*(\d+)/u', $pack, $m)) {
            return $m[1] . ' x ' . $m[2];
        }
        if (preg_match('/^(\d+)$/', $pack, $m)) {
            return $m[1];
        }
        // Drop dosage-form words if no NxM pattern matched
        $stripped = preg_replace(
            '/\b(tablets?|tabs?|capsules?|caps?|syrup|injection|inj|drops?|cream|gel|ointment|ml|mg|gm|g|strip|pack|bottle|vial|ampoule|sachet|tube|pcs?|nos?|units?)\b/iu',
            '',
            $pack
        );
        $stripped = trim(preg_replace('/\s+/u', ' ', (string) $stripped) ?? '');
        return $stripped !== '' ? $stripped : $pack;
    }

    /** MM/YY with no spaces — TCPDF otherwise wraps after "/". */
    private function formatExpiry(string $expiryDate): string
    {
        $ts = strtotime($expiryDate);
        if ($ts === false) {
            return '';
        }
        return date('m/y', $ts);
    }

    private function money(float $value): string
    {
        // Narrow no-break spaces around nothing — keep compact "1,500.00" inside <nobr>.
        return number_format($value, 2, '.', ',');
    }

    private function formatQty(float $value): string
    {
        return number_format($value, $value == floor($value) ? 0 : 2);
    }

    private function amountInWords(int $rupees): string
    {
        if ($rupees === 0) {
            return 'ZERO RUPEES ONLY';
        }
        return strtoupper($this->numToWords($rupees)) . ' RUPEES ONLY';
    }

    private function numToWords(int $n): string
    {
        if ($n === 0) {
            return 'Zero';
        }
        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
            'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

        $twoDigits = static function (int $num) use ($ones, $tens): string {
            if ($num < 20) {
                return $ones[$num];
            }
            $t = $tens[intdiv($num, 10)];
            $u = $ones[$num % 10];
            return $u !== '' ? $t . '-' . $u : $t;
        };

        $threeDigits = static function (int $num) use ($ones, $twoDigits): string {
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
}
