<?php

/**
 * Backfills manufacturer_short (Mfg bill code) for products that were seeded
 * before the column existed, and propagates it to any existing bindings.
 */

$pdo = new PDO('mysql:host=127.0.0.1;dbname=pharma_master;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$ops = new PDO('mysql:host=127.0.0.1;dbname=pharma_ops;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$map = [
    'Cipla' => 'CIP', 'Sun Pharma' => 'SUN', 'Mankind Pharma' => 'MKD', 'Alkem Laboratories' => 'ALK',
    "Dr. Reddy's" => 'DRL', 'Lupin' => 'LUP', 'Torrent Pharma' => 'TOR', 'Zydus Cadila' => 'ZYD',
    'Abbott India' => 'ABT', 'GlaxoSmithKline' => 'GSK', 'Micro Labs' => 'MIC', 'Intas Pharma' => 'INT',
    'Glenmark' => 'GLN', 'Aristo Pharma' => 'ARI', 'Macleods Pharma' => 'MAC', 'Ipca Laboratories' => 'IPC',
    'Mankind' => 'MKD', 'Emcure' => 'EMC', 'Wockhardt' => 'WOC', 'Cadila Pharma' => 'CAD',
    'USV' => 'USV', 'Sanofi India' => 'SAN', 'Pfizer India' => 'PFZ', 'Eris Lifesciences' => 'ERS',
    'Ajanta Pharma' => 'AJA', 'FDC Ltd' => 'FDC', 'Hetero Drugs' => 'HET', 'Biocon' => 'BIO',
    'Indoco Remedies' => 'IND', 'Unichem' => 'UNI', 'Albert David' => 'ALB', 'Bharat Serums' => 'BSV',
    'Mylan' => 'MYL', 'Novartis India' => 'NOV', 'Troikaa' => 'TRK', 'Bal Pharma' => 'BAL',
    'Khandelwal Lab' => 'KHA', 'Leeford' => 'LEE', 'Systopic' => 'SYS', 'Walter Bushnell' => 'WB',
    'Akumentis' => 'AKU', 'Corona Remedies' => 'COR', 'La Renon' => 'LAR', 'Mankind Lifesciences' => 'MKL',
    'Stadmed' => 'STD', 'Themis Medicare' => 'THM', 'Blue Cross' => 'BLU', 'Centaur Pharma' => 'CEN',
    'East West Pharma' => 'EWP', 'Zuventus' => 'ZUV', 'Dabur' => 'DAB', 'Himalaya' => 'HIM',
    'Patanjali' => 'PAT', 'Baidyanath' => 'BAI', 'Charak' => 'CHK',
];

$upd = $pdo->prepare('UPDATE products SET manufacturer_short = :short WHERE manufacturer = :mfr AND (manufacturer_short IS NULL OR manufacturer_short = "")');
$total = 0;
foreach ($map as $mfr => $short) {
    $upd->execute(['short' => $short, 'mfr' => $mfr]);
    $total += $upd->rowCount();
}
echo "products updated: {$total}\n";

// Fallback: any product still without a short gets an auto code from its name.
$pdo->exec("UPDATE products SET manufacturer_short = UPPER(LEFT(REPLACE(manufacturer,' ',''),3)) WHERE (manufacturer_short IS NULL OR manufacturer_short='') AND manufacturer IS NOT NULL");

// Propagate to existing bindings.
$ops->exec(
    'UPDATE product_bindings b
     JOIN pharma_master.products p ON p.id = b.master_product_id
     SET b.manufacturer = p.manufacturer, b.manufacturer_short = p.manufacturer_short'
);
echo "bindings synced.\n";
