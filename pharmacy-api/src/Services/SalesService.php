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

        // Validate IPD admission link, if present.
        if (!empty($data['ipd_admission_id'])) {
            $adm = (new \App\Models\IpdAdmission())->firstWhere([
                'id'        => (int) $data['ipd_admission_id'],
                'client_id' => $clientId,
            ]);
            if (!$adm) {
                throw HttpException::validation(['ipd_admission_id' => ['Admission not found.']]);
            }
            if ($adm['status'] !== 'admitted') {
                throw HttpException::validation(['ipd_admission_id' => ['This patient is already discharged.']]);
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
                $preferredBatchId = !empty($item['batch_id']) ? (int) $item['batch_id'] : null;

                if ($bindingId <= 0 || $qty <= 0) {
                    throw HttpException::validation(["items.$i" => ['Invalid product or quantity.']]);
                }

                $binding = $this->binding($db, $clientId, $branchId, $bindingId);
                $gstRate = (float) $binding['gst_rate'];
                $schedule = strtoupper((string) $binding['schedule_category']);
                if (in_array($schedule, self::LOCK_SCHEDULES, true)) {
                    $locked = true;
                }

                // GST mode: retail/MRP rates are GST-inclusive (reverse-calc);
                // wholesale PTR/PTS rates are GST-exclusive (GST added forward).
                $gstInclusive = !array_key_exists('gst_inclusive', $data) || (int) $data['gst_inclusive'] === 1;

                $lineGross = $rate * $qty;
                $afterDisc = $lineGross * (1 - $discPct / 100);
                if ($gstInclusive) {
                    $sell    = $afterDisc;                       // MRP-inclusive amount the customer pays
                    $taxable = $sell / (1 + $gstRate / 100);
                    $gstAmt  = $sell - $taxable;
                } else {
                    $taxable = $afterDisc;                       // PTR/PTS basic value (excl GST)
                    $gstAmt  = $taxable * $gstRate / 100;
                    $sell    = $taxable + $gstAmt;               // GST added forward
                }

                // Consume stock (preferred batch first, then FEFO). Quantities are
                // tracked in base (loose) units, so loose sales work transparently.
                // The batch purchase_rate IS the Net Rate (cost per unit, excl GST).
                $saleDate = date('Y-m-d', strtotime((string) ($data['invoice_date'] ?? 'now')));
                [$batchNo, $batchId, $unitNetRate, $expiry, $shelf, $unitMrp] =
                    $this->consumeStock($db, $clientId, $branchId, $bindingId, $qty, $preferredBatchId, $saleDate);
                // Profit = (Sale rate - discount - tax) - Purchase Net Rate
                $lineCost = $unitNetRate * $qty;   // already excl GST
                $profit   = $taxable - $lineCost;

                $gross        += $lineGross;
                $discountTotal += $lineGross * $discPct / 100;
                $gstTotal     += $gstAmt;
                $sellTotal    += $sell;
                $profitTotal  += $profit;

                $computed[] = [
                    'binding_id'   => $bindingId,
                    'batch_id'     => $batchId,
                    'batch_no'     => $batchNo,
                    'expiry_date'  => $expiry,
                    'shelf'        => $item['shelf'] ?? $shelf,
                    'pack'         => $item['pack'] ?? $binding['pack_size'] ?? null,
                    'mrp'          => $unitMrp,
                    'unit_type'    => $item['unit_type'] ?? 'unit',
                    'product_name' => $binding['product_name'],
                    'mfg'          => $binding['manufacturer_short'] ?? null,
                    'schedule'     => $schedule,
                    'quantity'     => $qty,
                    'rate'         => round($rate, 2),
                    'discount_pct' => $discPct,
                    'gst_rate'     => $gstRate,
                    'taxable'      => round($taxable, 2),
                    'total_amt'    => round($lineGross, 2),
                    'cost_price'   => round($lineCost, 2),
                    'line_total'   => round($sell, 2),
                    'profit'       => round($profit, 2),
                    'sell_raw'     => $sell,
                ];
            }

            $billDiscount = (float) ($data['bill_discount_amount'] ?? 0);
            if (!empty($data['bill_discount_pct'])) {
                $billDiscount += $sellTotal * (float) $data['bill_discount_pct'] / 100;
            }

            // Cash discount (wholesale): applied on the post-discount bill value.
            $cdPct = (float) ($data['cd_disc_pct'] ?? 0);
            $cdAmount = ($sellTotal - $billDiscount) * $cdPct / 100;

            $netAmount = max(0, $sellTotal - $billDiscount - $cdAmount);
            $profitTotal -= ($billDiscount + $cdAmount); // discounts erode realized profit

            // Allocate CD across lines proportionally for the line grid.
            foreach ($computed as &$cAlloc) {
                $share = $sellTotal > 0 ? $cAlloc['sell_raw'] / $sellTotal : 0;
                $cAlloc['cd_disc_amt'] = round($cdAmount * $share, 2);
            }
            unset($cAlloc);

            // GST split: intra-state = CGST + SGST; inter-state = IGST.
            $isInterstate = (int) ($data['is_interstate'] ?? 0) === 1;
            $cgst = $isInterstate ? 0 : round($gstTotal / 2, 2);
            $sgst = $isInterstate ? 0 : round($gstTotal - $cgst, 2);
            $igst = $isInterstate ? round($gstTotal, 2) : 0;

            $sale = new Sale();
            $invoiceNo = $data['invoice_no'] ?? $sale->nextInvoiceNo($clientId, $branchId);

            $stmt = $db->prepare(
                'INSERT INTO sales
                    (client_id, branch_id, counter, ipd_admission_id, sale_type, bill_mode, rate_type, invoice_no, invoice_date,
                     customer_id, customer_name, patient_name, contact_no, email, address, narration, reminder_days, doctor_name,
                     gross_amount, taxable_amount, discount_amount, cd_disc_pct, cd_amount, gst_amount,
                     is_interstate, cgst, sgst, igst, net_amount, profit_amount, payment_mode,
                     has_prescription, is_locked, created_by, created_at)
                 VALUES
                    (:client_id, :branch_id, :counter, :ipd_admission_id, :sale_type, :bill_mode, :rate_type, :invoice_no, :invoice_date,
                     :customer_id, :customer_name, :patient_name, :contact_no, :email, :address, :narration, :reminder_days, :doctor_name,
                     :gross, :taxable, :discount, :cd_pct, :cd_amt, :gst,
                     :interstate, :cgst, :sgst, :igst, :net, :profit, :payment_mode,
                     :has_presc, :is_locked, :created_by, :created_at)'
            );
            $stmt->execute([
                'client_id'     => $clientId,
                'branch_id'     => $branchId,
                'counter'       => $data['counter'] ?? null,
                'ipd_admission_id' => !empty($data['ipd_admission_id']) ? (int) $data['ipd_admission_id'] : null,
                'sale_type'     => $data['sale_type'] ?? 'Regular',
                'bill_mode'     => $data['bill_mode'] ?? 'retail',
                'rate_type'     => $data['rate_type'] ?? null,
                'invoice_no'    => $invoiceNo,
                'invoice_date'  => $data['invoice_date'] ?? now(),
                'customer_id'   => $data['customer_id'] ?? null,
                'customer_name' => $data['customer_name'] ?? ($data['patient_name'] ?? 'Walk-in'),
                'patient_name'  => $data['patient_name'] ?? null,
                'contact_no'    => $data['contact_no'] ?? null,
                'email'         => $data['email'] ?? null,
                'address'       => $data['address'] ?? null,
                'narration'     => $data['narration'] ?? null,
                'reminder_days' => !empty($data['reminder_days']) ? (int) $data['reminder_days'] : null,
                'doctor_name'   => $data['doctor_name'] ?? null,
                'gross'         => round($gross, 2),
                'taxable'       => round($sellTotal - $gstTotal, 2),
                'discount'      => round($discountTotal + $billDiscount, 2),
                'cd_pct'        => $cdPct,
                'cd_amt'        => round($cdAmount, 2),
                'gst'           => round($gstTotal, 2),
                'interstate'    => $isInterstate ? 1 : 0,
                'cgst'          => $cgst,
                'sgst'          => $sgst,
                'igst'          => $igst,
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
                    (sale_id, product_binding_id, batch_id, product_name, manufacturer_short, pack, shelf, batch_no, expiry_date,
                     schedule_category, quantity, rate, mrp, unit_type, discount_pct, gst_rate, taxable,
                     cost_price, line_total, total_amt, cd_disc_amt, profit)
                 VALUES
                    (:sale_id, :binding_id, :batch_id, :product_name, :mfg, :pack, :shelf, :batch_no, :expiry_date,
                     :schedule, :quantity, :rate, :mrp, :unit_type, :discount_pct, :gst_rate, :taxable,
                     :cost_price, :line_total, :total_amt, :cd_disc_amt, :profit)'
            );
            foreach ($computed as $c) {
                $itemStmt->execute([
                    'sale_id'      => $saleId,
                    'binding_id'   => $c['binding_id'],
                    'batch_id'     => $c['batch_id'],
                    'product_name' => $c['product_name'],
                    'mfg'          => $c['mfg'] ?? null,
                    'pack'         => $c['pack'],
                    'shelf'        => $c['shelf'],
                    'batch_no'     => $c['batch_no'],
                    'expiry_date'  => $c['expiry_date'] ?: null,
                    'schedule'     => $c['schedule'],
                    'quantity'     => $c['quantity'],
                    'rate'         => $c['rate'],
                    'mrp'          => $c['mrp'],
                    'unit_type'    => $c['unit_type'],
                    'discount_pct' => $c['discount_pct'],
                    'gst_rate'     => $c['gst_rate'],
                    'taxable'      => $c['taxable'],
                    'cost_price'   => $c['cost_price'],
                    'line_total'   => $c['line_total'],
                    'total_amt'    => $c['total_amt'],
                    'cd_disc_amt'  => $c['cd_disc_amt'] ?? 0,
                    'profit'       => $c['profit'],
                ]);
            }

            // Attach prescription to the sale.
            if (!empty($data['prescription_id'])) {
                $upd = $db->prepare('UPDATE prescriptions SET sale_id = :sid WHERE id = :pid AND client_id = :cid');
                $upd->execute(['sid' => $saleId, 'pid' => (int) $data['prescription_id'], 'cid' => $clientId]);
            }

            // Auto-schedule a refill reminder if reminder_days was provided.
            if (!empty($data['reminder_days'])) {
                $refillDate = date('Y-m-d', strtotime('+' . (int) $data['reminder_days'] . ' days'));
                $rstmt = $db->prepare(
                    'INSERT INTO refill_reminders
                        (client_id, branch_id, sale_id, customer_name, phone, product_name, refill_date, status, created_at, updated_at)
                     VALUES (:c, :b, :s, :name, :phone, :prod, :date, :status, :now, :now2)'
                );
                $rstmt->execute([
                    'c' => $clientId, 'b' => $branchId, 's' => $saleId,
                    'name' => $data['patient_name'] ?? ($data['customer_name'] ?? 'Customer'),
                    'phone' => $data['contact_no'] ?? null,
                    'prod' => $computed[0]['product_name'] ?? null,
                    'date' => $refillDate, 'status' => 'pending', 'now' => now(), 'now2' => now(),
                ]);
            }

            return [
                'id'            => $saleId,
                'invoice_no'    => $invoiceNo,
                'net_amount'    => round($netAmount, 2),
                'gst_amount'    => round($gstTotal, 2),
                'cd_amount'     => round($cdAmount, 2),
                'cgst'          => $cgst,
                'sgst'          => $sgst,
                'igst'          => $igst,
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
     * Consume $qty (base units) from batches. If a preferred batch is given it is
     * drained first, then remaining is taken FEFO.
     * Returns [primaryBatchNo, primaryBatchId, weightedCostInclGst, expiryDate, shelf, unitMrp].
     */
    private function consumeStock(PDO $db, int $clientId, int $branchId, int $bindingId, int $qty, ?int $preferredBatchId = null, ?string $txnDate = null): array
    {
        $stmt = $db->prepare(
            'SELECT id, batch_no, quantity, purchase_rate, expiry_date, shelf_location, mrp
             FROM inventory_batches
             WHERE client_id = :c AND branch_id = :b AND product_binding_id = :p AND quantity > 0
             ORDER BY (expiry_date IS NULL), expiry_date ASC, id ASC'
        );
        $stmt->execute(['c' => $clientId, 'b' => $branchId, 'p' => $bindingId]);
        $batches = $stmt->fetchAll();

        // Move the preferred batch to the front.
        if ($preferredBatchId !== null) {
            usort($batches, static fn ($a, $b) => ((int) $b['id'] === $preferredBatchId) <=> ((int) $a['id'] === $preferredBatchId));
        }

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
        $expiry = null;
        $shelf = null;
        $unitMrp = 0.0;

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }
            $take = min($remaining, (int) $batch['quantity']);
            $costAccum += $take * (float) $batch['purchase_rate'];
            if ($primaryBatchNo === null) {
                $primaryBatchNo = $batch['batch_no'];
                $primaryBatchId = (int) $batch['id'];
                $expiry = $batch['expiry_date'];
                $shelf = $batch['shelf_location'];
                $unitMrp = round((float) $batch['mrp'], 2);
            }
            $this->inventory->reduceBatch((int) $batch['id'], $take, 'sale', $txnDate, 'sale', null);
            $remaining -= $take;
        }

        $this->inventory->bumpAggregate($clientId, $branchId, $bindingId, -$qty);

        $weightedCost = $qty > 0 ? $costAccum / $qty : 0.0;
        return [$primaryBatchNo, $primaryBatchId, $weightedCost, $expiry, $shelf, $unitMrp];
    }
}
