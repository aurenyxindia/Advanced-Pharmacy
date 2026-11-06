<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Database\Connection;
use App\Models\Product;
use App\Support\PackSize;

/**
 * Parses supplier purchase invoices into draft purchase lines.
 * Supports named ERP formats (PurchaseFormatParser) and generic header aliases.
 */
final class PurchaseImportService
{
    /** Hard cap so huge files fail clearly instead of exhausting memory/time. */
    private const MAX_LINES = 5000;

    /** Canonical column => accepted header aliases (lowercased). */
    private const COLUMN_ALIASES = [
        'product_name'   => ['product', 'product_name', 'product name', 'item', 'description', 'particulars'],
        'batch_no'       => ['batch', 'batch_no', 'batch no', 'batchno', 'lot'],
        'expiry_date'    => ['expiry', 'expiry_date', 'exp', 'exp_date', 'exp. dt.', 'exp dt', 'expiry date'],
        'quantity'       => ['qty', 'qty.', 'quantity', 'units'],
        'free_quantity'  => ['free', 'free_qty', 'free qty', 'free qty.', 'scheme_qty', 'free_quantity', 'scheme'],
        'purchase_rate'  => ['rate', 'purchase_rate', 'price', 'cost', 'pur rate', 'pur.rate'],
        'mrp'            => ['mrp', 'm.r.p'],
        'ptr'            => ['ptr'],
        'pts'            => ['pts'],
        'discount_pct'   => ['discount', 'disc', 'disc %', 'disc.%', 'disc_pct', 'discount_pct', 'disc. %'],
        'discount_amt'   => ['disc_amt', 'disc amt', 'disc. amt.', 'discount_amt', 'discount amount'],
        'gross_amount'   => ['gross', 'gross_amt', 'gross amt', 'gross amt.', 'totalamt', 'total amt', 'total amount', 'amount'],
        'cd_amt'         => ['cd', 'cd_amt', 'cd amt', 'cd amt.', 'prodcdamt', 'cash discount'],
        'gst_rate'       => ['gst', 'gst_rate', 'gst %', 'gst%', 'tax'],
        'pack'           => ['pack', 'pack_size', 'pack size', 'packing', 'packsize'],
        'hsn_code'       => ['hsn', 'hsn_code', 'hsncode'],
        'mfg_name'       => ['mfg', 'mfg_name', 'mfg name', 'mfg name.', 'manufacturer', 'm_name', 'm_namecsv'],
    ];

    private PurchaseFormatParser $formats;

    /** When set, product matching uses only this client's own catalogue. */
    private ?int $ownProductClientId = null;

    public function __construct(?PurchaseFormatParser $formats = null)
    {
        $this->formats = $formats ?? new PurchaseFormatParser();
    }

    /** Apply Hide Master Catalogue (products) for this client before parse/match. */
    public function withCatalogPolicy(int $clientId): self
    {
        $this->ownProductClientId = ($clientId > 0 && (new ClientCatalogPolicy())->hideProducts($clientId))
            ? $clientId
            : null;
        return $this;
    }

    /** @return list<array{key:string,label:string}> */
    public function listFormats(): array
    {
        return $this->formats->listFormats();
    }

    /**
     * Parse uploaded CSV/TSV (or raw text) with optional ERP format key.
     *
     * @return array{lines: list<array<string,mixed>>, total_lines: int, matched: int, format: string}
     */
    public function parse(string $filePathOrText, string $format = 'Generic', bool $isRawText = false, bool $allowAiFallback = true): array
    {
        if ($isRawText) {
            \App\Support\UploadGuard::assertContentLength(strlen($filePathOrText), 'csv_text');
            $text = $filePathOrText;
        } else {
            $size = is_file($filePathOrText) ? (int) filesize($filePathOrText) : 0;
            \App\Support\UploadGuard::assertContentLength($size);
            $text = $this->readFile($filePathOrText);
        }
        $requested = $format;
        $ai = new AiBillExtractService();

        // Medivision Sale_*.csv H/T dumps: always use structured parser (AI mis-maps qty/rate/CD).
        if (
            $this->formats->looksLikeMedivisionHt($text)
            && in_array($requested, ['Auto', 'AI', 'Generic', 'Medivision', ''], true)
        ) {
            $header = $this->formats->extractHeader($text, 'Medivision');
            $rawRows = $this->formats->parseText($text, 'Medivision');
            if ($rawRows !== []) {
                $this->assertLineCap(count($rawRows));
                $lines = $this->enrichLines($rawRows);
                $lines = array_map(fn (array $l) => $this->computeLineAmounts($l), $lines);
                $lines = $this->reconcileMedivisionBill($lines, $header);
                unset($text, $rawRows);
                return [
                    'lines'            => $lines,
                    'total_lines'      => count($lines),
                    'matched'          => count(array_filter($lines, static fn ($r) => !empty($r['master_product_id']))),
                    'format'           => 'Medivision',
                    'format_requested' => $requested !== '' ? $requested : 'Auto',
                    'header'           => $header,
                ];
            }
        }

        // Explicit AI CSV capture (OpenAI maps every column; amounts recalculated exactly after).
        // If OpenAI fails (quota/timeout/5xx), fall back to structured ERP detect (e.g. Medivision).
        if ($format === 'AI') {
            if (!$ai->isConfigured()) {
                throw new HttpException('OpenAI is not configured. Set OPENAI_API_KEY in pharmacy-api/config/.env.', 501);
            }
            try {
                $result = $ai->extractFromCsvText($text);
                unset($text);
                return $result;
            } catch (HttpException $e) {
                $detected = $this->formats->detectFormat($text);
                if ($detected === 'Generic') {
                    throw $e;
                }
                // Structured fallback — still capture columns + exact amounts
                $header = $this->formats->extractHeader($text, $detected);
                $rawRows = $this->formats->parseText($text, $detected);
                $lines = $this->enrichLines($rawRows);
                $lines = array_map(fn (array $l) => $this->computeLineAmounts($l), $lines);
                unset($text);
                if ($lines === []) {
                    throw $e;
                }
                return [
                    'lines'            => $lines,
                    'total_lines'      => count($lines),
                    'matched'          => count(array_filter($lines, static fn ($r) => !empty($r['master_product_id']))),
                    'format'           => $detected,
                    'format_requested' => 'AI',
                    'ai_fallback'      => true,
                    'ai_error'         => $e->getMessage(),
                    'header'           => $header,
                ];
            }
        }

        if ($format === 'Auto' || !$this->formats->hasFormat($format)) {
            $format = $this->formats->detectFormat($text);
        }

        $header = $this->formats->extractHeader($text, $format);
        $lines = [];

        if ($format !== 'Generic') {
            $rawRows = $this->formats->parseText($text, $format);
            $this->assertLineCap(count($rawRows));
            $lines = $this->enrichLines($rawRows);
            unset($rawRows);
        } else {
            $lines = $this->parseGenericText($text);
            if ($lines === []) {
                $detected = $this->formats->detectFormat($text);
                if ($detected !== 'Generic') {
                    $format = $detected;
                    $header = $this->formats->extractHeader($text, $format);
                    $rawRows = $this->formats->parseText($text, $format);
                    $lines = $this->enrichLines($rawRows);
                }
            }
        }

        // Auto / unknown CSV with no lines → OpenAI column capture
        if (
            $lines === []
            && $allowAiFallback
            && $ai->isConfigured()
            && in_array($requested, ['Auto', 'Generic', ''], true)
        ) {
            $result = $ai->extractFromCsvText($text);
            unset($text);
            return $result;
        }
        unset($text);

        if ($lines === []) {
            throw new HttpException(
                'No product lines found. Try ERP format “Medivision”, “Auto-detect”, or “AI (OpenAI)”.',
                422
            );
        }

        // Exact retail recalculation on every line (never trust CSV gross if qty×rate differs)
        $lines = array_map(fn (array $l) => $this->computeLineAmounts($l), $lines);

        return [
            'lines'       => $lines,
            'total_lines' => count($lines),
            'matched'     => count(array_filter($lines, static fn ($r) => !empty($r['master_product_id']))),
            'format'      => $format,
            'format_requested' => $requested,
            'header'      => $header,
        ];
    }

    private function assertLineCap(int $count): void
    {
        if ($count > self::MAX_LINES) {
            throw new HttpException(
                'This file has too many product lines (max ' . self::MAX_LINES . '). Split the bill and import in parts.',
                400
            );
        }
    }

    /** Backward-compatible CSV path helper. */
    public function parseCsv(string $filePath): array
    {
        return $this->parse($filePath, 'Generic', false);
    }

    /**
     * Re-match a product name (or rematch an array of line names).
     *
     * @param list<string>|null $names
     * @return array{matches?: list<array<string,mixed>>, lines?: list<array<string,mixed>>}
     */
    public function matchProducts(?string $query = null, ?array $names = null, int $limit = 20, ?int $ownClientId = null): array
    {
        $product = new Product();
        $limit = max(1, min(50, $limit));

        if ($query !== null && trim($query) !== '') {
            $rows = $product->search(trim($query), $limit, $ownClientId);
            return [
                'matches' => array_map(static fn ($r) => [
                    'id'               => (int) $r['id'],
                    'name'             => $r['name'],
                    'manufacturer'     => $r['manufacturer'] ?? null,
                    'default_gst_rate' => (float) ($r['default_gst_rate'] ?? 0),
                    'hsn_code'         => $r['hsn_code'] ?? null,
                    'pack_size'        => $r['pack_size'] ?? null,
                ], $rows),
            ];
        }

        $names = $names ?? [];
        // Deduplicate so 50 identical CSV lines = 1 search.
        $unique = [];
        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $key = mb_strtolower($name);
            if (!isset($unique[$key])) {
                $unique[$key] = $name;
            }
        }

        $byName = [];
        foreach ($unique as $key => $name) {
            $hits = $product->search($name, $limit, $ownClientId);
            $byName[$key] = array_map(static fn ($r) => [
                'id'               => (int) $r['id'],
                'name'             => $r['name'],
                'manufacturer'     => $r['manufacturer'] ?? null,
                'default_gst_rate' => (float) ($r['default_gst_rate'] ?? 0),
                'hsn_code'         => $r['hsn_code'] ?? null,
                'pack_size'        => $r['pack_size'] ?? null,
            ], $hits);
        }

        // Legacy shape (1 best hit per input name, same order as request).
        $out = [];
        foreach ($names as $name) {
            $name = trim((string) $name);
            $key = mb_strtolower($name);
            $hits = $byName[$key] ?? [];
            $hit = $hits[0] ?? null;
            $out[] = [
                'product_name'      => $name,
                'master_product_id' => $hit ? (int) $hit['id'] : null,
                'matched_name'      => $hit['name'] ?? null,
                'default_gst_rate'  => $hit['default_gst_rate'] ?? null,
                'matches'           => $hits,
            ];
        }

        return ['lines' => $out, 'by_name' => $byName];
    }

    /** Check if invoice_no already exists for this client (any branch). */
    public function checkInvoice(int $clientId, string $invoiceNo): array
    {
        $invoiceNo = trim($invoiceNo);
        if ($invoiceNo === '') {
            return ['exists' => false, 'purchase' => null];
        }
        $db = Connection::get('ops');
        $stmt = $db->prepare(
            'SELECT id, po_no, invoice_no, invoice_date, supplier_name, branch_id, net_amount
             FROM purchases
             WHERE client_id = :cid AND invoice_no = :inv
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['cid' => $clientId, 'inv' => $invoiceNo]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        return [
            'exists'   => $row !== null,
            'purchase' => $row,
        ];
    }

    private function readFile(string $filePath): string
    {
        if (!is_readable($filePath)) {
            throw new HttpException('Uploaded file could not be read.', 400);
        }
        $text = file_get_contents($filePath);
        if ($text === false) {
            throw new HttpException('Unable to open the uploaded file.', 400);
        }
        // Strip UTF-8 BOM
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        return $text;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseGenericText(string $csvText): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $csvText) ?: [];
        $lines = array_values(array_filter($lines, static fn ($l) => trim((string) $l) !== ''));
        if ($lines === []) {
            throw new HttpException('The file appears to be empty.', 400);
        }

        $firstLine = $lines[0];
        $delimiter = substr_count($firstLine, "\t") > substr_count($firstLine, ',') ? "\t" : ',';
        $header = str_getcsv($firstLine, $delimiter, '"', '\\');
        $map = $this->mapHeader($header ?: []);

        $rows = [];
        for ($i = 1, $n = count($lines); $i < $n; $i++) {
            $cells = str_getcsv($lines[$i], $delimiter, '"', '\\');
            if ($cells === [null] || $cells === []) {
                continue;
            }
            $line = $this->mapRow($cells, $map);
            if (($line['product_name'] ?? '') === '') {
                continue;
            }
            $rows[] = $line;
            if (count($rows) > self::MAX_LINES) {
                $this->assertLineCap(count($rows));
            }
        }

        return $this->enrichLines($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function enrichLines(array $rows): array
    {
        $product = new Product();
        $out = [];
        foreach ($rows as $line) {
            $packRaw = (string) ($line['pack'] ?? $line['pack_raw'] ?? '');
            if ($packRaw !== '' && empty($line['pack_size'])) {
                $pack = PackSize::parse($packRaw);
                $line['pack_raw'] = $packRaw;
                $line['pack_size'] = $pack['pack_size'];
                $line['units_per_pack'] = $pack['units_per_pack'];
            } else {
                $line['pack_raw'] = $packRaw;
                $line['pack_size'] = $line['pack_size'] ?? ($packRaw !== '' ? $packRaw : null);
                $line['units_per_pack'] = max(1, (int) ($line['units_per_pack'] ?? 1));
            }

            $name = trim((string) ($line['product_name'] ?? ''));
            $line['product_name'] = $name;
            $line['quantity'] = (int) ($line['quantity'] ?? 0);
            $line['free_quantity'] = (int) ($line['free_quantity'] ?? 0);
            $line['purchase_rate'] = (float) ($line['purchase_rate'] ?? 0);
            $line['mrp'] = (float) ($line['mrp'] ?? 0);
            $line['ptr'] = (float) ($line['ptr'] ?? 0);
            $line['pts'] = (float) ($line['pts'] ?? 0);
            $line['discount_pct'] = (float) ($line['discount_pct'] ?? 0);
            $line['discount_amt'] = (float) ($line['discount_amt'] ?? 0);
            $line['gross_amount'] = (float) ($line['gross_amount'] ?? 0);
            $line['cd_amt'] = (float) ($line['cd_amt'] ?? 0);
            $line['gst_rate'] = (float) ($line['gst_rate'] ?? 0);
            $line['batch_no'] = trim((string) ($line['batch_no'] ?? ''));
            $line['hsn_code'] = trim((string) ($line['hsn_code'] ?? ''));
            $line['mfg_name'] = trim((string) ($line['mfg_name'] ?? $line['manufacturer_short'] ?? ''));
            $line['manufacturer_short'] = $line['mfg_name'];
            if (isset($line['expiry_date']) && is_string($line['expiry_date'])) {
                $line['expiry_date'] = $this->normalizeExpiry($line['expiry_date']);
            }

            if ($name !== '') {
                $matches = $product->search($name, 1, $this->ownProductClientId);
                if ($matches !== []) {
                    $line['master_product_id'] = (int) $matches[0]['id'];
                    $line['matched_name'] = $matches[0]['name'];
                    if (empty($line['gst_rate'])) {
                        $line['gst_rate'] = (float) ($matches[0]['default_gst_rate'] ?? 0);
                    }
                    if ($line['hsn_code'] === '' && !empty($matches[0]['hsn_code'])) {
                        $line['hsn_code'] = (string) $matches[0]['hsn_code'];
                    }
                    if ($line['mfg_name'] === '' && !empty($matches[0]['manufacturer'])) {
                        $line['mfg_name'] = (string) $matches[0]['manufacturer'];
                        $line['manufacturer_short'] = $line['mfg_name'];
                    }
                    if ($line['ptr'] <= 0 && isset($matches[0]['ptr'])) {
                        $line['ptr'] = (float) $matches[0]['ptr'];
                    }
                    if ($line['pts'] <= 0 && isset($matches[0]['pts'])) {
                        $line['pts'] = (float) $matches[0]['pts'];
                    }
                } else {
                    $line['master_product_id'] = null;
                    $line['matched_name'] = null;
                }
            }

            $line = $this->computeLineAmounts($line);
            $out[] = $line;
        }
        return $out;
    }

    /**
     * Exact retail upload-purchase calculateTaxable():
     * gross = qty × rate
     * disc_amt = gross × disc% / 100  (or derive disc% from CSV disc_amt)
     * cd_amt = (gross − disc_amt) × cd% / 100
     * taxable = gross − disc_amt − cd_amt
     * gst_amt = taxable × gst% / 100
     * net = taxable + gst_amt
     *
     * @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    public function computeLineAmounts(array $line, float $billCdPct = 0.0): array
    {
        $qty = (float) ($line['quantity'] ?? 0);
        $rate = (float) ($line['purchase_rate'] ?? 0);
        $discPct = (float) ($line['discount_pct'] ?? 0);
        $gst = (float) ($line['gst_rate'] ?? 0);
        $csvDiscAmt = (float) ($line['discount_amt'] ?? 0);
        $csvGross = (float) ($line['gross_amount'] ?? 0);

        // Prefer printed line amount when it reconciles with qty×rate (Medivision Sale dumps).
        $grossFromQr = round($qty * $rate, 2);
        if ($csvGross > 0 && $grossFromQr > 0 && abs($csvGross - $grossFromQr) <= max(0.05, $csvGross * 0.01)) {
            $gross = round($csvGross, 2);
        } else {
            $gross = $grossFromQr;
        }

        if ($discPct <= 0 && $csvDiscAmt > 0 && $gross > 0) {
            $discPct = round(($csvDiscAmt * 100) / $gross, 4);
        }
        // Prefer absolute CSV discount when present (avoids % rounding drift on Medivision amounts)
        if ($csvDiscAmt > 0 && abs(($gross * $discPct / 100) - $csvDiscAmt) <= max(0.05, $csvDiscAmt * 0.02)) {
            $discAmt = round($csvDiscAmt, 2);
            if ($gross > 0) {
                $discPct = round(($discAmt * 100) / $gross, 4);
            }
        } else {
            $discAmt = round(($gross * $discPct) / 100, 2);
        }

        $cdPct = $billCdPct > 0 ? $billCdPct : 0.0;
        // Retail: CD only from bill CD%. Never keep AI/CSV cd_amt when CD% is 0.
        $cdAmt = $cdPct > 0
            ? round((($gross - $discAmt) * $cdPct) / 100, 2)
            : 0.0;

        $taxable = round(max(0, $gross - $discAmt - $cdAmt), 2);
        $gstAmt = round(($taxable * $gst) / 100, 2);
        $netAmt = round($taxable + $gstAmt, 2);

        $line['quantity'] = (int) round($qty);
        $line['purchase_rate'] = $rate;
        $line['gross_amount'] = $gross;
        $line['discount_pct'] = $discPct;
        $line['discount_amt'] = $discAmt;
        $line['cd_amt'] = $cdAmt;
        $line['taxable_amount'] = $taxable;
        $line['gst_amount'] = $gstAmt;
        $line['net_amount'] = $netAmt;
        return $line;
    }

    /**
     * Align line totals to Medivision / PDF bill footer:
     * Gross − (line discounts + H extra Less) + Add(GST) (+ round) = NET.
     * Sale_54127: 8938.69 − 1538.94 + 370 (+0.25) = 7770.
     *
     * @param list<array<string,mixed>> $lines
     * @param array<string,mixed> $header
     * @return list<array<string,mixed>>
     */
    public function reconcileMedivisionBill(array $lines, array $header): array
    {
        if ($lines === []) {
            return $lines;
        }

        $extraLess = round((float) ($header['bill_extra_less'] ?? 0), 2);
        $billAdd = round((float) ($header['bill_add'] ?? 0), 2);
        $billNet = round((float) ($header['bill_net'] ?? 0), 2);
        $billRound = round((float) ($header['bill_round'] ?? 0), 2);

        $grossSum = 0.0;
        foreach ($lines as $l) {
            $grossSum += (float) ($l['gross_amount'] ?? 0);
        }
        $grossSum = round($grossSum, 2);

        // 1) Allocate H-row extra Less across lines by gross weight → discount
        if ($extraLess > 0 && $grossSum > 0) {
            $allocated = 0.0;
            $last = count($lines) - 1;
            foreach ($lines as $i => &$line) {
                $g = (float) ($line['gross_amount'] ?? 0);
                if ($i === $last) {
                    $share = round($extraLess - $allocated, 2);
                } else {
                    $share = round($extraLess * ($g / $grossSum), 2);
                    $allocated += $share;
                }
                $line['discount_amt'] = round((float) ($line['discount_amt'] ?? 0) + $share, 2);
                $line['cd_amt'] = 0.0;
                if ($g > 0) {
                    $line['discount_pct'] = round(((float) $line['discount_amt'] * 100) / $g, 4);
                }
                $line = $this->computeLineAmounts($line, 0.0);
            }
            unset($line);
        }

        // 2) Force GST total to PDF "Add" when present (usually bill GST)
        $gstSum = 0.0;
        $taxableSum = 0.0;
        foreach ($lines as $l) {
            $gstSum += (float) ($l['gst_amount'] ?? 0);
            $taxableSum += (float) ($l['taxable_amount'] ?? 0);
        }
        $gstSum = round($gstSum, 2);
        $taxableSum = round($taxableSum, 2);

        if ($billAdd > 0 && $gstSum > 0 && abs($billAdd - $gstSum) >= 0.01) {
            $scale = $billAdd / $gstSum;
            $allocatedGst = 0.0;
            $last = count($lines) - 1;
            foreach ($lines as $i => &$line) {
                if ($i === $last) {
                    $gstAmt = round($billAdd - $allocatedGst, 2);
                } else {
                    $gstAmt = round((float) ($line['gst_amount'] ?? 0) * $scale, 2);
                    $allocatedGst += $gstAmt;
                }
                $taxable = (float) ($line['taxable_amount'] ?? 0);
                $line['gst_amount'] = $gstAmt;
                $line['net_amount'] = round($taxable + $gstAmt, 2);
                if ($taxable > 0 && $gstAmt > 0) {
                    $line['gst_rate'] = round(($gstAmt * 100) / $taxable, 2);
                }
            }
            unset($line);
        } elseif ($billAdd > 0 && $gstSum <= 0 && $taxableSum > 0) {
            // No line GST computed — spread bill Add by taxable weight
            $allocatedGst = 0.0;
            $last = count($lines) - 1;
            foreach ($lines as $i => &$line) {
                $taxable = (float) ($line['taxable_amount'] ?? 0);
                if ($i === $last) {
                    $gstAmt = round($billAdd - $allocatedGst, 2);
                } else {
                    $gstAmt = round($billAdd * ($taxable / $taxableSum), 2);
                    $allocatedGst += $gstAmt;
                }
                $line['gst_amount'] = $gstAmt;
                $line['net_amount'] = round($taxable + $gstAmt, 2);
                if ($taxable > 0) {
                    $line['gst_rate'] = round(($gstAmt * 100) / $taxable, 2);
                }
            }
            unset($line);
        } elseif ($billAdd > 0 && abs($billAdd - $gstSum) >= 0.005 && $gstSum > 0) {
            // Snap residual paise onto last line GST
            $last = count($lines) - 1;
            $delta = round($billAdd - $gstSum, 2);
            $lines[$last]['gst_amount'] = round((float) $lines[$last]['gst_amount'] + $delta, 2);
            $taxable = (float) ($lines[$last]['taxable_amount'] ?? 0);
            $lines[$last]['net_amount'] = round($taxable + (float) $lines[$last]['gst_amount'], 2);
        }

        // 3) Match printed NET (includes round-off on last line net only)
        if ($billNet > 0) {
            $netSum = 0.0;
            foreach ($lines as $l) {
                $netSum += (float) ($l['net_amount'] ?? 0);
            }
            $netSum = round($netSum, 2);
            $delta = round($billNet - $netSum, 2);
            if (abs($delta) >= 0.01) {
                $last = count($lines) - 1;
                $lines[$last]['net_amount'] = round((float) $lines[$last]['net_amount'] + $delta, 2);
            }
        }

        return $lines;
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

        $packRaw = $get('pack');
        $pack = PackSize::parse($packRaw);

        $mfg = $get('mfg_name');
        return [
            'product_name'   => $get('product_name'),
            'batch_no'       => $get('batch_no'),
            'expiry_date'    => $this->normalizeExpiry($get('expiry_date')),
            'pack_raw'       => $packRaw,
            'pack_size'      => $pack['pack_size'] ?: $packRaw,
            'units_per_pack' => $pack['units_per_pack'],
            'quantity'       => (int) $get('quantity'),
            'free_quantity'  => (int) ($get('free_quantity') ?: 0),
            'purchase_rate'  => (float) $get('purchase_rate'),
            'mrp'            => (float) $get('mrp'),
            'ptr'            => (float) ($get('ptr') ?: 0),
            'pts'            => (float) ($get('pts') ?: 0),
            'discount_pct'   => (float) ($get('discount_pct') ?: 0),
            'discount_amt'   => (float) ($get('discount_amt') ?: 0),
            'gross_amount'   => (float) ($get('gross_amount') ?: 0),
            'cd_amt'         => (float) ($get('cd_amt') ?: 0),
            'gst_rate'       => (float) ($get('gst_rate') ?: 0),
            'hsn_code'       => $get('hsn_code') ?: null,
            'mfg_name'       => $mfg,
            'manufacturer_short' => $mfg,
        ];
    }

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
        if (preg_match('#^(\d{4})-(\d{2})$#', $raw, $m)) {
            return date('Y-m-t', strtotime("{$m[1]}-{$m[2]}-01"));
        }
        $ts = strtotime($raw);
        return $ts ? date('Y-m-d', $ts) : null;
    }
}
