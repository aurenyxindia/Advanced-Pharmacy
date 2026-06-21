<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Models\Product;

/**
 * Parses supplier purchase invoices into draft purchase lines.
 *
 * Implemented now:
 *   - CSV / TSV upload (header-mapped)
 *
 * Extension points (require external credentials supplied later):
 *   - Gmail auto-detect of CSV/Excel attachments  -> fetchFromGmail()
 *   - PDF + OCR/AI extraction                       -> extractFromPdf()
 */
final class PurchaseImportService
{
    /** Canonical column => accepted header aliases (lowercased). */
    private const COLUMN_ALIASES = [
        'product_name'  => ['product', 'product_name', 'item', 'description', 'particulars'],
        'batch_no'      => ['batch', 'batch_no', 'batchno', 'lot'],
        'expiry_date'   => ['expiry', 'expiry_date', 'exp', 'exp_date'],
        'quantity'      => ['qty', 'quantity', 'units'],
        'free_quantity' => ['free', 'free_qty', 'scheme_qty', 'free_quantity'],
        'purchase_rate' => ['rate', 'purchase_rate', 'ptr', 'price', 'cost'],
        'mrp'           => ['mrp', 'm.r.p'],
        'discount_pct'  => ['discount', 'disc', 'disc_pct', 'discount_pct'],
        'gst_rate'      => ['gst', 'gst_rate', 'tax', 'gst%'],
    ];

    public function parseCsv(string $filePath): array
    {
        if (!is_readable($filePath)) {
            throw new HttpException('Uploaded file could not be read.', 400);
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new HttpException('Unable to open the uploaded file.', 400);
        }

        // Detect delimiter from the first line.
        $firstLine = fgets($handle) ?: '';
        $delimiter = substr_count($firstLine, "\t") > substr_count($firstLine, ',') ? "\t" : ',';
        rewind($handle);

        $header = fgetcsv($handle, 0, $delimiter);
        if ($header === false) {
            fclose($handle);
            throw new HttpException('The file appears to be empty.', 400);
        }
        $map = $this->mapHeader($header);

        $product = new Product();
        $rows = [];
        while (($cells = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($cells === [null] || $cells === []) {
                continue;
            }
            $line = $this->mapRow($cells, $map);
            if (($line['product_name'] ?? '') === '') {
                continue;
            }
            // Best-effort match to the master catalog.
            $matches = $product->search($line['product_name'], 1);
            if ($matches !== []) {
                $line['master_product_id'] = (int) $matches[0]['id'];
                $line['matched_name'] = $matches[0]['name'];
                if (empty($line['gst_rate'])) {
                    $line['gst_rate'] = (float) $matches[0]['default_gst_rate'];
                }
            }
            $rows[] = $line;
        }
        fclose($handle);

        return [
            'lines'       => $rows,
            'total_lines' => count($rows),
            'matched'     => count(array_filter($rows, static fn ($r) => isset($r['master_product_id']))),
        ];
    }

    private function mapHeader(array $header): array
    {
        $map = [];
        foreach ($header as $index => $name) {
            $key = strtolower(trim((string) $name));
            foreach (self::COLUMN_ALIASES as $canonical => $aliases) {
                if (in_array($key, $aliases, true)) {
                    $map[$canonical] = $index;
                    break;
                }
            }
        }
        return $map;
    }

    private function mapRow(array $cells, array $map): array
    {
        $get = static fn (string $key) => isset($map[$key]) ? trim((string) ($cells[$map[$key]] ?? '')) : '';

        return [
            'product_name'  => $get('product_name'),
            'batch_no'      => $get('batch_no'),
            'expiry_date'   => $this->normalizeExpiry($get('expiry_date')),
            'quantity'      => (int) $get('quantity'),
            'free_quantity' => (int) ($get('free_quantity') ?: 0),
            'purchase_rate' => (float) $get('purchase_rate'),
            'mrp'           => (float) $get('mrp'),
            'discount_pct'  => (float) ($get('discount_pct') ?: 0),
            'gst_rate'      => (float) ($get('gst_rate') ?: 0),
        ];
    }

    /** Accept MM/YYYY, MM-YYYY, YYYY-MM-DD; return YYYY-MM-DD (end of month for MM/YYYY). */
    private function normalizeExpiry(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('#^(\d{1,2})[/-](\d{2,4})$#', $raw, $m)) {
            $month = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $year = strlen($m[2]) === 2 ? '20' . $m[2] : $m[2];
            return date('Y-m-t', strtotime("$year-$month-01"));
        }
        $ts = strtotime($raw);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    /**
     * Placeholder: pull the latest CSV/Excel attachment from a connected Gmail
     * inbox. Requires Gmail API OAuth credentials (configured in a later phase).
     */
    public function fetchFromGmail(): array
    {
        throw new HttpException('Gmail import is not yet configured. Connect a Gmail account in Settings.', 501);
    }

    /**
     * Placeholder: OCR + AI extraction of a scanned/PDF invoice into draft lines.
     * Requires an OCR/AI provider key (configured in a later phase).
     */
    public function extractFromPdf(string $filePath): array
    {
        throw new HttpException('PDF/OCR extraction is not yet configured. Add an OCR provider key in Settings.', 501);
    }
}
