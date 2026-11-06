<?php

declare(strict_types=1);

/**
 * Remove duplicate / near-duplicate master products.
 *
 * Groups products when pack matches AND either:
 *   - exact name (case-insensitive), or
 *   - similar brand name + same strength (e.g. "DOLO 500" + "Dolo 500mg Tab").
 *
 * Keeps the oldest row (lowest id). Newer rows are remapped then deleted.
 *
 * Usage:
 *   php bin/cleanup_duplicate_products.php [--dry-run]
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

function out(string $msg): void
{
    echo $msg . PHP_EOL;
}

function packKey(?string $pack): string
{
    $p = strtoupper(trim((string) $pack));
    $p = strtr($p, ['×' => 'X', '*' => 'X', 'x' => 'X']);
    $p = preg_replace(
        '/\b(TABLETS?|TABS?|CAPSULES?|CAPS?|BOTTLE|VIAL|SACHETS?|STRIP|NOS|PCS?|ML|GM|G|L|UNITS?)\b/i',
        '',
        $p
    ) ?? $p;
    $p = preg_replace('/\s+/', '', $p) ?? '';

    return $p;
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
    $n = preg_replace('/\b\d+\s*(TAB|TABLETS?|CAPS?|CAPSULES?)\b/i', ' ', $n) ?? $n;
    $n = preg_replace('/\b(STRIP OF|STRIP|PACK OF)\s*\d+\b/i', ' ', $n) ?? $n;
    $n = preg_replace(
        '/\b(TABLETS?|TABS?|CAPSULES?|CAPS?|SYRUP|SYP|SUSPENSION|SUSP|INJECTION|INJ|DROPS?|CREAM|OINTMENT|GEL|LOTION|SOLUTION|SACHET|BOTTLE|VIAL|CHEWABLE|DUO|FORTE|PLUS|KID|SR|ER|XR|CR|MR|OD|DS)\b/i',
        ' ',
        $n
    ) ?? $n;
    $n = preg_replace('/\s+/', ' ', trim($n)) ?? '';

    return $n;
}

function brandStem(string $normalizedName): string
{
    if (preg_match('/^([A-Z][A-Z0-9\-]+(?:\s+[A-Z]+)*?)(?:\s+\d|\s+\/|\s*$)/', $normalizedName, $m)) {
        return preg_replace('/\s+/', '', $m[1]) ?? '';
    }
    $parts = explode(' ', $normalizedName);

    return $parts[0] ?? '';
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

    if (isset($form)) {
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
            in_array($form, ['LOZENGE', 'LOZENGES'], true) => 'LOZ',
            in_array($form, ['RESPULE', 'RESPULES'], true) => 'RESP',
            default => $form,
        };
    }

    return 'GEN';
}

/** @return list<string> */
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
        // Bare number on a tablet-style name → treat as mg (Retail import habit).
        $units[] = $bare[1] . 'MG';
    }

    $units = array_values(array_unique($units));
    sort($units);

    return $units;
}

/** @param array<string,mixed> $row */
function exactDuplicateKey(array $row): string
{
    $name = strtoupper(trim((string) ($row['name'] ?? '')));
    $name = preg_replace('/\s+/', ' ', $name) ?? '';

    return $name . '|' . effectivePack($row);
}

/** @param array<string,mixed> $row */
function mergeGroupKey(array $row): ?string
{
    $pack = effectivePack($row);
    if ($pack === '') {
        return null;
    }

    $exact = exactDuplicateKey($row);
    $norm = normalizeProductNameForMatch((string) ($row['name'] ?? ''));
    if ($norm === '') {
        return 'EXACT:' . $exact;
    }

    $brand = brandStem($norm);
    if (strlen($brand) < 2) {
        return 'EXACT:' . $exact;
    }

    $strengths = strengthSignature($norm);
    if ($strengths === []) {
        return 'EXACT:' . $exact;
    }

    $form = dosageFormKey($row);

    return 'SIM:' . $brand . '|' . implode('+', $strengths) . '|' . $form . '|' . $pack;
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
        if ($t !== 'inventory_stock') {
            $tables[] = (string) $t;
        }
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
            $ops->prepare(
                'UPDATE inventory_stock SET quantity = quantity + ?, reorder_level = GREATEST(reorder_level, ?), updated_at = NOW() WHERE id = ?'
            )->execute([(int) $src['quantity'], (int) $src['reorder_level'], $dst['id']]);
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
        }
    }
}

function nameQuality(string $name): int
{
    $n = ProductName::clean($name);
    $q = strlen($n) > 0 ? 1 : 0;
    if ($n === strtoupper($n) || $n === ucwords(strtolower($n))) {
        $q += 3;
    }
    if (preg_match('/\d+\s*(MG|MCG|ML|G|IU|%)/i', $n)) {
        $q += 2;
    }

    return $q;
}

/** @param array<string,mixed> $keepRow @param array<string,mixed> $loseRow */
function mergeProductInto(
    PDO $master,
    PDO $ops,
    int $keepId,
    int $loseId,
    array $keepRow,
    array $loseRow,
    array $tables,
    string $now,
    bool $dryRun
): void {
    if ($keepId === $loseId) {
        return;
    }

    if ($dryRun) {
        return;
    }

    if (nameQuality((string) ($loseRow['name'] ?? '')) > nameQuality((string) ($keepRow['name'] ?? ''))) {
        $master->prepare('UPDATE products SET name = :n, updated_at = :u WHERE id = :id')->execute([
            'n' => ProductName::clean((string) $loseRow['name']),
            'u' => $now,
            'id' => $keepId,
        ]);
    }

    try {
        $master->prepare('DELETE FROM substitutes WHERE product_id = :l OR substitute_product_id = :l')->execute(['l' => $loseId]);
    } catch (Throwable) {
    }

    try {
        $ops->prepare('UPDATE stock_transfer_items SET master_product_id = :k WHERE master_product_id = :l')
            ->execute(['k' => $keepId, 'l' => $loseId]);
    } catch (Throwable) {
    }

    $loseBindings = $ops->prepare('SELECT id, client_id, branch_id FROM product_bindings WHERE master_product_id = ?');
    $loseBindings->execute([$loseId]);
    foreach ($loseBindings->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $from = (int) $row['id'];
        $find = $ops->prepare(
            'SELECT id FROM product_bindings WHERE client_id = ? AND branch_id = ? AND master_product_id = ? LIMIT 1'
        );
        $find->execute([$row['client_id'], $row['branch_id'], $keepId]);
        $keeperBinding = $find->fetch(PDO::FETCH_ASSOC);
        if ($keeperBinding) {
            reassignBinding($ops, $from, (int) $keeperBinding['id'], $tables);
            $ops->prepare('DELETE FROM product_bindings WHERE id = ?')->execute([$from]);
        } else {
            $ops->prepare(
                'UPDATE product_bindings SET master_product_id = :k, product_name = :n, updated_at = :u WHERE id = :id'
            )->execute([
                'k' => $keepId,
                'n' => ProductName::clean((string) ($keepRow['name'] ?? $loseRow['name'] ?? '')),
                'u' => $now,
                'id' => $from,
            ]);
        }
    }

    $left = $ops->prepare('SELECT COUNT(*) FROM product_bindings WHERE master_product_id = ?');
    $left->execute([$loseId]);
    if ((int) $left->fetchColumn() > 0) {
        out("  skip delete id={$loseId} — bindings still attached after remap");

        return;
    }

    try {
        $master->prepare('DELETE FROM substitutes WHERE product_id = :id OR substitute_product_id = :id')->execute(['id' => $loseId]);
    } catch (Throwable) {
    }
    $master->prepare('DELETE FROM products WHERE id = ?')->execute([$loseId]);
}

try {
out($dryRun ? '[DRY-RUN] Duplicate product cleanup' : 'Duplicate product cleanup');
out('Master=' . $masterDb . ' Ops=' . $opsDb);
out('Rule: same pack + (exact name OR same brand+strength) -> keep lowest id');

$tables = bindingTables($ops, $opsDb);
$products = $master->query(
    'SELECT id, name, pack_size, packing_desc, created_at FROM products ORDER BY id ASC'
)->fetchAll(PDO::FETCH_ASSOC);

$groups = [];
foreach ($products as $row) {
    $key = mergeGroupKey($row);
    if ($key === null || $key === 'EXACT:|') {
        continue;
    }
    $groups[$key][] = $row;
}

$duplicateGroups = 0;
$deleted = 0;
$skipped = 0;

if (!$dryRun) {
    $master->beginTransaction();
    $ops->beginTransaction();
}

foreach ($groups as $key => $rows) {
    if (count($rows) < 2) {
        continue;
    }

    usort($rows, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
    $keeper = $rows[0];
    $keepId = (int) $keeper['id'];
    $duplicateGroups++;

    out(sprintf(
        'Group %s -> keep id=%d (%s | %s)',
        $key,
        $keepId,
        $keeper['name'],
        trim((string) ($keeper['pack_size'] ?? $keeper['packing_desc'] ?? ''))
    ));

    foreach (array_slice($rows, 1) as $lose) {
        $loseId = (int) $lose['id'];
        out(sprintf('  delete id=%d (%s) created=%s', $loseId, $lose['name'], $lose['created_at'] ?? '-'));
        mergeProductInto($master, $ops, $keepId, $loseId, $keeper, $lose, $tables, $now, $dryRun);
        if (!$dryRun) {
            $check = $master->prepare('SELECT id FROM products WHERE id = ?');
            $check->execute([$loseId]);
            if ($check->fetchColumn() === false) {
                $deleted++;
            } else {
                $skipped++;
            }
        } else {
            $deleted++;
        }
    }
}

if (!$dryRun) {
    $ops->commit();
    $master->commit();
}

out('');
out('Duplicate groups : ' . $duplicateGroups);
out('Products deleted : ' . $deleted . ($dryRun ? ' (dry-run)' : ''));
out('Products skipped  : ' . $skipped);
out('Done.');
} catch (Throwable $e) {
    out('ERROR: ' . $e->getMessage());
    if (!$dryRun) {
        if ($ops->inTransaction()) {
            $ops->rollBack();
        }
        if ($master->inTransaction()) {
            $master->rollBack();
        }
    }
    exit(1);
}
