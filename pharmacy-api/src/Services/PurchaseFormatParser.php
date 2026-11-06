<?php

declare(strict_types=1);

namespace App\Services;

/**
 * ERP CSV column maps ported from retail upload-purchase formatConfigs.
 * After skipCols, remaining columns map 1:1 to headerKeys.
 */
final class PurchaseFormatParser
{
    /** @var array<string, array{label:string, skip_rows:int[], skip_cols:int[], header_keys:string[]}> */
    private const FORMATS = [
        'Medivision' => [
            'label' => 'Medivision',
            'skip_rows' => [0],
            'skip_cols' => [0, 1, 5, 13, 14, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33],
            'header_keys' => ['product', 'm_nameCsv', 'packSizeFromCsv', 'qty', 'scheme', 'mrp', 'purchase_rate', 'batch_no', 'expiry_date', 'totalAmt', 'disc_amt', 'gst'],
        ],
        'Marg ERP Format' => [
            'label' => 'Marg ERP Format',
            'skip_rows' => [0],
            'skip_cols' => [0, 1, 3, 4, 7, 10, 11, 14, 15, 17, 18, 19, 24, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 37, 38, 39, 40, 41, 42, 43, 44, 45, 46, 47, 48, 49],
            'header_keys' => ['m_nameCsv', 'product', 'packSizeFromCsv', 'batch_no', 'expiry_date', 'gst', 'purchase_rate', 'mrp', 'qty', 'scheme', 'disc', 'disc_amt', 'totalAmt'],
        ],
        'Marg ERP Nano' => [
            'label' => 'Marg ERP Nano',
            'skip_rows' => [0],
            'skip_cols' => [0, 1, 2, 3, 4, 6, 7, 8, 9, 10, 11, 13, 15, 17, 18, 19, 24, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 37, 39, 40, 41, 42, 43, 44, 45, 46, 47, 48, 49, 50],
            'header_keys' => ['product', 'gst', 'purchase_rate', 'mrp', 'qty', 'scheme', 'disc', 'disc_amt', 'totalAmt', 'hsn'],
        ],
        'Marg ERP 2' => [
            'label' => 'Marg ERP 2',
            'skip_rows' => [0],
            'skip_cols' => [0, 1, 4, 7, 10, 11, 13, 15, 17, 18, 19, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 37, 38, 39, 40, 41, 42, 43, 44, 45, 46, 47, 48],
            'header_keys' => ['m_nameCsv', 'hsn', 'product', 'packSizeFromCsv', 'batch_no', 'expiry_date', 'gst', 'purchase_rate', 'mrp', 'qty', 'scheme'],
        ],
        'Marg ERP 3' => [
            'label' => 'Marg ERP 3',
            'skip_rows' => [0],
            'skip_cols' => [0, 1, 3, 4, 7, 10, 13, 14, 17, 18, 19, 20, 21, 23, 24, 25, 27, 28, 29, 31, 32, 33, 34, 35, 36, 37, 38, 39, 40, 41, 42, 43, 44, 45, 46, 47, 48],
            'header_keys' => ['m_nameCsv', 'product', 'packSizeFromCsv', 'batch_no', 'expiry_date', 'purchase_rate', 'mrp', 'qty', 'scheme', 'cgstPer', 'sgstPer', 'hsn'],
        ],
        'Medica' => [
            'label' => 'Medica',
            'skip_rows' => [0],
            'skip_cols' => [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 24, 27, 28, 33, 36, 38, 39, 40, 42, 43, 44, 45, 46, 47, 48, 49, 50, 51, 52, 53, 54, 55, 56, 57, 60, 62, 64, 65, 66, 67, 68, 69, 70, 71, 72, 73, 74, 75],
            'header_keys' => ['m_nameCsv', 'product', 'packSizeFromCsv', 'batch_no', 'expiry_date', 'qty', 'scheme', 'purchase_rate', 'totalAmt', 'mrp', 'disc', 'hsn', 'igstPer', 'cgstPer', 'sgstPer'],
        ],
        'Easysol' => [
            'label' => 'Easysol',
            'skip_rows' => [0],
            'skip_cols' => [0, 1, 2, 3, 7, 16, 17, 18, 19, 20, 21, 22, 23, 24, 26, 28, 30, 31, 32, 34, 35, 36, 37],
            'header_keys' => ['product', 'packSizeFromCsv', 'm_nameCsv', 'batch_no', 'expiry_date', 'purchase_rate', 'mrp', 'totalAmt', 'qty', 'scheme', 'disc_amt', 'sgstPer', 'cgstPer', 'igstPer', 'hsn'],
        ],
        'Care' => [
            'label' => 'Care',
            'skip_rows' => [0],
            'skip_cols' => [0, 1, 3, 4, 7, 10, 11, 14, 15, 17, 18, 19, 22, 23, 24, 25, 26, 28, 29, 30, 31, 32, 33, 34, 35, 37],
            'header_keys' => ['m_nameCsv', 'product', 'packSizeFromCsv', 'batch_no', 'expiry_date', 'gst', 'purchase_rate', 'mrp', 'qty', 'scheme', 'totalAmt', 'hsn'],
        ],
        'PHARMACC' => [
            'label' => 'PHARMACC',
            'skip_rows' => [0],
            'skip_cols' => [0, 1, 2, 3, 4, 10, 11, 12, 14, 15, 17, 18, 19, 22, 23, 24, 25, 26, 27, 29, 31, 32, 33],
            'header_keys' => ['product', 'packSizeFromCsv', 'm_nameCsv', 'batch_no', 'expiry_date', 'purchase_rate', 'mrp', 'qty', 'scheme', 'gst', 'hsn'],
        ],
        'Reliable' => [
            'label' => 'Reliable',
            'skip_rows' => [0],
            'skip_cols' => [0, 3, 4, 5, 9, 15, 16, 17, 18, 19, 20, 22, 23, 24, 25, 27, 28, 29, 31, 32, 33, 34, 35, 36, 37],
            'header_keys' => ['product', 'm_nameCsv', 'batch_no', 'expiry_date', 'mrp', 'gst', 'qty', 'scheme', 'packSizeFromCsv', 'disc_amt', 'totalAmt', 'hsn', 'purchase_rate'],
        ],
        'Samarth' => [
            'label' => 'Samarth',
            'skip_rows' => [0],
            'skip_cols' => [0, 1, 2, 3, 6, 15, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 30, 31, 32, 34, 35, 36, 37, 38],
            'header_keys' => ['product', 'packSizeFromCsv', 'm_nameCsv', 'batch_no', 'expiry_date', 'purchase_rate', 'mrp', 'totalAmt', 'qty', 'scheme', 'disc', 'disc_amt', 'gst', 'hsn'],
        ],
        'MicroPro' => [
            'label' => 'MicroPro',
            'skip_rows' => [0],
            'skip_cols' => [0, 1, 2, 3, 4, 11, 13, 14, 17, 18, 19, 20, 22, 23, 25, 26, 27, 28, 29, 31],
            'header_keys' => ['product', 'packSizeFromCsv', 'm_nameCsv', 'batch_no', 'expiry_date', 'purchase_rate', 'mrp', 'qty', 'scheme', 'totalAmt', 'gst', 'hsn'],
        ],
        'Dawa' => [
            'label' => 'Dawa',
            'skip_rows' => [0],
            'skip_cols' => [0, 1, 3, 4, 7, 10, 11, 13, 15, 17, 18, 19, 22, 23, 24, 25, 27],
            'header_keys' => ['m_nameCsv', 'product', 'packSizeFromCsv', 'batch_no', 'expiry_date', 'gst', 'purchase_rate', 'mrp', 'qty', 'scheme', 'hsn'],
        ],
        'Generic' => [
            'label' => 'Generic (header aliases)',
            'skip_rows' => [],
            'skip_cols' => [],
            'header_keys' => [],
        ],
        'Auto' => [
            'label' => 'Auto-detect',
            'skip_rows' => [],
            'skip_cols' => [],
            'header_keys' => [],
        ],
        'AI' => [
            'label' => 'AI (OpenAI) — capture all columns',
            'skip_rows' => [],
            'skip_cols' => [],
            'header_keys' => [],
        ],
    ];

    /** @return list<array{key:string,label:string}> */
    public function listFormats(): array
    {
        $out = [
            ['key' => 'Auto', 'label' => 'Auto-detect'],
            ['key' => 'AI', 'label' => 'AI (OpenAI) — capture all columns'],
        ];
        foreach (self::FORMATS as $key => $cfg) {
            if ($key === 'Auto' || $key === 'AI') {
                continue;
            }
            $out[] = ['key' => $key, 'label' => $cfg['label']];
        }
        return $out;
    }

    public function hasFormat(string $format): bool
    {
        return isset(self::FORMATS[$format]);
    }

    /**
     * Detect ERP format from raw CSV text.
     * Medivision sale/purchase dumps start with H, then T product lines.
     */
    public function detectFormat(string $csvText): string
    {
        $lines = preg_split('/\r\n|\n|\r/', $csvText) ?: [];
        $nonEmpty = [];
        foreach ($lines as $line) {
            if (trim((string) $line) !== '') {
                $nonEmpty[] = trim((string) $line);
            }
            if (count($nonEmpty) >= 8) {
                break;
            }
        }
        if ($nonEmpty === []) {
            return 'Generic';
        }

        $first = strtoupper(substr($nonEmpty[0], 0, 2));
        $tCount = 0;
        foreach ($nonEmpty as $line) {
            if (str_starts_with(strtoupper($line), 'T,')) {
                $tCount++;
            }
        }
        if ($first === 'H,' || ($tCount >= 2 && str_starts_with(strtoupper($nonEmpty[1] ?? ''), 'T,'))) {
            return 'Medivision';
        }

        // Header-looking first row → Generic
        $header = strtolower($nonEmpty[0]);
        if (str_contains($header, 'product') || str_contains($header, 'item') || str_contains($header, 'qty')) {
            return 'Generic';
        }

        return 'Generic';
    }

    /**
     * Extract invoice header from Medivision H-row when present.
     *
     * Sale_ H-row (verified Sale_54127 PDF footer):
     *  [8]=Gross  [10]=Add(GST)  [13]=extra Less  [19]=NET  [20]=round-off
     *  PDF: Net = Gross − (lineDisc + extraLess) + Add (+ round)
     *
     * @return array<string, mixed>
     */
    public function extractHeader(string $csvText, string $format): array
    {
        $header = [
            'invoice_no'       => null,
            'invoice_date'     => null,
            'supplier_name'    => null,
            'bill_gross'       => null,
            'bill_add'         => null,
            'bill_extra_less'  => null,
            'bill_net'         => null,
            'bill_round'       => null,
        ];
        if ($format !== 'Medivision') {
            return $header;
        }
        $lines = preg_split('/\r\n|\n|\r/', $csvText) ?: [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            $cols = str_getcsv($line, ',', '"', '\\');
            if (strtoupper(trim((string) ($cols[0] ?? ''))) !== 'H') {
                break;
            }
            // H,,DD-MM-YY,invoiceNo,code,SUPPLIER,...
            $header['invoice_date'] = $this->normalizeInvoiceDate((string) ($cols[2] ?? ''));
            $inv = trim((string) ($cols[3] ?? ''));
            $header['invoice_no'] = $inv !== '' ? $inv : null;
            $sup = trim((string) ($cols[5] ?? ''));
            $header['supplier_name'] = $sup !== '' ? $sup : null;

            $num = static function ($v): float {
                if ($v === '' || $v === null) {
                    return 0.0;
                }
                return (float) str_replace([',', ' '], '', (string) $v);
            };
            $gross = $num($cols[8] ?? 0);
            $add = $num($cols[10] ?? 0);
            $extraLess = $num($cols[13] ?? 0);
            $net = $num($cols[19] ?? 0);
            $round = $num($cols[20] ?? 0);
            if ($gross > 0) {
                $header['bill_gross'] = round($gross, 2);
            }
            if ($add > 0) {
                $header['bill_add'] = round($add, 2);
            }
            if ($extraLess > 0) {
                $header['bill_extra_less'] = round($extraLess, 2);
            }
            if ($net > 0) {
                $header['bill_net'] = round($net, 2);
            }
            if (abs($round) > 0.00001) {
                $header['bill_round'] = round($round, 2);
            }
            break;
        }
        return $header;
    }

    private function normalizeInvoiceDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        // DD-MM-YY / DD-MM-YYYY
        if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{2,4})$#', $raw, $m)) {
            $year = strlen($m[3]) === 2 ? '20' . $m[3] : $m[3];
            $month = str_pad($m[2], 2, '0', STR_PAD_LEFT);
            $day = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            return "{$year}-{$month}-{$day}";
        }
        $ts = strtotime($raw);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    /**
     * Parse CSV/TSV text with a named ERP format into raw row maps.
     *
     * @return list<array<string, mixed>>
     */
    public function parseText(string $csvText, string $format): array
    {
        $format = $this->hasFormat($format) ? $format : 'Generic';
        if ($format === 'Generic') {
            return []; // handled by PurchaseImportService alias mapper
        }

        // Medivision Sale_*.csv H/T dumps need a dedicated column map (not Retail header-export skipCols).
        if ($format === 'Medivision' && $this->looksLikeMedivisionHt($csvText)) {
            return $this->parseMedivisionHt($csvText);
        }

        $cfg = self::FORMATS[$format];
        $lines = preg_split('/\r\n|\n|\r/', $csvText) ?: [];
        $lines = array_values(array_filter($lines, static fn ($l) => trim((string) $l) !== ''));
        $keys = $cfg['header_keys'];
        $skipRows = $cfg['skip_rows'];
        $skipCols = $cfg['skip_cols'];
        $result = [];

        foreach ($lines as $rowIdx => $line) {
            if (in_array($rowIdx, $skipRows, true)) {
                continue;
            }
            $cols = $this->parseCsvLine($line);
            $filtered = [];
            foreach ($cols as $colIdx => $val) {
                if (!in_array($colIdx, $skipCols, true)) {
                    $filtered[] = $val;
                }
            }
            $obj = [];
            foreach ($keys as $i => $key) {
                $obj[$key] = $filtered[$i] ?? '';
            }
            if (trim((string) ($obj['product'] ?? '')) === '') {
                continue;
            }
            $result[] = $this->normalizeRawRow($obj, $format);
            if (count($result) > 5000) {
                break;
            }
        }

        return $result;
    }

    /** True when CSV starts with H,/T, Medivision dump rows. */
    public function looksLikeMedivisionHt(string $csvText): bool
    {
        return $this->detectFormat($csvText) === 'Medivision';
    }

    /**
     * Medivision distributor Sale_*.csv (H header + T lines).
     *
     * Verified on Sale_54127:
     *  col6  = stock/unit qty (often NOT billed packs)
     *  col8  = MRP
     *  col9  = PTS / secondary rate
     *  col10 = batch, col11 = expiry (DDMMYYYY)
     *  col12 = line amount (sums to H bill total)
     *  col15 = line discount amount
     *  col16 = GST %
     *  col31 = billed purchase rate (amount ≈ billed_qty × this rate)
     */
    private function parseMedivisionHt(string $csvText): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $csvText) ?: [];
        $result = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            $cols = $this->parseCsvLine($line);
            if (strtoupper(trim((string) ($cols[0] ?? ''))) !== 'T') {
                continue;
            }
            $product = trim((string) ($cols[2] ?? ''));
            if ($product === '') {
                continue;
            }

            $num = static function ($v): float {
                if ($v === '' || $v === null) {
                    return 0.0;
                }
                return (float) str_replace([',', ' '], '', (string) $v);
            };

            $pack = trim((string) ($cols[4] ?? ''));
            $qtyRaw = (int) round($num($cols[6] ?? 0));
            $scheme = (int) round($num($cols[7] ?? 0));
            $mrp = $num($cols[8] ?? 0);
            $pts = $num($cols[9] ?? 0);
            $batch = trim((string) ($cols[10] ?? ''));
            $expiry = $this->normalizeExpiry((string) ($cols[11] ?? ''));
            $amount = $num($cols[12] ?? 0);
            $discAmt = $num($cols[15] ?? 0);
            $gst = $num($cols[16] ?? 0);

            // Trailing billed rate (usually index 31 on Sale dumps)
            $rateBill = 0.0;
            for ($i = count($cols) - 1; $i >= 20; $i--) {
                $v = $num($cols[$i] ?? 0);
                if ($v > 0) {
                    $rateBill = $v;
                    break;
                }
            }
            // Prefer col31 when present
            if ($num($cols[31] ?? 0) > 0) {
                $rateBill = $num($cols[31]);
            }

            $rate = $rateBill > 0 ? $rateBill : $pts;
            $qty = $qtyRaw;

            // Reconcile billed qty from amount ÷ rate (Sale dumps: col6 is often unit stock qty)
            if ($rate > 0 && $amount > 0) {
                $qFromAmt = $amount / $rate;
                if (abs($qFromAmt - round($qFromAmt)) < 0.051) {
                    $recon = (int) max(1, round($qFromAmt));
                    $errRaw = abs($qtyRaw * $rate - $amount);
                    $errRecon = abs($recon * $rate - $amount);
                    if ($errRecon <= max(0.05, $amount * 0.01) && ($qtyRaw <= 0 || $errRaw > max(0.5, $amount * 0.05))) {
                        $qty = $recon;
                    }
                }
            }

            $gross = $amount > 0 ? round($amount, 2) : round($qty * $rate, 2);
            // If amount missing, fall back to qty×rate
            if ($amount <= 0 && $qty > 0 && $rate > 0) {
                $gross = round($qty * $rate, 2);
            }

            $discPct = 0.0;
            if ($discAmt > 0 && $gross > 0) {
                $discPct = round(($discAmt * 100) / $gross, 2);
            }

            $code = trim((string) ($cols[1] ?? ''));
            $result[] = [
                'product_name'       => $product,
                'product_code'       => $code,
                'manufacturer_short' => '',
                'mfg_name'           => '',
                'pack'               => $pack,
                'pack_size'          => $pack,
                'batch_no'           => $batch,
                'expiry_date'        => $expiry,
                'quantity'           => max(0, $qty),
                'free_quantity'      => max(0, $scheme),
                'purchase_rate'      => $rate,
                'mrp'                => $mrp,
                'ptr'                => $rate, // billed rate as PTR seed
                'pts'                => $pts,
                'discount_pct'       => $discPct,
                'discount_amt'       => $discAmt,
                'gross_amount'       => $gross,
                'cd_amt'             => 0.0,
                'gst_rate'           => $gst,
                'hsn_code'           => '',
            ];
            if (count($result) > 5000) {
                break;
            }
        }
        return $result;
    }

    /** @param array<string, mixed> $obj @return array<string, mixed> */
    private function normalizeRawRow(array $obj, string $format): array
    {
        $num = static function ($v): float {
            if ($v === '' || $v === null) {
                return 0.0;
            }
            return (float) str_replace([',', ' '], '', (string) $v);
        };

        $gst = $num($obj['gst'] ?? 0);
        if (in_array($format, ['Medica', 'Easysol'], true)) {
            $gst = $num($obj['igstPer'] ?? 0) + $num($obj['cgstPer'] ?? 0) + $num($obj['sgstPer'] ?? 0);
        } elseif ($format === 'Marg ERP 3') {
            $gst = $num($obj['cgstPer'] ?? 0) + $num($obj['sgstPer'] ?? 0);
            if ($gst <= 0) {
                $gst = 5.0;
            }
        }

        $discPct = $num($obj['disc'] ?? 0);
        $discAmt = $num($obj['disc_amt'] ?? 0);
        $qty = (int) round($num($obj['qty'] ?? 0));
        $rate = $num($obj['purchase_rate'] ?? 0);
        if ($discPct <= 0 && $discAmt > 0 && $qty > 0 && $rate > 0) {
            $discPct = round(($discAmt / ($rate * $qty)) * 100, 2);
        }

        $scheme = (int) round($num($obj['scheme'] ?? 0));

        $grossCsv = $num($obj['totalAmt'] ?? 0);
        $gross = $grossCsv > 0 ? $grossCsv : round($rate * max(0, $qty), 2);
        if ($discPct <= 0 && $discAmt > 0 && $gross > 0) {
            $discPct = round(($discAmt / $gross) * 100, 2);
        }
        if ($discAmt <= 0 && $discPct > 0 && $gross > 0) {
            $discAmt = round($gross * $discPct / 100, 2);
        }

        return [
            'product_name'       => trim((string) ($obj['product'] ?? '')),
            'manufacturer_short' => trim((string) ($obj['m_nameCsv'] ?? '')),
            'mfg_name'           => trim((string) ($obj['m_nameCsv'] ?? '')),
            'pack'               => trim((string) ($obj['packSizeFromCsv'] ?? '')),
            'pack_size'          => trim((string) ($obj['packSizeFromCsv'] ?? '')),
            'batch_no'           => trim((string) ($obj['batch_no'] ?? '')),
            'expiry_date'        => $this->normalizeExpiry((string) ($obj['expiry_date'] ?? '')),
            'quantity'           => max(0, $qty),
            'free_quantity'      => max(0, $scheme),
            'purchase_rate'      => $rate,
            'mrp'                => $num($obj['mrp'] ?? 0),
            'ptr'                => $num($obj['ptr'] ?? 0),
            'pts'                => $num($obj['pts'] ?? 0),
            'discount_pct'       => $discPct,
            'discount_amt'       => $discAmt,
            'gross_amount'       => $gross,
            'cd_amt'             => $num($obj['prodCdAmt'] ?? $obj['cd_amt'] ?? 0),
            'gst_rate'           => $gst,
            'hsn_code'           => trim((string) ($obj['hsn'] ?? '')),
        ];
    }

    /** @return list<string> */
    private function parseCsvLine(string $line): array
    {
        $out = str_getcsv($line, ',', '"', '\\');
        return $out === [null] ? [] : array_map(static fn ($c) => trim((string) $c), $out);
    }

    private function normalizeExpiry(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        // MM/YYYY or MM-YY
        if (preg_match('#^(\d{1,2})[/-](\d{2,4})$#', $raw, $m)) {
            $month = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $year = strlen($m[2]) === 2 ? '20' . $m[2] : $m[2];
            return date('Y-m-t', strtotime("{$year}-{$month}-01"));
        }
        // YYYY-MM
        if (preg_match('#^(\d{4})-(\d{2})$#', $raw, $m)) {
            return date('Y-m-t', strtotime("{$m[1]}-{$m[2]}-01"));
        }
        // Compact Medivision: DDMMYYYY (8) or DMMYYYY (7) e.g. 1062027 → 2027-06-30
        $digits = preg_replace('/\D/', '', $raw) ?? '';
        if (strlen($digits) === 8) {
            $mm = (int) substr($digits, 2, 2);
            $yyyy = substr($digits, 4, 4);
            if ($mm >= 1 && $mm <= 12) {
                return date('Y-m-t', strtotime(sprintf('%s-%02d-01', $yyyy, $mm)));
            }
        }
        if (strlen($digits) === 7) {
            $mm = (int) substr($digits, 1, 2);
            $yyyy = substr($digits, 3, 4);
            if ($mm >= 1 && $mm <= 12) {
                return date('Y-m-t', strtotime(sprintf('%s-%02d-01', $yyyy, $mm)));
            }
        }
        $ts = strtotime($raw);
        return $ts ? date('Y-m-d', $ts) : null;
    }
}
