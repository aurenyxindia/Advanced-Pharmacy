<?php

declare(strict_types=1);

/**
 * Build a review CSV of products proposed for deletion (NO deletes).
 *
 * Categories:
 *   dummy_corrupt     — name has 4+ consecutive "?" (encoding garbage)
 *   similar_duplicate — same normalized name + pack + form (e.g. & vs AND)
 *   sim_brand_strength — same brand + strength + form + pack (existing dedupe rule)
 *
 * Usage:
 *   php bin/review_products_for_deletion.php [--output=path/to/file.csv]
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\App;
use App\Database\Connection;
use App\Support\Env;
use App\Support\ProductName;

set_time_limit(0);
ini_set('memory_limit', '512M');

try {
$app = new App(dirname(__DIR__));
$master = Connection::master();
$master->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$outputArg = null;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--output=')) {
        $outputArg = substr($arg, 9);
    }
}

$defaultOut = dirname(__DIR__) . '/storage/review_products_for_deletion_' . date('Y-m-d_His') . '.csv';
$outputPath = $outputArg !== null && $outputArg !== '' ? $outputArg : $defaultOut;

function packKey(?string $pack): string
{
    $p = strtoupper(trim((string) $pack));
    $p = strtr($p, ['×' => 'X', '*' => 'X', 'x' => 'X']);
    $p = preg_replace(
        '/\b(TABLETS?|TABS?|CAPSULES?|CAPS?|BOTTLE|VIAL|SACHETS?|STRIP|NOS|PCS?|ML|GM|G|L|UNITS?)\b/i',
        '',
        $p
    ) ?? $p;

    return preg_replace('/\s+/', '', $p) ?? '';
}

/** @param array<string,mixed> $row */
function effectivePack(array $row): string
{
    $packSize = trim((string) ($row['pack_size'] ?? ''));
    $packDesc = trim((string) ($row['packing_desc'] ?? ''));
    $raw = $packSize !== '' ? $packSize : $packDesc;

    return packKey($raw);
}

function normalizeProductNameForMatch(string $name): string
{
    $n = ProductName::cleanUpper($name);
    $n = str_replace(['&', '+'], ' AND ', $n);
    $n = preg_replace('/\bN\b/', ' AND ', $n) ?? $n; // rare "N" between words in imports
    $n = preg_replace('/\b\d+\s*(TAB|TABLETS?|CAPS?|CAPSULES?)\b/i', ' ', $n) ?? $n;
    $n = preg_replace('/\b(STRIP OF|STRIP|PACK OF)\s*\d+\b/i', ' ', $n) ?? $n;
    $n = preg_replace(
        '/\b(TABLETS?|TABS?|CAPSULES?|CAPS?|SYRUP|SYP|SUSPENSION|SUSP|INJECTION|INJ|DROPS?|CREAM|OINTMENT|GEL|LOTION|SOLUTION|SACHET|BOTTLE|VIAL|CHEWABLE|DUO|FORTE|PLUS|KID|SR|ER|XR|CR|MR|OD|DS)\b/i',
        ' ',
        $n
    ) ?? $n;
    $n = preg_replace('/[^A-Z0-9\s\/\-]/', ' ', $n) ?? $n;
    $n = preg_replace('/\s+/', ' ', trim($n)) ?? '';

    return $n;
}

function normalizedExactKey(string $name, array $row): string
{
    $n = normalizeProductNameForMatch($name);
    $n = preg_replace('/\s+/', ' ', $n) ?? '';

    return $n . '|' . effectivePack($row);
}

/** @param array<string,mixed> $row */
function dosageFormKey(array $row): string
{
    $name = strtoupper(trim((string) ($row['name'] ?? '')));
    if (preg_match('/\d+(TAB|TABLET|TABS|TABLETS|CAP|CAPS|CAPSULE|CAPSULES)\b/i', $name, $m)) {
        $form = strtoupper($m[1]);
    } elseif (preg_match('/\b(TABLETS?|TABS?|CAPSULES?|CAPS?|SYRUP|SYR|SYP|SUSPENSION|SUSP|INJECTION|INJ|DROPS?|CREAM|OINTMENT|OINT|GEL|LOTION|SOLUTION|SOL|SACHETS?|BOTTLE|VIAL|CHEWABLE|LOZENGES?|POWDER|PASTE|SPRAY|INHALER|RESPULES?)\b/i', $name, $m)) {
        $form = strtoupper($m[1]);
    } else {
        return 'GEN';
    }

    return match (true) {
        in_array($form, ['TABLET', 'TABLETS', 'TAB', 'TABS'], true) => 'TAB',
        in_array($form, ['CAPSULE', 'CAPSULES', 'CAP', 'CAPS'], true) => 'CAP',
        in_array($form, ['SYRUP', 'SYR', 'SYP'], true) => 'SYR',
        in_array($form, ['SUSPENSION', 'SUSP'], true) => 'SUSP',
        in_array($form, ['INJECTION', 'INJ'], true) => 'INJ',
        in_array($form, ['DROP', 'DROPS'], true) => 'DROP',
        in_array($form, ['OINTMENT', 'OINT'], true) => 'OINT',
        in_array($form, ['SOLUTION', 'SOL'], true) => 'SOL',
        in_array($form, ['SACHET', 'SACHETS'], true) => 'SACH',
        default => $form,
    };
}

function brandStem(string $normalizedName): string
{
    if (preg_match('/^([A-Z][A-Z0-9\-]+(?:\s+[A-Z]+)*?)(?:\s+\d|\s+\/|\s*$)/', $normalizedName, $m)) {
        return preg_replace('/\s+/', '', $m[1]) ?? '';
    }
    $parts = explode(' ', $normalizedName);

    return $parts[0] ?? '';
}

function strengthSignature(string $normalizedName): array
{
    $units = [];
    if (preg_match('/(\d+(?:\.\d+)?)\s*\/\s*(\d+(?:\.\d+)?)\s*(MG|MCG|G|ML|IU|GM|%)?/i', $normalizedName, $combo)) {
        $u = strtoupper(!empty($combo[3]) ? (string) $combo[3] : 'MG');
        $units[] = $combo[1] . $u;
        $units[] = $combo[2] . $u;
    }
    if (preg_match_all('/(\d+(?:\.\d+)?)\s*(MG|MCG|G|ML|IU|GM|%)/i', $normalizedName, $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            $units[] = strtoupper($hit[1] . $hit[2]);
        }
    }
    if ($units === [] && preg_match('/\b(\d+(?:\.\d+)?)\b/', $normalizedName, $bare)) {
        $units[] = $bare[1] . 'MG';
    }
    sort($units);

    return array_values(array_unique($units));
}

/** @param array<string,mixed> $row */
function simBrandStrengthKey(array $row): ?string
{
    $pack = effectivePack($row);
    if ($pack === '') {
        return null;
    }
    $norm = normalizeProductNameForMatch((string) ($row['name'] ?? ''));
    if ($norm === '') {
        return null;
    }
    $brand = brandStem($norm);
    if (strlen($brand) < 2) {
        return null;
    }
    $strengths = strengthSignature($norm);
    if ($strengths === []) {
        return null;
    }
    $form = dosageFormKey($row);

    return 'SIM:' . $brand . '|' . implode('+', $strengths) . '|' . $form . '|' . $pack;
}

/** Corrupted import names — long runs of literal question marks only. */
function isDummyCorruptName(string $name): bool
{
    if (preg_match('/\?{4,}/', $name)) {
        return true;
    }
    if (preg_match('/\?{3,}/', $name) && preg_match_all('/\?/', $name) >= 5) {
        return true;
    }

    return false;
}

function keeperScore(array $row): int
{
    $score = 0;
    $name = (string) ($row['name'] ?? '');
    $mfg = strtoupper(trim((string) ($row['manufacturer'] ?? '')));
    $mfgShort = strtoupper(trim((string) ($row['manufacturer_short'] ?? '')));

    if (!isDummyCorruptName($name)) {
        $score += 100;
    }
    if ($mfg !== '' && !in_array($mfg, ['UNKNOWN', 'NULL', 'NA', 'N/A', '***', '-'], true)) {
        $score += 50;
    }
    if ($mfgShort !== '' && $mfgShort !== 'UNK') {
        $score += 20;
    }
    if (preg_match('/\d+\s*(MG|MCG|ML|G|IU|%)/i', $name)) {
        $score += 10;
    }
    if (strlen($name) > 8) {
        $score += 5;
    }
    // Lower id = older = slight preference when tied
    $score -= (int) (((int) ($row['id'] ?? 0)) / 1000000);

    return $score;
}

/** @param list<array<string,mixed>> $rows */
function pickKeeper(array $rows): array
{
    usort($rows, static function (array $a, array $b): int {
        $sa = keeperScore($a);
        $sb = keeperScore($b);
        if ($sa !== $sb) {
            return $sb <=> $sa;
        }

        return (int) $a['id'] <=> (int) $b['id'];
    });

    return $rows;
}

function csvEscape(string $v): string
{
    if (str_contains($v, '"') || str_contains($v, ',') || str_contains($v, "\n")) {
        return '"' . str_replace('"', '""', $v) . '"';
    }

    return $v;
}

$products = $master->query(
    'SELECT id, name, pack_size, packing_desc, manufacturer, manufacturer_short,
            strength, composition, created_at
     FROM products ORDER BY id ASC'
)->fetchAll(PDO::FETCH_ASSOC);

$rows = [];
$deleteIds = [];

// --- 1) Dummy corrupt names ---
foreach ($products as $p) {
    $name = (string) ($p['name'] ?? '');
    if (!isDummyCorruptName($name)) {
        continue;
    }
    $id = (int) $p['id'];
    $deleteIds[$id] = true;
    $rows[] = [
        'action' => 'PROPOSED_DELETE',
        'category' => 'dummy_corrupt',
        'delete_id' => (string) $id,
        'delete_name' => $name,
        'delete_pack' => trim((string) ($p['pack_size'] ?? $p['packing_desc'] ?? '')),
        'delete_manufacturer' => (string) ($p['manufacturer'] ?? ''),
        'keep_id' => '',
        'keep_name' => '',
        'keep_pack' => '',
        'keep_manufacturer' => '',
        'reason' => 'Name contains corrupted "?" placeholder run (encoding/import garbage)',
    ];
}

// --- 2) Normalized exact name + pack + form (CHESTON & vs AND) ---
$normGroups = [];
foreach ($products as $p) {
    if (isDummyCorruptName((string) ($p['name'] ?? ''))) {
        continue;
    }
    $pack = effectivePack($p);
    if ($pack === '') {
        continue;
    }
    $form = dosageFormKey($p);
    $key = normalizedExactKey((string) ($p['name'] ?? ''), $p) . '|' . $form;
    $normGroups[$key][] = $p;
}

foreach ($normGroups as $key => $group) {
    if (count($group) < 2) {
        continue;
    }
    $sorted = pickKeeper($group);
    $keeper = $sorted[0];
    foreach (array_slice($sorted, 1) as $lose) {
        $loseId = (int) $lose['id'];
        if (isset($deleteIds[$loseId])) {
            continue;
        }
        $deleteIds[$loseId] = true;
        $rows[] = [
            'action' => 'PROPOSED_DELETE',
            'category' => 'similar_duplicate',
            'delete_id' => (string) $loseId,
            'delete_name' => (string) $lose['name'],
            'delete_pack' => trim((string) ($lose['pack_size'] ?? $lose['packing_desc'] ?? '')),
            'delete_manufacturer' => (string) ($lose['manufacturer'] ?? ''),
            'keep_id' => (string) $keeper['id'],
            'keep_name' => (string) $keeper['name'],
            'keep_pack' => trim((string) ($keeper['pack_size'] ?? $keeper['packing_desc'] ?? '')),
            'keep_manufacturer' => (string) ($keeper['manufacturer'] ?? ''),
            'reason' => 'Same normalized name + pack + form (' . $key . ')',
        ];
    }
}

// --- 3) Brand + strength + pack sim groups (when strength parseable from name) ---
$simGroups = [];
foreach ($products as $p) {
    if (isDummyCorruptName((string) ($p['name'] ?? ''))) {
        continue;
    }
    $key = simBrandStrengthKey($p);
    if ($key === null) {
        continue;
    }
    $simGroups[$key][] = $p;
}

foreach ($simGroups as $key => $group) {
    if (count($group) < 2) {
        continue;
    }
    $sorted = pickKeeper($group);
    $keeper = $sorted[0];
    foreach (array_slice($sorted, 1) as $lose) {
        $loseId = (int) $lose['id'];
        if (isset($deleteIds[$loseId])) {
            continue;
        }
        $deleteIds[$loseId] = true;
        $rows[] = [
            'action' => 'PROPOSED_DELETE',
            'category' => 'sim_brand_strength',
            'delete_id' => (string) $loseId,
            'delete_name' => (string) $lose['name'],
            'delete_pack' => trim((string) ($lose['pack_size'] ?? $lose['packing_desc'] ?? '')),
            'delete_manufacturer' => (string) ($lose['manufacturer'] ?? ''),
            'keep_id' => (string) $keeper['id'],
            'keep_name' => (string) $keeper['name'],
            'keep_pack' => trim((string) ($keeper['pack_size'] ?? $keeper['packing_desc'] ?? '')),
            'keep_manufacturer' => (string) ($keeper['manufacturer'] ?? ''),
            'reason' => 'Same brand + strength + form + pack (' . $key . ')',
        ];
    }
}

usort($rows, static fn (array $a, array $b): int => ((int) $a['delete_id']) <=> ((int) $b['delete_id']));

$headers = [
    'action', 'category', 'delete_id', 'delete_name', 'delete_pack', 'delete_manufacturer',
    'keep_id', 'keep_name', 'keep_pack', 'keep_manufacturer', 'reason',
];

$fp = fopen($outputPath, 'wb');
if ($fp === false) {
    fwrite(STDERR, "Cannot write {$outputPath}\n");
    exit(1);
}
fwrite($fp, implode(',', $headers) . "\n");
foreach ($rows as $row) {
    $line = [];
    foreach ($headers as $h) {
        $line[] = csvEscape((string) ($row[$h] ?? ''));
    }
    fwrite($fp, implode(',', $line) . "\n");
}
fclose($fp);

$byCat = [];
foreach ($rows as $row) {
    $byCat[$row['category']] = ($byCat[$row['category']] ?? 0) + 1;
}

echo 'Review file: ' . $outputPath . PHP_EOL;
echo 'Total proposed deletions: ' . count($rows) . PHP_EOL;
foreach ($byCat as $cat => $cnt) {
    echo '  ' . $cat . ': ' . $cnt . PHP_EOL;
}
echo 'NO products were deleted. Review the CSV and approve before running cleanup.' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL);
    exit(1);
}
