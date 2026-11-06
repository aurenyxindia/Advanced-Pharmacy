<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Database\Connection;
use App\Models\Product;
use App\Models\ProductBinding;
use App\Support\ProductName;
use PDO;

/**
 * Binds a global master product to a tenant (client + branch).
 *
 * The master catalog (pharma_master) is the single source of truth; binding
 * copies the denormalized attributes a branch needs for fast operations into
 * pharma_ops.product_bindings. A product can only be bound once per branch.
 */
final class ProductBindingService
{
    /** product_bindings.product_category is varchar(30); master products allow longer labels. */
    private const CATEGORY_MAX = 30;

    public function bind(int $clientId, int $branchId, int $masterProductId, int $reorderLevel = 0): array
    {
        $product = (new Product())->find($masterProductId);
        if (!$product) {
            throw HttpException::notFound('Master product not found.');
        }

        $bindings = new ProductBinding();
        $existing = $bindings->findBinding($clientId, $branchId, $masterProductId);
        $category = $this->clipCategory($product['product_category'] ?? null);

        if ($existing) {
            // Idempotent: refresh denormalized fields and return.
            $bindings->update((int) $existing['id'], [
                'product_name'      => ProductName::clean((string) ($product['name'] ?? '')),
                'manufacturer'      => $product['manufacturer'] ?? null,
                'manufacturer_short' => $product['manufacturer_short'] ?? null,
                'generic_id'        => $product['generic_id'],
                'schedule_category' => $product['schedule_category'],
                'product_category'  => $category,
                'gst_rate'          => $product['default_gst_rate'],
                'hsn_code'          => $product['hsn_code'],
                'units_per_pack'    => $product['units_per_pack'] ?? 1,
                'allow_loose'       => $product['allow_loose'] ?? 1,
                'shelf_location'    => $product['shelf_location'] ?? null,
                'pack_size'         => $product['pack_size'] ?? null,
                'mrp'               => $product['mrp'] ?? 0,
                'ptr'               => $product['ptr'] ?? 0,
                'pts'               => $product['pts'] ?? 0,
                'updated_at'        => now(),
            ]);
            return ['id' => (int) $existing['id'], 'rebound' => true];
        }

        $id = $bindings->insert([
            'client_id'         => $clientId,
            'branch_id'         => $branchId,
            'master_product_id' => $masterProductId,
            'product_name'      => ProductName::clean((string) ($product['name'] ?? '')),
            'manufacturer'      => $product['manufacturer'] ?? null,
            'manufacturer_short' => $product['manufacturer_short'] ?? null,
            'generic_id'        => $product['generic_id'],
            'schedule_category' => $product['schedule_category'],
            'product_category'  => $category,
            'gst_rate'          => $product['default_gst_rate'],
            'hsn_code'          => $product['hsn_code'],
            'units_per_pack'    => $product['units_per_pack'] ?? 1,
            'allow_loose'       => $product['allow_loose'] ?? 1,
            'shelf_location'    => $product['shelf_location'] ?? null,
            'pack_size'         => $product['pack_size'] ?? null,
            'mrp'               => $product['mrp'] ?? 0,
            'ptr'               => $product['ptr'] ?? 0,
            'pts'               => $product['pts'] ?? 0,
            'reorder_level'     => $reorderLevel,
            'is_active'         => 1,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        return ['id' => $id, 'rebound' => false];
    }

    private function clipCategory(mixed $value): string
    {
        $s = trim((string) ($value ?? ''));
        if ($s === '') {
            return 'Allopathy';
        }
        return mb_substr($s, 0, self::CATEGORY_MAX);
    }

    /**
     * Create a brand-new product in the master catalog (if it doesn't exist)
     * and immediately bind it to the tenant. Used by purchase entry when a
     * product is not yet in the catalog.
     */
    public function createAndBind(int $clientId, int $branchId, array $productData, int $reorderLevel = 0): array
    {
        $productData['created_by_client'] = $clientId;
        $productData['created_at'] = now();
        $productData['updated_at'] = now();
        $productData['is_active'] = 1;

        $masterProductId = (new Product())->insert($productData);

        $binding = $this->bind($clientId, $branchId, $masterProductId, $reorderLevel);
        $binding['master_product_id'] = $masterProductId;
        return $binding;
    }

    /**
     * Push master-product edits into every ops table that denormalizes product fields.
     *
     * Live catalog / stock always follow Product Master. Purchase / sale / note
     * line *identity* fields (name, HSN, pack, mfr, schedule, shelf, UPP, GST,
     * MRP/PTR/PTS) are refreshed so already-purchased products stay in sync.
     * Transactional money columns (purchase_rate, line_total, sale rate/totals)
     * are never rewritten.
     *
     * @return array{
     *   bindings:int,
     *   batches:int,
     *   purchase_items:int,
     *   sale_items:int,
     *   stock_transfer_items:int,
     *   online_order_items:int,
     *   credit_note_items:int,
     *   debit_note_items:int,
     *   retail_sale_return_items:int
     * }
     */
    public function syncFromMaster(int $masterProductId): array
    {
        $empty = [
            'bindings' => 0,
            'batches' => 0,
            'purchase_items' => 0,
            'sale_items' => 0,
            'stock_transfer_items' => 0,
            'online_order_items' => 0,
            'credit_note_items' => 0,
            'debit_note_items' => 0,
            'retail_sale_return_items' => 0,
        ];

        $product = (new Product())->find($masterProductId);
        if (!$product) {
            return $empty;
        }

        $name = ProductName::clean((string) ($product['name'] ?? ''));
        $mfg = $product['manufacturer'] ?? null;
        $mfgShort = $product['manufacturer_short'] ?? null;
        $genericId = $product['generic_id'] ?? null;
        $schedule = (string) ($product['schedule_category'] ?? 'OTC');
        $pcat = $this->clipCategory($product['product_category'] ?? null);
        $gst = (float) ($product['default_gst_rate'] ?? 12);
        $hsn = $product['hsn_code'] ?? null;
        $upp = max(1, (int) ($product['units_per_pack'] ?? 1));
        $loose = (int) ($product['allow_loose'] ?? 1);
        $shelf = trim((string) ($product['shelf_location'] ?? ''));
        $pack = $product['pack_size'] ?? null;
        $mrp = (float) ($product['mrp'] ?? 0);
        $ptr = (float) ($product['ptr'] ?? 0);
        $pts = (float) ($product['pts'] ?? 0);
        $active = (int) ($product['is_active'] ?? 1);
        $now = now();

        $db = Connection::ops();

        $stmt = $db->prepare(
            'UPDATE product_bindings SET
                product_name = :name,
                manufacturer = :mfg,
                manufacturer_short = :mfg_short,
                generic_id = :generic_id,
                schedule_category = :schedule,
                product_category = :pcat,
                gst_rate = :gst,
                hsn_code = :hsn,
                units_per_pack = :upp,
                allow_loose = :loose,
                shelf_location = :shelf,
                pack_size = :pack,
                mrp = :mrp,
                ptr = :ptr,
                pts = :pts,
                is_active = :active,
                updated_at = :updated
             WHERE master_product_id = :mp'
        );
        $stmt->execute([
            'name' => $name,
            'mfg' => $mfg,
            'mfg_short' => $mfgShort,
            'generic_id' => $genericId,
            'schedule' => $schedule,
            'pcat' => $pcat,
            'gst' => $gst,
            'hsn' => $hsn,
            'upp' => $upp,
            'loose' => $loose,
            'shelf' => $shelf !== '' ? $shelf : null,
            'pack' => $pack,
            'mrp' => $mrp,
            'ptr' => $ptr,
            'pts' => $pts,
            'active' => $active,
            'updated' => $now,
            'mp' => $masterProductId,
        ]);
        $empty['bindings'] = $stmt->rowCount();

        // Live stock batches: pricing + shelf (identity lives on bindings).
        // Batch MRP is per unit; master MRP is per pack.
        $batchSets = ['ib.updated_at = :updated'];
        $batchBind = ['mp' => $masterProductId, 'updated' => $now];
        if ($mrp > 0) {
            $batchSets[] = 'ib.mrp = :mrp';
            $batchBind['mrp'] = round($mrp / $upp, 2);
        }
        if ($ptr > 0) {
            $batchSets[] = 'ib.ptr = :ptr';
            $batchBind['ptr'] = $ptr;
        }
        if ($pts > 0) {
            $batchSets[] = 'ib.pts = :pts';
            $batchBind['pts'] = $pts;
        }
        if ($shelf !== '') {
            $batchSets[] = 'ib.shelf_location = :shelf';
            $batchBind['shelf'] = $shelf;
        }
        if (count($batchSets) > 1) {
            $empty['batches'] = $this->execCount(
                $db,
                'UPDATE inventory_batches ib
                 INNER JOIN product_bindings pb ON pb.id = ib.product_binding_id
                 SET ' . implode(', ', $batchSets) . '
                 WHERE pb.master_product_id = :mp',
                $batchBind
            );
        }

        // Already-purchased lines: identity + master rates (not purchase_rate / totals).
        $piSets = [
            'pi.product_name = :name',
            'pi.hsn_code = :hsn',
            'pi.units_per_pack = :upp',
            'pi.gst_rate = :gst',
        ];
        $piBind = [
            'name' => $name,
            'hsn' => $hsn,
            'upp' => $upp,
            'gst' => $gst,
            'mp' => $masterProductId,
        ];
        if ($shelf !== '') {
            $piSets[] = 'pi.shelf_location = :shelf';
            $piBind['shelf'] = $shelf;
        }
        if ($mrp > 0) {
            $piSets[] = 'pi.mrp = :mrp';
            $piBind['mrp'] = $mrp;
        }
        if ($ptr > 0) {
            $piSets[] = 'pi.ptr = :ptr';
            $piBind['ptr'] = $ptr;
        }
        if ($pts > 0) {
            $piSets[] = 'pi.pts = :pts';
            $piBind['pts'] = $pts;
        }
        $empty['purchase_items'] = $this->execCount(
            $db,
            'UPDATE purchase_items pi
             INNER JOIN product_bindings pb ON pb.id = pi.product_binding_id
             SET ' . implode(', ', $piSets) . '
             WHERE pb.master_product_id = :mp',
            $piBind
        );

        // Sale line identity only — keep billed rates / totals intact.
        $siSets = [
            'si.product_name = :name',
            'si.manufacturer_short = :mfg_short',
            'si.hsn_code = :hsn',
            'si.pack = :pack',
            'si.schedule_category = :schedule',
        ];
        $siBind = [
            'name' => $name,
            'mfg_short' => $mfgShort,
            'hsn' => $hsn,
            'pack' => $pack,
            'schedule' => $schedule,
            'mp' => $masterProductId,
        ];
        if ($shelf !== '') {
            $siSets[] = 'si.shelf = :shelf';
            $siBind['shelf'] = $shelf;
        }
        $empty['sale_items'] = $this->execCount(
            $db,
            'UPDATE sale_items si
             INNER JOIN product_bindings pb ON pb.id = si.product_binding_id
             SET ' . implode(', ', $siSets) . '
             WHERE pb.master_product_id = :mp',
            $siBind
        );

        $empty['stock_transfer_items'] = $this->execCount(
            $db,
            'UPDATE stock_transfer_items SET product_name = :name WHERE master_product_id = :mp',
            ['name' => $name, 'mp' => $masterProductId]
        );

        $empty['online_order_items'] = $this->execCount(
            $db,
            'UPDATE online_order_items oi
             INNER JOIN product_bindings pb ON pb.id = oi.product_binding_id
             SET oi.product_name = :name
             WHERE pb.master_product_id = :mp',
            ['name' => $name, 'mp' => $masterProductId]
        );

        // Optional note / return tables (created at runtime on some installs).
        $empty['credit_note_items'] = $this->execCount(
            $db,
            'UPDATE credit_note_items cni
             INNER JOIN product_bindings pb ON pb.id = cni.product_binding_id
             SET cni.product_name = :name, cni.hsn = :hsn, cni.pack_size = :pack
             WHERE pb.master_product_id = :mp',
            ['name' => $name, 'hsn' => $hsn, 'pack' => $pack, 'mp' => $masterProductId]
        );

        $empty['debit_note_items'] = $this->execCount(
            $db,
            'UPDATE debit_note_items dni
             INNER JOIN product_bindings pb ON pb.id = dni.product_binding_id
             SET dni.product_name = :name, dni.hsn = :hsn, dni.pack_size = :pack
             WHERE pb.master_product_id = :mp',
            ['name' => $name, 'hsn' => $hsn, 'pack' => $pack, 'mp' => $masterProductId]
        );

        $empty['retail_sale_return_items'] = $this->execCount(
            $db,
            'UPDATE retail_sale_return_items rsi
             INNER JOIN product_bindings pb ON pb.id = rsi.product_binding_id
             SET rsi.product_name = :name, rsi.pack_size = :pack
             WHERE pb.master_product_id = :mp',
            ['name' => $name, 'pack' => $pack, 'mp' => $masterProductId]
        );

        return $empty;
    }

    /** @param array<string,mixed> $bind */
    private function execCount(PDO $db, string $sql, array $bind): int
    {
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($bind);
            return $stmt->rowCount();
        } catch (\Throwable) {
            return 0;
        }
    }
}
