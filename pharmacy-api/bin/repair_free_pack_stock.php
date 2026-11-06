<?php

/**
 * CLI: repair free-pack undercount on inventory (all clients or one).
 * Usage: ea-php82 bin/repair_free_pack_stock.php [client_id] [branch_id]
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\App;
use App\Services\PurchaseService;

$app = new App(dirname(__DIR__));

$clientId = isset($argv[1]) ? (int) $argv[1] : null;
$branchId = isset($argv[2]) ? (int) $argv[2] : null;
if ($clientId !== null && $clientId <= 0) {
    $clientId = null;
}
if ($branchId !== null && $branchId <= 0) {
    $branchId = null;
}

$result = (new PurchaseService())->repairFreePackUndercount($clientId, $branchId);
echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
