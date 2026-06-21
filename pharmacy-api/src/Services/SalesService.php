<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Database\Connection;
use App\Models\Sale;
use PDO;

/**
 * Creates a retail sale / bill.
 *
 * Retail MRP is GST-inclusive, so GST is REVERSE-calculated:
 *   sell      = rate * qty * (1 - disc%/100)       (what the customer pays)
 *   taxable   = sell / (1 + gst%/100)
 *   gst       = sell - taxable
 *
 * Profit per line = taxable(selling, excl GST) - cost(excl GST).
 * Stock is consumed FEFO (first-expiry-first-out) across batches.
 *
 * Compliance:
 *   - Schedule H1 / X / G / RESTRICTED require a prescription on file.
 *   - Any scheduled item locks the sale (immutable after creation).
 */
final class SalesService
{
    private const PRESCRIPTION_REQUIRED = ['H1', 'X', 'G', 'RESTRICTED'];
    private const LOCK_SCHEDULES = ['H', 'H1', 'X', 'G', 'RESTRICTED'];

    private InventoryService $inventory;

    public function __construct()
    {
        $this->inventory = new InventoryService();
    }

    public function create(int $clientId, int $branchId, ?int $userId, array $data): array
    {
        $items = $data['items'] ?? [];
        if (!is_array($items) || $items === []) {
            throw HttpException::validation(['items' => ['Add at least one item to the bill.']]);
        }

        // Pre-flight compliance check before opening a transaction.
        $needsPrescription = false;
        foreach ($items as $item) {
            $schedule = strtoupper((string) ($item['schedule_category'] ?? 'OTC'));
            if (in_array($schedule, self::PRESCRIPTION_REQUIRED, true)) {
                $needsPrescription = true;
            }
        }
        if ($needsPrescription) {
            if (empty($data['prescription_id'])) {
                throw HttpException::validation([
                    'prescription' => ['A prescription upload is mandatory for Schedule H1/X/G/Restricted drugs.'],
                ]);
            }
            $presc = (new \App\Models\Prescription())->firstWhere([
                'id'        => (int) $data['prescription_id'],
                'client_id' => $clientId,
            ]);
            if (!$presc) {
                throw HttpException::validation([
                    'prescription' => ['The referenced prescription was not found.'],
                ]);
            }
        }

        return Connection::transaction('ops', function (PDO $db) use ($clientId, $branchId, $userId, $data, $items) {
            $gross = 0.0;
            $discountTotal = 0.0;
            $gstTotal = 0.0;
            $sellTotal = 0.0;
            $profitTotal = 0.0;
            $locked = false;
            $computed = [];

            foreach ($items as $i => $item) {
                $bindingId = (int) ($item['product_binding_id'] ?? 0);
                $qty       = (int) ($item['quantity'] ?? 0);
                $rate      = (float) ($item['rate'] ?? 0);
                $discPct   = (float) ($item['discount_pct'] ?? 0);

                if ($bindingId <= 0 || $qty <= 0) {
                    throw HttpException::validation(["items.$i" => ['Invalid product or quantity.']]);
                }

                $binding = $this->binding($db, $clientId, $branchId, $bindingId);
                $gstRate = (float) $binding['gst_rate'];
                $schedule = strtoupper((string) $binding['schedule_category']);
                if (in_array($schedule, self::LOCK_SCHEDULES, true)) {
                    $locked = true;
                }

                $lineGross = $rate * $qty;
                $sell      = $lineGross * (1 - $discPct / 100);
                $taxable   = $sell / (1 + $gstRate / 100);
                $gstAmt    = $sell - $taxable;

                // Consume stock FEFO; obtain weighted per-unit landed cost (incl GST).
                [$batchNo, $batchId, $unitCostInclGst] = $this->consumeStock($db, $clientId, $branchId, $bindingId, $qty);
                $lineCostIncl = $unitCostInclGst * $qty;
                $lineCostExcl = $lineCostIncl / (1 + $gstRate / 100);
                $profit       = $taxable - $lineCostExcl;

                $gross        += $lineGross;
                $discountTotal += $lineGross * $discPct / 100;
                $gstTotal     += $gstAmt;
                $sellTotal    += $sell;
                $profitTotal  += $profit;

                $computed[] = [
                    'binding_id'   => $bindingId,
                    'batch_id'     => $batchId,
                    'batch_no'     => $batchNo,
                    'product_name' => $binding['product_name'],
                    'schedule'     => $schedule,
                    'quantity'     => $qty,
                    'rate'         => round($rate, 2),
                    'discount_pct' => $discPct,
                    'gst_rate'     => $gstRate,
                    'cost_price'   => round($lineCostIncl, 2),
                    'line_total'   => round($sell, 2),
                    'profit'       => round($profit, 2),
                ];
            }

            $billDiscount = (float) ($data['bill_discount_amount'] ?? 0);
            if (!empty($data['bill_discount_pct'])) {
                $billDiscount += $sellTotal * (float) $data['bill_discount_pct'] / 100;
            }
            $netAmount = max(0, $sellTotal - $billDiscount);
            $profitTotal -= $billDiscount; // bill discount erodes realized profit

            $sale = new Sale();
            $invoiceNo = $data['invoice_no'] ?? $sale->nextInvoiceNo($clientId, $branchId);

            $stmt = $db->prepare(
                'INSERT INTO sales
                    (client_id, branch_id, invoice_no, invoice_date, customer_id, customer_name, doctor_name,
                     gross_amount, discount_amount, gst_amount, net_amount, profit_amount, payment_mode,
                     has_prescription, is_locked, created_by, created_at)
                 VALUES
                    (:client_id, :branch_id, :invoice_no, :invoice_date, :customer_id, :customer_name, :doctor_name,
                     :gross, :discount, :gst, :net, :profit, :payment_mode,
                     :has_presc, :is_locked, :created_by, :created_at)'
            );
            $stmt->execute([
                'client_id'     => $clientId,
                'branch_id'     => $branchId,
                'invoice_no'    => $invoiceNo,
                'invoice_date'  => $data['invoice_date'] ?? now(),
                'customer_id'   => $data['customer_id'] ?? null,
                'customer_name' => $data['customer_name'] ?? 'Walk-in',
                'doctor_name'   => $data['doctor_name'] ?? null,
                'gross'         => round($gross, 2),
                'discount'      => round($discountTotal + $billDiscount, 2),
                'gst'           => round($gstTotal, 2),
                'net'           => round($netAmount, 2),
                'profit'        => round($profitTotal, 2),
                'payment_mode'  => $data['payment_mode'] ?? 'cash',
                'has_presc'     => !empty($data['prescription_id']) ? 1 : 0,
                'is_locked'     => $locked ? 1 : 0,
                'created_by'    => $userId,
                'created_at'    => now(),
            ]);
            $saleId = (int) $db->lastInsertId();

            $itemStmt = $db->prepare(
                'INSERT INTO sale_items
                    (sale_id, product_binding_id, batch_id, product_name, batch_no, schedule_category,
                     quantity, rate, discount_pct, gst_rate, cost_price, line_total, profit)
                 VALUES
                    (:sale_id, :binding_id, :batch_id, :product_name, :batch_no, :schedule,
                     :quantity, :rate, :discount_pct, :gst_rate, :cost_price, :line_total, :profit)'
            );
            foreach ($computed as $c) {
                $itemStmt->execute([
                    'sale_id'      => $saleId,
                    'binding_id'   => $c['binding_id'],
                    'batch_id'     => $c['batch_id'],
                    'product_name' => $c['product_name'],
                    'batch_no'     => $c['batch_no'],
                    'schedule'     => $c['schedule'],
                    'quantity'     => $c['quantity'],
                    'rate'         => $c['rate'],
                    'discount_pct' => $c['discount_pct'],
                    'gst_rate'     => $c['gst_rate'],
                    'cost_price'   => $c['cost_price'],
                    'line_total'   => $c['line_total'],
                    'profit'       => $c['profit'],
                ]);
            }

            // Attach prescription to the sale.
            if (!empty($data['prescription_id'])) {
                $upd = $db->prepare('UPDATE prescriptions SET sale_id = :sid WHERE id = :pid AND client_id = :cid');
                $upd->execute(['sid' => $saleId, 'pid' => (int) $data['prescription_id'], 'cid' => $clientId]);
            }

            return [
                'id'            => $saleId,
                'invoice_no'    => $invoiceNo,
                'net_amount'    => round($netAmount, 2),
                'gst_amount'    => round($gstTotal, 2),
                'profit_amount' => round($profitTotal, 2),
                'is_locked'     => $locked,
            ];
        });
    }

    private function binding(PDO $db, int $clientId, int $branchId, int $bindingId): array
    {
        $stmt = $db->prepare(
            'SELECT * FROM product_bindings WHERE id = :id AND client_id = :c AND branch_id = :b LIMIT 1'
        );
        $stmt->execute(['id' => $bindingId, 'c' => $clientId, 'b' => $branchId]);
        $binding = $stmt->fetch();
        if (!$binding) {
            throw HttpException::notFound("Product (binding {$bindingId}) is not in your branch catalog.");
        }
        return $binding;
    }

    /**
     * Consume $qty from batches FEFO. Returns [primaryBatchNo, primaryBatchId, weightedCostInclGst].
     */
    private function consumeStock(PDO $db, int $clientId, int $branchId, int $bindingId, int $qty): array
    {
        $stmt = $db->prepare(
            'SELECT id, batch_no, quantity, purchase_rate
             FROM inventory_batches
             WHERE client_id = :c AND branch_id = :b AND product_binding_id = :p AND quantity > 0
             ORDER BY (expiry_date IS NULL), expiry_date ASC, id ASC'
        );
        $stmt->execute(['c' => $clientId, 'b' => $branchId, 'p' => $bindingId]);
        $batches = $stmt->fetchAll();

        $available = array_sum(array_map(static fn ($b) => (int) $b['quantity'], $batches));
        if ($available < $qty) {
            throw HttpException::validation([
                'stock' => ["Insufficient stock (have {$available}, need {$qty})."],
            ]);
        }

        $remaining = $qty;
        $costAccum = 0.0;
        $primaryBatchNo = null;
        $primaryBatchId = null;

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }
            $take = min($remaining, (int) $batch['quantity']);
            $costAccum += $take * (float) $batch['purchase_rate'];
            if ($primaryBatchNo === null) {
                $primaryBatchNo = $batch['batch_no'];
                $primaryBatchId = (int) $batch['id'];
            }
            $this->inventory->reduceBatch((int) $batch['id'], $take);
            $remaining -= $take;
        }

        $this->inventory->bumpAggregate($clientId, $branchId, $bindingId, -$qty);

        $weightedCost = $qty > 0 ? $costAccum / $qty : 0.0;
        return [$primaryBatchNo, $primaryBatchId, $weightedCost];
    }
}
