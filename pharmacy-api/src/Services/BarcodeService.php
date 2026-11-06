<?php

declare(strict_types=1);

namespace App\Services;

use TCPDF;

/**
 * Pharmacy strip barcode labels (Print All + thermal).
 *
 * Exact sticker size: **40 × 15 mm**.
 *
 * Layout:
 *   Top:    product name (left) · Rs.MRP (right)
 *   Sub:    medical / pharmacy name (small)
 *   Middle: Code-128 barcode
 *   Bottom: B.batch (left) · EXP mm/yy (right)
 *
 * A4 Print All: 5×15 grid of 40×15 mm cells.
 * Thermal roll: one 40×15 mm page per label (TSC TE 210 / TE 244).
 *
 * @param array<int, array{name:string, medical:?string, mfg:?string, batch:?string, exp:?string, mrp:float, code:string}> $labels
 */
final class BarcodeService
{
    private const LBL_W = 40.0;
    private const LBL_H = 15.0;
    private const COLS = 5;
    private const ROWS = 15;
    private const GAP_X = 2.0;
    private const GAP_Y = 3.0;
    private const MARGIN_Y = 10.0;

    private const THERMAL_W = 40.0;
    private const THERMAL_H = 15.0;

    public function render(array $labels): string
    {
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->SetCreator('Advanced Pharmacy Suite');
        $pdf->SetTitle('Barcode Labels 40x15mm');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetCellPadding(0);
        $pdf->setCellMargins(0, 0, 0, 0);

        $marginX = $this->sheetMarginX();
        $colStep = self::LBL_W + self::GAP_X;
        $rowStep = self::LBL_H + self::GAP_Y;
        $perPage = self::COLS * self::ROWS;
        $barStyle = $this->barStyle();

        $pdf->AddPage();
        foreach ($labels as $i => $label) {
            $posOnPage = $i % $perPage;
            if ($i > 0 && $posOnPage === 0) {
                $pdf->AddPage();
            }

            $col = $posOnPage % self::COLS;
            $row = intdiv($posOnPage, self::COLS);
            $x = $marginX + $col * $colStep;
            $y = self::MARGIN_Y + $row * $rowStep;

            $this->drawStickerLabel($pdf, $x, $y, self::LBL_W, self::LBL_H, $label, $barStyle, true);
        }

        return $pdf->Output('barcodes.pdf', 'S');
    }

    public function renderThermalRoll(array $labels): string
    {
        $pdf = new TCPDF('L', 'mm', [self::THERMAL_W, self::THERMAL_H], true, 'UTF-8', false);
        $pdf->SetCreator('Advanced Pharmacy Suite');
        $pdf->SetTitle('Label Barcodes 40x15mm');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetCellPadding(0);
        $pdf->setCellMargins(0, 0, 0, 0);

        $barStyle = $this->barStyle();

        foreach ($labels as $label) {
            $pdf->AddPage('L', [self::THERMAL_W, self::THERMAL_H]);
            $pageW = (float) $pdf->getPageWidth();
            $pageH = (float) $pdf->getPageHeight();
            if ($pageW < $pageH) {
                $pageW = self::THERMAL_W;
                $pageH = self::THERMAL_H;
            }
            $this->drawStickerLabel($pdf, 0.0, 0.0, $pageW, $pageH, $label, $barStyle, false);
        }

        return $pdf->Output('label-barcodes.pdf', 'S');
    }

    /** @return array<string, mixed> */
    private function barStyle(): array
    {
        return [
            'position'     => '',
            'border'       => false,
            'padding'      => 0,
            'hpadding'     => 0,
            'vpadding'     => 0,
            'fgcolor'      => [0, 0, 0],
            'bgcolor'      => false,
            'text'         => false,
            'font'         => 'helvetica',
            'fontsize'     => 6,
            'stretchtext'  => 0,
            'stretch'      => false,
            'fitwidth'     => true,
            'cellfitalign' => 'C',
        ];
    }

    private function sheetMarginX(): float
    {
        $gridW = self::COLS * self::LBL_W + (self::COLS - 1) * self::GAP_X;

        return max(0.0, (210.0 - $gridW) / 2);
    }

    /**
     * @param array{name:string, medical?:?string, mfg?:?string, batch?:?string, exp?:?string, mrp:float, code:string} $label
     */
    private function drawStickerLabel(
        TCPDF $pdf,
        float $x,
        float $y,
        float $w,
        float $h,
        array $label,
        array $barStyle,
        bool $cutGuide
    ): void {
        if ($cutGuide) {
            $pdf->SetDrawColor(200, 205, 210);
            $pdf->SetLineWidth(0.15);
            $pdf->SetLineStyle(['width' => 0.15, 'dash' => '1,1.2', 'color' => [200, 205, 210]]);
            $pdf->Rect($x, $y, $w, $h, 'D');
            $pdf->SetLineStyle(['width' => 0.2, 'dash' => 0, 'color' => [0, 0, 0]]);
            $pdf->SetDrawColor(0, 0, 0);
        }

        $padX = 0.7;
        $padTop = 0.35;
        $padBot = 0.3;
        $innerW = $w - ($padX * 2);
        $left = $x + $padX;

        $name = strtoupper(trim((string) ($label['name'] ?? '')));
        $medical = trim((string) ($label['medical'] ?? ''));
        $batch = trim((string) ($label['batch'] ?? '')) ?: '-';
        $exp = trim((string) ($label['exp'] ?? '')) ?: '-';
        $mrpText = $this->formatMrp((float) ($label['mrp'] ?? 0));

        $titleSize = 5.8;
        $metaSize = 4.3;
        $medSize = 3.5;

        $topBand = 2.25;
        $medBand = 1.25;
        $botBand = 1.85;
        $gapAfterTop = 0.12;
        $gapBeforeBot = 0.15;

        $topY = $y + $padTop;
        $medY = $topY + $topBand + $gapAfterTop;
        $botY = $y + $h - $padBot - $botBand;
        $barTop = $medY + ($medical !== '' ? $medBand : 0.08) + 0.08;
        $barH = max(5.0, $botY - $gapBeforeBot - $barTop);

        $mrpW = $this->mrpColumnWidth($pdf, $mrpText, $titleSize, $innerW);
        $nameW = max(12.0, $innerW - $mrpW - 0.4);

        $pdf->SetXY($left, $topY);
        $pdf->SetFont('helvetica', 'B', $titleSize);
        $pdf->Cell($nameW, $topBand, $this->fitText($pdf, $name, $nameW, $titleSize, true), 0, 0, 'L', false, '', 0, false, 'T', 'M');

        $pdf->SetXY($left + $nameW + 0.4, $topY);
        $pdf->SetFont('helvetica', 'B', $titleSize);
        $pdf->Cell($mrpW, $topBand, $mrpText, 0, 0, 'R', false, '', 0, false, 'T', 'M');

        if ($medical !== '') {
            $pdf->SetXY($left, $medY);
            $pdf->SetTextColor(55, 65, 80);
            $pdf->SetFont('helvetica', '', $medSize);
            $pdf->Cell($innerW, $medBand, $this->fitText($pdf, $medical, $innerW, $medSize, false), 0, 0, 'L', false, '', 0, false, 'T', 'M');
            $pdf->SetTextColor(0, 0, 0);
        }

        $pdf->write1DBarcode(
            (string) $label['code'],
            'C128',
            $left,
            $barTop,
            $innerW,
            $barH,
            0.28,
            $barStyle,
            'N'
        );

        $half = $innerW / 2;
        $pdf->SetXY($left, $botY);
        $pdf->SetFont('helvetica', '', $metaSize);
        $pdf->Cell($half - 0.25, $botBand, $this->fitText($pdf, 'B.' . $batch, $half - 0.25, $metaSize, false), 0, 0, 'L', false, '', 0, false, 'T', 'M');

        $pdf->SetXY($left + $half + 0.25, $botY);
        $pdf->SetFont('helvetica', '', $metaSize);
        $pdf->Cell($half - 0.25, $botBand, $this->fitText($pdf, 'EXP ' . $exp, $half - 0.25, $metaSize, false), 0, 0, 'R', false, '', 0, false, 'T', 'M');
    }

    private function formatMrp(float $mrp): string
    {
        if ($mrp <= 0) {
            return '';
        }
        if (abs($mrp - round($mrp)) < 0.001) {
            return 'Rs.' . (string) (int) round($mrp);
        }

        return 'Rs.' . number_format($mrp, 2);
    }

    private function mrpColumnWidth(TCPDF $pdf, string $mrpText, float $fontSize, float $innerW): float
    {
        if ($mrpText === '') {
            return 0.0;
        }
        $pdf->SetFont('helvetica', 'B', $fontSize);
        $need = $pdf->GetStringWidth($mrpText) + 0.8;

        return min(max(7.0, $need), $innerW * 0.30);
    }

    private function fitText(TCPDF $pdf, string $text, float $maxW, float $fontSize, bool $bold): string
    {
        $text = trim($text);
        if ($text === '' || $maxW <= 1) {
            return '';
        }
        $pdf->SetFont('helvetica', $bold ? 'B' : '', $fontSize);
        if ($pdf->GetStringWidth($text) <= $maxW) {
            return $text;
        }
        $ellipsis = '…';
        $len = mb_strlen($text);
        while ($len > 1) {
            $len--;
            $candidate = mb_substr($text, 0, $len) . $ellipsis;
            if ($pdf->GetStringWidth($candidate) <= $maxW) {
                return $candidate;
            }
        }

        return $ellipsis;
    }
}
