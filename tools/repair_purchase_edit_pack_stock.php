<?php
/**
 * Dry-run / apply repair for purchase-edit pack/UPP stock drift.
 *
 * Bug: reverse+repost used clamped reduceBatch, so after sales/stock-adj a pack-size
 * edit left on-hand wrong. Correct: stock_after = stock_before + new_units - old_units.
 *
 * Usage (from repo root, with pharmacy-api config):
 *   php tools/repair_purchase_edit_pack_stock.php --client=ID [--branch=ID] [--apply]
 *   php tools/repair_purchase_edit_pack_stock.php --client=ID --user=Puja --apply
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/pharmacy-api/vendor/autoload.php';

use App\Core\Config;
use App\Database\Connection;
use App\Services\InventoryService;

Config::load($root . '/pharmacy-api/config');

$opts = getopt('', ['client:', 'branch::', 'user::', 'apply', 'limit::']);
$clientId = (int) ($opts['client'] ?? 0);
$branchId = isset($opts['branch']) ? (int) $opts['branch'] : 0;
$userLike = trim((string) ($opts['user'] ?? ''));
$apply = array_key_exists('apply', $opts);
$limit = max(1, (int) ($opts['limit'] ?? 500));

if ($clientId <= 0) {
    fwrite(STDERR, "Required: --client=ID\n");
    exit(1);
}

$db = Connection::ops();
$sql = "SELECT id, branch_id, purchase_id, product_binding_id, batch_id, product_name, batch_no,
               old_pack, new_pack, old_units, new_units, stock_before, stock_after, user_name, created_at
        FROM purchase_edit_audit_log
        WHERE client_id = :c
          AND change_type = 'EDITED'
          AND old_units <> new_units
          AND batch_id IS NOT NULL";
$params = ['c' => $clientId];
if ($branchId > 0) {
    $sql .= ' AND branch_id = :b';
    $params['b'] = $branchId;
}
if ($userLike !== '') {
    $sql .= ' AND user_name LIKE :u';
    $params['u'] = '%' . $userLike . '%';
}
$sql .= ' ORDER BY id DESC LIMIT ' . $limit;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$inv = new InventoryService();
$fixed = 0;
$skipped = 0;

echo ($apply ? "APPLY" : "DRY-RUN") . " client={$clientId} rows=" . count($rows) . PHP_EOL;

foreach ($rows as $r) {
    $oldU = (int) $r['old_units'];
    $newU = (int) $r['new_units'];
    $before = (int) $r['stock_before'];
    $after = (int) $r['stock_after'];
    $expected = $before + ($newU - $oldU);
    if ($expected < 0) {
        $expected = 0;
    }
    $drift = $after - $expected; // positive => stock left too high at edit time
    if ($drift === 0) {
        $skipped++;
        continue;
    }

    $batchId = (int) $r['batch_id'];
    $bq = $db->prepare('SELECT quantity FROM inventory_batches WHERE id = :id AND client_id = :c LIMIT 1');
    $bq->execute(['id' => $batchId, 'c' => $clientId]);
    $have = $bq->fetchColumn();
    if ($have === false) {
        echo "SKIP missing batch {$batchId} {$r['product_name']}\n";
        $skipped++;
        continue;
    }
    $have = (int) $have;
    // Move current stock back by the same drift introduced at edit time.
    $target = $have - $drift;
    if ($target < 0) {
        $target = 0;
    }
    $delta = $target - $have;

    echo sprintf(
        "#%s %s batch %s | pack %s→%s units %d→%d | stock_before=%d after=%d expected=%d drift=%d | now=%d → %d (delta %+d) | %s %s\n",
        $r['id'],
        $r['product_name'],
        $r['batch_no'],
        $r['old_pack'] ?? '-',
        $r['new_pack'] ?? '-',
        $oldU,
        $newU,
        $before,
        $after,
        $expected,
        $drift,
        $have,
        $target,
        $delta,
        $r['user_name'] ?? '',
        $r['created_at'] ?? ''
    );

    if (!$apply || $delta === 0) {
        continue;
    }

    $inv->adjustBatchByDelta(
        $batchId,
        $delta,
        'adjustment',
        date('Y-m-d'),
        'purchase',
        (int) $r['purchase_id'],
        'Repair purchase-edit pack/UPP stock drift (audit #' . $r['id'] . ')',
        true
    );
    $fixed++;
}

echo "Done. would_fix_or_fixed={$fixed} unchanged={$skipped}\n";
