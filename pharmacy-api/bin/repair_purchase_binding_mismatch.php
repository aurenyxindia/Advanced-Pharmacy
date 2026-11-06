<?php

/**
 * CLI: move purchase lines + inventory batches off the wrong product_binding_id.
 * Usage: ea-php82 bin/repair_purchase_binding_mismatch.php [client_id] [branch_id]
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

$result = (new PurchaseService())->repairMislinkedPurchaseBindings($clientId, $branchId);
echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
