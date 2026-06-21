<?php

/**
 * One-time backfill: creates an 'opening' stock-ledger entry for every batch
 * that currently has stock but no ledger history, so the stock book / FDA
 * tally / closing balances reconcile with current on-hand stock.
 */
$pdo = new PDO('mysql:host=127.0.0.1;dbname=pharma_ops;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$now = date('Y-m-d H:i:s');
$today = date('Y-m-d');

$batches = $pdo->query(
    'SELECT id, client_id, branch_id, product_binding_id, quantity, DATE(created_at) AS d
     FROM inventory_batches WHERE quantity > 0'
)->fetchAll(PDO::FETCH_ASSOC);

$ins = $pdo->prepare(
    "INSERT INTO stock_ledger (client_id, branch_id, product_binding_id, batch_id, txn_date, txn_type, qty_in, qty_out, ref_type, note, created_at)
     VALUES (:c, :b, :p, :batch, :date, 'opening', :qty, 0, 'backfill', 'Opening balance backfill', :now)"
);

$exists = $pdo->prepare('SELECT 1 FROM stock_ledger WHERE batch_id = :b LIMIT 1');

$count = 0;
foreach ($batches as $b) {
    $exists->execute(['b' => $b['id']]);
    if ($exists->fetchColumn()) {
        continue; // already has ledger movements
    }
    $ins->execute([
        'c' => $b['client_id'], 'b' => $b['branch_id'], 'p' => $b['product_binding_id'],
        'batch' => $b['id'], 'date' => $b['d'] ?: $today, 'qty' => (int) $b['quantity'], 'now' => $now,
    ]);
    $count++;
}

echo "opening ledger entries created: {$count}\n";
