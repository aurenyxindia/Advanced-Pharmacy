<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Align saved sale_items with billing grid / print (purchase batch overlay).
 */
final class SaleDisplayService
{
    /** @param array<string,mixed> $sale */
    public function enrichSale(array $sale): array
    {
        $isWholesale = strtolower(trim((string) ($sale['bill_mode'] ?? 'retail'))) === 'wholesale';
        $gstInclusive = !array_key_exists('gst_inclusive', $sale) || (int) $sale['gst_inclusive'] === 1;
        $items = is_array($sale['items'] ?? null) ? $sale['items'] : [];
        foreach ($items as $i => $item) {
            if (is_array($item)) {
                $items[$i] = $this->enrichItem($item, $isWholesale, $gstInclusive);
            }
        }
        $sale['items'] = $items;

        if (!$isWholesale && $items !== []) {
            $displayTotal = 0.0;
            foreach ($items as $item) {
                if (is_array($item)) {
                    $displayTotal += (float) ($item['line_total'] ?? $item['display_net'] ?? 0);
                }
            }
            $lineSum = round($displayTotal, 2);
            $storedNet = round((float) ($sale['net_amount'] ?? 0), 2);
            $netBeforeRound = round($storedNet - (float) ($sale['round_off'] ?? 0), 2);
            $bulkBill = ($storedNet > 0 && $lineSum > $netBeforeRound + 0.001)
                ? round($lineSum - $netBeforeRound, 2)
                : 0.0;
            $sale['retail_items_net_total'] = $lineSum;
            $sale['bill_bulk_discount_amount'] = $bulkBill;
            // Line sum excludes retail bulk bill discount; invoice/footer use stored net when lower.
            $sale['display_net_amount'] = ($storedNet > 0 && $bulkBill > 0)
                ? $storedNet
                : $lineSum;
        }

        return $sale;
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    public function enrichItem(array $item, bool $isWholesale, bool $gstInclusive = true): array
    {
        $upp = max(1, (int) ($item['units_per_pack'] ?? 1));
        $qty = (int) ($item['quantity'] ?? 0);
        $totalAmt = (float) ($item['total_amt'] ?? 0);
        $discPct = (float) ($item['discount_pct'] ?? 0);
        $lineTotal = (float) ($item['line_total'] ?? 0);
        $gstRate = (float) ($item['gst_rate'] ?? 0);

        if ($isWholesale) {
            $rate = (float) ($item['rate'] ?? 0);
            $mrp = (float) ($item['mrp'] ?? 0);
            $stripQty = $upp > 1 ? $qty / $upp : (float) $qty;
            $packRate = round($rate * $upp, 2);
            $batchPtr = round((float) ($item['batch_ptr'] ?? 0), 2);
            $displayRate = $packRate > 0 ? $packRate : ($batchPtr > 0 ? $batchPtr : $packRate);

            $item['display_qty'] = $stripQty;
            $item['display_rate'] = $displayRate;
            $item['display_mrp'] = round($mrp, 2);
            $item['display_disc_pct'] = round($discPct, 2);
            $item['display_disc_amt'] = round($totalAmt * $discPct / 100, 2);
            $item['display_net'] = round($lineTotal, 2);

            return $item;
        }

        $overlay = $this->retailBatchOverlay($item, $upp);
        $item['display_qty'] = $qty;
        $item['display_rate'] = $overlay['pack_purchase_rate'];
        $item['display_mrp'] = $overlay['pack_mrp'];
        $item['display_disc_pct'] = round($discPct, 2);
        $item['display_disc_amt'] = round($totalAmt * $discPct / 100, 2);
        $item['display_net'] = $this->retailLineSell(
            $overlay['unit_mrp'],
            $qty,
            $discPct,
            $gstRate,
            $gstInclusive,
            $lineTotal,
            $overlay['uses_purchase_overlay']
        );

        return $item;
    }

    /**
     * @param array<string,mixed> $item
     * @return array{pack_mrp: float, pack_purchase_rate: float, unit_mrp: float, uses_purchase_overlay: bool}
     */
    private function retailBatchOverlay(array $item, int $upp): array
    {
        $batchUnitMrp = (float) ($item['batch_unit_mrp'] ?? 0);
        $batchUnitPurchase = (float) ($item['batch_unit_purchase_rate'] ?? 0);
        $packMrp = $batchUnitMrp > 0
            ? round($batchUnitMrp * $upp, 2)
            : $this->packMrpFromSaved((float) ($item['mrp'] ?? 0), (float) ($item['rate'] ?? 0), $upp);
        $packPurchase = $batchUnitPurchase > 0 ? round($batchUnitPurchase * $upp, 2) : 0.0;
        $unitMrp = (float) ($item['rate'] ?? 0);
        $usesPurchaseOverlay = false;

        $purchaseMrp = (float) ($item['purchase_mrp'] ?? 0);
        $purchaseRate = (float) ($item['purchase_rate'] ?? 0);
        if ($purchaseMrp > 0 || $purchaseRate > 0) {
            $usesPurchaseOverlay = true;
            $piUpp = max(1, (int) ($item['purchase_units_per_pack'] ?? $upp));
            if ($purchaseMrp > 0) {
                $packMrp = round($purchaseMrp, 2);
                $unitMrp = round($packMrp / $piUpp, 2);
            }
            if ($purchaseRate > 0) {
                $packPurchase = round($purchaseRate, 2);
            }
        } elseif ($batchUnitMrp > 0) {
            $unitMrp = round($batchUnitMrp, 2);
        }

        if ($packPurchase <= 0 && $batchUnitPurchase > 0) {
            $packPurchase = round($batchUnitPurchase * $upp, 2);
        } elseif ($packPurchase <= 0 && ($qty = (int) ($item['quantity'] ?? 0)) > 0) {
            $cost = (float) ($item['cost_price'] ?? 0);
            if ($cost > 0) {
                $packPurchase = round(($cost / $qty) * $upp, 2);
            }
        }

        return [
            'pack_mrp'              => $packMrp,
            'pack_purchase_rate'    => $packPurchase,
            'unit_mrp'              => $unitMrp,
            'uses_purchase_overlay' => $usesPurchaseOverlay,
        ];
    }

    private function retailLineSell(
        float $unitMrp,
        int $qty,
        float $discPct,
        float $gstRate,
        bool $gstInclusive,
        float $savedLineTotal,
        bool $usesPurchaseOverlay
    ): float {
        if (!$usesPurchaseOverlay || $unitMrp <= 0 || $qty <= 0) {
            return round($savedLineTotal, 2);
        }

        $base = $unitMrp * $qty * (1 - $discPct / 100);
        if ($gstInclusive) {
            return round($base, 2);
        }

        $taxable = round($base, 2);
        $gstAmt = round($taxable * $gstRate / 100, 2);

        return round($taxable + $gstAmt, 2);
    }

    private function packMrpFromSaved(float $mrp, float $unitRate, int $upp): float
    {
        if ($upp > 1 && $unitRate > 0 && abs($mrp - $unitRate) < 0.02) {
            return round($mrp * $upp, 2);
        }

        return round($mrp, 2);
    }
}
