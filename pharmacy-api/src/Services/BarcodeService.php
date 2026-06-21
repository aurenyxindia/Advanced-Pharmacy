<?php

declare(strict_types=1);

namespace App\Services;

use TCPDF;

/**
 * Renders small Code-128 strip/pack barcode labels on an A4 sheet.
 * Each label: product (+Mfg), batch/expiry, MRP and the barcode.
 *
 * @param array<int, array{name:string, mfg:?string, batch:?string, exp:?string, mrp:float, code:string}> $labels
 */
final class BarcodeService
{
    public function render(array $labels): string
    {
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->SetCreator('Advanced Pharmacy Suite');
        $pdf->SetTitle('Barcode Labels');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(6, 8, 6);

        // Label geometry (mm) — sized for small strips.
        $cols   = 4;
        $labelW = 49.0;
        $labelH = 24.0;
        $gapX   = 0.0;
        $marginX = 6.0;
        $marginY = 8.0;
        $pageH  = 297.0;
        $rowsPerPage = (int) floor(($pageH - $marginY - 4) / $labelH);
        $perPage = $cols * $rowsPerPage;

        $barStyle = [
            'position' => '', 'border' => false, 'padding' => 0,
            'fgcolor' => [0, 0, 0], 'bgcolor' => false,
            'text' => true, 'font' => 'helvetica', 'fontsize' => 6, 'stretchtext' => 4,
        ];

        $pdf->AddPage();
        $i = 0;
        foreach ($labels as $label) {
            $posOnPage = $i % $perPage;
            if ($i > 0 && $posOnPage === 0) {
                $pdf->AddPage();
            }
            $col = $posOnPage % $cols;
            $row = intdiv($posOnPage, $cols);
            $x = $marginX + $col * ($labelW + $gapX);
            $y = $marginY + $row * $labelH;

            // Text lines.
            $pdf->SetXY($x, $y + 0.6);
            $pdf->SetFont('helvetica', 'B', 6);
            $name = $this->truncate((string) $label['name'], 30);
            $pdf->Cell($labelW, 2.8, $name, 0, 2, 'C');

            $pdf->SetFont('helvetica', '', 5);
            $line2 = trim(($label['mfg'] ? $label['mfg'] . ' | ' : '') . 'B:' . ($label['batch'] ?: '-') . ' E:' . ($label['exp'] ?: '-'));
            $pdf->Cell($labelW, 2.4, $this->truncate($line2, 40), 0, 2, 'C');
            $pdf->Cell($labelW, 2.4, 'MRP ' . number_format((float) $label['mrp'], 2), 0, 2, 'C');

            // Barcode.
            $pdf->write1DBarcode((string) $label['code'], 'C128', $x + 4, $y + 9.5, $labelW - 8, 8.5, 0.4, $barStyle, 'N');

            $i++;
        }

        return $pdf->Output('barcodes.pdf', 'S');
    }

    private function truncate(string $s, int $len): string
    {
        return mb_strlen($s) > $len ? mb_substr($s, 0, $len - 1) . '…' : $s;
    }
}
