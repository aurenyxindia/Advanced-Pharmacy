<?php

declare(strict_types=1);

/**
 * Catalog cleanup (master products + generics + brand↔generic mapping).
 *
 * 1. Merge duplicate generics (same name ignoring spaces/case/punctuation).
 * 2. Merge duplicate product brands (same name + same pack). Different packs
 *    (Honey 50g vs 500g) are kept as separate SKUs.
 * 3. Never DELETE a product that is already on a purchase AND has stock > 0.
 *    Unused duplicates are remapped then removed; in-use rows keep their id.
 * 4. Seed missing generic names from composition / Indian brand dataset.
 * 5. Map products.generic_id and sync product_bindings.generic_id.
 *
 * Usage: php bin/cleanup_catalog.php [--dry-run]
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\App;
use App\Database\Connection;
use App\Support\Env;
use App\Support\ProductName;

set_time_limit(0);
ini_set('memory_limit', '512M');

$app = new App(dirname(__DIR__));
$dryRun = in_array('--dry-run', $argv ?? [], true);

$master = Connection::master();
$ops = Connection::ops();
$master->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$ops->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$masterDb = (string) Env::get('DB_MASTER', 'pharma_master');
$opsDb = (string) Env::get('DB_OPS', 'pharma_ops');
$now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
$csvPath = dirname(__DIR__) . '/storage/indian_medicine_data.csv';

$stats = [
    'generic_merged' => 0,
    'generic_deleted' => 0,
    'generic_seeded' => 0,
    'product_merged' => 0,
    'product_deleted' => 0,
    'product_skipped_protected' => 0,
    'product_filled' => 0,
    'products_mapped' => 0,
    'unlinked_mismatch' => 0,
    'bindings_synced' => 0,
    'unmapped_left' => 0,
];

function out(string $msg): void
{
    echo $msg . PHP_EOL;
}

function norm(string $s): string
{
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = mb_strtolower(trim($s));
    $s = str_replace(['%', '=', '_'], ' ', $s);
    $s = preg_replace('/[^a-z0-9+.\-\/\s]/u', ' ', $s) ?? $s;
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    return trim($s);
}

function compactKey(string $s): string
{
    return preg_replace('/[^a-z0-9]+/u', '', cleanComp($s)) ?? '';
}

/** @return list<string> */
function brandLookupKeys(string $name): array
{
    $n = str_replace('-', ' ', norm($name));
    $n = trim(preg_replace('/\s+/', ' ', $n) ?? $n);
    if ($n === '') {
        return [];
    }
    $keys = [$n];
    $stripped = preg_replace(
        '/\b(tablets?|tab|capsules?|cap|syrup|syp|suspension|susp|injection|inj|drops?|cream|ointment|gel|lotion|solution|sachet|strip|bottle|chewable|duo|forte|plus|kid|ds|sr|er|xr|cr|mr|od)\b/i',
        ' ',
        $n
    ) ?? $n;
    $stripped = preg_replace('/\b\d+(\.\d+)?\s*(mg|mcg|ml|g|iu|%)?\b/i', ' ', $stripped) ?? $stripped;
    $stripped = trim(preg_replace('/\s+/', ' ', $stripped) ?? $stripped);
    if ($stripped !== '' && $stripped !== $n) {
        $keys[] = $stripped;
    }
    if (preg_match('/^([a-z][a-z0-9]*)(?:\s+(\d+))?/i', $n, $m)) {
        $stem = strtolower($m[1]);
        if (strlen($stem) >= 8) {
            $keys[] = $stem;
        }
        if (!empty($m[2])) {
            $keys[] = $stem . ' ' . $m[2];
        }
    }
    $parts = explode(' ', $n);
    if (count($parts) >= 2) {
        $keys[] = $parts[0] . ' ' . $parts[1];
    }
    return array_values(array_unique($keys));
}

function nameKey(string $s): string
{
    $s = ProductName::clean($s);
    return strtoupper(preg_replace('/\s+/', ' ', trim($s)) ?? '');
}

function packKey(?string $pack): string
{
    $p = strtoupper(trim((string) $pack));
    $p = strtr($p, ['×' => 'X', '*' => 'X', 'x' => 'X']);
    $p = preg_replace('/\s+/', '', $p) ?? '';
    return $p;
}

function dupKey(string $name, ?string $pack): string
{
    return nameKey($name) . '|' . packKey($pack);
}

function cleanComp(string $raw): string
{
    $s = norm($raw);
    $s = preg_replace('/\bamoxycillin\b/', 'amoxicillin', $s) ?? $s;
    $s = preg_replace('/\bpropanolol\b/', 'propranolol', $s) ?? $s;
    $s = preg_replace('/\bamlodepine\b/', 'amlodipine', $s) ?? $s;
    $s = preg_replace('/\bmetorpolol\b/', 'metoprolol', $s) ?? $s;
    $s = preg_replace('/\bcalvunic\b|\bclavunic\b|\bclavulanate\b/', 'clavulanic', $s) ?? $s;
    return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
}

function isJunkComp(string $c): bool
{
    $c = norm($c);
    if ($c === '' || strlen($c) < 3) {
        return true;
    }
    static $junk = [
        'generic' => 1, 'na' => 1, 'n/a' => 1, 'nil' => 1, 'none' => 1, '-' => 1,
        'surgical' => 1, 'general' => 1, 'ayurvedic' => 1, 'unknown' => 1,
    ];
    return isset($junk[$c]);
}

function isBogusComp(string $c): bool
{
    if (isJunkComp($c)) {
        return true;
    }
    return (bool) preg_match(
        '/\b(sunscreen|spf\d*|moisturiz|lotion standard|cream standard|shampoo standard|face wash|toothpaste standard|cosmetic|hair oil standard)\b/i',
        $c
    );
}

function isCredibleComp(string $c): bool
{
    if (isBogusComp($c)) {
        return false;
    }
    if (preg_match('/\d+\s*(mg|mcg|g|ml|iu|%)/i', $c)) {
        return true;
    }
    $toks = molTokens($c);
    return $toks !== [] && strlen($toks[0]) >= 5;
}

/** @return list<string> */
function molTokens(string $s): array
{
    $s = cleanComp($s);
    $s = preg_replace(
        '/\b(\d+(\.\d+)?\s*(mg|mcg|g|ml|iu|%|w\/v|units?)|tablet|tablets|capsule|capsules|syrup|injection|inj|drops?|cream|ointment|gel|suspension|solution|powder|sachet|oral|softgel|strip|bottle|vial|ampoule|dt|sr|er|xr|cr|mr|forte|plus|kid|ds|od)\b/u',
        ' ',
        $s
    ) ?? $s;
    $parts = preg_split('/\s*\+\s*|\s*,\s*|\//', trim(preg_replace('/\s+/', ' ', $s) ?? '')) ?: [];
    $out = [];
    $skip = ['acid', 'sodium', 'potassium', 'calcium', 'hydrate', 'anhydrous', 'dihydrate', 'monohydrate', 'hydrochloride', 'sulphate', 'sulfate', 'phosphate', 'maleate', 'succinate', 'tartrate', 'acetate', 'with', 'and', 'the'];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '' || !preg_match_all('/[a-z][a-z]{3,}/', $p, $m)) {
            continue;
        }
        foreach ($m[0] as $w) {
            if (!in_array($w, $skip, true)) {
                $out[] = $w;
            }
        }
    }
    return array_values(array_unique($out));
}

/** @return list<array{n:float,u:string}> */
function strengthNumbers(string $s): array
{
    if (!preg_match_all('/(\d+(?:\.\d+)?)\s*(mg|mcg|g|ml|iu)\b/i', $s, $m)) {
        return [];
    }
    $out = [];
    foreach ($m[1] as $i => $num) {
        $out[] = ['n' => (float) $num, 'u' => strtolower($m[2][$i])];
    }
    return $out;
}

function strengthKey(string $s): ?string
{
    $nums = strengthNumbers($s);
    if (!$nums) {
        return null;
    }
    $bits = [];
    foreach ($nums as $x) {
        $bits[] = $x['n'] . $x['u'];
    }
    sort($bits);
    return implode('+', $bits);
}

function strengthsAgree(string $name, string $comp): bool
{
    $a = strengthNumbers($name);
    $b = strengthNumbers($comp);
    if (!$a || !$b) {
        return true;
    }
    if (strengthKey($name) === strengthKey($comp)) {
        return true;
    }
    $unit = $a[0]['u'];
    $sumA = 0.0;
    $sumB = 0.0;
    foreach ($a as $x) {
        if ($x['u'] !== $unit) {
            return false;
        }
        $sumA += $x['n'];
    }
    foreach ($b as $x) {
        if ($x['u'] !== $unit) {
            return false;
        }
        $sumB += $x['n'];
    }
    return abs($sumA - $sumB) < 0.05;
}

function prettyGeneric(string $s): string
{
    $s = trim(preg_replace('/\s+/', ' ', $s) ?? '');
    $s = mb_substr($s, 0, 180);
    $s = mb_convert_case($s, MB_CASE_TITLE, 'UTF-8');
    $s = preg_replace('/\bhcl\b/i', 'HCl', $s) ?? $s;
    $s = preg_replace('/\bmg\b/i', 'mg', $s) ?? $s;
    $s = preg_replace('/\bmcg\b/i', 'mcg', $s) ?? $s;
    return $s;
}

function emptyMfg(?string $m): bool
{
    $m = strtoupper(trim((string) $m));
    return $m === '' || in_array($m, ['UNKNOWN', 'NULL', 'NA', 'N/A', '***', '-'], true);
}

function nameQuality(string $n): int
{
    $n = ProductName::clean($n);
    $q = strlen($n) > 0 ? 1 : 0;
    if ($n === strtoupper($n) || $n === ucwords(strtolower($n))) {
        $q += 3;
    }
    if (preg_match('/[A-Z]{2,}[a-z]+[A-Z]/', $n)) {
        $q -= 5;
    }
    return $q;
}

function isProtected(array $u): bool
{
    return ((int) ($u['purchase'] ?? 0) > 0) && ((float) ($u['stock'] ?? 0) > 0);
}

/** @return array<int, array{purchase:int,stock:float}> */
function loadUsage(PDO $ops): array
{
    $usage = [];
    $q1 = $ops->query(
        'SELECT pb.master_product_id AS id, COUNT(pi.id) AS c
         FROM product_bindings pb
         JOIN purchase_items pi ON pi.product_binding_id = pb.id
         GROUP BY pb.master_product_id'
    );
    foreach ($q1 as $r) {
        $id = (int) $r['id'];
        $usage[$id]['purchase'] = (int) $r['c'];
        $usage[$id]['stock'] = $usage[$id]['stock'] ?? 0.0;
    }
    $q2 = $ops->query(
        'SELECT pb.master_product_id AS id, IFNULL(SUM(ib.quantity),0) AS q
         FROM product_bindings pb
         JOIN inventory_batches ib ON ib.product_binding_id = pb.id
         GROUP BY pb.master_product_id'
    );
    foreach ($q2 as $r) {
        $id = (int) $r['id'];
        $usage[$id]['stock'] = (float) $r['q'];
        $usage[$id]['purchase'] = $usage[$id]['purchase'] ?? 0;
    }
    return $usage;
}

/** @return list<string> */
function bindingTables(PDO $ops, string $opsDb): array
{
    $stmt = $ops->prepare(
        "SELECT TABLE_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :db AND COLUMN_NAME = 'product_binding_id'"
    );
    $stmt->execute(['db' => $opsDb]);
    $tables = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $t) {
        if ($t === 'inventory_stock') {
            continue;
        }
        $tables[] = (string) $t;
    }
    return $tables;
}

function reassignBinding(PDO $ops, int $from, int $to, array $tables): void
{
    if ($from === $to) {
        return;
    }
    $fromStock = $ops->prepare('SELECT id, quantity, reorder_level FROM inventory_stock WHERE product_binding_id = ?');
    $fromStock->execute([$from]);
    $src = $fromStock->fetch(PDO::FETCH_ASSOC);
    if ($src) {
        $toStock = $ops->prepare('SELECT id, quantity, reorder_level FROM inventory_stock WHERE product_binding_id = ?');
        $toStock->execute([$to]);
        $dst = $toStock->fetch(PDO::FETCH_ASSOC);
        if ($dst) {
            $ops->prepare('UPDATE inventory_stock SET quantity = quantity + ?, reorder_level = GREATEST(reorder_level, ?), updated_at = NOW() WHERE id = ?')
                ->execute([(int) $src['quantity'], (int) $src['reorder_level'], $dst['id']]);
            $ops->prepare('DELETE FROM inventory_stock WHERE id = ?')->execute([$src['id']]);
        } else {
            $ops->prepare('UPDATE inventory_stock SET product_binding_id = ? WHERE id = ?')->execute([$to, $src['id']]);
        }
    }
    foreach ($tables as $t) {
        try {
            $ops->prepare("UPDATE `{$t}` SET product_binding_id = :to WHERE product_binding_id = :from")
                ->execute(['to' => $to, 'from' => $from]);
        } catch (Throwable) {
            // table may not exist on every install
        }
    }
}

function mergeProductInto(PDO $master, PDO $ops, int $keepId, int $loseId, array $keepRow, array $loseRow, array $tables, string $now, bool $dryRun): void
{
    if ($keepId === $loseId) {
        return;
    }
    $sets = [];
    $bind = ['id' => $keepId, 'u' => $now];
    $fill = static function (string $col, $keepVal, $loseVal, bool $emptyKeep) use (&$sets, &$bind): void {
        if ($emptyKeep && $loseVal !== null && $loseVal !== '') {
            $sets[] = "{$col} = :{$col}";
            $bind[$col] = $loseVal;
        }
    };
    $fill('generic_id', $keepRow['generic_id'] ?? null, $loseRow['generic_id'] ?? null, empty($keepRow['generic_id']));
    $fill('manufacturer', $keepRow['manufacturer'] ?? null, $loseRow['manufacturer'] ?? null, emptyMfg($keepRow['manufacturer'] ?? null) && !emptyMfg($loseRow['manufacturer'] ?? null));
    $fill('manufacturer_short', $keepRow['manufacturer_short'] ?? null, $loseRow['manufacturer_short'] ?? null, trim((string) ($keepRow['manufacturer_short'] ?? '')) === '');
    $fill('composition', $keepRow['composition'] ?? null, $loseRow['composition'] ?? null, trim((string) ($keepRow['composition'] ?? '')) === '');
    $fill('hsn_code', $keepRow['hsn_code'] ?? null, $loseRow['hsn_code'] ?? null, trim((string) ($keepRow['hsn_code'] ?? '')) === '');
    $fill('dosage_form', $keepRow['dosage_form'] ?? null, $loseRow['dosage_form'] ?? null, trim((string) ($keepRow['dosage_form'] ?? '')) === '');
    $fill('strength', $keepRow['strength'] ?? null, $loseRow['strength'] ?? null, trim((string) ($keepRow['strength'] ?? '')) === '');
    $fill('packing_desc', $keepRow['packing_desc'] ?? null, $loseRow['packing_desc'] ?? null, trim((string) ($keepRow['packing_desc'] ?? '')) === '');
    if ((float) ($keepRow['mrp'] ?? 0) <= 0 && (float) ($loseRow['mrp'] ?? 0) > 0) {
        $sets[] = 'mrp = :mrp';
        $bind['mrp'] = $loseRow['mrp'];
    }
    if (nameQuality((string) ($loseRow['name'] ?? '')) > nameQuality((string) ($keepRow['name'] ?? ''))) {
        $sets[] = 'name = :name';
        $bind['name'] = ProductName::clean((string) $loseRow['name']);
    }
    $schKeep = (string) ($keepRow['schedule_category'] ?? 'OTC');
    $schLose = (string) ($loseRow['schedule_category'] ?? 'OTC');
    if ($schKeep === 'OTC' && $schLose !== 'OTC') {
        $sets[] = 'schedule_category = :sch';
        $bind['sch'] = $schLose;
    }

    if ($dryRun) {
        return;
    }

    if ($sets) {
        $sql = 'UPDATE products SET ' . implode(', ', $sets) . ', updated_at = :u WHERE id = :id';
        $master->prepare($sql)->execute($bind);
        foreach ($bind as $k => $v) {
            if ($k !== 'id' && $k !== 'u') {
                $keepRow[$k === 'sch' ? 'schedule_category' : $k] = $v;
            }
        }
    }

    try {
        $master->prepare('DELETE FROM substitutes WHERE product_id = :l OR substitute_product_id = :l')->execute(['l' => $loseId]);
    } catch (Throwable) {
    }
    try {
        $ops->prepare('UPDATE stock_transfer_items SET master_product_id = :k WHERE master_product_id = :l')->execute(['k' => $keepId, 'l' => $loseId]);
    } catch (Throwable) {
    }

    $lb = $ops->prepare('SELECT id, client_id, branch_id FROM product_bindings WHERE master_product_id = ?');
    $lb->execute([$loseId]);
    foreach ($lb->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $from = (int) $row['id'];
        $find = $ops->prepare('SELECT id FROM product_bindings WHERE client_id = ? AND branch_id = ? AND master_product_id = ? LIMIT 1');
        $find->execute([$row['client_id'], $row['branch_id'], $keepId]);
        $kb = $find->fetch(PDO::FETCH_ASSOC);
        if ($kb) {
            reassignBinding($ops, $from, (int) $kb['id'], $tables);
            $ops->prepare('DELETE FROM product_bindings WHERE id = ?')->execute([$from]);
        } else {
            $ops->prepare(
                'UPDATE product_bindings SET master_product_id = :k, product_name = :n, generic_id = :g, manufacturer = :m, manufacturer_short = :ms, updated_at = :u WHERE id = :id'
            )->execute([
                'k' => $keepId,
                'n' => ProductName::clean((string) ($keepRow['name'] ?? $loseRow['name'] ?? '')),
                'g' => $keepRow['generic_id'] ?? $loseRow['generic_id'] ?? null,
                'm' => emptyMfg($keepRow['manufacturer'] ?? null) ? ($loseRow['manufacturer'] ?? null) : $keepRow['manufacturer'],
                'ms' => $keepRow['manufacturer_short'] ?? $loseRow['manufacturer_short'] ?? null,
                'u' => $now,
                'id' => $from,
            ]);
        }
    }

    $cntStmt = $ops->prepare('SELECT COUNT(*) FROM product_bindings WHERE master_product_id = ?');
    $cntStmt->execute([$loseId]);
    if ((int) $cntStmt->fetchColumn() > 0) {
        return;
    }
    try {
        $master->prepare('DELETE FROM substitutes WHERE product_id = :id OR substitute_product_id = :id')->execute(['id' => $loseId]);
    } catch (Throwable) {
    }
    $master->prepare('DELETE FROM products WHERE id = ?')->execute([$loseId]);
}

function fillMissingProduct(PDO $master, array $target, array $donor, string $now, bool $dryRun): int
{
    $sets = [];
    $bind = ['id' => (int) $target['id'], 'u' => $now];
    if (empty($target['generic_id']) && !empty($donor['generic_id'])) {
        $sets[] = 'generic_id = :gid';
        $bind['gid'] = $donor['generic_id'];
    }
    if (emptyMfg($target['manufacturer'] ?? null) && !emptyMfg($donor['manufacturer'] ?? null)) {
        $sets[] = 'manufacturer = :mfg';
        $bind['mfg'] = $donor['manufacturer'];
        if (trim((string) ($target['manufacturer_short'] ?? '')) === '' && trim((string) ($donor['manufacturer_short'] ?? '')) !== '') {
            $sets[] = 'manufacturer_short = :ms';
            $bind['ms'] = $donor['manufacturer_short'];
        }
    }
    if (trim((string) ($target['composition'] ?? '')) === '' && trim((string) ($donor['composition'] ?? '')) !== '') {
        $sets[] = 'composition = :comp';
        $bind['comp'] = $donor['composition'];
    }
    if (nameQuality((string) ($donor['name'] ?? '')) > nameQuality((string) ($target['name'] ?? ''))) {
        $sets[] = 'name = :name';
        $bind['name'] = ProductName::clean((string) $donor['name']);
    }
    if (!$sets) {
        return 0;
    }
    if (!$dryRun) {
        $master->prepare('UPDATE products SET ' . implode(', ', $sets) . ', updated_at = :u WHERE id = :id')->execute($bind);
    }
    return 1;
}

out($dryRun ? '[DRY-RUN] Catalog cleanup' : 'Catalog cleanup');
out('Master=' . $masterDb . ' Ops=' . $opsDb);

// ------------------------------------------------------------------
// 1) Duplicate generics
// ------------------------------------------------------------------
out("\n== 1) Merge duplicate generics ==");
$generics = $master->query('SELECT id, name, therapeutic_class, schedule_category, is_active FROM generics ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
$gGroups = [];
foreach ($generics as $g) {
    $k = compactKey((string) $g['name']);
    if ($k === '') {
        continue;
    }
    $gGroups[$k][] = $g;
}

if (!$dryRun) {
    $master->beginTransaction();
    $ops->beginTransaction();
}
$updProdG = $master->prepare('UPDATE products SET generic_id = :k, updated_at = :u WHERE generic_id = :l');
$updBindG = $ops->prepare("UPDATE `{$opsDb}`.product_bindings SET generic_id = :k, updated_at = :u WHERE generic_id = :l");
$updSubG = $master->prepare('UPDATE substitutes SET generic_id = :k WHERE generic_id = :l');
$delG = $master->prepare('DELETE FROM generics WHERE id = ?');

foreach ($gGroups as $rows) {
    if (count($rows) < 2) {
        continue;
    }
    usort($rows, static function ($a, $b) {
        $la = strlen((string) $a['name']);
        $lb = strlen((string) $b['name']);
        if ($la !== $lb) {
            return $lb <=> $la;
        }
        return (int) $a['id'] <=> (int) $b['id'];
    });
    $keep = $rows[0];
    $kid = (int) $keep['id'];
    foreach (array_slice($rows, 1) as $lose) {
        $lid = (int) $lose['id'];
        $stats['generic_merged']++;
        if ($dryRun) {
            $stats['generic_deleted']++;
            continue;
        }
        $updProdG->execute(['k' => $kid, 'l' => $lid, 'u' => $now]);
        try {
            $updBindG->execute(['k' => $kid, 'l' => $lid, 'u' => $now]);
        } catch (Throwable) {
        }
        try {
            $updSubG->execute(['k' => $kid, 'l' => $lid]);
        } catch (Throwable) {
        }
        $delG->execute([$lid]);
        $stats['generic_deleted']++;
    }
}
if (!$dryRun) {
    $ops->commit();
    $master->commit();
}
out('  merged groups extras: ' . $stats['generic_merged'] . ' deleted: ' . $stats['generic_deleted']);

// ------------------------------------------------------------------
// 2) Duplicate product brands (name + pack)
// ------------------------------------------------------------------
out("\n== 2) Merge duplicate product brands (name + pack) ==");
$usage = loadUsage($ops);
$tables = bindingTables($ops, $opsDb);
$products = $master->query(
    'SELECT id, name, generic_id, manufacturer, manufacturer_short, schedule_category,
            hsn_code, mrp, pack_size, dosage_form, strength, packing_desc, composition, is_active
     FROM products ORDER BY id ASC'
)->fetchAll(PDO::FETCH_ASSOC);

$pGroups = [];
foreach ($products as $p) {
    $pGroups[dupKey((string) $p['name'], $p['pack_size'] ?? null)][] = $p;
}

$byId = [];
foreach ($products as $p) {
    $byId[(int) $p['id']] = $p;
}

if (!$dryRun) {
    $master->beginTransaction();
    $ops->beginTransaction();
}

foreach ($pGroups as $rows) {
    if (count($rows) < 2) {
        continue;
    }
    $scored = [];
    foreach ($rows as $p) {
        $id = (int) $p['id'];
        $u = $usage[$id] ?? ['purchase' => 0, 'stock' => 0.0];
        $score = 0;
        if (isProtected($u)) {
            $score += 1_000_000;
        }
        if ((float) $u['stock'] > 0) {
            $score += 100_000;
        }
        if ((int) $u['purchase'] > 0) {
            $score += 10_000;
        }
        if (!empty($p['generic_id'])) {
            $score += 1_000;
        }
        if (!emptyMfg($p['manufacturer'] ?? null)) {
            $score += 200;
        }
        if (trim((string) ($p['composition'] ?? '')) !== '') {
            $score += 100;
        }
        if ((int) ($p['is_active'] ?? 1) === 1) {
            $score += 10;
        }
        $score += nameQuality((string) $p['name']);
        $score -= $id / 1_000_000; // stable: prefer older id
        $scored[] = ['row' => $p, 'u' => $u, 'score' => $score, 'id' => $id];
    }
    usort($scored, static fn ($a, $b) => $b['score'] <=> $a['score']);
    $keep = $scored[0];
    $keepId = $keep['id'];
    $keepRow = $byId[$keepId];

    $bestDonor = $keepRow;
    foreach ($scored as $item) {
        $r = $item['row'];
        if (!empty($r['generic_id']) && empty($bestDonor['generic_id'])) {
            $bestDonor = $r;
        }
        if (!emptyMfg($r['manufacturer'] ?? null) && emptyMfg($bestDonor['manufacturer'] ?? null)) {
            $bestDonor = $r;
        }
        if (trim((string) ($r['composition'] ?? '')) !== '' && trim((string) ($bestDonor['composition'] ?? '')) === '') {
            $bestDonor = $r;
        }
    }

    foreach ($scored as $item) {
        if ($item['id'] === $keepId) {
            continue;
        }
        if (isProtected($item['u'])) {
            $stats['product_skipped_protected']++;
            $n = fillMissingProduct($master, $item['row'], $bestDonor, $now, $dryRun);
            $stats['product_filled'] += $n;
            continue;
        }
        $stats['product_merged']++;
        mergeProductInto($master, $ops, $keepId, $item['id'], $keepRow, $item['row'], $tables, $now, $dryRun);
        if (!$dryRun) {
            $stats['product_deleted']++;
            unset($byId[$item['id']]);
            $fresh = $master->prepare('SELECT id, name, generic_id, manufacturer, manufacturer_short, schedule_category, hsn_code, mrp, pack_size, dosage_form, strength, packing_desc, composition, is_active FROM products WHERE id = ?');
            $fresh->execute([$keepId]);
            $got = $fresh->fetch(PDO::FETCH_ASSOC);
            if ($got) {
                $keepRow = $got;
                $byId[$keepId] = $got;
            }
        } else {
            $stats['product_deleted']++;
        }
    }
    $n = fillMissingProduct($master, $keepRow, $bestDonor, $now, $dryRun);
    $stats['product_filled'] += $n;
}

if (!$dryRun) {
    $ops->commit();
    $master->commit();
}
out('  merged: ' . $stats['product_merged'] . ' deleted: ' . $stats['product_deleted'] . ' kept(protected): ' . $stats['product_skipped_protected'] . ' fields filled: ' . $stats['product_filled']);

// ------------------------------------------------------------------
// 3) Seed + map generics
// ------------------------------------------------------------------
out("\n== 3) Seed missing generics and map brand names ==");

$byExact = [];
$byCompact = [];
foreach ($master->query('SELECT id, name FROM generics WHERE is_active = 1') as $g) {
    $id = (int) $g['id'];
    $name = (string) $g['name'];
    $row = ['id' => $id, 'name' => $name];
    $byExact[cleanComp($name)] = $row;
    $c = compactKey($name);
    if ($c !== '') {
        $byCompact[$c] = $row;
    }
}

$findGeneric = static function (string $compText) use (&$byExact, &$byCompact): ?array {
    $compText = trim($compText);
    if ($compText === '' || isBogusComp($compText)) {
        return null;
    }
    $n = cleanComp($compText);
    if (isset($byExact[$n])) {
        return $byExact[$n];
    }
    $c = compactKey($compText);
    if ($c !== '' && isset($byCompact[$c])) {
        return $byCompact[$c];
    }
    return null;
};

$insG = $master->prepare(
    'INSERT INTO generics (name, therapeutic_class, schedule_category, is_active, created_at, updated_at)
     VALUES (:name, NULL, :sch, 1, :c, :u)'
);

$seedSeq = 0;
$ensureGeneric = static function (string $compText, string $schedule = 'OTC') use (
    &$findGeneric, &$insG, &$byExact, &$byCompact, $now, $dryRun, &$stats, $master, &$seedSeq
): ?int {
    $hit = $findGeneric($compText);
    if ($hit && (int) $hit['id'] !== 0) {
        return (int) $hit['id'];
    }
    if ($hit && (int) $hit['id'] < 0) {
        return (int) $hit['id'];
    }
    if (!isCredibleComp($compText)) {
        return null;
    }
    $pretty = prettyGeneric($compText);
    if ($pretty === '' || mb_strlen($pretty) < 4) {
        return null;
    }
    $stats['generic_seeded']++;
    if ($dryRun) {
        $id = --$seedSeq;
        $fake = ['id' => $id, 'name' => $pretty];
        $byExact[cleanComp($pretty)] = $fake;
        $ck = compactKey($pretty);
        if ($ck !== '') {
            $byCompact[$ck] = $fake;
        }
        return $id;
    }
    $insG->execute(['name' => $pretty, 'sch' => $schedule ?: 'OTC', 'c' => $now, 'u' => $now]);
    $id = (int) $master->lastInsertId();
    $row = ['id' => $id, 'name' => $pretty];
    $byExact[cleanComp($pretty)] = $row;
    $ck = compactKey($pretty);
    if ($ck !== '') {
        $byCompact[$ck] = $row;
    }
    return $id;
};

$brandComp = [];
if (is_file($csvPath)) {
    out('  loading brand dataset…');
    $fh = fopen($csvPath, 'rb');
    fgetcsv($fh);
    $n = 0;
    while (($r = fgetcsv($fh)) !== false) {
        $c1 = trim((string) ($r[7] ?? ''));
        $c2 = trim((string) ($r[8] ?? ''));
        $comp = $c1;
        if ($c2 !== '') {
            $comp = $c1 . ' + ' . $c2;
        }
        if ($comp === '') {
            continue;
        }
        foreach (brandLookupKeys((string) ($r[1] ?? '')) as $k) {
            $brandComp[$k] ??= $comp;
        }
        $n++;
    }
    fclose($fh);
    out("  dataset rows: {$n}  brand keys: " . count($brandComp));
} else {
    out('  WARNING: dataset missing (' . $csvPath . ') — mapping from composition only');
}

$resolveBrandComp = static function (string $pname) use (&$brandComp): ?string {
    if (!$brandComp) {
        return null;
    }
    foreach (brandLookupKeys($pname) as $k) {
        if (!isset($brandComp[$k])) {
            continue;
        }
        $comp = $brandComp[$k];
        if (strengthsAgree($pname, $comp)) {
            return $comp;
        }
    }
    return null;
};

out('  clearing maps whose strength / form does not match the brand name…');
$mismatchStmt = $master->query(
    'SELECT p.id, p.name, p.composition, g.name AS gname
     FROM products p
     JOIN generics g ON g.id = p.generic_id'
);
$clearBoth = $master->prepare('UPDATE products SET generic_id = NULL, composition = NULL, updated_at = :u WHERE id = :id');
$clearGid = $master->prepare('UPDATE products SET generic_id = NULL, updated_at = :u WHERE id = :id');
$nonDrug = '/\b(inhalant|vaporub|balm|honey|cotton|syringe|soap|shampoo|face ?wash|sunscreen|bandage|gauze|boost|tonic)\b/i';
while ($row = $mismatchStmt->fetch(PDO::FETCH_ASSOC)) {
    $pname = (string) $row['name'];
    $comp = trim((string) ($row['composition'] ?? ''));
    $gname = (string) $row['gname'];
    $bad = false;
    $wipeComp = false;
    if ($comp !== '' && !strengthsAgree($pname, $comp)) {
        $bad = true;
        $wipeComp = true;
    }
    if (!$bad && preg_match($nonDrug, $pname) && $comp !== '' && preg_match('/\d+\s*(mg|mcg)\b/i', $comp)) {
        $bad = true;
        $wipeComp = $comp !== '';
    }
    if (!$bad) {
        continue;
    }
    $stats['unlinked_mismatch']++;
    if ($dryRun) {
        continue;
    }
    if ($wipeComp) {
        $clearBoth->execute(['u' => $now, 'id' => $row['id']]);
    } else {
        $clearGid->execute(['u' => $now, 'id' => $row['id']]);
    }
}
out('  unlinked mismatches: ' . $stats['unlinked_mismatch']);

if (!$dryRun) {
    $master->beginTransaction();
}
$updMap = $master->prepare('UPDATE products SET generic_id = :gid, composition = COALESCE(NULLIF(TRIM(composition), \'\'), :comp), updated_at = :u WHERE id = :id');
$updMapGid = $master->prepare('UPDATE products SET generic_id = :gid, updated_at = :u WHERE id = :id');

$stmt = $master->query(
    'SELECT id, name, composition, generic_id, schedule_category FROM products WHERE generic_id IS NULL OR generic_id = 0'
);
$processed = 0;
$unmappedStart = 0;
while ($p = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $processed++;
    $unmappedStart++;
    $pid = (int) $p['id'];
    $pname = (string) $p['name'];
    if (preg_match($nonDrug, $pname)) {
        continue;
    }
    $comp = trim((string) ($p['composition'] ?? ''));
    $brandC = $resolveBrandComp($pname);
    $useComp = '';
    if (isCredibleComp($comp) && strengthsAgree($pname, $comp)) {
        $useComp = $comp;
    } elseif ($brandC && !isBogusComp($brandC) && strengthsAgree($pname, $brandC)) {
        $useComp = $brandC;
    }

    $newGid = null;
    if ($useComp !== '') {
        $newGid = $ensureGeneric($useComp, (string) ($p['schedule_category'] ?? 'OTC'));
    }

    if ($newGid && $newGid !== 0) {
        $stats['products_mapped']++;
        if (!$dryRun && $newGid > 0) {
            if ($comp === '' && $useComp !== '') {
                $updMap->execute(['gid' => $newGid, 'comp' => mb_substr($useComp, 0, 255), 'u' => $now, 'id' => $pid]);
            } else {
                $updMapGid->execute(['gid' => $newGid, 'u' => $now, 'id' => $pid]);
            }
        }
    }

    if ($processed % 5000 === 0) {
        out("  … {$processed} unmapped (mapped {$stats['products_mapped']}, seeded {$stats['generic_seeded']})");
        if (!$dryRun && $master->inTransaction()) {
            $master->commit();
            $master->beginTransaction();
        }
    }
}
if (!$dryRun && $master->inTransaction()) {
    $master->commit();
}

$row = $master->query('SELECT COUNT(*) t, SUM(generic_id IS NOT NULL) linked, SUM(generic_id IS NULL) unlinked FROM products')->fetch(PDO::FETCH_ASSOC);
$stats['unmapped_left'] = (int) ($row['unlinked'] ?? 0);
out('  unmapped considered: ' . $unmappedStart);
out('  seeded generics: ' . $stats['generic_seeded'] . ' products mapped: ' . $stats['products_mapped']);
out('  linked now: ' . ($row['linked'] ?? '?') . ' / ' . ($row['t'] ?? '?') . ' (unlinked ' . $stats['unmapped_left'] . ')');

// ------------------------------------------------------------------
// 4) Sync bindings
// ------------------------------------------------------------------
out("\n== 4) Sync product_bindings.generic_id ==");
if (!$dryRun) {
    $sync = $ops->prepare(
        "UPDATE `{$opsDb}`.product_bindings pb
         INNER JOIN `{$masterDb}`.products p ON p.id = pb.master_product_id
         SET pb.generic_id = p.generic_id, pb.updated_at = :u
         WHERE IFNULL(pb.generic_id,0) <> IFNULL(p.generic_id,0)"
    );
    $sync->execute(['u' => $now]);
    $stats['bindings_synced'] = $sync->rowCount();
} else {
    $c = $ops->query(
        "SELECT COUNT(*) FROM `{$opsDb}`.product_bindings pb
         INNER JOIN `{$masterDb}`.products p ON p.id = pb.master_product_id
         WHERE IFNULL(pb.generic_id,0) <> IFNULL(p.generic_id,0)"
    )->fetchColumn();
    $stats['bindings_synced'] = (int) $c;
}
out('  bindings updated: ' . $stats['bindings_synced']);

out("\n=== Summary ===");
foreach ($stats as $k => $v) {
    out(str_pad($k, 28) . ': ' . $v);
}
out('Done.');
