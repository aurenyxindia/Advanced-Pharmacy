<?php

declare(strict_types=1);

/**
 * One-off: import staged retail `product` dump into pharma_master.products
 * with schedule/category correction, pack normalisation, mfg short codes, dedupe.
 *
 * Prerequisite: retail rows loaded into retail_product_staging
 * Usage: php bin/import_retail_products.php [--dry-run]
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Support\Env;
use App\Support\PackSize;
use Dotenv\Dotenv;

$configDir = dirname(__DIR__) . '/config';
if (is_file($configDir . '/.env')) {
    Dotenv::createImmutable($configDir, '.env')->safeLoad();
}

$dryRun = in_array('--dry-run', $argv ?? [], true);

$host = Env::get('DB_HOST', '127.0.0.1');
$port = (int) Env::get('DB_PORT', 3306);
$user = Env::get('DB_USER', 'root');
$pass = Env::get('DB_PASS', '');
$db   = Env::get('DB_MASTER', 'pharma_master');

$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
    $user,
    $pass,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]
);

$now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

function normText(string $s): string
{
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = preg_replace('/\s+/u', ' ', trim($s)) ?? '';
    return mb_strtolower($s);
}

function cleanMfgName(string $raw): string
{
    $s = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = preg_replace('/\s+/u', ' ', trim($s)) ?? '';
    $s = preg_replace('/\bPRV\.?\b/i', 'PVT', $s) ?? $s;
    $s = preg_replace('/\bPvt\.?\b/', 'PVT', $s) ?? $s;
    $s = preg_replace('/\bLtd\.?\b/', 'LTD', $s) ?? $s;
    return trim($s);
}

function mfgMatchKey(string $name): string
{
    $s = normText($name);
    $s = preg_replace('/\b(pvt|private|ltd|limited|labs?|laboratories|pharma(?:ceuticals?)?|healthcare|india|inc|llc|biotech|remedies|lifesciences?|drugs?)\b/u', '', $s) ?? $s;
    $s = preg_replace('/[^a-z0-9]+/u', '', $s) ?? $s;
    // Prefix so PHP never casts purely-numeric keys to int
    return $s === '' ? '' : ('m:' . $s);
}

function generateSCode(string $name, array &$usedCodes): string
{
    $clean = preg_replace('/\b(PVT|PRIVATE|LTD|LIMITED|LABS?|LABORATORIES|PHARMA(?:CEUTICALS?)?|HEALTHCARE|INDIA|INC|LLC)\b/i', '', $name) ?? $name;
    $words = preg_split('/\s+/', trim($clean)) ?: [];
    $letters = '';
    foreach ($words as $w) {
        $w = preg_replace('/[^A-Za-z0-9]/', '', $w) ?? '';
        if ($w === '') {
            continue;
        }
        $letters .= strtoupper(substr($w, 0, 3));
        if (strlen($letters) >= 3) {
            break;
        }
    }
    if ($letters === '') {
        $letters = 'UNK';
    }
    $base = substr($letters, 0, 3);
    $code = $base;
    $n = 2;
    while (isset($usedCodes[$code])) {
        $code = substr($base, 0, 2) . $n;
        $n++;
        if ($n > 99) {
            $code = substr(md5($name), 0, 3);
            break;
        }
    }
    $usedCodes[$code] = true;
    return $code;
}

/**
 * Retail dump often puts schedule in p_cat and category in drugClass/productTye,
 * but many rows invert that. Resolve both.
 *
 * @return array{0:string,1:string} [schedule, product_category]
 */
function mapScheduleAndCategory(?string $pCat, ?string $drugClass, ?string $productType): array
{
    $pCat = strtoupper(trim((string) $pCat));
    $drug = strtoupper(trim((string) $drugClass));
    $ptype = strtoupper(trim((string) $productType));

    $schedule = 'OTC';
    $category = 'Allopathy';

    $scheduleFrom = static function (string $v): ?string {
        $v = preg_replace('/\s+/', ' ', $v) ?? $v;
        if ($v === '' || $v === 'GENERAL' || $v === 'OTC' || $v === 'GENERIC') {
            return 'OTC';
        }
        if (preg_match('/\bRESTRICTED\b/', $v)) {
            return 'RESTRICTED';
        }
        if (preg_match('/SCHEDULE\s*-?\s*H\s*1\b|\bH1\b|SCHEDULE\s*H1/', $v)) {
            return 'H1';
        }
        if (preg_match('/SCHEDULE\s*-?\s*X\b|\bSCHEDULE X\b/', $v)) {
            return 'X';
        }
        if (preg_match('/SCHEDULE\s*-?\s*G\b|\bSCHEDULE G\b/', $v)) {
            return 'G';
        }
        if (preg_match('/SCHEDULE\s*-?\s*H\b|\bSCHEDULE H\b/', $v) && !preg_match('/H\s*1/', $v)) {
            return 'H';
        }
        return null;
    };

    $categoryFrom = static function (string $v): ?string {
        if ($v === '') {
            return null;
        }
        if (preg_match('/AYURVED|PATANJALI|HERBAL/', $v)) {
            return 'Ayurvedic';
        }
        if (preg_match('/COSMET/', $v)) {
            return 'Cosmetic';
        }
        if (preg_match('/SURGICAL/', $v)) {
            return 'Surgical';
        }
        if (preg_match('/VETERIN/', $v)) {
            return 'Allopathy'; // no separate vet category in AP master UI
        }
        if (preg_match('/FOOD|NUTRA/', $v)) {
            return 'Allopathy';
        }
        if (preg_match('/ALLOPATH|MEDICINE|ETHICAL|PCD|GENERIC/', $v)) {
            return 'Allopathy';
        }
        return null;
    };

    $sch = $scheduleFrom($pCat) ?? $scheduleFrom($drug) ?? $scheduleFrom($ptype);
    if ($sch !== null) {
        $schedule = $sch;
    }

    $cat = $categoryFrom($drug) ?? $categoryFrom($ptype) ?? $categoryFrom($pCat);
    if ($cat !== null) {
        $category = $cat;
    }

    // p_cat used as category (not schedule) — keep OTC unless schedule already set from other fields
    if ($scheduleFrom($pCat) === null && $categoryFrom($pCat) !== null && $scheduleFrom($drug) === null) {
        $schedule = 'OTC';
    }

    // General / Cosmetics product type defaults
    if ($ptype === 'GENERAL' && $schedule === 'OTC' && $category === 'Allopathy') {
        $category = 'Allopathy';
    }
    if (in_array($ptype, ['COSMETICS', 'COSMETIC'], true)) {
        $category = 'Cosmetic';
        if ($schedule === 'H' || $schedule === 'H1') {
            // Cosmetics wrongly tagged as schedule — prefer OTC
            $schedule = 'OTC';
        }
    }

    return [$schedule, $category];
}

function parseGst($tCat): float
{
    if ($tCat === null || $tCat === '') {
        return 12.0;
    }
    if (is_numeric($tCat)) {
        $n = (float) $tCat;
        if (in_array($n, [0.0, 5.0, 12.0, 18.0, 28.0], true)) {
            return $n;
        }
        if ($n > 0 && $n <= 28) {
            return $n;
        }
    }
    return 12.0;
}

function cleanHsn(?string $hsn): ?string
{
    $hsn = trim((string) $hsn);
    if ($hsn === '' || preg_match('/^0+$/', $hsn)) {
        return null;
    }
    return substr($hsn, 0, 20);
}

// ---- Load manufacturers ----
$mfgByKey = [];
$usedCodes = [];
foreach ($pdo->query('SELECT id, name, s_code FROM manufacturers') as $row) {
    $key = mfgMatchKey($row['name']);
    if ($key !== '') {
        $mfgByKey[$key] = $row;
    }
    $usedCodes[strtoupper((string) $row['s_code'])] = true;
}

// ---- Load generics (exact name match only) ----
$genericByName = [];
foreach ($pdo->query('SELECT id, name FROM generics WHERE is_active = 1') as $row) {
    $genericByName[normText($row['name'])] = (int) $row['id'];
}

// ---- Existing products index ----
$existingByKey = [];
foreach ($pdo->query('SELECT id, name, manufacturer, manufacturer_short, pack_size, schedule_category, product_category, mrp, hsn_code FROM products') as $row) {
    $pack = PackSize::parse((string) ($row['pack_size'] ?? ''));
    $key = normText($row['name']) . '|' . mfgMatchKey((string) ($row['manufacturer'] ?? '')) . '|' . normText($pack['pack_size']);
    $existingByKey[$key] = $row;
}

$stats = [
    'staged'     => 0,
    'skipped'    => 0,
    'dup_import' => 0,
    'inserted'   => 0,
    'updated'    => 0,
    'mfg_created'=> 0,
    'errors'     => 0,
];

$insertSql = 'INSERT INTO products
    (name, generic_id, manufacturer, manufacturer_short, schedule_category, product_category,
     hsn_code, default_gst_rate, mrp, ptr, pts, pack_size, units_per_pack, allow_loose,
     composition, is_active, created_at, updated_at)
    VALUES
    (:name, :generic_id, :manufacturer, :manufacturer_short, :schedule_category, :product_category,
     :hsn_code, :default_gst_rate, :mrp, 0, 0, :pack_size, :units_per_pack, 1,
     :composition, :is_active, :created_at, :updated_at)';

$updateSql = 'UPDATE products SET
    manufacturer = :manufacturer,
    manufacturer_short = :manufacturer_short,
    schedule_category = :schedule_category,
    product_category = :product_category,
    hsn_code = COALESCE(:hsn_code, hsn_code),
    default_gst_rate = :default_gst_rate,
    mrp = CASE WHEN :mrp > 0 AND (mrp IS NULL OR mrp = 0) THEN :mrp2 ELSE mrp END,
    pack_size = :pack_size,
    units_per_pack = :units_per_pack,
    composition = COALESCE(NULLIF(:composition, \'\'), composition),
    generic_id = COALESCE(:generic_id, generic_id),
    is_active = :is_active,
    updated_at = :updated_at
 WHERE id = :id';

$insertMfgSql = 'INSERT INTO manufacturers (name, s_code, is_active, created_at, updated_at)
    VALUES (:name, :s_code, 1, :created_at, :updated_at)';

$insertStmt = $pdo->prepare($insertSql);
$updateStmt = $pdo->prepare($updateSql);
$insertMfgStmt = $pdo->prepare($insertMfgSql);

// Prefer Active, then better schedule info, then non-zero MRP
$seenImport = [];

echo ($dryRun ? "[DRY-RUN] " : '') . "Scanning retail_product_staging...\n";

$stmt = $pdo->query('SELECT p_name, p_cat, pack_size, m_name, generic, mrp, oldMrp, hsn, productTye, drugClass, t_cat, status
    FROM retail_product_staging
    ORDER BY id ASC');

$batch = 0;
if (!$dryRun) {
    $pdo->beginTransaction();
}

while ($row = $stmt->fetch()) {
    $stats['staged']++;
    $name = trim(html_entity_decode((string) ($row['p_name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($name === '' || mb_strlen($name) < 2) {
        $stats['skipped']++;
        continue;
    }
    if (mb_strlen($name) > 200) {
        $name = mb_substr($name, 0, 200);
    }

    $status = strtoupper(trim((string) ($row['status'] ?? 'Active')));
    $isActive = !in_array($status, ['INACTIVE', '0', 'NO', 'DISABLED', 'DELETE', 'DELETED'], true) ? 1 : 0;

    $mfgName = cleanMfgName((string) ($row['m_name'] ?? ''));
    if ($mfgName === '') {
        $mfgName = 'UNKNOWN';
    }
    if (mb_strlen($mfgName) > 150) {
        $mfgName = mb_substr($mfgName, 0, 150);
    }

    $mKey = mfgMatchKey($mfgName);
    $mfgShort = 'UNK';
    $mfgDisplay = $mfgName;

    if ($mKey !== '' && isset($mfgByKey[$mKey])) {
        $mfgShort = strtoupper((string) $mfgByKey[$mKey]['s_code']);
        $mfgDisplay = $mfgByKey[$mKey]['name'];
    } else {
        // Fuzzy only when both keys share a strong stem (avoid UNKNOWN → U LABS)
        $found = null;
        $stem = substr($mKey, 2); // strip 'm:'
        if (strlen($stem) >= 5) {
            foreach ($mfgByKey as $k => $m) {
                $other = substr((string) $k, 2);
                if (strlen($other) < 5) {
                    continue;
                }
                $a = substr($stem, 0, 6);
                $b = substr($other, 0, 6);
                if ($a === $b || (strlen($stem) >= 6 && (str_starts_with($stem, $other) || str_starts_with($other, $stem)))) {
                    $found = $m;
                    break;
                }
            }
        }
        if ($found) {
            $mfgShort = strtoupper((string) $found['s_code']);
            $mfgDisplay = $found['name'];
            if ($mKey !== '') {
                $mfgByKey[$mKey] = $found;
            }
        } else {
            $mfgShort = generateSCode($mfgName, $usedCodes);
            if (!$dryRun) {
                try {
                    $insertMfgStmt->execute([
                        'name'       => $mfgDisplay,
                        's_code'     => $mfgShort,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $id = (int) $pdo->lastInsertId();
                    $mfgByKey[$mKey ?: mfgMatchKey($mfgDisplay)] = [
                        'id' => $id,
                        'name' => $mfgDisplay,
                        's_code' => $mfgShort,
                    ];
                    $stats['mfg_created']++;
                } catch (Throwable $e) {
                    // unique name collision — fetch existing
                    $q = $pdo->prepare('SELECT id, name, s_code FROM manufacturers WHERE name = :n LIMIT 1');
                    $q->execute(['n' => $mfgDisplay]);
                    $ex = $q->fetch();
                    if ($ex) {
                        $mfgShort = strtoupper((string) $ex['s_code']);
                        $mfgByKey[$mKey] = $ex;
                    }
                }
            } else {
                $stats['mfg_created']++;
                $mfgByKey[$mKey ?: uniqid('x', true)] = [
                    'id' => 0,
                    'name' => $mfgDisplay,
                    's_code' => $mfgShort,
                ];
            }
        }
    }

    $packRaw = trim((string) ($row['pack_size'] ?? ''));
    // Drop junk pack values that are clearly company names
    if ($packRaw !== '' && (
        preg_match('/pharma|laborat|cross|macleods|divis?/i', $packRaw)
        || strlen($packRaw) > 40
    )) {
        $packRaw = '1 unit';
    }
    $pack = PackSize::parse($packRaw !== '' ? $packRaw : '1 unit');
    $packSize = substr($pack['pack_size'], 0, 40);
    $units = max(1, (int) $pack['units_per_pack']);

    [$schedule, $category] = mapScheduleAndCategory(
        $row['p_cat'] ?? null,
        $row['drugClass'] ?? null,
        $row['productTye'] ?? null
    );

    $mrp = (float) ($row['mrp'] ?? 0);
    $oldMrp = (float) ($row['oldMrp'] ?? 0);
    if ($mrp <= 0 && $oldMrp > 0) {
        $mrp = $oldMrp;
    }

    $hsn = cleanHsn($row['hsn'] ?? null);
    $gst = parseGst($row['t_cat'] ?? null);

    $composition = trim((string) ($row['generic'] ?? ''));
    if (mb_strlen($composition) > 255) {
        $composition = mb_substr($composition, 0, 255);
    }

    $genericId = null;
    if ($composition !== '') {
        $gKey = normText($composition);
        if (isset($genericByName[$gKey])) {
            $genericId = $genericByName[$gKey];
        }
    }

    $dedupeKey = normText($name) . '|' . mfgMatchKey($mfgDisplay) . '|' . normText($packSize);

    if (isset($seenImport[$dedupeKey])) {
        $stats['dup_import']++;
        continue;
    }
    $seenImport[$dedupeKey] = true;

    $payload = [
        'name'                => $name,
        'generic_id'          => $genericId,
        'manufacturer'        => $mfgDisplay,
        'manufacturer_short'  => substr($mfgShort, 0, 20),
        'schedule_category'   => $schedule,
        'product_category'    => substr($category, 0, 30),
        'hsn_code'            => $hsn,
        'default_gst_rate'    => $gst,
        'mrp'                 => round($mrp, 2),
        'pack_size'           => $packSize,
        'units_per_pack'      => $units,
        'composition'         => $composition !== '' ? $composition : null,
        'is_active'           => $isActive,
        'created_at'          => $now,
        'updated_at'          => $now,
    ];

    try {
        if (isset($existingByKey[$dedupeKey])) {
            $ex = $existingByKey[$dedupeKey];
            if (!$dryRun) {
                $updateStmt->execute([
                    'manufacturer'       => $payload['manufacturer'],
                    'manufacturer_short' => $payload['manufacturer_short'],
                    'schedule_category'  => $payload['schedule_category'],
                    'product_category'   => $payload['product_category'],
                    'hsn_code'           => $payload['hsn_code'],
                    'default_gst_rate'   => $payload['default_gst_rate'],
                    'mrp'                => $payload['mrp'],
                    'mrp2'               => $payload['mrp'],
                    'pack_size'          => $payload['pack_size'],
                    'units_per_pack'     => $payload['units_per_pack'],
                    'composition'        => $payload['composition'] ?? '',
                    'generic_id'         => $payload['generic_id'],
                    'is_active'          => $payload['is_active'],
                    'updated_at'         => $now,
                    'id'                 => (int) $ex['id'],
                ]);
            }
            $stats['updated']++;
        } else {
            if (!$dryRun) {
                $insertStmt->execute($payload);
                $newId = (int) $pdo->lastInsertId();
                $existingByKey[$dedupeKey] = ['id' => $newId] + $payload;
            } else {
                $existingByKey[$dedupeKey] = ['id' => 0] + $payload;
            }
            $stats['inserted']++;
        }
    } catch (Throwable $e) {
        $stats['errors']++;
        if ($stats['errors'] <= 10) {
            fwrite(STDERR, 'ERR: ' . $name . ' — ' . $e->getMessage() . PHP_EOL);
        }
    }

    $batch++;
    if (!$dryRun && $batch % 500 === 0) {
        $pdo->commit();
        $pdo->beginTransaction();
        echo "  … processed {$stats['staged']} (ins {$stats['inserted']}, upd {$stats['updated']})\n";
    }
}

if (!$dryRun && $pdo->inTransaction()) {
    $pdo->commit();
}

echo "\n=== Import summary ===\n";
foreach ($stats as $k => $v) {
    echo str_pad($k, 14) . ": {$v}\n";
}

$count = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
echo 'products total : ' . $count . "\n";

$sample = $pdo->query("SELECT name, manufacturer_short, schedule_category, product_category, pack_size, units_per_pack, default_gst_rate
    FROM products ORDER BY id DESC LIMIT 8")->fetchAll();
echo "\nLatest products:\n";
foreach ($sample as $s) {
    echo sprintf(
        "  %-28s | %-4s | %-10s | %-12s | %-8s (%d) | GST %s\n",
        mb_substr($s['name'], 0, 28),
        $s['manufacturer_short'],
        $s['schedule_category'],
        $s['product_category'],
        $s['pack_size'],
        (int) $s['units_per_pack'],
        $s['default_gst_rate']
    );
}

echo "\nDone.\n";
