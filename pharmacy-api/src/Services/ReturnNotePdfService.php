<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Support\InvoiceProfileAssets;
use TCPDF;

/**
 * Retail-parity PDF prints for Return Note hub:
 * Debit Note, Wholesale Credit Note, Retail Sales Return.
 */
final class ReturnNotePdfService
{
    public function debitNote(array $note, int $clientId, int $branchId): string
    {
        [$client, $branch] = $this->resolveOrg($clientId, $branchId);
        $titleColor = 'rgb(245, 234, 90)';
        $titleFg = '#000';
        $html = $this->buildDebitCreditHtml(
            $note,
            $client,
            $branch,
            'Debit Note',
            $titleColor,
            $titleFg,
            true
        );
        return $this->renderPdf(
            (string) ($client['business_name'] ?? 'Pharmacy'),
            'Debit Note ' . ($note['note_no'] ?? ''),
            $html
        );
    }

    public function creditNote(array $note, int $clientId, int $branchId): string
    {
        [$client, $branch] = $this->resolveOrg($clientId, $branchId);
        $html = $this->buildDebitCreditHtml(
            $note,
            $client,
            $branch,
            'Credit Note',
            'rgb(112, 57, 240)',
            '#fff',
            false
        );
        return $this->renderPdf(
            (string) ($client['business_name'] ?? 'Pharmacy'),
            'Credit Note ' . ($note['note_no'] ?? ''),
            $html
        );
    }

    public function retailSalesReturn(array $ret, int $clientId, int $branchId, ?string $preparedBy = null): string
    {
        [$client, $branch] = $this->resolveOrg($clientId, $branchId);
        $business = (string) ($client['business_name'] ?? 'Pharmacy');

        $pdf = new class ('P', 'mm', 'A4', true, 'UTF-8') extends TCPDF {
            public string $footerMedical = '';

            public function Header(): void {}

            public function Footer(): void
            {
                if ($this->footerMedical === '') {
                    return;
                }
                $this->SetY(-14);
                $this->SetDrawColor(30, 58, 95);
                $this->Line(10, $this->GetY(), $this->getPageWidth() - 10, $this->GetY());
                $this->SetY(-12);
                $this->SetFont('helvetica', 'B', 9);
                $this->Cell(0, 8, $this->footerMedical, 0, 0, 'C');
            }
        };
        $pdf->footerMedical = $business;
        $pdf->SetCreator('Advanced Pharmacy Suite');
        $pdf->SetAuthor($business);
        $pdf->SetTitle('Retail Sales Return ' . ($ret['return_no'] ?? ''));
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AddPage('P');

        $medical = $this->e($business);
        $address = $this->e($this->orgAddress($client, $branch));
        $gstNo = $this->e((string) ($branch['gstin'] ?? $client['gstin'] ?? '-'));
        $dlNo = $this->e((string) ($branch['drug_license_no'] ?? $client['drug_license_no'] ?? '-'));
        $mobile = $this->e((string) ($branch['phone'] ?? $client['phone'] ?? '-'));
        $clientEmail = $this->e((string) ($client['email'] ?? '-'));
        $logoHtml = InvoiceProfileAssets::imgHtml($client, 'logo', 78, 32);

        $patient = $this->e((string) ($ret['customer_name'] ?? '-'));
        $doctor = $this->e((string) ($ret['doctor_name'] ?: '-'));
        $contact = $this->e((string) ($ret['contact_no'] ?: '-'));
        $email = $this->e((string) ($ret['email'] ?: '-'));
        $isManual = strtolower((string) ($ret['return_type'] ?? 'bill')) === 'manual';
        $saleBill = $this->e($isManual ? 'EXTERNAL (no system bill)' : (string) ($ret['sale_invoice_no'] ?? ''));
        $saleDate = $this->fmtDate($ret['sale_invoice_date'] ?? null);
        $returnDate = $this->fmtDateTime($ret['created_at'] ?? null);
        $returnNo = $this->e((string) ($ret['return_no'] ?? ''));
        $returnTotal = number_format((float) ($ret['return_total'] ?? 0), 2);
        $user = $this->e((string) ($preparedBy ?: '-'));

        $labelStyle = 'width:18%; text-align:left; font-size:7px; font-weight:bold; padding:2px;';
        $colonStyle = 'width:3%; text-align:center; font-size:7px; font-weight:bold; padding:2px;';
        $valueStyle = 'width:29%; text-align:left; font-size:7px; padding:2px;';

        $html = '
<style>table { border-collapse:collapse; } .box { border:1px solid #000000; }</style>
<table class="box" cellpadding="3" cellspacing="0" width="100%">
  <tr>
    <td style="width:18%; text-align:center; vertical-align:middle;" rowspan="4">' . $logoHtml . '</td>
    <td style="width:52%; text-align:center; font-size:10px; font-weight:bold; color:#1e3a5f; padding:4px;">RETAIL SALES RETURN NOTE</td>
    <td style="width:12%; text-align:left; font-size:7px; font-weight:bold; padding:2px;">GST No.</td>
    <td style="width:18%; text-align:left; font-size:7px; padding:2px;">: ' . $gstNo . '</td>
  </tr>
  <tr>
    <td style="width:52%; text-align:center; font-size:12px; font-weight:bold; padding:2px;">' . $medical . '</td>
    <td style="width:12%; text-align:left; font-size:7px; font-weight:bold; padding:2px;">DL No.</td>
    <td style="width:18%; text-align:left; font-size:7px; padding:2px;">: ' . $dlNo . '</td>
  </tr>
  <tr>
    <td style="width:52%; text-align:center; font-size:8px; padding:2px;" rowspan="2">' . $address . '</td>
    <td style="width:12%; text-align:left; font-size:7px; font-weight:bold; padding:2px;">Phone</td>
    <td style="width:18%; text-align:left; font-size:7px; padding:2px;">: ' . $mobile . '</td>
  </tr>
  <tr>
    <td style="width:12%; text-align:left; font-size:7px; font-weight:bold; padding:2px;">Email</td>
    <td style="width:18%; text-align:left; font-size:7px; padding:2px;">: ' . $clientEmail . '</td>
  </tr>
</table>
<div style="height:6px;"></div>
<table class="box" cellpadding="2" cellspacing="0" width="100%">
  <tr>
    <td colspan="6" style="background-color:#e8eef5; font-size:8px; font-weight:bold; text-align:center; padding:4px; border:1px solid #000000;">Return &amp; Customer Details</td>
  </tr>
  <tr>
    <td style="' . $labelStyle . '">Return No.</td><td style="' . $colonStyle . '">:</td><td style="' . $valueStyle . '">' . $returnNo . '</td>
    <td style="' . $labelStyle . '">Return Date</td><td style="' . $colonStyle . '">:</td><td style="' . $valueStyle . '">' . $returnDate . '</td>
  </tr>
  <tr>
    <td style="' . $labelStyle . '">Customer</td><td style="' . $colonStyle . '">:</td><td style="' . $valueStyle . '">' . $patient . '</td>
    <td style="' . $labelStyle . '">Contact</td><td style="' . $colonStyle . '">:</td><td style="' . $valueStyle . '">' . $contact . '</td>
  </tr>
  <tr>
    <td style="' . $labelStyle . '">Doctor</td><td style="' . $colonStyle . '">:</td><td style="' . $valueStyle . '">' . $doctor . '</td>
    <td style="' . $labelStyle . '">Email</td><td style="' . $colonStyle . '">:</td><td style="' . $valueStyle . '">' . $email . '</td>
  </tr>
  <tr>
    <td style="' . $labelStyle . '">' . ($isManual ? 'Bill Ref.' : 'Original Bill No.') . '</td><td style="' . $colonStyle . '">:</td><td style="' . $valueStyle . '">' . $saleBill . '</td>
    <td style="' . $labelStyle . '">' . ($isManual ? 'Sale Bill Date' : 'Original Bill Date') . '</td><td style="' . $colonStyle . '">:</td><td style="' . $valueStyle . '">' . $saleDate . '</td>
  </tr>
</table>';

        $pdf->writeHTML($html, true, false, true, false, '');

        $colWidths = [8, 62, 18, 16, 16, 16, 18, 16, 20];
        $headers = ['Sr.', 'Product Name', 'Batch', 'Expiry', 'Sale Qty', 'Ret. Qty', 'MRP', 'Disc%', 'Return Amt'];
        $headerAlign = ['C', 'L', 'C', 'C', 'C', 'C', 'R', 'R', 'R'];

        $pdf->Ln(2);
        $pdf->SetFillColor(30, 58, 95);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 7);
        for ($i = 0; $i < count($headers); $i++) {
            $pdf->Cell($colWidths[$i], 6, $headers[$i], 1, 0, $headerAlign[$i], true);
        }
        $pdf->Ln();

        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('helvetica', '', 7);
        $sr = 1;
        $items = is_array($ret['products'] ?? null) ? $ret['products'] : [];
        foreach ($items as $line) {
            $productName = (string) ($line['p_name'] ?? $line['product_name'] ?? '');
            if (strlen($productName) > 42) {
                $productName = substr($productName, 0, 42) . '...';
            }
            $exp = $this->fmtExpiry($line['exp_date'] ?? $line['expiry_date'] ?? null);
            $pdf->Cell($colWidths[0], 5, (string) $sr, 1, 0, 'C');
            $pdf->Cell($colWidths[1], 5, $productName, 1, 0, 'L');
            $pdf->Cell($colWidths[2], 5, (string) ($line['batch_no'] ?? ''), 1, 0, 'C');
            $pdf->Cell($colWidths[3], 5, $exp, 1, 0, 'C');
            $pdf->Cell($colWidths[4], 5, (string) ($line['sale_qty'] ?? '0'), 1, 0, 'C');
            $pdf->Cell($colWidths[5], 5, (string) ($line['return_qty'] ?? '0'), 1, 0, 'C');
            $pdf->Cell($colWidths[6], 5, number_format((float) ($line['mrp'] ?? 0), 2), 1, 0, 'R');
            $pdf->Cell($colWidths[7], 5, number_format((float) ($line['disc_per'] ?? $line['discount_pct'] ?? 0), 2), 1, 0, 'R');
            $pdf->Cell($colWidths[8], 5, number_format((float) ($line['return_amt'] ?? 0), 2), 1, 0, 'R');
            $pdf->Ln();
            $sr++;
        }

        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetFillColor(232, 238, 245);
        $totalLabelWidth = array_sum(array_slice($colWidths, 0, 8));
        $linesSum = array_sum(array_map(static fn ($l) => (float) ($l['return_amt'] ?? 0), $items));
        $roundOff = round((float) ($ret['return_total'] ?? 0) - $linesSum, 2);
        if (abs($roundOff) >= 0.01) {
            $pdf->SetFont('helvetica', '', 7);
            $pdf->Cell($totalLabelWidth, 5, 'Round off', 1, 0, 'R');
            $pdf->Cell($colWidths[8], 5, ($roundOff > 0 ? '+' : '') . number_format($roundOff, 2), 1, 1, 'R');
            $pdf->SetFont('helvetica', 'B', 8);
        }
        $pdf->Cell($totalLabelWidth, 6, 'Total Return Amount', 1, 0, 'R', true);
        $pdf->Cell($colWidths[8], 6, $returnTotal, 1, 1, 'R', true);

        $footerHtml = '
<div style="height:6px;"></div>
<table cellpadding="2" cellspacing="0" width="100%">
  <tr>
    <td style="width:50%; font-size:7px; text-align:left; padding:2px;"><b>Prepared By:</b> ' . $user . '</td>
    <td style="width:50%; font-size:7px; text-align:right; padding:2px;"><b>Total Items Returned:</b> ' . count($items) . '</td>
  </tr>
  <tr>
    <td colspan="2" style="font-size:7px; text-align:left; padding:6px 2px 2px 2px;">
      <i>This is a computer-generated retail sales return note. Returned goods have been accepted as per pharmacy policy.</i>
    </td>
  </tr>
</table>';
        $pdf->writeHTML($footerHtml, true, false, true, false, '');

        return $pdf->Output('retail_sale_return.pdf', 'S');
    }

    /**
     * @param array<string,mixed> $note
     * @param array<string,mixed> $client
     * @param array<string,mixed> $branch
     */
    private function buildDebitCreditHtml(
        array $note,
        array $client,
        array $branch,
        string $title,
        string $bannerBg,
        string $bannerFg,
        bool $isDebit
    ): string {
        $business = $this->e((string) ($client['business_name'] ?? 'Pharmacy'));
        $gstin = $this->e((string) ($branch['gstin'] ?? $client['gstin'] ?? '-'));
        $address = $this->e($this->orgAddress($client, $branch));
        $state = $this->e((string) ($branch['state'] ?? $client['state'] ?? '-'));
        $phone = $this->e((string) ($branch['phone'] ?? $client['phone'] ?? '-'));
        $logoHtml = InvoiceProfileAssets::imgHtml($client, 'logo', 90, 36);

        $partyName = $this->e((string) (
            $note['distributor_name']
            ?? $note['customer_name']
            ?? $note['party_name']
            ?? '-'
        ));
        $noteDate = $this->fmtDate($note['note_date'] ?? $note['order_date'] ?? null);
        $partyAddr = $this->e((string) ($note['party_address'] ?? $note['d_add'] ?? '-'));
        $noteNo = $this->e((string) ($note['note_no'] ?? $note['order_no'] ?? '-'));
        $partyPhone = $this->e((string) ($note['d_cont'] ?? $note['party_phone'] ?? '-'));
        $partyGst = $this->e((string) ($note['d_gst'] ?? $note['party_gstin'] ?? '-'));
        $partyState = $this->e((string) ($note['state'] ?? $note['party_state'] ?? '-'));

        $taxable = (float) ($note['taxable'] ?? 0);
        $tax = (float) ($note['tax'] ?? $note['gst_amount'] ?? 0);
        $net = (float) ($note['netTotal'] ?? $note['amount'] ?? 0);

        $th = 'background-color:' . $bannerBg . '; color:' . $bannerFg . '; border: 1px solid black; font-weight: bold; text-align: left; font-size: 7px;';
        $td = 'border: 1px solid black; font-size: 7px;';

        $html = '
<table cellpadding="6">
  <tr>
    <td style="width:100%; text-align:center; font-size:13px; background-color:' . $bannerBg . '; color:' . $bannerFg . ';"><b>' . $this->e($title) . '</b></td>
  </tr>
  <tr>
    <td style="width:12%; text-align:left; font-size:7px;"><b>Business Name</b></td>
    <td style="width:28%; text-align:left; font-size:7px;"><b>: </b>' . $business . '</td>
    <td style="width:12%; text-align:left; font-size:7px;"><b>GSTIN No.</b></td>
    <td style="width:28%; text-align:left; font-size:7px;"><b>: </b>' . $gstin . '</td>
    <td style="width:20%; text-align:center;" rowspan="3">' . $logoHtml . '</td>
  </tr>
  <tr>
    <td style="width:12%; text-align:left; font-size:7px;"><b>Address</b></td>
    <td style="width:68%; text-align:left; font-size:6px;" colspan="3"><b>: </b>' . $address . '</td>
  </tr>
  <tr>
    <td style="width:12%; text-align:left; font-size:7px;"><b>State</b></td>
    <td style="width:28%; text-align:left; font-size:7px;"><b>: </b>' . $state . '</td>
    <td style="width:12%; text-align:left; font-size:7px;"><b>Phone No.</b></td>
    <td style="width:28%; text-align:left; font-size:7px;"><b>: </b>' . $phone . '</td>
  </tr>
</table>
<div></div>
<table cellpadding="6">
  <tr>
    <td style="width:50%; text-align:left; background-color:' . $bannerBg . '; color:' . $bannerFg . '; font-size:10px;"><b>Return/Credit From</b></td>
    <td style="width:50%; text-align:left; background-color:' . $bannerBg . '; color:' . $bannerFg . '; font-size:10px;"><b>Shipping From</b></td>
  </tr>
  <tr>
    <td style="width:10%; text-align:left; font-size:7px;"><b>Name</b></td>
    <td style="width:23%; text-align:left; font-size:7px;"><b>: </b>' . $partyName . '</td>
    <td style="width:10%; text-align:left; font-size:7px;"><b>Date</b></td>
    <td style="width:23%; text-align:left; font-size:7px;"><b>: </b>' . $noteDate . '</td>
    <td style="width:10%; text-align:left; font-size:7px;"><b>Address</b></td>
    <td style="width:24%; text-align:left; font-size:7px;"><b>: </b>' . $partyAddr . '</td>
  </tr>
  <tr>
    <td style="width:10%; text-align:left; font-size:7px;"><b>Cr. Note No.</b></td>
    <td style="width:23%; text-align:left; font-size:7px;"><b>: </b>' . $noteNo . '</td>
    <td style="width:10%; text-align:left; font-size:7px;"><b>Phone No.</b></td>
    <td style="width:23%; text-align:left; font-size:7px;"><b>: </b>' . $partyPhone . '</td>
    <td style="width:10%; text-align:left; font-size:7px;"><b>GSTIN No</b></td>
    <td style="width:24%; text-align:left; font-size:7px;"><b>: </b>' . $partyGst . '</td>
  </tr>
  <tr>
    <td style="width:10%; text-align:left; font-size:7px;"><b>State</b></td>
    <td style="width:90%; text-align:left; font-size:7px;" colspan="5"><b>: </b>' . $partyState . '</td>
  </tr>
</table>
<div></div>
<table cellpadding="6">
  <tr>
    <th style="' . $th . ' width:5%;">Sr. No.</th>
    <th style="' . $th . ' width:30%;">Product Name</th>
    <th style="' . $th . ' width:10%; text-align:center;">B.No</th>
    <th style="' . $th . ' width:10%; text-align:center;">HSN.</th>
    <th style="' . $th . ' width:5%; text-align:center;">Qty</th>
    <th style="' . $th . ' width:10%; text-align:center;">Rate</th>
    <th style="' . $th . ' width:10%; text-align:center;">Taxable</th>
    <th style="' . $th . ' width:10%; text-align:center;">TAX</th>
    <th style="' . $th . ' width:10%; text-align:center;">Amount</th>
  </tr>';

        $products = is_array($note['products'] ?? null) ? $note['products'] : [];
        $i = 1;
        foreach ($products as $row) {
            $rate = $isDebit
                ? (float) ($row['net_rate'] ?? $row['purchase_rate'] ?? $row['nate_rate'] ?? 0)
                : (float) ($row['sale_rate'] ?? $row['net_rate'] ?? 0);
            $html .= '<tr>
              <td style="' . $td . ' text-align:left;">' . $i . '</td>
              <td style="' . $td . ' text-align:left;">' . $this->e((string) ($row['p_name'] ?? $row['product_name'] ?? '')) . '</td>
              <td style="' . $td . ' text-align:center;">' . $this->e((string) ($row['batch_no'] ?? '')) . '</td>
              <td style="' . $td . ' text-align:center;">' . $this->e((string) ($row['hsn'] ?? '')) . '</td>
              <td style="' . $td . ' text-align:center;">' . $this->e((string) ($row['return_qty'] ?? 0)) . '</td>
              <td style="' . $td . ' text-align:right;">' . number_format($rate, 2) . '</td>
              <td style="' . $td . ' text-align:right;">' . number_format((float) ($row['taxable'] ?? 0), 2) . '</td>
              <td style="' . $td . ' text-align:right;">' . number_format((float) ($row['tax'] ?? 0), 2) . '</td>
              <td style="' . $td . ' text-align:right;">' . number_format((float) ($row['netTotal'] ?? $row['net_total'] ?? 0), 2) . '</td>
            </tr>';
            $i++;
        }

        $html .= '<tr>
          <td style="' . $td . ' text-align:right;" colspan="6"> Total</td>
          <td style="' . $td . ' text-align:right;">' . number_format($taxable, 2) . '</td>
          <td style="' . $td . ' text-align:right;">' . number_format($tax, 2) . '</td>
          <td style="' . $td . ' text-align:right;">' . number_format($net, 2) . '</td>
        </tr>
</table>
<div></div>
<span>Please Debit <b>' . number_format($net, 2) . '</b> From Your Next Payment!!!!!</span>';

        return $html;
    }

    private function renderPdf(string $business, string $title, string $html): string
    {
        $pdf = new class ('P', 'mm', 'A4', true, 'UTF-8') extends TCPDF {
            public function Header(): void {}

            public function Footer(): void
            {
                $this->SetY(-15);
                $this->SetFont('helvetica', 'I', 8);
                $this->Cell(0, 10, 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'C');
            }
        };
        $pdf->SetCreator('Advanced Pharmacy Suite');
        $pdf->SetAuthor($business);
        $pdf->SetTitle($title);
        $pdf->SetMargins(10, 20, 10);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage('P');
        $pdf->writeHTML($html, true, false, true, false, '');
        return $pdf->Output('note.pdf', 'S');
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function resolveOrg(int $clientId, int $branchId): array
    {
        try {
            (new ClientProfileService())->ensureProfileColumns();
        } catch (\Throwable) {
        }
        $client = Connection::ho()->query('SELECT * FROM clients WHERE id = ' . (int) $clientId)->fetch() ?: [];
        $branch = Connection::ho()->query('SELECT * FROM branches WHERE id = ' . (int) $branchId)->fetch() ?: [];
        return [$client, $branch];
    }

    /** @param array<string,mixed> $client @param array<string,mixed> $branch */
    private function orgAddress(array $client, array $branch): string
    {
        return trim(
            ($branch['address'] ?? $client['address'] ?? '')
            . ', ' . ($branch['city'] ?? $client['city'] ?? '')
            . ' ' . ($branch['pincode'] ?? $client['pincode'] ?? ''),
            ', '
        );
    }

    private function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }

    private function fmtDate(mixed $value): string
    {
        if ($value === null || trim((string) $value) === '') {
            return '-';
        }
        $ts = strtotime((string) $value);
        return $ts === false ? $this->e((string) $value) : date('d-m-Y', $ts);
    }

    private function fmtDateTime(mixed $value): string
    {
        if ($value === null || trim((string) $value) === '') {
            return '-';
        }
        $ts = strtotime((string) $value);
        return $ts === false ? $this->e((string) $value) : date('d-m-Y h:i A', $ts);
    }

    private function fmtExpiry(mixed $value): string
    {
        if ($value === null || trim((string) $value) === '') {
            return '';
        }
        $raw = trim((string) $value);
        $ts = strtotime($raw);
        if ($ts === false) {
            return $raw;
        }
        return date('m/y', $ts);
    }
}
