<?php

declare(strict_types=1);

/**
 * One-shot: import Jeevan Medico opening stock for client_id=22 / branch_id=24.
 * Usage: ea-php82 scripts/import-jeevan-opening-stock.php /path/to/jeevan-opening-stock.json
 *
 * - Does NOT create purchase bills
 * - Qty / MRP / rate / pack / expiry come from Excel JSON (not master overrides)
 * - TAB/CAP with pack>1: qty is loose tablets; unit MRP = pack MRP / pack
 * - Other products: qty is unit qty; MRP is per unit
 */

use App\Core\Config;
use App\Database\Connection;
use App\Models\Product;
use App\Services\InventoryService;
use App\Services\ProductBindingService;
use App\Support\ProductName;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

$configDir = dirname(__DIR__) . '/config';
if (is_file($configDir . '/.env')) {
    Dotenv::createImmutable($configDir, '.env')->safeLoad();
}
Config::load($configDir);
date_default_timezone_set((string) Config::get('app.timezone', 'Asia/Kolkata'));

$jsonPath = $argv[1] ?? '';
if ($jsonPath === '' || !is_file($jsonPath)) {
    fwrite(STDERR, "Usage: php import-jeevan-opening-stock.php /path/to/jeevan-opening-stock.json\n");
    exit(1);
}

$payload = json_decode((string) file_get_contents($jsonPath), true);
if (!is_array($payload) || empty($payload['rows']) || !is_array($payload['rows'])) {
    fwrite(STDERR, "Invalid JSON payload.\n");
    exit(1);
}

$clientId = (int) ($payload['client_id'] ?? 22);
$branchId = (int) ($payload['branch_id'] ?? 24);
$openingDate = (string) ($payload['opening_date'] ?? date('Y-m-d'));
$rows = $payload['rows'];

if ($clientId !== 22) {
    fwrite(STDERR, "Refusing: this importer is locked to client_id=22 (got {$clientId}).\n");
    exit(1);
}

fwrite(STDOUT, "Loading master product name index…\n");
$master = Connection::master();
$stmt = $master->query('SELECT id, name FROM products WHERE is_active = 1');
$byName = [];
$byCompact = [];
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $n = ProductName::clean((string) $r['name']);
    $key = mb_strtoupper($n);
    if ($key === '') {
        continue;
    }
    if (!isset($byName[$key])) {
        $byName[$key] = (int) $r['id'];
    }
    $compact = preg_replace('/[^A-Z0-9]/', '', $key) ?? '';
    if ($compact !== '' && !isset($byCompact[$compact])) {
        $byCompact[$compact] = (int) $r['id'];
    }
}
fwrite(STDOUT, 'Master index: ' . count($byName) . " names\n");

$binder = new ProductBindingService();
$inv = new InventoryService();
$productModel = new Product();

$refNo = sprintf('OS-C%d-B%d-%s', $clientId, $branchId, date('Ymd-His'));
$matched = 0;
$created = 0;
$saved = 0;
$errors = [];

$resolveMasterId = static function (string $name) use (&$byName, &$byCompact): ?int {
    $key = mb_strtoupper(ProductName::clean($name));
    if ($key === '') {
        return null;
    }
    if (isset($byName[$key])) {
        return $byName[$key];
    }
    $compact = preg_replace('/[^A-Z0-9]/', '', $key) ?? '';
    if ($compact !== '' && isset($byCompact[$compact])) {
        return $byCompact[$compact];
    }
    return null;
};

fwrite(STDOUT, 'Importing ' . count($rows) . " opening stock lines for client {$clientId} branch {$branchId}…\n");

$ops = Connection::ops();
$total = count($rows);
$n = 0;
foreach ($rows as $row) {
    $n++;
    try {
        $name = mb_strtoupper(trim((string) ($row['product'] ?? '')));
        $batchNo = trim((string) ($row['batch_no'] ?? 'NA'));
        $qtyUnits = (int) ($row['qty_units'] ?? 0);
        $unitMrp = (float) ($row['unit_mrp'] ?? 0);
        $unitRate = (float) ($row['unit_rate'] ?? 0);
        $packMrp = (float) ($row['pack_mrp'] ?? 0);
        $gst = (float) ($row['gst'] ?? 0);
        $hsn = trim((string) ($row['hsn'] ?? ''));
        $upp = max(1, (int) ($row['units_per_pack'] ?? 1));
        $packSize = trim((string) ($row['pack_size'] ?? ''));
        $expiry = $row['expiry_date'] ?? null;
        if (is_string($expiry)) {
            $expiry = trim($expiry);
            if ($expiry === '') {
                $expiry = null;
            }
        } else {
            $expiry = null;
        }

        if ($name === '' || $batchNo === '' || $qtyUnits <= 0 || $unitMrp <= 0 || $unitRate <= 0) {
            throw new RuntimeException('Invalid row fields');
        }

        $masterId = $resolveMasterId($name);
        if ($masterId === null) {
            $masterId = $productModel->insert([
                'name'               => $name,
                'product_type'       => 'Medicine',
                'schedule_category'  => 'OTC',
                'product_category'   => 'Allopathy',
                'hsn_code'           => $hsn !== '' ? $hsn : null,
                'default_gst_rate'   => $gst > 0 ? $gst : 12,
                'mrp'                => $packMrp > 0 ? $packMrp : $unitMrp,
                'ptr'                => 0,
                'pts'                => 0,
                'pack_size'          => $packSize !== '' ? $packSize : null,
                'units_per_pack'     => $upp,
                'allow_loose'        => !empty($row['loose_strip']) ? 1 : 0,
                'uom'                => 'NOS',
                'is_active'          => 1,
                'created_by_client'  => $clientId,
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);
            $key = mb_strtoupper(ProductName::clean($name));
            $byName[$key] = $masterId;
            $compact = preg_replace('/[^A-Z0-9]/', '', $key) ?? '';
            if ($compact !== '') {
                $byCompact[$compact] = $masterId;
            }
            $created++;
        } else {
            $matched++;
        }

        $bind = $binder->bind($clientId, $branchId, $masterId);
        $bindingId = (int) $bind['id'];

        // Excel wins over master for binding display / tax / pack.
        $ops->prepare(
            'UPDATE product_bindings
             SET product_name = :name,
                 hsn_code = COALESCE(NULLIF(:hsn, \'\'), hsn_code),
                 gst_rate = CASE WHEN :gst > 0 THEN :gst2 ELSE gst_rate END,
                 units_per_pack = :upp,
                 pack_size = COALESCE(NULLIF(:pack, \'\'), pack_size),
                 mrp = :mrp,
                 allow_loose = :loose,
                 updated_at = :now
             WHERE id = :id'
        )->execute([
            'name'  => ProductName::clean($name),
            'hsn'   => $hsn,
            'gst'   => $gst,
            'gst2'  => $gst,
            'upp'   => $upp,
            'pack'  => $packSize,
            'mrp'   => $packMrp > 0 ? $packMrp : ($unitMrp * $upp),
            'loose' => !empty($row['loose_strip']) ? 1 : 0,
            'now'   => now(),
            'id'    => $bindingId,
        ]);

        $inv->addStock(
            $clientId,
            $branchId,
            $bindingId,
            $batchNo,
            $expiry,
            $unitMrp,
            $unitRate,
            $qtyUnits,
            null, // shelf skipped
            0.0,
            0.0,
            'opening',
            $openingDate,
            'opening',
            null,
            $refNo
        );
        $saved++;

        if ($n % 200 === 0) {
            fwrite(STDOUT, "  … {$n}/{$total} processed (saved={$saved}, created={$created}, errors=" . count($errors) . ")\n");
        }
    } catch (Throwable $e) {
        $errors[] = [
            'excel_row' => $row['excel_row'] ?? null,
            'product'   => $row['product'] ?? '',
            'error'     => $e->getMessage(),
        ];
    }
}

$summary = [
    'ref_no'           => $refNo,
    'client_id'        => $clientId,
    'branch_id'        => $branchId,
    'lines_saved'      => $saved,
    'master_matched'   => $matched,
    'master_created'   => $created,
    'error_count'      => count($errors),
    'errors_sample'    => array_slice($errors, 0, 20),
];

fwrite(STDOUT, json_encode($summary, JSON_PRETTY_PRINT) . "\n");

$ops = Connection::ops();
$c1 = $ops->prepare('SELECT COUNT(*) FROM product_bindings WHERE client_id = ?');
$c1->execute([$clientId]);
$c2 = $ops->prepare('SELECT COUNT(*) FROM inventory_batches WHERE client_id = ?');
$c2->execute([$clientId]);
$c3 = $ops->prepare('SELECT COALESCE(SUM(quantity),0) FROM inventory_batches WHERE client_id = ?');
$c3->execute([$clientId]);
fwrite(STDOUT, sprintf(
    "DB check client %d: bindings=%d batches=%d total_qty=%s\n",
    $clientId,
    (int) $c1->fetchColumn(),
    (int) $c2->fetchColumn(),
    (string) $c3->fetchColumn()
));

exit(count($errors) > 0 && $saved === 0 ? 1 : 0);
