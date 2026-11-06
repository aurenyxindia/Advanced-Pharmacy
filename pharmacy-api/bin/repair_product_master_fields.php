<?php

declare(strict_types=1);

/**
 * Repair Product Master: schedule vs OTC, margin category, dosage form, strength.
 *
 * Usage: php bin/repair_product_master_fields.php [--dry-run]
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\App;
use App\Database\Connection;
use App\Support\ProductFieldParser;

set_time_limit(0);

$app = new App(dirname(__DIR__));
$dryRun = in_array('--dry-run', $argv ?? [], true);

$master = Connection::master();
$ops = Connection::ops();
$now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

$stats = [
    'products' => 0,
    'schedule_upgraded' => 0,
    'schedule_otc_general' => 0,
    'category_fixed' => 0,
    'dosage_filled' => 0,
    'strength_filled' => 0,
    'swapped_fixed' => 0,
    'updated' => 0,
    'bindings_synced' => 0,
];

echo ($dryRun ? '[DRY-RUN] ' : '') . "Product master field repair\n";

$sql = 'SELECT p.id, p.name, p.product_type, p.schedule_category, p.product_category,
               p.dosage_form, p.strength, p.packing_desc, p.pack_size, p.composition,
               g.name AS generic_name, g.schedule_category AS generic_schedule
        FROM products p
        LEFT JOIN generics g ON g.id = p.generic_id
        ORDER BY p.id ASC';

$upd = $master->prepare(
    'UPDATE products SET schedule_category = :sch, product_category = :pcat,
            dosage_form = :dos, strength = :str, updated_at = :u WHERE id = :id'
);

foreach ($master->query($sql) as $row) {
    $stats['products']++;
    $id = (int) $row['id'];
    $name = (string) $row['name'];
    $ptype = (string) ($row['product_type'] ?? 'Medicine');
    $sch = (string) ($row['schedule_category'] ?? 'OTC');
    $pcat = (string) ($row['product_category'] ?? 'Allopathy');
    $dos = trim((string) ($row['dosage_form'] ?? ''));
    $str = trim((string) ($row['strength'] ?? ''));

    $newSch = $sch;
    $newPcat = ProductFieldParser::normalizeProductCategory($ptype, $pcat);
    $inferred = ProductFieldParser::inferSchedule(
        $name,
        $row['generic_name'] ?? null,
        $row['composition'] ?? null,
        $row['generic_schedule'] ?? null
    );

    if (ProductFieldParser::isLikelyOtcGeneral($name, $ptype)) {
        if (in_array($sch, ['H', 'H1', 'X', 'RESTRICTED'], true)) {
            $newSch = 'OTC';
            $stats['schedule_otc_general']++;
        }
    } elseif (ProductFieldParser::scheduleShouldUpgrade($sch, $inferred)) {
        $newSch = $inferred;
        $stats['schedule_upgraded']++;
    } elseif ($sch === 'OTC' && $inferred !== 'OTC' && $ptype === 'Medicine') {
        $newSch = $inferred;
        $stats['schedule_upgraded']++;
    }

    $newDos = $dos !== '' ? strtoupper($dos) : null;
    $newStr = $str !== '' ? $str : null;

    if ($ptype === 'Medicine') {
        [$fixDos, $fixStr] = ProductFieldParser::fixSwappedDosageStrength($newDos, $newStr);
        if ($fixDos !== $newDos || $fixStr !== $newStr) {
            $stats['swapped_fixed']++;
        }
        $newDos = $fixDos;
        $newStr = $fixStr;

        if ($newDos === null || $newDos === '') {
            $parsed = ProductFieldParser::parseDosageForm($name, $row['packing_desc'] ?? null, $row['pack_size'] ?? null);
            if ($parsed !== null) {
                $newDos = $parsed;
                $stats['dosage_filled']++;
            }
        }
        $parsedS = ProductFieldParser::parseStrength($name, $row['composition'] ?? null, $newStr);
        if ($parsedS !== null) {
            $suspicious = $newStr !== null && $newStr !== ''
                && preg_match('/^\d{1,2} MG$/i', $newStr)
                && preg_match('/\b(\d{3,4})\s*(?:TAB|TABLET|CAP|CAPSULE|DT|SR|XR|CR|MR)\b/i', $name);
            if ($newStr === null || $newStr === '' || $suspicious) {
                if ($newStr === null || $newStr === '') {
                    $stats['strength_filled']++;
                } else {
                    $stats['strength_filled']++;
                }
                $newStr = $parsedS;
            }
        }
    } else {
        $newDos = null;
        $newStr = null;
    }

    $changed = ($newSch !== $sch)
        || ($newPcat !== $pcat)
        || (($newDos ?? '') !== $dos)
        || (($newStr ?? '') !== $str);

    if ($newPcat !== $pcat) {
        $stats['category_fixed']++;
    }

    if (!$changed) {
        continue;
    }

    $stats['updated']++;
    if ($dryRun) {
        continue;
    }

    $upd->execute([
        'sch' => $newSch,
        'pcat' => $newPcat,
        'dos' => $newDos,
        'str' => $newStr,
        'u' => $now,
        'id' => $id,
    ]);

    if ($stats['updated'] % 2000 === 0) {
        echo "  … {$stats['updated']} rows updated\n";
    }
}

if (!$dryRun) {
    $sync = $ops->prepare(
        'UPDATE product_bindings pb
         INNER JOIN products p ON p.id = pb.master_product_id
         SET pb.schedule_category = p.schedule_category,
             pb.product_category = p.product_category,
             pb.updated_at = :u
         WHERE pb.schedule_category <> p.schedule_category
            OR IFNULL(pb.product_category, \'\') <> IFNULL(p.product_category, \'\')'
    );
    $sync->execute(['u' => $now]);
    $stats['bindings_synced'] = $sync->rowCount();
}

echo "\n=== Summary ===\n";
foreach ($stats as $k => $v) {
    echo str_pad($k, 22) . ": {$v}\n";
}

$counts = $master->query(
    "SELECT schedule_category, COUNT(*) c FROM products GROUP BY schedule_category ORDER BY FIELD(schedule_category,'OTC','H','H1','G','X','RESTRICTED')"
)->fetchAll();
echo "\nSchedule distribution:\n";
foreach ($counts as $c) {
    echo "  {$c['schedule_category']}: {$c['c']}\n";
}

$med = $master->query(
    "SELECT
        SUM(product_type='Medicine' AND (dosage_form IS NULL OR TRIM(dosage_form)='')) empty_dos,
        SUM(product_type='Medicine' AND (strength IS NULL OR TRIM(strength)='') AND name REGEXP '[0-9]+[[:space:]]*(MG|MCG|ML|GM|G|%|IU)') empty_str
     FROM products"
)->fetch();
echo "\nMedicine still missing dosage: " . ($med['empty_dos'] ?? '?') . "\n";
echo "Medicine still missing strength (name has dose): " . ($med['empty_str'] ?? '?') . "\n";
echo "Done.\n";
