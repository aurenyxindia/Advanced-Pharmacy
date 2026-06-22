<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Models\Product;
use App\Models\ProductBinding;

/**
 * Binds a global master product to a tenant (client + branch).
 *
 * The master catalog (pharma_master) is the single source of truth; binding
 * copies the denormalized attributes a branch needs for fast operations into
 * pharma_ops.product_bindings. A product can only be bound once per branch.
 */
final class ProductBindingService
{
    public function bind(int $clientId, int $branchId, int $masterProductId, int $reorderLevel = 0): array
    {
        $product = (new Product())->find($masterProductId);
        if (!$product) {
            throw HttpException::notFound('Master product not found.');
        }

        $bindings = new ProductBinding();
        $existing = $bindings->findBinding($clientId, $branchId, $masterProductId);

        if ($existing) {
            // Idempotent: refresh denormalized fields and return.
            $bindings->update((int) $existing['id'], [
                'product_name'      => $product['name'],
                'manufacturer'      => $product['manufacturer'] ?? null,
                'manufacturer_short' => $product['manufacturer_short'] ?? null,
                'generic_id'        => $product['generic_id'],
                'schedule_category' => $product['schedule_category'],
                'product_category'  => $product['product_category'] ?? 'Allopathy',
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
            'product_name'      => $product['name'],
            'manufacturer'      => $product['manufacturer'] ?? null,
            'manufacturer_short' => $product['manufacturer_short'] ?? null,
            'generic_id'        => $product['generic_id'],
            'schedule_category' => $product['schedule_category'],
            'product_category'  => $product['product_category'] ?? 'Allopathy',
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
}
