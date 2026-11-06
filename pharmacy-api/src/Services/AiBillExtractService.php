<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Support\Env;
use App\Support\UploadGuard;

/**
 * AI extraction of pharmacy purchase invoices — OpenAI (primary) / Gemini (fallback).
 * Prompts + row normalization ported from retail import-bill for accurate purchase lines.
 */
final class AiBillExtractService
{
    public function isConfigured(): bool
    {
        return $this->openaiKey() !== '' || $this->geminiKey() !== '';
    }

    public function provider(): string
    {
        $forced = strtolower(trim((string) Env::get('AI_PROVIDER', '')));
        if (in_array($forced, ['openai', 'gemini'], true)) {
            if ($forced === 'openai' && $this->openaiKey() !== '') {
                return 'openai';
            }
            if ($forced === 'gemini' && $this->geminiKey() !== '') {
                return 'gemini';
            }
        }
        if ($this->openaiKey() !== '') {
            return 'openai';
        }
        if ($this->geminiKey() !== '') {
            return 'gemini';
        }
        return 'none';
    }

    /**
     * @return array{configured:bool, provider:string, message:?string}
     */
    public function status(): array
    {
        if (!$this->isConfigured()) {
            return [
                'configured' => false,
                'provider'   => 'none',
                'message'    => 'Set OPENAI_API_KEY (or AI_API_KEY / GEMINI_API_KEY) in pharmacy-api/config/.env.',
            ];
        }
        return [
            'configured' => true,
            'provider'   => $this->provider(),
            'message'    => null,
        ];
    }

    /**
     * Extract from a single uploaded PDF/image file path.
     *
     * @return array{lines: list<array<string,mixed>>, total_lines: int, matched: int, format: string, header: array<string,mixed>}
     */
    public function extractFromFile(string $filePath, string $mimeType): array
    {
        $this->assertReady();
        UploadGuard::boostRuntime();
        if (!is_readable($filePath)) {
            throw new HttpException('Uploaded file could not be read.', 400);
        }
        $size = (int) filesize($filePath);
        UploadGuard::assertContentLength($size);

        $mimeType = $this->resolveMime($filePath, $mimeType);
        $bytes = file_get_contents($filePath);
        if ($bytes === false || $bytes === '') {
            throw new HttpException('Uploaded file is empty.', 400);
        }
        $b64 = base64_encode($bytes);
        unset($bytes);

        // OpenAI vision needs images; PDFs must be converted client-side (or Gemini can take PDF).
        if ($mimeType === 'application/pdf' && $this->provider() === 'openai') {
            throw new HttpException(
                'For OpenAI, PDF pages are converted to images in the browser. Please re-upload; if this persists, use JPG/PNG.',
                400
            );
        }

        try {
            $json = $this->extractFromImages([['base64' => $b64, 'mimeType' => $mimeType]]);
        } finally {
            unset($b64);
        }

        return $this->toDraft($json);
    }

    /**
     * Parse distributor CSV/TSV text with OpenAI — capture every product column exactly,
     * then recalculate gross/net with retail formulas (never trust AI for arithmetic).
     *
     * @return array{lines: list<array<string,mixed>>, total_lines: int, matched: int, format: string, header: array<string,mixed>}
     */
    public function extractFromCsvText(string $csvText): array
    {
        $this->assertReady();
        UploadGuard::boostRuntime();
        UploadGuard::assertContentLength(strlen($csvText), 'csv_text');

        $csvText = trim($csvText);
        if ($csvText === '') {
            throw new HttpException('CSV content is empty.', 400);
        }

        // Cap payload size for the model (keep head + body sample)
        $lines = preg_split('/\r\n|\n|\r/', $csvText) ?: [];
        if (count($lines) > 250) {
            $lines = array_slice($lines, 0, 250);
            $csvText = implode("\n", $lines) . "\n...[truncated for AI; first 250 rows sent]...\n";
        }
        if (strlen($csvText) > 120000) {
            $csvText = substr($csvText, 0, 120000) . "\n...[truncated]...\n";
        }

        $json = $this->callOpenAIForCsv($csvText);
        $draft = $this->toDraft($json);
        $import = new PurchaseImportService();

        // Force exact amount recalculation on every line
        $draft['lines'] = array_map(
            static fn (array $l) => $import->computeLineAmounts($l, 0.0),
            $draft['lines']
        );
        $draft['matched'] = count(array_filter($draft['lines'], static fn ($r) => !empty($r['master_product_id'])));
        $draft['total_lines'] = count($draft['lines']);
        $draft['format'] = 'AI-CSV';
        $draft['provider'] = $this->provider();
        return $draft;
    }

    /**
     * Extract from one or more page images (retail multi-page flow).
     *
     * @param list<array{base64:string,mimeType?:string}> $images
     * @return array{lines: list<array<string,mixed>>, total_lines: int, matched: int, format: string, header: array<string,mixed>}
     */
    public function extractFromImagePayloads(array $images): array
    {
        $this->assertReady();
        UploadGuard::boostRuntime();

        $normalized = [];
        $totalBytes = 0;
        foreach ($images as $img) {
            if (!is_array($img)) {
                continue;
            }
            $b64 = preg_replace('#^data:[^;]+;base64,#', '', (string) ($img['base64'] ?? ''));
            $b64 = trim((string) $b64);
            if ($b64 === '') {
                continue;
            }
            $mime = (string) ($img['mimeType'] ?? $img['mime_type'] ?? 'image/png');
            if ($mime === 'image/jpg') {
                $mime = 'image/jpeg';
            }
            $approx = (int) (strlen($b64) * 0.75);
            $totalBytes += $approx;
            $normalized[] = ['base64' => $b64, 'mimeType' => $mime];
        }
        if ($normalized === []) {
            throw HttpException::validation(['images' => ['Provide at least one invoice page image.']]);
        }
        UploadGuard::assertContentLength($totalBytes, 'images');
        // Cap pages like retail (prevents timeout / token blowups)
        $normalized = array_slice($normalized, 0, 4);

        $json = $this->extractFromImages($normalized);
        return $this->toDraft($json);
    }

    /**
     * @param list<array{base64:string,mimeType:string}> $images
     * @return array<string, mixed>
     */
    private function extractFromImages(array $images): array
    {
        return match ($this->provider()) {
            'openai' => $this->callOpenAI($images),
            'gemini' => $this->callGemini($images),
            default  => throw new HttpException('No AI provider configured.', 501),
        };
    }

    /**
     * @param array<string, mixed> $json
     * @return array{lines: list<array<string,mixed>>, total_lines: int, matched: int, format: string, header: array<string,mixed>}
     */
    private function toDraft(array $json): array
    {
        $header = [
            'invoice_no'       => $this->str($json['invoice_no'] ?? $json['invoiceNo'] ?? null),
            'invoice_date'     => $this->normalizeDate($json['invoice_date'] ?? $json['invoiceDate'] ?? null),
            'supplier_name'    => $this->str(
                $json['distributor_name'] ?? $json['seller'] ?? $json['supplier_name'] ?? null
            ),
            'bill_gross'       => $this->numOrNull($json['bill_gross'] ?? $json['gross'] ?? $json['Gross'] ?? null),
            'bill_add'         => $this->numOrNull($json['bill_add'] ?? $json['add'] ?? $json['Add'] ?? $json['gst_total'] ?? null),
            'bill_less'        => $this->numOrNull($json['bill_less'] ?? $json['less'] ?? $json['Less'] ?? null),
            'bill_net'         => $this->numOrNull($json['bill_net'] ?? $json['net'] ?? $json['NET'] ?? $json['net_amount'] ?? null),
            'bill_round'       => $this->numOrNull($json['bill_round'] ?? $json['round_off'] ?? $json['round'] ?? null),
            'bill_extra_less'  => null,
        ];

        $products = $json['products'] ?? [];
        if (!is_array($products)) {
            $products = [];
        }

        $rawLines = [];
        foreach ($products as $p) {
            if (!is_array($p)) {
                continue;
            }
            $row = $this->normalizeExtractedProductRow($p);
            if ($row['product_name'] === '') {
                continue;
            }
            $rawLines[] = [
                'product_name'  => $row['product_name'],
                'batch_no'      => $row['batch'],
                'expiry_date'   => $row['expiry'],
                'quantity'      => (int) round($row['qty']),
                'free_quantity' => (int) round($row['scheme']),
                'purchase_rate' => $row['rate'],
                'mrp'           => $row['mrp'],
                'ptr'           => $row['ptr'],
                'pts'           => $row['pts'],
                'discount_pct'  => $row['discount_pct'],
                'discount_amt'  => $row['discount_amt'],
                'cd_amt'        => $row['cd_amt'],
                'gst_rate'      => $row['gst'] > 0 ? $row['gst'] : 0.0,
                'pack'          => $row['pack_size'],
                'pack_size'     => $row['pack_size'],
                'hsn_code'      => $row['hsn'],
                'mfg_name'      => $row['mfg_name'],
                'manufacturer_short' => $row['mfg_name'],
            ];
        }

        if ($rawLines === []) {
            throw new HttpException('AI found no product lines. Try a clearer scan or CSV import.', 422);
        }

        // allowAiFallback=false prevents recursion when AI CSV → Generic enrich
        $parsed = (new PurchaseImportService())->parse(
            $this->linesToGenericCsv($rawLines),
            'Generic',
            true,
            false
        );

        $import = new PurchaseImportService();
        foreach ($parsed['lines'] as $i => $line) {
            if (!isset($rawLines[$i])) {
                continue;
            }
            $src = $rawLines[$i];
            $parsed['lines'][$i]['batch_no'] = $src['batch_no'];
            $parsed['lines'][$i]['expiry_date'] = $this->normalizeExpiry($src['expiry_date']);
            $parsed['lines'][$i]['quantity'] = max(0, (int) $src['quantity']);
            $parsed['lines'][$i]['free_quantity'] = max(0, (int) $src['free_quantity']);
            $parsed['lines'][$i]['purchase_rate'] = (float) $src['purchase_rate'];
            $parsed['lines'][$i]['mrp'] = (float) $src['mrp'];
            $parsed['lines'][$i]['ptr'] = (float) ($src['ptr'] ?? 0);
            $parsed['lines'][$i]['pts'] = (float) ($src['pts'] ?? 0);
            $parsed['lines'][$i]['discount_pct'] = (float) $src['discount_pct'];
            $parsed['lines'][$i]['discount_amt'] = (float) ($src['discount_amt'] ?? 0);
            $parsed['lines'][$i]['cd_amt'] = (float) ($src['cd_amt'] ?? 0);
            if ((float) $src['gst_rate'] > 0) {
                $parsed['lines'][$i]['gst_rate'] = (float) $src['gst_rate'];
            }
            if (($src['hsn_code'] ?? '') !== '') {
                $parsed['lines'][$i]['hsn_code'] = $src['hsn_code'];
            }
            if (($src['pack'] ?? '') !== '') {
                $parsed['lines'][$i]['pack'] = $src['pack'];
                $parsed['lines'][$i]['pack_size'] = $src['pack'];
                $parsed['lines'][$i]['pack_raw'] = $src['pack'];
            }
            if (($src['mfg_name'] ?? '') !== '') {
                $parsed['lines'][$i]['mfg_name'] = $src['mfg_name'];
                $parsed['lines'][$i]['manufacturer_short'] = $src['mfg_name'];
            }
            // Exact retail recalculation (gross = qty × rate, then disc → CD → taxable → GST → net)
            $parsed['lines'][$i] = $import->computeLineAmounts($parsed['lines'][$i], 0.0);
        }

        // PDF / AI footer: Gross, Less, Add, NET → same Medivision bill reconcile
        $lineDisc = 0.0;
        foreach ($parsed['lines'] as $l) {
            $lineDisc += (float) ($l['discount_amt'] ?? 0);
        }
        $billLess = (float) ($header['bill_less'] ?? 0);
        if ($billLess > 0) {
            $extra = round($billLess - $lineDisc, 2);
            if ($extra > 0.009) {
                $header['bill_extra_less'] = $extra;
            }
        }
        $parsed['lines'] = $import->reconcileMedivisionBill($parsed['lines'], $header);

        $parsed['header'] = $header;
        $parsed['format'] = 'AI';
        $parsed['provider'] = $this->provider();
        return $parsed;
    }

    private function numOrNull(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = preg_replace('/[^\d.\-]/', '', str_replace(',', '', (string) $v)) ?? '';
        if ($s === '' || !is_numeric($s)) {
            return null;
        }
        return round((float) $s, 2);
    }

    /**
     * Retail-style row cleanup: fix rate/MRP/amount mix-ups.
     *
     * @param array<string, mixed> $p
     * @return array{product_name:string,qty:float,scheme:float,rate:float,mrp:float,amount:float,batch:string,expiry:string,hsn:string,pack_size:string,discount_pct:float,discount_amt:float,gst:float,ptr:float,pts:float,mfg_name:string,cd_amt:float}
     */
    private function normalizeExtractedProductRow(array $p): array
    {
        $num = static function (mixed $v): float {
            if ($v === null || $v === '') {
                return 0.0;
            }
            $s = preg_replace('/[^\d.\-]/', '', str_replace(',', '', (string) $v)) ?? '0';
            return is_numeric($s) ? (float) $s : 0.0;
        };
        $str = static function (array $src, string ...$keys): string {
            foreach ($keys as $k) {
                if (!array_key_exists($k, $src)) {
                    continue;
                }
                $v = $src[$k];
                if ($v !== null && trim((string) $v) !== '') {
                    return trim((string) $v);
                }
            }
            return '';
        };

        $productName = $str(
            $p,
            'product_name', 'Product Name', 'PRODUCT', 'item', 'item_name', 'medicine', 'description', 'name', 'product'
        );
        $batch = $str($p, 'batch', 'Batch', 'batch_no', 'bno', 'bn', 'Batch No');
        $expiry = $str($p, 'expiry', 'Exp', 'exp_date', 'expiry_date', 'EXP', 'Exp. Dt.');
        $hsn = $str($p, 'hsn', 'HSN', 'hsn_code', 'HSN Code');
        $pack = $str($p, 'pack_size', 'pack', 'Pack', 'packing', 'Pack Size', 'packSizeFromCsv');
        $mfg = $str($p, 'mfg_name', 'mfg', 'manufacturer', 'm_name', 'm_nameCsv', 'MFG Name.');

        $qty = $num($p['qty'] ?? $p['Qty'] ?? $p['quantity'] ?? $p['sqty'] ?? 0);
        $scheme = $num($p['scheme'] ?? $p['free_qty'] ?? $p['free'] ?? $p['free_quantity'] ?? $p['sch'] ?? $p['scheme_qty'] ?? 0);
        $rate = $num($p['rate'] ?? $p['Rate'] ?? $p['purchase_rate'] ?? $p['pr'] ?? $p['buy_rate'] ?? $p['Net Rate'] ?? $p['net_rate'] ?? 0);
        $ptr = $num($p['ptr'] ?? $p['PTR'] ?? 0);
        $pts = $num($p['pts'] ?? $p['PTS'] ?? 0);
        $mrp = $num($p['mrp'] ?? $p['MRP'] ?? 0);
        $amount = $num($p['amount'] ?? $p['Amount'] ?? $p['gross'] ?? $p['gross_amount'] ?? $p['totalAmt'] ?? $p['value'] ?? 0);
        $discPct = $num($p['discount_pct'] ?? $p['disc'] ?? $p['Disc'] ?? $p['discount'] ?? 0);
        $discAmt = $num($p['discount_amt'] ?? $p['disc_amt'] ?? $p['Disc. Amt.'] ?? 0);
        $cdAmt = $num($p['cd_amt'] ?? $p['prodCdAmt'] ?? $p['CD Amt.'] ?? 0);
        $gst = $num($p['gst'] ?? $p['gst_rate'] ?? $p['GST'] ?? $p['tax'] ?? 0);
        if ($gst <= 0) {
            $gst = $num($p['cgst'] ?? 0) + $num($p['sgst'] ?? 0) + $num($p['igst'] ?? 0);
        }

        // Purchase rate should not exceed MRP (swap if reversed)
        if ($mrp > 0 && $rate > 0 && $rate > $mrp * 1.02) {
            [$rate, $mrp] = [$mrp, $rate];
        }

        // Reconcile qty from amount÷rate when printed amount disagrees with qty×rate
        // (Medivision Sale dumps: unit qty in one column, billed packs = amount/rate).
        if ($rate > 0 && $amount > 0) {
            $qFromAmt = $amount / $rate;
            if (abs($qFromAmt - round($qFromAmt)) < 0.051) {
                $recon = (int) max(1, round($qFromAmt));
                $errRaw = abs($qty * $rate - $amount);
                $errRecon = abs($recon * $rate - $amount);
                if ($errRecon <= max(0.05, $amount * 0.01) && ($qty <= 0 || $errRaw > max(0.5, $amount * 0.05))) {
                    $qty = $recon;
                }
            }
        }

        // If amount matches qty×MRP better than qty×rate, rates were swapped
        if ($qty > 0 && $rate > 0 && $mrp > 0 && $amount > 0) {
            $eR = abs($qty * $rate - $amount);
            $eM = abs($qty * $mrp - $amount);
            if ($eM < $eR * 0.45 && $eM <= max($amount * 0.12, 1)) {
                [$rate, $mrp] = [$mrp, $rate];
            }
        }

        // Only derive rate from amount/qty when rate is missing — never crush a valid rate
        // into amount/stock_qty (that bug turned 226.29 into ~25 on Medivision Sale files).
        if ($qty > 0 && $rate === 0.0 && $amount > 0) {
            $rate = round($amount / $qty, 5);
        }

        if ($discPct <= 0 && $discAmt > 0 && $qty > 0 && $rate > 0) {
            $discPct = round(($discAmt * 100) / ($qty * $rate), 2);
        }

        // CD is bill-level only — never trust model-invented line CD on extract
        $cdAmt = 0.0;

        return [
            'product_name'  => $productName,
            'qty'           => $qty,
            'scheme'        => $scheme,
            'rate'          => $rate,
            'mrp'           => $mrp,
            'amount'        => $amount,
            'batch'         => $batch,
            'expiry'        => $expiry,
            'hsn'           => $hsn,
            'pack_size'     => $pack,
            'discount_pct'  => $discPct,
            'discount_amt'  => $discAmt,
            'gst'           => $gst,
            'ptr'           => $ptr,
            'pts'           => $pts,
            'mfg_name'      => $mfg,
            'cd_amt'        => $cdAmt,
        ];
    }

    /** @return array<string, mixed> */
    private function callOpenAIForCsv(string $csvText): array
    {
        if ($this->provider() !== 'openai') {
            // Gemini text-only path
            return $this->callGeminiCsv($csvText);
        }

        $apiKey = $this->openaiKey();
        $model = (string) Env::get('OPENAI_MODEL', Env::get('AI_MODEL', 'gpt-4o'));
        $prompt = $this->csvExtractionPrompt();

        $body = [
            'model' => $model,
            'max_tokens' => 12000,
            'temperature' => 0,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You map Indian pharmacy distributor CSV/TSV purchase dumps into strict JSON for ERP stock entry. Copy printed values exactly. Do not invent rows. Do not invent arithmetic — leave amount fields as printed; the server recalculates gross/net.',
                ],
                [
                    'role' => 'user',
                    'content' => $prompt . "\n\n----- CSV START -----\n" . $csvText . "\n----- CSV END -----",
                ],
            ],
        ];

        $response = $this->httpJson(
            'https://api.openai.com/v1/chat/completions',
            $body,
            [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ]
        );
        $text = $response['choices'][0]['message']['content'] ?? '';
        return $this->parseAiJson(is_string($text) ? $text : '');
    }

    /** @return array<string, mixed> */
    private function callGeminiCsv(string $csvText): array
    {
        $apiKey = $this->geminiKey();
        $model = (string) Env::get('GEMINI_MODEL', Env::get('AI_MODEL', 'gemini-2.0-flash'));
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
            . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);

        $body = [
            'contents' => [[
                'parts' => [
                    ['text' => $this->csvExtractionPrompt() . "\n\n----- CSV START -----\n" . $csvText . "\n----- CSV END -----"],
                ],
            ]],
            'generationConfig' => [
                'temperature' => 0,
                'maxOutputTokens' => 8192,
                'responseMimeType' => 'application/json',
            ],
        ];
        $response = $this->httpJson($url, $body, ['Content-Type: application/json']);
        $text = $response['candidates'][0]['content']['parts'][0]['text'] ?? '';
        return $this->parseAiJson(is_string($text) ? $text : '');
    }

    private function csvExtractionPrompt(): string
    {
        return <<<'PROMPT'
Parse this Indian pharmacy PURCHASE / distributor CSV (may be Medivision H/T rows, Marg, Medica, Easysol, or header CSV).

Extract invoice header + EVERY product line. Skip blank/footer rows. For Medivision: H-row = header, T-rows = products.

Return ONE JSON object:
{
  "invoice_no": string|null,
  "invoice_date": "YYYY-MM-DD"|null,
  "distributor_name": string|null,
  "products": [ { ... } ]
}

Each product MUST include these keys (use null/0 if missing — do not skip keys):
product_name, qty, scheme, rate, mrp, ptr, pts, amount, batch, expiry, hsn, pack_size, gst, discount_pct, discount_amt, cd_amt, mfg_name

FIELD RULES:
- product_name: EXACT medicine name as in CSV (character for character). Prefer name column, not product code.
- qty: BILLED quantity (packs/strips on the invoice). For Medivision Sale H/T: billed qty = line_amount ÷ trailing_rate when that is a clean integer — do NOT use the large stock/unit qty column when it disagrees with amount.
- scheme: free/scheme qty (0 if none).
- rate: billed purchase rate. For Medivision Sale H/T use the trailing rate column near the end of the T-row (e.g. 226.29), NOT amount÷stock_qty.
- mrp: MRP only. ptr / pts: if columns exist; else 0.
- amount: line amount as printed (Medivision col after expiry) — must equal qty×rate.
- batch, expiry: as printed (expiry may be DDMMYYYY like 1042027).
- hsn, pack_size, mfg_name: as printed.
- gst: GST % (or CGST+SGST).
- discount_amt: line discount amount column (Medivision). discount_pct if printed, else 0.
- cd_amt: ALWAYS 0 unless an explicit Cash Discount %/amount column exists. Never put line discount into cd_amt.

Copy values exactly from the CSV columns. Do not invent products. JSON only, no markdown.
PROMPT;
    }

    /** @param list<array{base64:string,mimeType:string}> $images */
    private function callOpenAI(array $images): array
    {
        $apiKey = $this->openaiKey();
        $model = (string) Env::get('OPENAI_MODEL', Env::get('AI_MODEL', 'gpt-4o'));
        $content = [
            ['type' => 'text', 'text' => $this->extractionPrompt(count($images) > 1)],
        ];
        foreach ($images as $img) {
            $mime = $img['mimeType'];
            if (!str_starts_with($mime, 'image/')) {
                // OpenAI chat vision: images only
                continue;
            }
            $content[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => 'data:' . $mime . ';base64,' . $img['base64'],
                    'detail' => 'high',
                ],
            ];
        }
        if (count($content) < 2) {
            throw new HttpException('No usable images for OpenAI vision. Convert PDF pages to images first.', 400);
        }

        $body = [
            'model' => $model,
            'max_tokens' => 8192,
            'temperature' => 0.1,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You extract Indian pharmacy PURCHASE invoices into strict JSON for ERP stock entry. Never invent products. Copy printed names exactly.',
                ],
                ['role' => 'user', 'content' => $content],
            ],
        ];

        $response = $this->httpJson(
            'https://api.openai.com/v1/chat/completions',
            $body,
            [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ]
        );

        $text = $response['choices'][0]['message']['content'] ?? '';
        return $this->parseAiJson(is_string($text) ? $text : '');
    }

    /** @param list<array{base64:string,mimeType:string}> $images */
    private function callGemini(array $images): array
    {
        $apiKey = $this->geminiKey();
        $model = (string) Env::get('GEMINI_MODEL', Env::get('AI_MODEL', 'gemini-2.0-flash'));
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
            . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);

        $parts = [['text' => $this->extractionPrompt(count($images) > 1)]];
        foreach ($images as $img) {
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $img['mimeType'],
                    'data' => $img['base64'],
                ],
            ];
        }

        $body = [
            'contents' => [['parts' => $parts]],
            'generationConfig' => [
                'temperature' => 0.1,
                'maxOutputTokens' => 8192,
                'responseMimeType' => 'application/json',
            ],
        ];

        $response = $this->httpJson($url, $body, ['Content-Type: application/json']);
        $text = $response['candidates'][0]['content']['parts'][0]['text'] ?? '';
        return $this->parseAiJson(is_string($text) ? $text : '');
    }

    private function extractionPrompt(bool $multiPage): string
    {
        $scope = $multiPage
            ? 'You are reading consecutive pages of ONE Indian medical/pharmacy purchase bill. Extract EVERY product line from ALL pages in table row order.'
            : 'You are reading an Indian medical/pharmacy PURCHASE / distributor invoice (image). Extract every PRODUCT LINE from the printed table.';

        return <<<PROMPT
{$scope}

Also extract invoice header fields when visible — especially the bill TOTAL box:
Gross / Less / Add / NET (e.g. Gross 8938.69, Less 1538.94, Add 370.00, NET 7770.00).

Return ONE JSON object with keys:
- invoice_no (string|null) — invoice / bill / challan number as printed
- invoice_date (string|null) — prefer YYYY-MM-DD
- distributor_name (string|null) — seller / supplier / distributor name
- bill_gross (number|null) — footer Gross
- bill_less (number|null) — footer Less (total deductions)
- bill_add (number|null) — footer Add (usually GST total)
- bill_net (number|null) — footer NET (must match printed net)
- bill_round (number|null) — round-off if shown
- products: array of line objects

Each product object MUST use ONLY these keys:
product_name, qty, scheme, rate, mrp, amount, batch, expiry, hsn, pack_size, gst, discount_pct, discount_amt

FIELD RULES (wrong columns break billing — map carefully):
- product_name: ONLY the medicine/product description as printed (name, strength, form like TAB/CAP). Do NOT put batch, expiry, HSN, or amounts in the name. Copy EXACTLY as printed (character for character). Do not fix spelling.
- qty: Main billed quantity (primary Qty column). NOT free/scheme unless only one qty column exists.
- scheme: Free goods / scheme qty (Sch, Free, BQ). Use 0 if not present.
- rate: PURCHASE / buy rate per unit (Rate, PR, PTR, Net Rate, PUR.RATE). Usually LOWER than MRP. NEVER put MRP or line gross here.
- mrp: MRP / Max Retail Price only. NEVER put purchase rate here.
- amount: Line gross / taxable before GST if shown (Amount, Gross, Taxable). Must equal qty × rate when both exist.
- batch: Batch / B.No only.
- expiry: Expiry / EXP as printed (string).
- hsn: HSN digits only if column exists.
- pack_size: Pack as printed (e.g. "10x10", "1 x 32", "15'S"). Copy literally.
- gst: GST % for the line if printed (or CGST+SGST). Else null/0.
- discount_pct: Line discount % if printed. Else 0.
- discount_amt: Line discount amount if printed (₹). Else 0. Do NOT invent Cash Discount (CD) — server applies bill CD% and footer Less/Add/NET.

Line amounts must be consistent with footer: sum(amount) ≈ bill_gross; bill_net is authoritative for final total.
Use numbers for qty, scheme, rate, amount, mrp, gst, discount_pct, discount_amt, bill_*; strings for text; null if missing.
Return only valid JSON, no markdown.
PROMPT;
    }

    /** @return array<string, mixed> */
    private function parseAiJson(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new HttpException('AI returned an empty response.', 502);
        }
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
            $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        }
        $json = json_decode($text, true);
        if (!is_array($json)) {
            throw new HttpException('AI returned invalid JSON.', 502);
        }
        if (array_is_list($json)) {
            return ['products' => $json];
        }
        if (!isset($json['products']) || !is_array($json['products'])) {
            $json['products'] = [];
        }
        return $json;
    }

    /**
     * @param array<string, mixed> $body
     * @param list<string> $headers
     * @return array<string, mixed>
     */
    private function httpJson(string $url, array $body, array $headers): array
    {
        $payload = json_encode($body);
        $attempts = 0;
        $lastMsg = 'network failure';

        while ($attempts < 2) {
            $attempts++;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_TIMEOUT        => 280,
                CURLOPT_CONNECTTIMEOUT => 30,
            ]);
            $response = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                $lastMsg = $err ?: 'network failure';
                if ($attempts < 2) {
                    usleep(400000);
                    continue;
                }
                throw new HttpException('AI provider error: ' . $lastMsg, 502);
            }

            $decoded = json_decode($response, true);
            if ($code >= 200 && $code < 300) {
                if (!is_array($decoded)) {
                    throw new HttpException('AI provider returned non-JSON.', 502);
                }
                return $decoded;
            }

            $lastMsg = is_array($decoded)
                ? (string) ($decoded['error']['message'] ?? $decoded['error']['status'] ?? "HTTP {$code}")
                : "HTTP {$code}";

            // Retry once on OpenAI transient 5xx
            if ($code >= 500 && $attempts < 2) {
                usleep(600000);
                continue;
            }
            throw new HttpException('AI provider error: ' . $lastMsg, 502);
        }

        throw new HttpException('AI provider error: ' . $lastMsg, 502);
    }

    /** @param list<array<string,mixed>> $lines */
    private function linesToGenericCsv(array $lines): string
    {
        $out = "product,batch,expiry,qty,free,rate,mrp,discount,gst,pack,hsn\n";
        foreach ($lines as $l) {
            $cells = [
                $l['product_name'] ?? '',
                $l['batch_no'] ?? '',
                $l['expiry_date'] ?? '',
                $l['quantity'] ?? 0,
                $l['free_quantity'] ?? 0,
                $l['purchase_rate'] ?? 0,
                $l['mrp'] ?? 0,
                $l['discount_pct'] ?? 0,
                $l['gst_rate'] ?? 0,
                $l['pack'] ?? '',
                $l['hsn_code'] ?? '',
            ];
            $out .= implode(',', array_map(static function ($c) {
                $s = (string) $c;
                if (str_contains($s, ',') || str_contains($s, '"') || str_contains($s, "\n")) {
                    return '"' . str_replace('"', '""', $s) . '"';
                }
                return $s;
            }, $cells)) . "\n";
        }
        return $out;
    }

    private function assertReady(): void
    {
        if (!$this->isConfigured()) {
            throw new HttpException(
                'AI extraction is not configured. Add OPENAI_API_KEY to pharmacy-api/config/.env.',
                501
            );
        }
    }

    private function openaiKey(): string
    {
        foreach (['OPENAI_API_KEY', 'AI_API_KEY'] as $k) {
            $v = trim((string) Env::get($k, ''));
            if ($v !== '' && !str_contains($v, 'YOUR_') && (str_starts_with($v, 'sk-') || $k === 'OPENAI_API_KEY')) {
                // Prefer explicit OPENAI key; AI_API_KEY only if it looks like OpenAI
                if ($k === 'AI_API_KEY' && !str_starts_with($v, 'sk-')) {
                    continue;
                }
                return $v;
            }
        }
        return '';
    }

    private function geminiKey(): string
    {
        foreach (['GEMINI_API_KEY', 'AI_API_KEY'] as $k) {
            $v = trim((string) Env::get($k, ''));
            if ($v !== '' && !str_contains($v, 'YOUR_') && !str_starts_with($v, 'sk-')) {
                return $v;
            }
        }
        return '';
    }

    private function resolveMime(string $filePath, string $mimeType): string
    {
        $allowed = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/jpg'];
        if (!in_array($mimeType, $allowed, true)) {
            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $mimeType = match ($ext) {
                'pdf' => 'application/pdf',
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'webp' => 'image/webp',
                default => $mimeType,
            };
        }
        return $mimeType === 'image/jpg' ? 'image/jpeg' : $mimeType;
    }

    private function normalizeDate(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $s = trim((string) $raw);
        $ts = strtotime($s);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    private function normalizeExpiry(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $s = trim((string) $raw);
        if (preg_match('#^(\d{1,2})[/-](\d{2,4})$#', $s, $m)) {
            $month = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $year = strlen($m[2]) === 2 ? '20' . $m[2] : $m[2];
            return date('Y-m-t', strtotime("{$year}-{$month}-01"));
        }
        if (preg_match('#^(\d{4})-(\d{2})$#', $s, $m)) {
            return date('Y-m-t', strtotime("{$m[1]}-{$m[2]}-01"));
        }
        $ts = strtotime($s);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    private function str(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }
}
