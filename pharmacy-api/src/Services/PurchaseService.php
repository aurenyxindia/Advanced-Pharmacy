<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Database\Connection;
use App\Models\ProductBinding;
use PDO;

/**
 * Creates a purchase invoice: resolves/binds products, computes net-rate,
 * scheme, discount and GST figures, and posts batch-wise stock.
 *
 * Per-line maths:
 *   taxable   = rate * qty * (1 - disc% / 100)
 *   gst       = taxable * gst% / 100
 *   lineTotal = taxable + gst
 *   landed cost/unit = lineTotal / (qty + freeQty)   (scheme lowers unit cost)
 */
final class PurchaseService
{
    private ProductBindingService $binder;
    private InventoryService $inventory;

    public function __construct()
    {
        $this->binder = new ProductBindingService();
        $this->inventory = new InventoryService();
    }

    public function create(int $clientId, int $branchId, ?int $userId, array $data): array
    {
        $items = $data['items'] ?? [];
        if (!is_array($items) || $items === []) {
            throw HttpException::validation(['items' => ['At least one purchase line is required.']]);
        }

        return Connection::transaction('ops', function (PDO $db) use ($clientId, $branchId, $userId, $data, $items) {
            $gross = 0.0;
            $scheme = 0.0;
            $lineDiscount = 0.0;
            $gstTotal = 0.0;
            $itemsTotal = 0.0;
            $computedItems = [];

            foreach ($items as $i => $item) {
                $bindingId = $this->resolveBinding($clientId, $branchId, $item);

                $qty      = (int) ($item['quantity'] ?? 0);   // packs
                $free     = (int) ($item['free_quantity'] ?? 0);
                $rate     = (float) ($item['purchase_rate'] ?? 0); // per pack
                $mrp      = (float) ($item['mrp'] ?? 0);            // per pack
                $discPct  = (float) ($item['discount_pct'] ?? 0);
                $gstRate  = (float) ($item['gst_rate'] ?? 0);
                $upp      = max(1, (int) ($item['units_per_pack'] ?? 1));

                if ($qty <= 0 || $rate < 0) {
                    throw HttpException::validation(["items.$i" => ['Invalid quantity or rate.']]);
                }

                $lineGross = $rate * $qty;
                $taxable   = $lineGross * (1 - $discPct / 100);
                $gstAmt    = $taxable * $gstRate / 100;
                $lineTotal = $taxable + $gstAmt;
                $netRate   = $rate * (1 - $discPct / 100);

                // Convert to base (loose) units for inventory.
                $baseUnits      = ($qty + $free) * $upp;
                $unitCostIncl   = $baseUnits > 0 ? $lineTotal / $baseUnits : $lineTotal; // per base unit
                $unitMrp        = $upp > 0 ? $mrp / $upp : $mrp;                          // per base unit

                $gross        += $lineGross;
                $scheme       += $free * $rate;
                $lineDiscount += $lineGross * $discPct / 100;
                $gstTotal     += $gstAmt;
                $itemsTotal   += $lineTotal;

                $computedItems[] = [
                    'binding_id'   => $bindingId,
                    'product_name' => $item['product_name'] ?? '',
                    'batch_no'     => $item['batch_no'] ?? null,
                    'expiry_date'  => $this->normalizeExpiry($item['expiry_date'] ?? null),
                    'shelf'        => $item['shelf_location'] ?? null,
                    'quantity'     => $qty,
                    'free_quantity' => $free,
                    'units_per_pack' => $upp,
                    'base_units'   => $baseUnits,
                    'purchase_rate' => $rate,
                    'mrp'          => $mrp,
                    'unit_mrp'     => round($unitMrp, 2),
                    'discount_pct' => $discPct,
                    'gst_rate'     => $gstRate,
                    'net_rate'     => round($netRate, 2),
                    'line_total'   => round($lineTotal, 2),
                    'unit_cost'    => round($unitCostIncl, 4),
                ];
            }

            // Bill-level discount (flat amount or percent of items total).
            $billDiscount = (float) ($data['bill_discount_amount'] ?? 0);
            if (!empty($data['bill_discount_pct'])) {
                $billDiscount += $itemsTotal * (float) $data['bill_discount_pct'] / 100;
            }
            $netAmount = max(0, $itemsTotal - $billDiscount);

            // Insert purchase header.
            $stmt = $db->prepare(
                'INSERT INTO purchases
                    (client_id, branch_id, supplier_id, supplier_name, invoice_no, invoice_date,
                     gross_amount, scheme_amount, discount_amount, gst_amount, net_amount, created_by, created_at)
                 VALUES
                    (:client_id, :branch_id, :supplier_id, :supplier_name, :invoice_no, :invoice_date,
                     :gross, :scheme, :discount, :gst, :net, :created_by, :created_at)'
            );
            $stmt->execute([
                'client_id'     => $clientId,
                'branch_id'     => $branchId,
                'supplier_id'   => $data['supplier_id'] ?? null,
                'supplier_name' => $data['supplier_name'] ?? null,
                'invoice_no'    => $data['invoice_no'] ?? ('PUR-' . time()),
                'invoice_date'  => $data['invoice_date'] ?? date('Y-m-d'),
                'gross'         => round($gross, 2),
                'scheme'        => round($scheme, 2),
                'discount'      => round($lineDiscount + $billDiscount, 2),
                'gst'           => round($gstTotal, 2),
                'net'           => round($netAmount, 2),
                'created_by'    => $userId,
                'created_at'    => now(),
            ]);
            $purchaseId = (int) $db->lastInsertId();

            // Insert items + post stock.
            $itemStmt = $db->prepare(
                'INSERT INTO purchase_items
                    (purchase_id, product_binding_id, product_name, batch_no, expiry_date, shelf_location, quantity,
                     units_per_pack, free_quantity, purchase_rate, mrp, discount_pct, gst_rate, net_rate, line_total)
                 VALUES
                    (:purchase_id, :binding_id, :product_name, :batch_no, :expiry_date, :shelf, :quantity,
                     :upp, :free_quantity, :purchase_rate, :mrp, :discount_pct, :gst_rate, :net_rate, :line_total)'
            );

            foreach ($computedItems as $ci) {
                $itemStmt->execute([
                    'purchase_id'   => $purchaseId,
                    'binding_id'    => $ci['binding_id'],
                    'product_name'  => $ci['product_name'],
                    'batch_no'      => $ci['batch_no'],
                    'expiry_date'   => $ci['expiry_date'] ?: null,
                    'shelf'         => $ci['shelf'],
                    'quantity'      => $ci['quantity'],
                    'upp'           => $ci['units_per_pack'],
                    'free_quantity' => $ci['free_quantity'],
                    'purchase_rate' => $ci['purchase_rate'],
                    'mrp'           => $ci['mrp'],
                    'discount_pct'  => $ci['discount_pct'],
                    'gst_rate'      => $ci['gst_rate'],
                    'net_rate'      => $ci['net_rate'],
                    'line_total'    => $ci['line_total'],
                ]);

                // Stock is posted in BASE units; MRP/cost stored per base unit.
                $this->inventory->addStock(
                    $clientId,
                    $branchId,
                    $ci['binding_id'],
                    (string) ($ci['batch_no'] ?: 'NA'),
                    $ci['expiry_date'] ?: null,
                    (float) $ci['unit_mrp'],
                    (float) $ci['unit_cost'],
                    $ci['base_units'],
                    $ci['shelf']
                );
            }

            return [
                'id'         => $purchaseId,
                'net_amount' => round($netAmount, 2),
                'gst_amount' => round($gstTotal, 2),
                'items'      => count($computedItems),
            ];
        });
    }

    /**
     * Resolve a product binding id for a line. Either an existing master
     * product (bind it) or a brand-new product (create in master + bind).
     */
    private function resolveBinding(int $clientId, int $branchId, array $item): int
    {
        if (!empty($item['master_product_id'])) {
            $binding = $this->binder->bind($clientId, $branchId, (int) $item['master_product_id']);
            return $this->bindingId($clientId, $branchId, (int) $item['master_product_id'], $binding);
        }

        if (!empty($item['new_product']) && is_array($item['new_product'])) {
            $np = $item['new_product'];
            $result = $this->binder->createAndBind($clientId, $branchId, [
                'name'              => $np['name'] ?? ($item['product_name'] ?? 'Unnamed'),
                'generic_id'        => $np['generic_id'] ?? null,
                'manufacturer'      => $np['manufacturer'] ?? null,
                'schedule_category' => $np['schedule_category'] ?? 'OTC',
                'hsn_code'          => $np['hsn_code'] ?? null,
                'default_gst_rate'  => $np['default_gst_rate'] ?? ($item['gst_rate'] ?? 12),
                'pack_size'         => $np['pack_size'] ?? null,
                'units_per_pack'    => $np['units_per_pack'] ?? ($item['units_per_pack'] ?? 1),
                'allow_loose'       => $np['allow_loose'] ?? 1,
                'shelf_location'    => $np['shelf_location'] ?? ($item['shelf_location'] ?? null),
                'uom'               => $np['uom'] ?? 'NOS',
                'composition'       => $np['composition'] ?? null,
            ]);
            return (int) $result['id'];
        }

        throw HttpException::validation(['items' => ['Each line needs an existing or new product.']]);
    }

    /** Accept MM/YYYY, MM-YYYY or YYYY-MM-DD; return YYYY-MM-DD (end of month for MM/YYYY). */
    private function normalizeExpiry(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('#^(\d{1,2})[/-](\d{2,4})$#', $raw, $m)) {
            $month = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $year = strlen($m[2]) === 2 ? '20' . $m[2] : $m[2];
            return date('Y-m-t', strtotime("$year-$month-01"));
        }
        $ts = strtotime($raw);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    private function bindingId(int $clientId, int $branchId, int $masterProductId, array $bindResult): int
    {
        if (!empty($bindResult['id'])) {
            return (int) $bindResult['id'];
        }
        $binding = (new ProductBinding())->findBinding($clientId, $branchId, $masterProductId);
        return (int) ($binding['id'] ?? 0);
    }
}
