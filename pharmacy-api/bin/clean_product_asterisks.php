<?php

declare(strict_types=1);

/**
 * One-shot: remove * / ** from product names in master + ops denormalized tables.
 * Usage (from pharmacy-api): php bin/clean_product_asterisks.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\Config;
use App\Database\Connection;
use App\Support\ProductName;
use Dotenv\Dotenv;

$base = dirname(__DIR__);
$configDir = $base . '/config';
if (is_file($configDir . '/.env')) {
    Dotenv::createImmutable($configDir, '.env')->safeLoad();
}
Config::load($configDir);

function cleanTable(PDO $pdo, string $table, string $column, string $idColumn = 'id'): int
{
    $changed = 0;
    $stmt = $pdo->query("SELECT {$idColumn} AS id, {$column} AS name FROM {$table} WHERE {$column} LIKE '%*%'");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $upd = $pdo->prepare("UPDATE {$table} SET {$column} = :n WHERE {$idColumn} = :id");
    foreach ($rows as $row) {
        $raw = (string) ($row['name'] ?? '');
        $clean = ProductName::clean($raw);
        if ($clean === $raw) {
            continue;
        }
        $upd->execute(['n' => $clean, 'id' => $row['id']]);
        $changed++;
    }
    return $changed;
}

$master = Connection::master();
$ops = Connection::ops();

$m = cleanTable($master, 'products', 'name');
$b = cleanTable($ops, 'product_bindings', 'product_name');
$s = cleanTable($ops, 'sale_items', 'product_name');
$p = cleanTable($ops, 'purchase_items', 'product_name');

echo json_encode([
    'products' => $m,
    'product_bindings' => $b,
    'sale_items' => $s,
    'purchase_items' => $p,
], JSON_PRETTY_PRINT) . PHP_EOL;
