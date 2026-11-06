<?php

declare(strict_types=1);

/**
 * Delete master products listed in an approved review CSV (delete_id column).
 *
 * Usage:
 *   php bin/delete_products_from_csv.php storage/review_dummy_corrupt_only.csv [--dry-run]
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\App;
use App\Database\Connection;
use App\Support\Env;

set_time_limit(0);
ini_set('memory_limit', '512M');

$app = new App(dirname(__DIR__));
$dryRun = in_array('--dry-run', $argv ?? [], true);

$csvPath = null;
foreach (array_slice($argv ?? [], 1) as $arg) {
    if ($arg === '--dry-run') {
        continue;
    }
    if ($csvPath === null) {
        $csvPath = $arg;
    }
}

if ($csvPath === null || $csvPath === '') {
    fwrite(STDERR, "Usage: php bin/delete_products_from_csv.php <csv-file> [--dry-run]\n");
    exit(1);
}

if (!str_starts_with($csvPath, '/') && !preg_match('/^[A-Za-z]:\\\\/', $csvPath)) {
    $csvPath = dirname(__DIR__) . '/' . ltrim($csvPath, '/\\');
}

if (!is_readable($csvPath)) {
    fwrite(STDERR, "CSV not readable: {$csvPath}\n");
    exit(1);
}

$ids = [];
$rawCsv = file_get_contents($csvPath);
if ($rawCsv === false) {
    fwrite(STDERR, "Cannot read CSV\n");
    exit(1);
}
$rawCsv = preg_replace('/^\xEF\xBB\xBF/', '', $rawCsv) ?? $rawCsv;
$lines = preg_split('/\R/', $rawCsv) ?: [];
if ($lines === []) {
    fwrite(STDERR, "Empty CSV\n");
    exit(1);
}

$normalizeCol = static function ($col): string {
    $col = (string) $col;

    return trim($col, " \t\n\r\0\x0B\"");
};

$header = str_getcsv((string) array_shift($lines));
$header = array_map($normalizeCol, $header);
$deleteIdx = array_search('delete_id', $header, true);
if ($deleteIdx === false) {
    foreach ($header as $i => $col) {
        if (str_contains(strtolower($col), 'delete_id')) {
            $deleteIdx = $i;
            break;
        }
    }
}
if ($deleteIdx === false) {
    fwrite(STDERR, 'CSV must have delete_id column. Header=' . implode('|', $header) . "\n");
    exit(1);
}

foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    $row = str_getcsv($line);
    $id = (int) trim((string) ($row[$deleteIdx] ?? ''));
    if ($id > 0) {
        $ids[$id] = true;
    }
}
$ids = array_keys($ids);
sort($ids);

if ($ids === []) {
    fwrite(STDERR, "No product ids in CSV\n");
    exit(1);
}

$master = Connection::master();
$ops = Connection::ops();
$master->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$ops->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$opsDb = (string) Env::get('DB_OPS', 'pharma_ops');

function out(string $msg): void
{
    echo $msg . PHP_EOL;
}

/** @return list<string> */
function bindingRefTables(PDO $ops, string $opsDb): array
{
    $stmt = $ops->prepare(
        "SELECT TABLE_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :db AND COLUMN_NAME = 'product_binding_id'"
    );
    $stmt->execute(['db' => $opsDb]);
    $skip = ['inventory_stock', 'product_bindings'];
    $tables = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $t = (string) $t;
        if (!in_array($t, $skip, true)) {
            $tables[] = $t;
        }
    }

    return $tables;
}

/** @return array{blocked:bool,reason:string,refs:int} */
function bindingUsage(PDO $ops, int $bindingId, array $refTables): array
{
    $total = 0;
    foreach ($refTables as $table) {
        try {
            $stmt = $ops->prepare("SELECT COUNT(*) FROM `{$table}` WHERE product_binding_id = ?");
            $stmt->execute([$bindingId]);
            $total += (int) $stmt->fetchColumn();
        } catch (Throwable) {
        }
    }

    if ($total > 0) {
        return ['blocked' => true, 'reason' => "binding {$bindingId} referenced in ops ({$total} rows)", 'refs' => $total];
    }

    return ['blocked' => false, 'reason' => '', 'refs' => 0];
}

/** @return array{ok:bool,reason:string} */
function deleteStandaloneProduct(PDO $master, PDO $ops, int $productId, array $refTables, bool $dryRun): array
{
    $row = $master->prepare('SELECT id, name FROM products WHERE id = ?');
    $row->execute([$productId]);
    $product = $row->fetch(PDO::FETCH_ASSOC);
    if (!$product) {
        return ['ok' => false, 'reason' => 'not found'];
    }

    $bindings = $ops->prepare('SELECT id FROM product_bindings WHERE master_product_id = ?');
    $bindings->execute([$productId]);
    $bindingIds = array_map(static fn (array $r): int => (int) $r['id'], $bindings->fetchAll(PDO::FETCH_ASSOC));

    foreach ($bindingIds as $bid) {
        $usage = bindingUsage($ops, $bid, $refTables);
        if ($usage['blocked']) {
            return ['ok' => false, 'reason' => $usage['reason']];
        }
    }

    try {
        $st = $ops->prepare('SELECT COUNT(*) FROM stock_transfer_items WHERE master_product_id = ?');
        $st->execute([$productId]);
        if ((int) $st->fetchColumn() > 0) {
            return ['ok' => false, 'reason' => 'stock_transfer_items reference'];
        }
    } catch (Throwable) {
    }

    if ($dryRun) {
        return ['ok' => true, 'reason' => 'dry-run'];
    }

    foreach ($bindingIds as $bid) {
        $ops->prepare('DELETE FROM inventory_stock WHERE product_binding_id = ?')->execute([$bid]);
        $ops->prepare('DELETE FROM product_bindings WHERE id = ?')->execute([$bid]);
    }

    try {
        $master->prepare('DELETE FROM substitutes WHERE product_id = :id OR substitute_product_id = :id')
            ->execute(['id' => $productId]);
    } catch (Throwable) {
    }

    $master->prepare('DELETE FROM products WHERE id = ?')->execute([$productId]);

    return ['ok' => true, 'reason' => 'deleted'];
}

out($dryRun ? '[DRY-RUN] Delete products from CSV' : 'Delete products from CSV');
out('CSV: ' . $csvPath);
out('Ids to delete: ' . count($ids));

$refTables = bindingRefTables($ops, $opsDb);
$deleted = 0;
$skipped = 0;
$missing = 0;

if (!$dryRun) {
    $master->beginTransaction();
    $ops->beginTransaction();
}

try {
    foreach ($ids as $id) {
        $result = deleteStandaloneProduct($master, $ops, $id, $refTables, $dryRun);
        if ($result['ok']) {
            $deleted++;
            out(sprintf('  deleted id=%d%s', $id, $dryRun ? ' (dry-run)' : ''));
        } elseif ($result['reason'] === 'not found') {
            $missing++;
            out(sprintf('  missing id=%d', $id));
        } else {
            $skipped++;
            out(sprintf('  SKIP id=%d — %s', $id, $result['reason']));
        }
    }

    if (!$dryRun) {
        $ops->commit();
        $master->commit();
    }
} catch (Throwable $e) {
    if (!$dryRun) {
        if ($ops->inTransaction()) {
            $ops->rollBack();
        }
        if ($master->inTransaction()) {
            $master->rollBack();
        }
    }
    out('ERROR: ' . $e->getMessage());
    exit(1);
}

out('');
out('Deleted : ' . $deleted . ($dryRun ? ' (dry-run)' : ''));
out('Skipped  : ' . $skipped);
out('Missing  : ' . $missing);
out('Done.');
