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
    private const PRESCRIPTION_REQUIRED = ['X', 'G', 'RESTRICTED'];
    private const LOCK_SCHEDULES = ['H', 'H1', 'X', 'G', 'RESTRICTED'];

    private InventoryService $inventory;

    public function __construct()
    {
        $this->inventory = new InventoryService();
    }

    /** HO Admin: full bill edit — reverse stock, replace lines, recalc totals (Retail editsales parity). */
    public function update(int $clientId, int $branchId, int $saleId, ?int $userId, array $data): array
    {
        if ($saleId <= 0) {
            throw HttpException::validation(['id' => ['Sale id is required.']]);
        }

        return $this->create($clientId, $branchId, $userId, $data, $saleId);
    }

    /**
     * Delete a sale bill and restore stock (purchase-delete / Retail deleteSale parity).
     * Blocked when the bill has returns or a wholesale credit note.
     */
    public function delete(int $clientId, int $branchId, int $saleId): void
    {
        if ($saleId <= 0) {
            throw HttpException::validation(['id' => ['Sale id is required.']]);
        }

        Connection::transaction('ops', function (PDO $db) use ($clientId, $branchId, $saleId) {
            $sale = $this->fetchSaleForEdit($db, $clientId, $branchId, $saleId);
            $this->assertSaleDeletable($db, $sale, $saleId);

            $txnDate = date('Y-m-d', strtotime((string) ($sale['invoice_date'] ?? 'now')) ?: time());
            $bindings = $this->reverseSaleStockForEdit(
                $db,
                $clientId,
                $branchId,
                $saleId,
                $txnDate,
                'Sale deleted — restore stock'
            );
            $this->inventory->reconcileAggregates($clientId, $branchId, $bindings);

            $db->prepare('DELETE FROM sale_items WHERE sale_id = :id')->execute(['id' => $saleId]);
            $db->prepare('DELETE FROM sales WHERE id = :id AND client_id = :c AND branch_id = :b')
                ->execute(['id' => $saleId, 'c' => $clientId, 'b' => $branchId]);
        });
    }

    public function create(int $clientId, int $branchId, ?int $userId, array $data, ?int $updateSaleId = null): array
    {
        $items = $data['items'] ?? [];
        if (!is_array($items) || $items === []) {
            throw HttpException::validation(['items' => ['Add at least one item to the bill.']]);
        }

        // Normalize item keys / quantities and pull schedule from the catalog
        // (never trust client-supplied schedule for compliance gates).
        $normalized = [];
        foreach ($items as $i => $item) {
            if (!is_array($item)) {
                throw HttpException::validation(["items.$i" => ['Invalid product or quantity.']]);
            }
            $bindingId = (int) ($item['product_binding_id'] ?? 0);
            $qty = (int) round((float) ($item['quantity'] ?? 0));
            if ($bindingId <= 0 || $qty <= 0) {
                throw HttpException::validation(["items.$i" => ['Invalid product or quantity. Enter a quantity of at least 1.']]);
            }
            $normalized[] = array_merge($item, [
                'product_binding_id' => $bindingId,
                'quantity'           => $qty,
            ]);
        }
        $items = $normalized;

        // Pre-flight compliance check before opening a transaction.
        $needsPrescription = false;
        $hasH1 = false;
        $ops = Connection::ops();
        foreach ($items as $i => $item) {
            $binding = $this->binding($ops, $clientId, $branchId, (int) $item['product_binding_id']);
            $schedule = strtoupper((string) ($binding['schedule_category'] ?? 'OTC'));
            $items[$i]['schedule_category'] = $schedule;
            if (in_array($schedule, self::PRESCRIPTION_REQUIRED, true)) {
                $needsPrescription = true;
            }
            if ($schedule === 'H1') {
                $hasH1 = true;
            }
        }

        // Wholesale (B2B) billing: the buyer is itself a licensed party, so the
        // retail-counter compliance gates (mandatory prescription for X/G/Restricted,
        // mandatory doctor+customer for H1) do not apply. The UI still shows a warning.
        $isWholesale = ($data['bill_mode'] ?? 'retail') === 'wholesale';

        // Schedule H1: doctor + customer details are mandatory (register requirement).
        if ($hasH1 && !$isWholesale) {
            $doctor = trim((string) ($data['doctor_name'] ?? ''));
            $customer = trim((string) ($data['patient_name'] ?? '')) ?: trim((string) ($data['customer_name'] ?? ''));
            if ($doctor === '') {
                throw HttpException::validation(['doctor_name' => ['Schedule H1: Doctor details are mandatory.']]);
            }
            if ($customer === '' || strtolower($customer) === 'walk-in') {
                throw HttpException::validation(['customer_name' => ['Schedule H1: Customer details are mandatory.']]);
            }
        }
        if ($needsPrescription && !$isWholesale) {
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

        return Connection::transaction('ops', function (PDO $db) use ($clientId, $branchId, $userId, $data, $items, $updateSaleId) {
            $existingSale = null;
            $reversedBindings = [];
            if ($updateSaleId !== null) {
                $existingSale = $this->fetchSaleForEdit($db, $clientId, $branchId, $updateSaleId);
                $this->assertSaleEditable($existingSale);
                $txnDate = date('Y-m-d', strtotime((string) ($data['invoice_date'] ?? $existingSale['invoice_date'] ?? 'now')));
                $reversedBindings = $this->reverseSaleStockForEdit($db, $clientId, $branchId, $updateSaleId, $txnDate);
                $db->prepare('DELETE FROM sale_items WHERE sale_id = :id')->execute(['id' => $updateSaleId]);
            }

            $gross = 0.0;
            $discountTotal = 0.0;
            $gstTotal = 0.0;
            $sellTotal = 0.0;
            $profitTotal = 0.0;
            $locked = false;
            $computed = [];

            $billMode = strtolower((string) ($data['bill_mode'] ?? 'retail'));
            $isWholesale = $billMode === 'wholesale';
            $cdPct = $isWholesale ? (float) ($data['cd_disc_pct'] ?? 0) : 0.0;
            $cdAmount = 0.0;

            foreach ($items as $i => $item) {
                $bindingId = (int) ($item['product_binding_id'] ?? 0);
                $qty       = (int) ($item['quantity'] ?? 0);
                $freeQty   = max(0, (int) ($item['free_quantity'] ?? 0));
                $rate      = (float) ($item['rate'] ?? 0);
                $discPct   = (float) ($item['discount_pct'] ?? 0);
                $preferredBatchId = !empty($item['batch_id']) ? (int) $item['batch_id'] : null;
                $stockQty  = $qty + ($isWholesale ? $freeQty : 0);

                if ($bindingId <= 0 || $qty <= 0) {
                    throw HttpException::validation(["items.$i" => ['Invalid product or quantity.']]);
                }

                $binding = $this->binding($db, $clientId, $branchId, $bindingId);
                $upp = max(1, (int) ($binding['units_per_pack'] ?? 1));
                $gstRate = (float) $binding['gst_rate'];
                $schedule = strtoupper((string) $binding['schedule_category']);
                if (in_array($schedule, self::LOCK_SCHEDULES, true)) {
                    $locked = true;
                }

                // Consume stock per line: explicit batch_id = that batch only (multi-batch
                // same product must each deduct their own batch). No batch = FEFO.
                // Quantities are base (loose) units. Batch purchase_rate = net cost/unit.
                $saleDate = date('Y-m-d', strtotime((string) ($data['invoice_date'] ?? 'now')));
                [$batchNo, $batchId, $unitNetRate, $expiry, $shelf, $unitMrp] =
                    $this->consumeStock($db, $clientId, $branchId, $bindingId, $stockQty, $preferredBatchId, $saleDate);

                // Retail: new bills default sell rate from purchase-overlay MRP; edit/wholesale
                // keep the client rate so per-batch MRP edits and wholesale PTR/PTS stick.
                if ($billMode !== 'wholesale' && $unitMrp > 0) {
                    $purchaseUnitMrp = $this->retailPurchaseUnitMrp(
                        $db,
                        $clientId,
                        $branchId,
                        $bindingId,
                        (string) $batchNo,
                        $upp
                    );
                    if ($purchaseUnitMrp > 0) {
                        $unitMrp = $purchaseUnitMrp;
                    }
                    if ($updateSaleId === null || $rate <= 0) {
                        $rate = $unitMrp;
                    }
                }

                // GST mode: retail = GST-inclusive; wholesale PTR/PTS = GST-exclusive (Retail wsales).
                $gstInclusive = $isWholesale
                    ? false
                    : (!array_key_exists('gst_inclusive', $data) || (int) $data['gst_inclusive'] === 1);

                $lineGross = $rate * $qty;
                $discAmtLine = round($lineGross * $discPct / 100, 2);
                $lineCost = $unitNetRate * $qty;

                if ($isWholesale) {
                    $cdAmtLine = round(($lineGross - $discAmtLine) * $cdPct / 100, 2);
                    $taxable = round($lineGross - $discAmtLine - $cdAmtLine, 2);
                    $gstAmt  = round($taxable * $gstRate / 100, 2);
                    $sell    = round($taxable + $gstAmt, 2);
                    $packQty = $upp > 0 ? $qty / $upp : (float) $qty;
                    $packPurchase = $this->wholesalePackPurchaseRate($db, $clientId, $branchId, $bindingId, $batchNo);
                    if ($packPurchase <= 0) {
                        $packPurchase = $unitNetRate * $upp;
                    }
                    $profit  = round($lineGross - $discAmtLine - $packPurchase * $packQty, 2);
                    $cdAmount += $cdAmtLine;
                } else {
                    $afterDisc = $lineGross * (1 - $discPct / 100);
                    if ($gstInclusive) {
                        $sell    = $afterDisc;
                        $taxable = $sell / (1 + $gstRate / 100);
                        $gstAmt  = $sell - $taxable;
                    } else {
                        $taxable = $afterDisc;
                        $gstAmt  = $taxable * $gstRate / 100;
                        $sell    = $taxable + $gstAmt;
                    }
                    $profit  = $taxable - $lineCost;
                    $cdAmtLine = 0.0;
                }

                $gross        += $lineGross;
                $discountTotal += $discAmtLine;
                $gstTotal     += $gstAmt;
                $sellTotal    += $sell;
                $profitTotal  += $profit;

                // HSN always comes from product master (via binding) — never trust blank client payload.
                $hsn = trim((string) ($binding['hsn_code'] ?? ''));
                if ($hsn === '') {
                    $hsn = trim((string) ($item['hsn_code'] ?? ''));
                }
                if ($hsn === '' && !empty($binding['master_product_id'])) {
                    $m = Connection::master()->prepare('SELECT hsn_code FROM products WHERE id = :id LIMIT 1');
                    $m->execute(['id' => (int) $binding['master_product_id']]);
                    $hsn = trim((string) ($m->fetchColumn() ?: ''));
                }

                $computed[] = [
                    'binding_id'   => $bindingId,
                    'batch_id'     => $batchId,
                    'batch_no'     => $batchNo,
                    'expiry_date'  => $expiry,
                    'shelf'        => $item['shelf'] ?? $shelf,
                    'pack'         => $item['pack'] ?? $binding['pack_size'] ?? null,
                    // Pack/strip MRP for print/grid — follow sell unit rate when set (edit multi-MRP).
                    'mrp'          => round(($rate > 0 ? $rate : $unitMrp) * $upp, 2),
                    'unit_type'    => $item['unit_type'] ?? 'unit',
                    'product_name' => $binding['product_name'],
                    'mfg'          => $binding['manufacturer_short'] ?? null,
                    'hsn_code'     => $hsn !== '' ? $hsn : null,
                    'schedule'     => $schedule,
                    'quantity'     => $qty,
                    'free_quantity'=> $isWholesale ? $freeQty : 0,
                    'rate'         => round($rate, 2),
                    'oth_rate'     => $isWholesale ? round((float) ($item['oth_rate'] ?? 0), 2) : 0,
                    'base_rate'    => $isWholesale ? round((float) ($item['base_rate'] ?? $rate), 2) : round($rate, 2),
                    'net_rate'     => $isWholesale ? round((float) ($item['net_rate'] ?? $rate), 2) : round($rate, 2),
                    'billing_with' => $isWholesale ? (string) ($item['billing_with'] ?? 'Sale Rate') : 'Sale Rate',
                    'sale_scheme_pct' => $isWholesale ? round((float) ($item['sale_scheme_pct'] ?? 0), 2) : 0,
                    'discount_pct' => $discPct,
                    'gst_rate'     => $gstRate,
                    'taxable'      => round($taxable, 2),
                    'total_amt'    => round($lineGross, 2),
                    'cost_price'   => round($lineCost, 2),
                    'line_total'   => round($sell, 2),
                    'profit'       => round($profit, 2),
                    'cd_disc_amt'  => round($cdAmtLine, 2),
                    'sell_raw'     => $sell,
                ];
            }

            $billDiscount = (float) ($data['bill_discount_amount'] ?? 0);
            if (!empty($data['bill_discount_pct'])) {
                $billDiscount += $sellTotal * (float) $data['bill_discount_pct'] / 100;
            }

            if ($isWholesale) {
                // Retail wsales: CD applied per line before GST; sellTotal already includes it.
                $netAmount = max(0, $sellTotal - $billDiscount);
                $profitTotal -= $billDiscount;
            } else {
                $cdPct = (float) ($data['cd_disc_pct'] ?? 0);
                $cdAmount = ($sellTotal - $billDiscount) * $cdPct / 100;
                $netAmount = max(0, $sellTotal - $billDiscount - $cdAmount);
                $profitTotal -= ($billDiscount + $cdAmount);
                foreach ($computed as &$cAlloc) {
                    $share = $sellTotal > 0 ? $cAlloc['sell_raw'] / $sellTotal : 0;
                    $cAlloc['cd_disc_amt'] = round($cdAmount * $share, 2);
                }
                unset($cAlloc);
            }

            // GST split: intra-state = CGST + SGST; inter-state = IGST.
            $isInterstate = (int) ($data['is_interstate'] ?? 0) === 1;
            $cgst = $isInterstate ? 0 : round($gstTotal / 2, 2);
            $sgst = $isInterstate ? 0 : round($gstTotal - $cgst, 2);
            $igst = $isInterstate ? round($gstTotal, 2) : 0;

            $paymentMode = strtolower((string) ($data['payment_mode'] ?? 'cash'));
            $netRaw = round($netAmount, 2);
            $netRounded = round($netRaw, 0);
            $roundOff = round($netRounded - $netRaw, 2);
            $profitTotal += $roundOff;
            $netAmount = $netRounded;
            if ($updateSaleId !== null && $existingSale !== null) {
                $oldMode = strtolower(trim((string) ($existingSale['payment_mode'] ?? 'cash')));
                if ($paymentMode === 'credit' && $oldMode === 'credit') {
                    $paidAmt = max(0, min($netRounded, round((float) ($existingSale['paid'] ?? 0), 2)));
                    $balanceAmt = max(0, round($netRounded - $paidAmt, 2));
                } elseif ($paymentMode === 'credit') {
                    $paidAmt = 0.0;
                    $balanceAmt = $netRounded;
                } else {
                    $paidAmt = $netRounded;
                    $balanceAmt = 0.0;
                }
            } elseif ($paymentMode === 'credit') {
                $paidAmt = 0.0;
                $balanceAmt = $netRounded;
            } else {
                $paidAmt = $netRounded;
                $balanceAmt = 0.0;
            }
            $accountId = !empty($data['account_id']) ? (int) $data['account_id'] : null;
            if ($paymentMode !== 'card') {
                $accountId = null;
            }

            $splitCash = 0.0;
            $splitUpi = 0.0;
            if ($paymentMode === 'cash+upi') {
                if (!self::hasSplitPaymentColumns($db)) {
                    throw HttpException::validation(['payment_mode' => ['Cash+UPI is not available yet — database update pending.']]);
                }
                $splitCash = round((float) ($data['cash_amount'] ?? 0), 2);
                if ($splitCash <= 0 || $splitCash >= $netRounded) {
                    throw HttpException::validation(['cash_amount' => ['Cash amount must be more than 0 and less than the bill net amount.']]);
                }
                $splitUpi = round($netRounded - $splitCash, 2);
            }

            if ($updateSaleId !== null && $existingSale !== null) {
                $saleId = $updateSaleId;
                $invoiceNo = (string) ($existingSale['invoice_no'] ?? '');
                $upd = $db->prepare(
                    'UPDATE sales SET
                        counter = :counter, ipd_admission_id = :ipd_admission_id, sale_type = :sale_type, bill_mode = :bill_mode,
                        rate_type = :rate_type, invoice_date = :invoice_date,
                        customer_id = :customer_id, customer_name = :customer_name, patient_name = :patient_name,
                        contact_no = :contact_no, email = :email, address = :address, narration = :narration,
                        reminder_days = :reminder_days, doctor_name = :doctor_name,
                        gross_amount = :gross, taxable_amount = :taxable, discount_amount = :discount,
                        cd_disc_pct = :cd_pct, cd_amount = :cd_amt, gst_amount = :gst,
                        is_interstate = :interstate, cgst = :cgst, sgst = :sgst, igst = :igst,
                        net_amount = :net, profit_amount = :profit, payment_mode = :payment_mode, account_id = :account_id,
                        paid = :paid, balance = :balance, has_prescription = :has_presc, is_locked = :is_locked
                     WHERE id = :id AND client_id = :client_id AND branch_id = :branch_id'
                );
                $upd->execute([
                    'id' => $saleId,
                    'client_id' => $clientId,
                    'branch_id' => $branchId,
                    'counter' => $data['counter'] ?? $existingSale['counter'] ?? null,
                    'ipd_admission_id' => !empty($data['ipd_admission_id']) ? (int) $data['ipd_admission_id'] : ($existingSale['ipd_admission_id'] ?? null),
                    'sale_type' => $data['sale_type'] ?? $existingSale['sale_type'] ?? 'Regular',
                    'bill_mode' => $data['bill_mode'] ?? $existingSale['bill_mode'] ?? 'retail',
                    'rate_type' => $data['rate_type'] ?? $existingSale['rate_type'] ?? null,
                    'invoice_date' => $data['invoice_date'] ?? $existingSale['invoice_date'] ?? now(),
                    'customer_id' => $data['customer_id'] ?? $existingSale['customer_id'] ?? null,
                    'customer_name' => $data['customer_name'] ?? ($data['patient_name'] ?? $existingSale['customer_name'] ?? 'Walk-in'),
                    'patient_name' => $data['patient_name'] ?? $existingSale['patient_name'] ?? null,
                    'contact_no' => $data['contact_no'] ?? $existingSale['contact_no'] ?? null,
                    'email' => $data['email'] ?? $existingSale['email'] ?? null,
                    'address' => $data['address'] ?? $existingSale['address'] ?? null,
                    'narration' => $data['narration'] ?? $existingSale['narration'] ?? null,
                    'reminder_days' => !empty($data['reminder_days']) ? (int) $data['reminder_days'] : ($existingSale['reminder_days'] ?? null),
                    'doctor_name' => $data['doctor_name'] ?? $existingSale['doctor_name'] ?? null,
                    'gross' => round($gross, 2),
                    'taxable' => round($sellTotal - $gstTotal, 2),
                    'discount' => round($discountTotal + $billDiscount, 2),
                    'cd_pct' => $cdPct,
                    'cd_amt' => round($cdAmount, 2),
                    'gst' => round($gstTotal, 2),
                    'interstate' => $isInterstate ? 1 : 0,
                    'cgst' => $cgst,
                    'sgst' => $sgst,
                    'igst' => $igst,
                    'net' => $netRounded,
                    'profit' => round($profitTotal, 2),
                    'payment_mode' => $paymentMode,
                    'account_id' => $accountId,
                    'paid' => $paidAmt,
                    'balance' => $balanceAmt,
                    'has_presc' => !empty($data['prescription_id']) ? 1 : (int) ($existingSale['has_prescription'] ?? 0),
                    'is_locked' => $locked ? 1 : (int) ($existingSale['is_locked'] ?? 0),
                ]);
            } else {
            $sale = new Sale();
            $invoiceNo = $data['invoice_no'] ?? $sale->nextInvoiceNo($clientId, $branchId);

            $stmt = $db->prepare(
                'INSERT INTO sales
                    (client_id, branch_id, counter, ipd_admission_id, sale_type, bill_mode, rate_type, invoice_no, invoice_date,
                     customer_id, customer_name, patient_name, contact_no, email, address, narration, reminder_days, doctor_name,
                     gross_amount, taxable_amount, discount_amount, cd_disc_pct, cd_amount, gst_amount,
                     is_interstate, cgst, sgst, igst, net_amount, profit_amount, payment_mode, account_id, paid, balance,
                     has_prescription, is_locked, created_by, created_at)
                 VALUES
                    (:client_id, :branch_id, :counter, :ipd_admission_id, :sale_type, :bill_mode, :rate_type, :invoice_no, :invoice_date,
                     :customer_id, :customer_name, :patient_name, :contact_no, :email, :address, :narration, :reminder_days, :doctor_name,
                     :gross, :taxable, :discount, :cd_pct, :cd_amt, :gst,
                     :interstate, :cgst, :sgst, :igst, :net, :profit, :payment_mode, :account_id, :paid, :balance,
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
                'net'           => $netRounded,
                'profit'        => round($profitTotal, 2),
                'payment_mode'  => $paymentMode,
                'account_id'    => $accountId,
                'paid'          => $paidAmt,
                'balance'       => $balanceAmt,
                'has_presc'     => !empty($data['prescription_id']) ? 1 : 0,
                'is_locked'     => $locked ? 1 : 0,
                'created_by'    => $userId,
                'created_at'    => now(),
            ]);
            $saleId = (int) $db->lastInsertId();
            }

            if (self::hasSplitPaymentColumns($db)) {
                $db->prepare('UPDATE sales SET cash_amount = :cash, upi_amount = :upi WHERE id = :id AND client_id = :c')
                    ->execute(['cash' => $splitCash, 'upi' => $splitUpi, 'id' => $saleId, 'c' => $clientId]);
            }
            if (self::hasRoundOffColumn($db)) {
                $db->prepare('UPDATE sales SET round_off = :ro WHERE id = :id AND client_id = :c')
                    ->execute(['ro' => $roundOff, 'id' => $saleId, 'c' => $clientId]);
            }

            $wholesaleCols = $isWholesale && $this->saleItemsHasWholesaleColumns($db);
            if ($wholesaleCols) {
                $itemStmt = $db->prepare(
                    'INSERT INTO sale_items
                        (sale_id, product_binding_id, batch_id, product_name, manufacturer_short, hsn_code, pack, shelf, batch_no, expiry_date,
                         schedule_category, quantity, free_quantity, rate, oth_rate, base_rate, net_rate, billing_with, sale_scheme_pct,
                         mrp, unit_type, discount_pct, gst_rate, taxable,
                         cost_price, line_total, total_amt, cd_disc_amt, profit)
                     VALUES
                        (:sale_id, :binding_id, :batch_id, :product_name, :mfg, :hsn, :pack, :shelf, :batch_no, :expiry_date,
                         :schedule, :quantity, :free_quantity, :rate, :oth_rate, :base_rate, :net_rate, :billing_with, :sale_scheme_pct,
                         :mrp, :unit_type, :discount_pct, :gst_rate, :taxable,
                         :cost_price, :line_total, :total_amt, :cd_disc_amt, :profit)'
                );
            } else {
                $itemStmt = $db->prepare(
                    'INSERT INTO sale_items
                        (sale_id, product_binding_id, batch_id, product_name, manufacturer_short, hsn_code, pack, shelf, batch_no, expiry_date,
                         schedule_category, quantity, rate, mrp, unit_type, discount_pct, gst_rate, taxable,
                         cost_price, line_total, total_amt, cd_disc_amt, profit)
                     VALUES
                        (:sale_id, :binding_id, :batch_id, :product_name, :mfg, :hsn, :pack, :shelf, :batch_no, :expiry_date,
                         :schedule, :quantity, :rate, :mrp, :unit_type, :discount_pct, :gst_rate, :taxable,
                         :cost_price, :line_total, :total_amt, :cd_disc_amt, :profit)'
                );
            }
            foreach ($computed as $c) {
                $params = [
                    'sale_id'      => $saleId,
                    'binding_id'   => $c['binding_id'],
                    'batch_id'     => $c['batch_id'],
                    'product_name' => $c['product_name'],
                    'mfg'          => $c['mfg'] ?? null,
                    'hsn'          => $c['hsn_code'] ?? null,
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
                ];
                if ($wholesaleCols) {
                    $params['free_quantity'] = $c['free_quantity'] ?? 0;
                    $params['oth_rate'] = $c['oth_rate'] ?? 0;
                    $params['base_rate'] = $c['base_rate'] ?? $c['rate'];
                    $params['net_rate'] = $c['net_rate'] ?? $c['rate'];
                    $params['billing_with'] = $c['billing_with'] ?? 'Sale Rate';
                    $params['sale_scheme_pct'] = $c['sale_scheme_pct'] ?? 0;
                }
                $itemStmt->execute($params);
            }

            // Attach prescription to the sale.
            if (!empty($data['prescription_id'])) {
                $upd = $db->prepare('UPDATE prescriptions SET sale_id = :sid WHERE id = :pid AND client_id = :cid');
                $upd->execute(['sid' => $saleId, 'pid' => (int) $data['prescription_id'], 'cid' => $clientId]);
            }

            // Maintain the patient (customer) + doctor masters from this bill.
            $resolvedCustomerId = $this->syncCustomer($db, $clientId, $data);
            if ($resolvedCustomerId !== null && empty($data['customer_id'])) {
                $db->prepare('UPDATE sales SET customer_id = :cid WHERE id = :sid')
                   ->execute(['cid' => $resolvedCustomerId, 'sid' => $saleId]);
            }
            $this->syncDoctor($db, $clientId, $data);

            // Auto-schedule a refill reminder if reminder_days was provided (new bills only).
            if ($updateSaleId === null && !empty($data['reminder_days'])) {
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

            $this->inventory->reconcileAggregates($clientId, $branchId, array_values(array_unique(array_merge(
                $reversedBindings,
                array_column($computed, 'binding_id')
            ))));

            if ($updateSaleId !== null) {
                try {
                    $db->prepare(
                        'UPDATE agt_orders SET sale_net = :net, sale_invoice_no = :inv
                         WHERE sale_id = :sid AND client_id = :c'
                    )->execute([
                        'net' => $netRounded,
                        'inv' => $invoiceNo,
                        'sid' => $saleId,
                        'c' => $clientId,
                    ]);
                } catch (\Throwable) {
                    // agt_orders may not exist on all installs.
                }
            }

            if ($updateSaleId === null) {
                $agtOrderId = (int) ($data['agt_order_id'] ?? 0);
                if ($agtOrderId > 0) {
                    (new AuraGharTakService())->markOrderReady(
                        $clientId,
                        $branchId,
                        $agtOrderId,
                        (string) $invoiceNo,
                        $saleId,
                        (float) $netRounded
                    );
                }
                $mrOrderId = (int) ($data['mr_order_id'] ?? 0);
                if ($mrOrderId > 0 && $isWholesale) {
                    MrService::markSold($clientId, $mrOrderId, (string) $invoiceNo, $saleId);
                }
            }

            $creditNoteNo = trim((string) ($data['credit_note_no'] ?? ''));
            $creditDeduction = (float) ($data['credit_deduction_amt'] ?? 0);
            if ($updateSaleId === null && $isWholesale && $creditNoteNo !== '') {
                $cnSvc = new WholesaleCreditNoteService();
                $cnSvc->ensureSchema();
                if ($creditDeduction <= 0) {
                    $open = $cnSvc->openNotesForParty(
                        $clientId,
                        $branchId,
                        (string) ($data['customer_name'] ?? $data['patient_name'] ?? '')
                    );
                    foreach ($open as $o) {
                        if ($o['note_no'] === $creditNoteNo) {
                            $creditDeduction = (float) $o['amount'];
                            break;
                        }
                    }
                }
                if ($creditDeduction > 0) {
                    $newNetRaw = max(0, round($netRaw - $creditDeduction, 2));
                    $newNet = round($newNetRaw, 0);
                    $newRoundOff = round($newNet - $newNetRaw, 2);
                    if ($paymentMode === 'credit') {
                        $newPaid = 0.0;
                        $newBal = $newNet;
                    } else {
                        $newPaid = $newNet;
                        $newBal = 0.0;
                    }
                    try {
                        $db->prepare(
                            'UPDATE sales SET credit_note_no = :cn, credit_deduction_amt = :cd,
                                net_amount = :net, paid = :paid, balance = :bal WHERE id = :id'
                        )->execute([
                            'cn' => $creditNoteNo,
                            'cd' => round($creditDeduction, 2),
                            'net' => $newNet,
                            'paid' => $newPaid,
                            'bal' => $newBal,
                            'id' => $saleId,
                        ]);
                    } catch (\Throwable) {
                        // Columns may not exist yet on older DBs until ensureSchema ran.
                    }
                    if (self::hasRoundOffColumn($db)) {
                        $db->prepare('UPDATE sales SET round_off = :ro, profit_amount = :pf WHERE id = :id AND client_id = :c')
                            ->execute([
                                'ro' => $newRoundOff,
                                'pf' => round($profitTotal - $roundOff + $newRoundOff, 2),
                                'id' => $saleId,
                                'c' => $clientId,
                            ]);
                    }
                    $profitTotal = $profitTotal - $roundOff + $newRoundOff;
                    $cnSvc->applyToSale($clientId, $branchId, $creditNoteNo, (string) $invoiceNo);
                    $netAmount = $newNet;
                }
            }

            return [
                'id'            => $saleId,
                'invoice_no'    => $invoiceNo,
                'net_amount'    => round($netAmount, 2),
                'gst_amount'    => round($gstTotal, 2),
                'cd_amount'     => round($cdAmount, 2),
                'credit_note_no' => $creditNoteNo !== '' ? $creditNoteNo : null,
                'credit_deduction_amt' => $creditDeduction > 0 ? round($creditDeduction, 2) : 0,
                'cgst'          => $cgst,
                'sgst'          => $sgst,
                'igst'          => $igst,
                'profit_amount' => round($profitTotal, 2),
                'is_locked'     => $locked,
            ];
        });
    }

    /**
     * Upsert the patient into the customer master keyed by phone (within the client).
     * Returns the customer id, or null when there is nothing worth saving.
     * Used so a patient billed once appears under /masters/customers.
     */
    private function syncCustomer(PDO $db, int $clientId, array $data): ?int
    {
        $phone = trim((string) ($data['contact_no'] ?? ''));
        $name  = trim((string) ($data['patient_name'] ?? '')) ?: trim((string) ($data['customer_name'] ?? ''));
        if ($name === '' || strtolower($name) === 'walk-in') {
            return !empty($data['customer_id']) ? (int) $data['customer_id'] : null;
        }

        $chronic = (int) ($data['is_chronic'] ?? 0) === 1 ? 1 : 0;
        $email   = $data['email'] ?? null;
        $address = $data['address'] ?? null;
        $custType = strtolower(trim((string) ($data['customer_type'] ?? '')));
        if (!in_array($custType, ['retail', 'wholesale', 'ipd'], true)) {
            $custType = (($data['bill_mode'] ?? 'retail') === 'wholesale') ? 'wholesale' : 'retail';
        }

        // Prefer match by phone (unique for this client), else by explicit customer_id.
        $existing = null;
        if ($phone !== '') {
            $find = $db->prepare('SELECT id, is_chronic, customer_type FROM customers WHERE client_id = :c AND phone = :p LIMIT 1');
            $find->execute(['c' => $clientId, 'p' => $phone]);
            $existing = $find->fetch() ?: null;
        }
        if (!$existing && !empty($data['customer_id'])) {
            $find = $db->prepare('SELECT id, is_chronic, customer_type FROM customers WHERE client_id = :c AND id = :id LIMIT 1');
            $find->execute(['c' => $clientId, 'id' => (int) $data['customer_id']]);
            $existing = $find->fetch() ?: null;
        }

        if ($existing) {
            // Keep an already-chronic patient flagged even if this bill did not tick it.
            $newChronic = $chronic === 1 ? 1 : (int) $existing['is_chronic'];
            // Promote retail -> wholesale when billed on wholesale; never downgrade wholesale to retail here.
            $existingType = (string) ($existing['customer_type'] ?? 'retail');
            $newType = ($custType === 'wholesale' || $existingType === 'wholesale') ? 'wholesale'
                : (($custType === 'ipd' || $existingType === 'ipd') ? 'ipd' : 'retail');
            $db->prepare(
                'UPDATE customers SET name = :name, phone = COALESCE(NULLIF(:phone, \'\'), phone),
                    email = :email, address = :address, is_chronic = :chronic,
                    customer_type = :ctype, updated_at = :u WHERE id = :id'
            )->execute([
                'name' => $name, 'phone' => $phone, 'email' => $email, 'address' => $address,
                'chronic' => $newChronic, 'ctype' => $newType, 'u' => now(), 'id' => (int) $existing['id'],
            ]);
            return (int) $existing['id'];
        }

        // New patient: phone is required to create a durable master record.
        if ($phone === '') {
            return null;
        }

        $db->prepare(
            'INSERT INTO customers (client_id, name, phone, email, address, is_chronic, customer_type, created_at, updated_at)
             VALUES (:c, :name, :phone, :email, :address, :chronic, :ctype, :ca, :ua)'
        )->execute([
            'c' => $clientId, 'name' => $name, 'phone' => $phone, 'email' => $email,
            'address' => $address, 'chronic' => $chronic, 'ctype' => $custType,
            'ca' => now(), 'ua' => now(),
        ]);
        return (int) $db->lastInsertId();
    }

    /** Add the doctor to the doctor master if not already present (by name, within the client). */
    private function syncDoctor(PDO $db, int $clientId, array $data): void
    {
        $name = trim((string) ($data['doctor_name'] ?? ''));
        if ($name === '') {
            return;
        }
        $find = $db->prepare('SELECT id FROM doctors WHERE client_id = :c AND name = :n LIMIT 1');
        $find->execute(['c' => $clientId, 'n' => $name]);
        if ($find->fetch()) {
            return;
        }
        $db->prepare(
            'INSERT INTO doctors (client_id, name, created_at, updated_at) VALUES (:c, :n, :ca, :ua)'
        )->execute(['c' => $clientId, 'n' => $name, 'ca' => now(), 'ua' => now()]);
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
    /**
     * Deduct stock for one sale line.
     * - With batch_id: ONLY that batch (required for same product / different batches).
     * - Without batch_id: FEFO across sellable batches.
     *
     * @return array{0:?string,1:?int,2:float,3:?string,4:?string,5:float} batch_no, batch_id, unit_cost, expiry, shelf, unit_mrp
     */
    private function consumeStock(PDO $db, int $clientId, int $branchId, int $bindingId, int $qty, ?int $preferredBatchId = null, ?string $txnDate = null): array
    {
        if ($qty <= 0) {
            throw HttpException::validation([
                'stock' => ['Quantity must be at least 1.'],
            ]);
        }

        if ($preferredBatchId !== null && $preferredBatchId > 0) {
            return $this->consumePreferredBatch(
                $db,
                $clientId,
                $branchId,
                $bindingId,
                $qty,
                $preferredBatchId,
                $txnDate
            );
        }

        return $this->consumeFefoStock($db, $clientId, $branchId, $bindingId, $qty, $txnDate);
    }

    /**
     * Strict single-batch consume — never spills to another batch.
     *
     * @return array{0:string,1:int,2:float,3:?string,4:?string,5:float}
     */
    private function consumePreferredBatch(
        PDO $db,
        int $clientId,
        int $branchId,
        int $bindingId,
        int $qty,
        int $batchId,
        ?string $txnDate
    ): array {
        $stmt = $db->prepare(
            'SELECT ib.id, ib.batch_no, ib.quantity, ib.purchase_rate, ib.expiry_date, ib.shelf_location, ib.mrp,
                    ib.product_binding_id
             FROM inventory_batches ib
             WHERE ib.id = :id AND ib.client_id = :c AND ib.branch_id = :b
             FOR UPDATE'
        );
        $stmt->execute(['id' => $batchId, 'c' => $clientId, 'b' => $branchId]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$batch) {
            throw HttpException::validation([
                'stock' => ['Selected batch was not found for this branch.'],
            ]);
        }
        if ((int) ($batch['product_binding_id'] ?? 0) !== $bindingId) {
            throw HttpException::validation([
                'stock' => ['Selected batch does not belong to this product.'],
            ]);
        }
        if (InventoryService::isBatchExpired($batch['expiry_date'] ?? null)) {
            throw HttpException::validation([
                'stock' => ['Selected batch is expired and cannot be sold.'],
            ]);
        }
        $have = (int) ($batch['quantity'] ?? 0);
        $batchNo = (string) ($batch['batch_no'] ?? '');
        if ($have < $qty) {
            throw HttpException::validation([
                'stock' => [
                    "Insufficient stock on batch {$batchNo} (have {$have}, need {$qty}). "
                    . 'Same product on another batch must be a separate line — stock is not taken from other batches.',
                ],
            ]);
        }

        $taken = $this->inventory->reduceBatch($batchId, $qty, 'sale', $txnDate, 'sale', null);
        if ($taken < $qty) {
            throw HttpException::validation([
                'stock' => [
                    "Could not deduct full qty from batch {$batchNo} (deducted {$taken}, need {$qty}).",
                ],
            ]);
        }

        $this->inventory->bumpAggregate($clientId, $branchId, $bindingId, -$qty);

        $unitCost = (float) ($batch['purchase_rate'] ?? 0);
        return [
            $batchNo,
            $batchId,
            $unitCost,
            $batch['expiry_date'] ?? null,
            $batch['shelf_location'] ?? null,
            round((float) ($batch['mrp'] ?? 0), 2),
        ];
    }

    /**
     * FEFO when the client did not pin a batch.
     *
     * @return array{0:?string,1:?int,2:float,3:?string,4:?string,5:float}
     */
    private function consumeFefoStock(
        PDO $db,
        int $clientId,
        int $branchId,
        int $bindingId,
        int $qty,
        ?string $txnDate
    ): array {
        $stmt = $db->prepare(
            'SELECT ib.id, ib.batch_no, ib.quantity, ib.purchase_rate, ib.expiry_date, ib.shelf_location, ib.mrp
             FROM inventory_batches ib
             WHERE ib.client_id = :c AND ib.branch_id = :b AND ib.product_binding_id = :p
               AND ib.quantity > 0
               AND (ib.expiry_date IS NULL OR ib.expiry_date >= CURDATE())
             ORDER BY (ib.expiry_date IS NULL), ib.expiry_date ASC, ib.id ASC
             FOR UPDATE'
        );
        $stmt->execute(['c' => $clientId, 'b' => $branchId, 'p' => $bindingId]);
        $batches = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $available = array_sum(array_map(static fn ($b) => (int) $b['quantity'], $batches));
        if ($available < $qty) {
            throw HttpException::validation([
                'stock' => ["Insufficient sellable stock (have {$available}, need {$qty}). Expired batches are excluded."],
            ]);
        }

        $remaining = $qty;
        $costAccum = 0.0;
        $primaryBatchNo = null;
        $primaryBatchId = null;
        $expiry = null;
        $shelf = null;
        $unitMrp = 0.0;
        $actuallyTaken = 0;

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }
            $want = min($remaining, (int) $batch['quantity']);
            if ($want <= 0) {
                continue;
            }
            $taken = $this->inventory->reduceBatch((int) $batch['id'], $want, 'sale', $txnDate, 'sale', null);
            if ($taken <= 0) {
                continue;
            }
            $costAccum += $taken * (float) $batch['purchase_rate'];
            $actuallyTaken += $taken;
            if ($primaryBatchNo === null) {
                $primaryBatchNo = $batch['batch_no'];
                $primaryBatchId = (int) $batch['id'];
                $expiry = $batch['expiry_date'];
                $shelf = $batch['shelf_location'];
                $unitMrp = round((float) $batch['mrp'], 2);
            }
            $remaining -= $taken;
        }

        if ($actuallyTaken < $qty || $remaining > 0) {
            throw HttpException::validation([
                'stock' => [
                    "Could not deduct full stock (deducted {$actuallyTaken}, need {$qty}). Try again or pick batches explicitly.",
                ],
            ]);
        }

        $this->inventory->bumpAggregate($clientId, $branchId, $bindingId, -$qty);

        $weightedCost = $qty > 0 ? $costAccum / $qty : 0.0;
        return [$primaryBatchNo, $primaryBatchId, $weightedCost, $expiry, $shelf, $unitMrp];
    }

    /** Unit sell MRP from latest purchase line (SalesController::batches overlay parity). */
    private function retailPurchaseUnitMrp(
        PDO $db,
        int $clientId,
        int $branchId,
        int $bindingId,
        string $batchNo,
        int $upp
    ): float {
        if ($batchNo === '') {
            return 0.0;
        }
        $stmt = $db->prepare(
            'SELECT pi.mrp, pi.units_per_pack
             FROM purchase_items pi
             INNER JOIN purchases p ON p.id = pi.purchase_id
             WHERE p.client_id = :c AND p.branch_id = :b
               AND pi.product_binding_id = :bind
               AND pi.batch_no = :batch
             ORDER BY p.invoice_date DESC, p.id DESC, pi.id DESC
             LIMIT 1'
        );
        $stmt->execute([
            'c'     => $clientId,
            'b'     => $branchId,
            'bind'  => $bindingId,
            'batch' => $batchNo,
        ]);
        $row = $stmt->fetch();
        if (!$row || (float) ($row['mrp'] ?? 0) <= 0) {
            return 0.0;
        }
        $piUpp = max(1, (int) ($row['units_per_pack'] ?? $upp));

        return round((float) $row['mrp'] / $piUpp, 2);
    }

    /** Pack gross purchase rate from latest purchase line (Retail wsales unit_net_rate parity). */
    private function wholesalePackPurchaseRate(
        PDO $db,
        int $clientId,
        int $branchId,
        int $bindingId,
        string $batchNo
    ): float {
        if ($batchNo === '') {
            return 0.0;
        }
        $stmt = $db->prepare(
            'SELECT pi.purchase_rate, pi.scheme_pct
             FROM purchase_items pi
             INNER JOIN purchases p ON p.id = pi.purchase_id
             WHERE p.client_id = :c AND p.branch_id = :b
               AND pi.product_binding_id = :bind
               AND pi.batch_no = :batch
             ORDER BY p.invoice_date DESC, p.id DESC, pi.id DESC
             LIMIT 1'
        );
        $stmt->execute([
            'c'     => $clientId,
            'b'     => $branchId,
            'bind'  => $bindingId,
            'batch' => $batchNo,
        ]);
        $row = $stmt->fetch();
        if (!$row) {
            return 0.0;
        }
        $rate = (float) ($row['purchase_rate'] ?? 0);
        $scheme = (float) ($row['scheme_pct'] ?? 0);
        if ($rate > 0 && $scheme > 0) {
            return round($rate * 100 / (100 + $scheme), 2);
        }
        return $rate;
    }

    /** @return array<string,mixed> */
    private function fetchSaleForEdit(PDO $db, int $clientId, int $branchId, int $saleId): array
    {
        $st = $db->prepare(
            'SELECT * FROM sales WHERE id = :id AND client_id = :c AND branch_id = :b LIMIT 1'
        );
        $st->execute(['id' => $saleId, 'c' => $clientId, 'b' => $branchId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw HttpException::notFound('Sale not found.');
        }

        return $row;
    }

    /** @param array<string,mixed> $sale */
    private function assertSaleEditable(array $sale): void
    {
        if ((float) ($sale['return_total'] ?? 0) > 0.009) {
            throw HttpException::validation(['sale' => ['This bill has sales returns — full edit is not allowed.']]);
        }
        if (trim((string) ($sale['credit_note_no'] ?? '')) !== '') {
            throw HttpException::validation(['sale' => ['Wholesale bill with credit note applied cannot be edited here.']]);
        }
    }

    /** @param array<string,mixed> $sale */
    private function assertSaleDeletable(PDO $db, array $sale, int $saleId): void
    {
        if ((float) ($sale['return_total'] ?? 0) > 0.009) {
            throw HttpException::validation(['sale' => ['This bill has sales returns — delete is not allowed.']]);
        }
        if (trim((string) ($sale['credit_note_no'] ?? '')) !== '') {
            throw HttpException::validation(['sale' => ['Wholesale bill with credit note applied cannot be deleted.']]);
        }
        try {
            $chk = $db->prepare(
                'SELECT COUNT(*) FROM retail_sale_returns WHERE sale_id = :id LIMIT 1'
            );
            $chk->execute(['id' => $saleId]);
            if ((int) $chk->fetchColumn() > 0) {
                throw HttpException::validation(['sale' => ['This bill has retail sales returns — delete is not allowed.']]);
            }
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable) {
            // Table may not exist on older DBs — ignore.
        }
    }

    /** @return int[] product_binding_id values restored */
    private function reverseSaleStockForEdit(
        PDO $db,
        int $clientId,
        int $branchId,
        int $saleId,
        string $txnDate,
        string $ledgerNote = 'Sale edit — restore stock',
    ): array {
        $st = $db->prepare(
            'SELECT product_binding_id, batch_id, quantity, free_quantity
             FROM sale_items WHERE sale_id = :id'
        );
        $st->execute(['id' => $saleId]);
        $bindings = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $bindingId = (int) ($row['product_binding_id'] ?? 0);
            $batchId = (int) ($row['batch_id'] ?? 0);
            $qty = (int) ($row['quantity'] ?? 0) + max(0, (int) ($row['free_quantity'] ?? 0));
            if ($bindingId <= 0 || $batchId <= 0 || $qty <= 0) {
                continue;
            }
            $db->prepare(
                'UPDATE inventory_batches SET quantity = quantity + :q, updated_at = :now
                 WHERE id = :id AND client_id = :c'
            )->execute(['q' => $qty, 'now' => now(), 'id' => $batchId, 'c' => $clientId]);
            // stock_ledger.txn_type ENUM has no sale_reversal — use adjustment + note (Retail editsales parity).
            $this->inventory->ledger(
                $clientId,
                $branchId,
                $bindingId,
                $batchId,
                'adjustment',
                $qty,
                0,
                $txnDate,
                'sale',
                $saleId,
                $ledgerNote
            );
            $this->inventory->bumpAggregate($clientId, $branchId, $bindingId, $qty);
            $bindings[] = $bindingId;
        }

        return array_values(array_unique($bindings));
    }

    /**
     * Update payment mode only (Retail updateRetailPaymentMode / ).
     * Recalculates paid / balance so customer dues and reports stay in sync.
     *
     * @return array{id:int,payment_mode:string,paid:float,balance:float,account_id:int|null,net_amount:float}
     */
    public function updatePaymentMode(int $clientId, int $saleId, string $paymentMode, ?int $accountId = null): array
    {
        $db = Connection::ops();
        $stmt = $db->prepare(
            'SELECT id, client_id, payment_mode, paid, balance, net_amount, account_id
             FROM sales WHERE id = :id AND client_id = :c LIMIT 1'
        );
        $stmt->execute(['id' => $saleId, 'c' => $clientId]);
        $sale = $stmt->fetch();
        if (!$sale) {
            throw HttpException::notFound('Sale not found.');
        }

        $mode = strtolower(trim($paymentMode));
        $allowed = ['cash', 'card', 'upi', 'credit', 'cash+upi'];
        if (!in_array($mode, $allowed, true)) {
            throw HttpException::validation(['payment_mode' => ['Use Cash, Card, UPI, Credit, or Cash+UPI.']]);
        }

        $net = round((float) ($sale['net_amount'] ?? 0), 2);
        $splitCash = 0.0;
        $splitUpi = 0.0;
        if ($mode === 'cash+upi') {
            if (!self::hasSplitPaymentColumns($db)) {
                throw HttpException::validation(['payment_mode' => ['Cash+UPI is not available yet — database update pending.']]);
            }
            $splitCash = round((float) ($cashAmount ?? 0), 2);
            if ($splitCash <= 0 || $splitCash >= $net) {
                throw HttpException::validation(['cash_amount' => ['Cash amount must be more than 0 and less than the bill net amount.']]);
            }
            $splitUpi = round($net - $splitCash, 2);
        }
        $oldMode = strtolower(trim((string) ($sale['payment_mode'] ?? 'cash')));
        $existingPaid = round((float) ($sale['paid'] ?? 0), 2);

        if ($mode === 'credit') {
            if ($oldMode === 'credit') {
                // Keep partial collections already received on a credit bill.
                $paid = max(0.0, min($net, $existingPaid));
                $balance = round($net - $paid, 2);
                if ($balance < 0.01) {
                    $paid = $net;
                    $balance = 0.0;
                }
            } else {
                $paid = 0.0;
                $balance = $net;
            }
        } else {
            $paid = $net;
            $balance = 0.0;
        }

        $acc = $mode === 'card' && $accountId !== null && $accountId > 0 ? $accountId : null;
        if ($mode === 'card' && $acc === null) {
            throw HttpException::validation(['account_id' => ['Select a bank / card account for Card payments.']]);
        }

        $upd = $db->prepare(
            'UPDATE sales SET payment_mode = :m, account_id = :a, paid = :p, balance = :b WHERE id = :id AND client_id = :c'
        );
        $upd->execute([
            'm' => $mode,
            'a' => $acc,
            'p' => $paid,
            'b' => $balance,
            'id' => $saleId,
            'c' => $clientId,
        ]);
        if (self::hasSplitPaymentColumns($db)) {
            $db->prepare('UPDATE sales SET cash_amount = :cash, upi_amount = :upi WHERE id = :id AND client_id = :c')
                ->execute(['cash' => $splitCash, 'upi' => $splitUpi, 'id' => $saleId, 'c' => $clientId]);
        }

        return [
            'id' => $saleId,
            'payment_mode' => $mode,
            'cash_amount' => $splitCash,
            'upi_amount' => $splitUpi,
            'paid' => $paid,
            'balance' => $balance,
            'account_id' => $acc,
            'net_amount' => $net,
        ];
    }

    /** True when database/43_sales_round_off.sql has been applied. */
    public static function hasRoundOffColumn(\PDO $db): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        try {
            $stmt = $db->query("SHOW COLUMNS FROM sales LIKE 'round_off'");
            $cached = $stmt !== false && $stmt->fetch() !== false;
        } catch (\Throwable) {
            $cached = false;
        }
        return $cached;
    }

    /** True when database/42_sales_split_payment.sql has been applied. */
    public static function hasSplitPaymentColumns(\PDO $db): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        try {
            $stmt = $db->query("SHOW COLUMNS FROM sales LIKE 'upi_amount'");
            $cached = $stmt !== false && $stmt->fetch() !== false;
        } catch (\Throwable) {
            $cached = false;
        }
        return $cached;
    }

    /** True when database/28_sale_items_wholesale_fields.sql has been applied. */
    private function saleItemsHasWholesaleColumns(\PDO $db): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        try {
            $stmt = $db->query("SHOW COLUMNS FROM sale_items LIKE 'free_quantity'");
            $cached = $stmt !== false && $stmt->fetch() !== false;
        } catch (\Throwable) {
            $cached = false;
        }
        return $cached;
    }
}
