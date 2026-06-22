<?php
/**
 * Migration 17 runner: add MRP column to the product master + bindings and
 * backfill existing rows by reversing the seeder's PTR formula:
 *   netMrp = ptr * (1 + ptrMargin/100);  mrp = netMrp * (1 + gst/100)
 */

$master = new PDO('mysql:host=127.0.0.1;dbname=pharma_master', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$ops    = new PDO('mysql:host=127.0.0.1;dbname=pharma_ops', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// 1) Schema changes
$master->exec("ALTER TABLE `pharma_master`.`products` ADD COLUMN IF NOT EXISTS `mrp` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `default_gst_rate`");
$ops->exec("ALTER TABLE `pharma_ops`.`product_bindings` ADD COLUMN IF NOT EXISTS `mrp` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `pts`");
echo "columns added\n";

// 2) Backfill master MRP from PTR (retailer margin by category)
$ptrMargin = ['Allopathy' => 20, 'Ayurvedic' => 25, 'Generic' => 40, 'Surgical' => 15, 'Cosmetic' => 18];
$rows = $master->query("SELECT id, ptr, default_gst_rate, product_category FROM products WHERE (mrp IS NULL OR mrp = 0) AND ptr > 0");
$upd = $master->prepare('UPDATE products SET mrp = :mrp WHERE id = :id');
$n = 0;
foreach ($rows as $r) {
    $margin = $ptrMargin[$r['product_category']] ?? 20;
    $gst = (float) $r['default_gst_rate'];
    $netMrp = (float) $r['ptr'] * (1 + $margin / 100);
    $mrp = round($netMrp * (1 + $gst / 100), 0); // MRP usually a round figure
    if ($mrp < 5) $mrp = 5;
    $upd->execute(['mrp' => $mrp, 'id' => $r['id']]);
    $n++;
}
echo "master MRP backfilled: {$n}\n";

// 3) Backfill existing bindings' MRP from master (prefer batch MRP if present)
$ops->exec(
    "UPDATE product_bindings pb
     JOIN pharma_master.products p ON p.id = pb.master_product_id
     SET pb.mrp = p.mrp
     WHERE (pb.mrp IS NULL OR pb.mrp = 0) AND p.mrp > 0"
);
echo "bindings MRP backfilled\n";

$sample = $master->query("SELECT name, mrp, ptr, default_gst_rate FROM products WHERE name LIKE 'Dolo%' LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
foreach ($sample as $s) {
    echo "  {$s['name']} mrp={$s['mrp']} ptr={$s['ptr']} gst={$s['default_gst_rate']}\n";
}
