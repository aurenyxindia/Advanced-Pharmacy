<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Database\Connection;
use App\Models\ProductBinding;
use App\Support\PackSize;
use App\Support\ProductName;
use PDO;

/**
 * Creates a purchase invoice: resolves/binds products, computes net-rate,
 * scheme, discount and GST figures, and posts batch-wise stock.
 *
 * Per-line maths:
 *   taxable   = rate * qty * (1 - disc% / 100)
 *   gst       = taxable * gst% / 100
 *   lineTotal = taxable + gst
 *   landed cost/unit = taxable / ((qty + freeQty) * units_per_pack)
 *   (scheme free packs also convert to base units — same as paid packs)
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

    /**
     * Delete a purchase bill and reverse the stock it posted.
     * Blocked when any batch no longer has enough qty (already sold / adjusted).
     */
    public function delete(int $clientId, int $branchId, int $purchaseId): void
    {
        Connection::transaction('ops', function (PDO $db) use ($clientId, $branchId, $purchaseId) {
            $this->assertPurchase($db, $clientId, $branchId, $purchaseId);
            $reversedBindings = $this->reversePurchaseStock($db, $clientId, $branchId, $purchaseId, 'delete');
            $this->inventory->reconcileAggregates($clientId, $branchId, $reversedBindings);
            $db->prepare('DELETE FROM purchase_items WHERE purchase_id = :id')->execute(['id' => $purchaseId]);
            $db->prepare('DELETE FROM purchases WHERE id = :id')->execute(['id' => $purchaseId]);
        });
    }

    /**
     * Update purchase header + lines; reverses old stock then re-posts recalculated lines.
     *
     * @param array{user_id?: int|null, user_name?: string, user_role?: string|null, ip_address?: string, user_agent?: string|null} $audit
     */
    public function update(int $clientId, int $branchId, int $purchaseId, array $data, bool $bypassSoldCheck = false, array $audit = []): array
    {
        $items = $data['items'] ?? [];
        if (!is_array($items) || $items === []) {
            throw HttpException::validation(['items' => ['At least one purchase line is required.']]);
        }
        // DDL commits implicitly in MySQL — must run before the transaction opens.
        $this->ensureEditAuditSchema();

        return Connection::transaction('ops', function (PDO $db) use ($clientId, $branchId, $purchaseId, $data, $items, $bypassSoldCheck, $audit) {
            $existing = $this->assertPurchase($db, $clientId, $branchId, $purchaseId);
            $beforeLines = $this->snapshotEditLines($db, $clientId, $branchId, $purchaseId);

            // Compute new lines first, then apply stock as exact unit deltas
            // (stock_after = stock_before + new_units - old_units). Reverse+re-post
            // with clamped reduceBatch was wiping pack-size corrections after stock adj.
            [$totals, $computedItems] = $this->computeItems($clientId, $branchId, $items, $data);
            $totals = $this->preserveCreditPaymentOnEdit($existing, $totals);
            $invoiceNo = trim((string) ($data['invoice_no'] ?? $existing['invoice_no'] ?? ''));
            $this->assertUniqueInvoice($db, $clientId, $invoiceNo, $purchaseId);

            $invoiceDate = (string) ($data['invoice_date'] ?? ($existing['invoice_date'] ?? date('Y-m-d')));
            $stockBindings = $this->applyPurchaseEditStockDeltas(
                $db,
                $clientId,
                $branchId,
                $purchaseId,
                $beforeLines,
                $computedItems,
                $bypassSoldCheck,
                !empty($data['confirm_sold_edit']),
                $invoiceDate
            );

            $db->prepare('DELETE FROM purchase_items WHERE purchase_id = :id')->execute(['id' => $purchaseId]);
            $this->insertItemsAndStock(
                $db,
                $clientId,
                $branchId,
                $purchaseId,
                $computedItems,
                $invoiceDate,
                false // stock already applied via deltas
            );
            $this->inventory->reconcileAggregates($clientId, $branchId, array_merge(
                $stockBindings,
                array_column($computedItems, 'binding_id')
            ));

            $supplierId = $this->resolveSupplier($db, $clientId, $data);
            $supplierName = trim((string) ($data['supplier_name'] ?? $existing['supplier_name'] ?? ''));
            if ($supplierName === '' && $supplierId !== null) {
                $supplierName = (string) ($existing['supplier_name'] ?? '');
            }

            $upd = $db->prepare(
                'UPDATE purchases SET
                    supplier_id = :supplier_id,
                    supplier_name = :supplier_name,
                    invoice_no = :invoice_no,
                    invoice_date = :invoice_date,
                    cash_credit = :cash_credit,
                    due_date = :due_date,
                    cd_mode = :cd_mode,
                    cd_pct = :cd_pct,
                    cd_amount = :cd_amount,
                    gross_amount = :gross,
                    scheme_amount = :scheme,
                    discount_amount = :discount,
                    gst_amount = :gst,
                    net_amount = :net,
                    transport_charge = :transport_charge,
                    transport_gst_pct = :transport_gst_pct,
                    transport_charge_amt = :transport_charge_amt,
                    debit_note_no = :debit_note_no,
                    debit_deduction_amt = :debit_deduction_amt,
                    bill_net_before_debit = :bill_net_before_debit,
                    paid = :paid,
                    balance = :balance,
                    payment_status = :payment_status
                 WHERE id = :id'
            );
            $upd->execute([
                'id'                     => $purchaseId,
                'supplier_id'            => $supplierId ?? $existing['supplier_id'],
                'supplier_name'          => $supplierName !== '' ? $supplierName : $existing['supplier_name'],
                'invoice_no'             => $data['invoice_no'] ?? $existing['invoice_no'],
                'invoice_date'           => $data['invoice_date'] ?? $existing['invoice_date'],
                'cash_credit'            => $totals['cash_credit'],
                'due_date'               => $totals['due_date'],
                'cd_mode'                => $totals['cd_mode'],
                'cd_pct'                 => $totals['cd_pct'],
                'cd_amount'              => $totals['cd_amount'],
                'gross'                  => $totals['gross'],
                'scheme'                 => $totals['scheme'],
                'discount'               => $totals['discount'],
                'gst'                    => $totals['gst'],
                'net'                    => $totals['net'],
                'transport_charge'       => $totals['transport_charge'],
                'transport_gst_pct'      => $totals['transport_gst_pct'],
                'transport_charge_amt'   => $totals['transport_charge_amt'],
                'debit_note_no'          => $totals['debit_note_no'],
                'debit_deduction_amt'    => $totals['debit_deduction_amt'],
                'bill_net_before_debit'  => $totals['bill_net_before_debit'],
                'paid'                   => $totals['paid'],
                'balance'                => $totals['balance'],
                'payment_status'         => $totals['payment_status'],
            ]);

            $this->writeEditAudit(
                $db,
                $clientId,
                $branchId,
                $purchaseId,
                [
                    'po_no'         => (string) ($existing['po_no'] ?? ''),
                    'invoice_no'    => $invoiceNo,
                    'supplier_name' => $supplierName !== '' ? $supplierName : (string) ($existing['supplier_name'] ?? ''),
                ],
                $beforeLines,
                $computedItems,
                $bypassSoldCheck,
                $audit
            );

            $debitNo = trim((string) ($totals['debit_note_no'] ?? ''));
            $prevDebit = trim((string) ($existing['debit_note_no'] ?? ''));
            if ($debitNo !== '' && $debitNo !== $prevDebit) {
                (new ReturnNoteService())->applyToPurchase(
                    $clientId,
                    $branchId,
                    $debitNo,
                    (string) ($existing['po_no'] ?? ''),
                    isset($totals['debit_deduction_amt']) ? (float) $totals['debit_deduction_amt'] : null
                );
            }

            return [
                'id'         => $purchaseId,
                'po_no'      => $existing['po_no'] ?? null,
                'net_amount' => $totals['net'],
                'gst_amount' => $totals['gst'],
                'items'      => count($computedItems),
            ];
        });
    }

    public function create(int $clientId, int $branchId, ?int $userId, array $data): array
    {
        $items = $data['items'] ?? [];
        if (!is_array($items) || $items === []) {
            throw HttpException::validation(['items' => ['At least one purchase line is required.']]);
        }

        return Connection::transaction('ops', function (PDO $db) use ($clientId, $branchId, $userId, $data, $items) {
            $invoiceNo = trim((string) ($data['invoice_no'] ?? ''));
            if ($invoiceNo === '') {
                throw HttpException::validation(['invoice_no' => ['Invoice number is required.']]);
            }
            $this->assertUniqueInvoice($db, $clientId, $invoiceNo);

            [$totals, $computedItems] = $this->computeItems($clientId, $branchId, $items, $data);

            $supplierId = $this->resolveSupplier($db, $clientId, $data);
            if ($supplierId === null && trim((string) ($data['supplier_name'] ?? '')) === '') {
                throw HttpException::validation(['supplier_name' => ['Distributor is required.']]);
            }
            [$poNo, $poSeq] = $this->nextPoNo($db, $clientId);

            $stmt = $db->prepare(
                'INSERT INTO purchases
                    (client_id, branch_id, po_no, po_seq, supplier_id, supplier_name, invoice_no, invoice_date,
                     cash_credit, due_date, cd_mode, cd_pct, cd_amount,
                     gross_amount, scheme_amount, discount_amount, gst_amount, net_amount,
                     transport_charge, transport_gst_pct, transport_charge_amt,
                     debit_note_no, debit_deduction_amt, bill_net_before_debit,
                     paid, balance, payment_status, created_by, created_at)
                 VALUES
                    (:client_id, :branch_id, :po_no, :po_seq, :supplier_id, :supplier_name, :invoice_no, :invoice_date,
                     :cash_credit, :due_date, :cd_mode, :cd_pct, :cd_amount,
                     :gross, :scheme, :discount, :gst, :net,
                     :transport_charge, :transport_gst_pct, :transport_charge_amt,
                     :debit_note_no, :debit_deduction_amt, :bill_net_before_debit,
                     :paid, :balance, :payment_status, :created_by, :created_at)'
            );
            $stmt->execute([
                'client_id'              => $clientId,
                'branch_id'              => $branchId,
                'po_no'                  => $poNo,
                'po_seq'                 => $poSeq,
                'supplier_id'            => $supplierId,
                'supplier_name'          => $data['supplier_name'] ?? null,
                'invoice_no'             => $data['invoice_no'] ?? ('PUR-' . time()),
                'invoice_date'           => $data['invoice_date'] ?? date('Y-m-d'),
                'cash_credit'            => $totals['cash_credit'],
                'due_date'               => $totals['due_date'],
                'cd_mode'                => $totals['cd_mode'],
                'cd_pct'                 => $totals['cd_pct'],
                'cd_amount'              => $totals['cd_amount'],
                'gross'                  => $totals['gross'],
                'scheme'                 => $totals['scheme'],
                'discount'               => $totals['discount'],
                'gst'                    => $totals['gst'],
                'net'                    => $totals['net'],
                'transport_charge'       => $totals['transport_charge'],
                'transport_gst_pct'      => $totals['transport_gst_pct'],
                'transport_charge_amt'   => $totals['transport_charge_amt'],
                'debit_note_no'          => $totals['debit_note_no'],
                'debit_deduction_amt'    => $totals['debit_deduction_amt'],
                'bill_net_before_debit'  => $totals['bill_net_before_debit'],
                'paid'                   => $totals['paid'],
                'balance'                => $totals['balance'],
                'payment_status'         => $totals['payment_status'],
                'created_by'             => $userId,
                'created_at'             => now(),
            ]);
            $purchaseId = (int) $db->lastInsertId();

            $this->insertItemsAndStock(
                $db,
                $clientId,
                $branchId,
                $purchaseId,
                $computedItems,
                $data['invoice_date'] ?? date('Y-m-d')
            );
            $this->inventory->reconcileAggregates($clientId, $branchId, array_column($computedItems, 'binding_id'));

            $debitNo = trim((string) ($totals['debit_note_no'] ?? ''));
            if ($debitNo !== '') {
                (new ReturnNoteService())->applyToPurchase(
                    $clientId,
                    $branchId,
                    $debitNo,
                    $poNo,
                    isset($totals['debit_deduction_amt']) ? (float) $totals['debit_deduction_amt'] : null
                );
            }

            return [
                'id'         => $purchaseId,
                'po_no'      => $poNo,
                'net_amount' => $totals['net'],
                'gst_amount' => $totals['gst'],
                'items'      => count($computedItems),
            ];
        });
    }

    /** @return array{gross: float, scheme: float, discount: float, gst: float, net: float} */
    private function computeItems(int $clientId, int $branchId, array $items, array $data): array
    {
        $gross = 0.0;
        $scheme = 0.0;
        $lineDiscount = 0.0;
        $gstTotal = 0.0;
        $itemsTotal = 0.0;
        $computedItems = [];

        foreach ($items as $i => $item) {
            $bindingId = $this->resolveBinding($clientId, $branchId, $item);

            $qty     = (int) ($item['quantity'] ?? 0);
            $free    = (int) ($item['free_quantity'] ?? 0);
            $rate    = (float) ($item['purchase_rate'] ?? 0);
            $mrp     = (float) ($item['mrp'] ?? 0);
            $discPct = (float) ($item['discount_pct'] ?? 0);
            $gstRate = (float) ($item['gst_rate'] ?? 0);

            $packRaw = trim((string) ($item['pack_size'] ?? $item['pack'] ?? ''));
            if ($packRaw === '') {
                throw HttpException::validation(["items.$i.pack_size" => ['Pack size is required.']]);
            }
            // Pack label sets default units; explicit units_per_pack from the form wins
            // (purchase-edit pack / UPP correction must move stock by the value staff entered).
            $parsed = PackSize::parse($packRaw);
            $parsedUpp = max(1, (int) ($parsed['units_per_pack'] ?? 1));
            $clientUpp = (int) ($item['units_per_pack'] ?? 0);
            $upp = $clientUpp > 0 ? $clientUpp : $parsedUpp;
            $packSize = (string) ($parsed['pack_size'] ?: $packRaw);

            if ($qty <= 0 || $rate < 0) {
                throw HttpException::validation(["items.$i" => ['Invalid quantity or rate.']]);
            }
            $batch = trim((string) ($item['batch_no'] ?? ''));
            if ($batch === '') {
                throw HttpException::validation(["items.$i.batch_no" => ['Batch number is required.']]);
            }
            $expiryRaw = trim((string) ($item['expiry_date'] ?? ''));
            if ($expiryRaw === '') {
                throw HttpException::validation(["items.$i.expiry_date" => ['Expiry date is required.']]);
            }
            if ($mrp <= 0) {
                throw HttpException::validation(["items.$i.mrp" => ['MRP must be greater than 0.']]);
            }
            if ($rate <= 0) {
                throw HttpException::validation(["items.$i.purchase_rate" => ['Purchase rate must be greater than 0.']]);
            }

            $lineGross = $rate * $qty;
            $discAmt   = (float) ($item['discount_amt'] ?? 0);
            if ($discAmt > 0 && $discPct <= 0 && $lineGross > 0) {
                $discPct = round(($discAmt * 100) / $lineGross, 4);
            } elseif ($discPct > 0) {
                $discAmt = round($lineGross * $discPct / 100, 2);
            } else {
                $discAmt = 0.0;
            }
            $taxable   = $lineGross - $discAmt;
            $gstAmt    = $taxable * $gstRate / 100;
            $lineTotal = $taxable + $gstAmt;
            // Retail: free qty is packs (or bottles), same as billed qty — both × units_per_pack.
            // e.g. qty 10 + free 2, pack 1x10 → (10+2)*10 = 120 units (not 100+2).
            $baseUnits = ($qty + $free) * $upp;
            $netRateExcl = $baseUnits > 0 ? ($lineGross - $discAmt) / $baseUnits : 0;
            $unitMrp = $upp > 0 ? $mrp / $upp : $mrp;

            $gross        += $lineGross;
            $scheme       += $free * $rate;
            $lineDiscount += $discAmt;
            $gstTotal     += $gstAmt;
            $itemsTotal   += $lineTotal;

            $computedItems[] = [
                'binding_id'      => $bindingId,
                'product_name'    => $item['product_name'] ?? '',
                'hsn_code'        => $item['hsn_code'] ?? null,
                'batch_no'        => $item['batch_no'] ?? null,
                'expiry_date'     => $this->normalizeExpiry($item['expiry_date'] ?? null),
                'shelf'           => $item['shelf_location'] ?? null,
                'quantity'        => $qty,
                'free_quantity'   => $free,
                'units_per_pack'  => $upp,
                'pack_size'       => $packSize,
                'base_units'      => $baseUnits,
                'purchase_rate'   => $rate,
                'mrp'             => $mrp,
                'unit_mrp'        => round($unitMrp, 2),
                'ptr'             => (float) ($item['ptr'] ?? 0),
                'pts'             => (float) ($item['pts'] ?? 0),
                'scheme_pct'      => (float) ($item['scheme_pct'] ?? 0),
                'discount_pct'    => $discPct,
                'gst_rate'        => $gstRate,
                'net_rate'        => round($netRateExcl, 4),
                'line_total'      => round($lineTotal, 2),
                'unit_cost'       => round($netRateExcl, 4),
            ];
        }

        $billDiscount = (float) ($data['bill_discount_amount'] ?? 0);
        if (!empty($data['bill_discount_pct'])) {
            $billDiscount += $itemsTotal * (float) $data['bill_discount_pct'] / 100;
        }

        // Cash discount (CD) — Retail Total Bill CD % or Amount.
        $cdMode = trim((string) ($data['cd_mode'] ?? $data['total_bill_cd'] ?? 'Percentage'));
        if ($cdMode === '') {
            $cdMode = 'Percentage';
        }
        $cdPct = (float) ($data['cd_pct'] ?? $data['cdPer'] ?? 0);
        $cdAmount = (float) ($data['cd_amount'] ?? $data['cdAmt'] ?? 0);
        if (strcasecmp($cdMode, 'Amount') === 0) {
            $cdAmount = max(0, $cdAmount);
            $cdPct = $itemsTotal > 0 ? round(($cdAmount / $itemsTotal) * 100, 2) : 0;
        } else {
            $cdMode = 'Percentage';
            $cdAmount = round($itemsTotal * $cdPct / 100, 2);
        }

        $transportCharge = (float) ($data['transport_charge'] ?? $data['transportation_charge'] ?? 0);
        $transportGstPct = (float) ($data['transport_gst_pct'] ?? $data['transportation_gst_per'] ?? 0);
        $transportAmt = round($transportCharge * (1 + $transportGstPct / 100), 2);

        $billNet = max(0, $itemsTotal - $billDiscount - $cdAmount) + $transportAmt;
        $debitNoteNo = trim((string) ($data['debit_note_no'] ?? ''));
        $debitAmt = (float) ($data['debit_deduction_amt'] ?? 0);
        if ($debitNoteNo === '') {
            $debitAmt = 0;
        }
        $netAmount = max(0, $billNet - $debitAmt);

        $cashCredit = trim((string) ($data['cash_credit'] ?? 'Credit'));
        if (!in_array($cashCredit, ['Cash', 'UPI', 'Cheque', 'Credit'], true)) {
            $cashCredit = 'Credit';
        }
        $dueDate = trim((string) ($data['due_date'] ?? ''));
        if ($cashCredit !== 'Credit') {
            $dueDate = $data['invoice_date'] ?? date('Y-m-d');
        } elseif ($dueDate === '') {
            $dueDate = date('Y-m-d', strtotime('+45 days'));
        }
        if ($cashCredit === 'Credit') {
            $paid = 0.0;
            $balance = $netAmount;
            $paymentStatus = 'pending';
        } else {
            $paid = $netAmount;
            $balance = 0.0;
            $paymentStatus = 'paid';
        }

        $totals = [
            'gross'                 => round($gross, 2),
            'scheme'                => round($scheme, 2),
            'discount'              => round($lineDiscount + $billDiscount + $cdAmount, 2),
            'gst'                   => round($gstTotal, 2),
            'net'                   => round($netAmount, 2),
            'cash_credit'           => $cashCredit,
            'due_date'              => $dueDate ?: null,
            'cd_mode'               => $cdMode,
            'cd_pct'                => round($cdPct, 2),
            'cd_amount'             => round($cdAmount, 2),
            'transport_charge'      => round($transportCharge, 2),
            'transport_gst_pct'     => round($transportGstPct, 2),
            'transport_charge_amt'  => $transportAmt,
            'debit_note_no'         => $debitNoteNo !== '' ? $debitNoteNo : null,
            'debit_deduction_amt'   => round($debitAmt, 2),
            'bill_net_before_debit' => round($billNet, 2),
            'paid'                  => round($paid, 2),
            'balance'               => round($balance, 2),
            'payment_status'        => $paymentStatus,
        ];

        return [$totals, $computedItems];
    }

    private function insertItemsAndStock(
        PDO $db,
        int $clientId,
        int $branchId,
        int $purchaseId,
        array $computedItems,
        string $invoiceDate,
        bool $postStock = true
    ): void {
        $itemStmt = $db->prepare(
            'INSERT INTO purchase_items
                (purchase_id, product_binding_id, product_name, hsn_code, batch_no, expiry_date, shelf_location, quantity,
                 units_per_pack, free_quantity, purchase_rate, mrp, ptr, pts, scheme_pct, discount_pct, gst_rate, net_rate, line_total)
             VALUES
                (:purchase_id, :binding_id, :product_name, :hsn, :batch_no, :expiry_date, :shelf, :quantity,
                 :upp, :free_quantity, :purchase_rate, :mrp, :ptr, :pts, :scheme, :discount_pct, :gst_rate, :net_rate, :line_total)'
        );
        $bindUpd = $db->prepare(
            'UPDATE product_bindings
             SET ptr = IF(:ptr_chk > 0, :ptr_val, ptr),
                 pts = IF(:pts_chk > 0, :pts_val, pts),
                 updated_at = :now
             WHERE id = :id'
        );
        $bindMeta = $db->prepare(
            'UPDATE product_bindings
             SET pack_size = :pack,
                 units_per_pack = :upp,
                 mrp = IF(:mrp_chk > 0, :mrp_val, mrp),
                 hsn_code = COALESCE(NULLIF(:hsn, ""), hsn_code),
                 shelf_location = COALESCE(NULLIF(:shelf, ""), shelf_location),
                 gst_rate = :gst,
                 updated_at = :now
             WHERE id = :id AND client_id = :c'
        );

        foreach ($computedItems as $ci) {
            $itemStmt->execute([
                'purchase_id'   => $purchaseId,
                'binding_id'    => $ci['binding_id'],
                'product_name'  => $ci['product_name'],
                'hsn'           => $ci['hsn_code'],
                'batch_no'      => $ci['batch_no'],
                'expiry_date'   => $ci['expiry_date'] ?: null,
                'shelf'         => $ci['shelf'],
                'quantity'      => $ci['quantity'],
                'upp'           => $ci['units_per_pack'],
                'free_quantity' => $ci['free_quantity'],
                'purchase_rate' => $ci['purchase_rate'],
                'mrp'           => $ci['mrp'],
                'ptr'           => $ci['ptr'],
                'pts'           => $ci['pts'],
                'scheme'        => $ci['scheme_pct'],
                'discount_pct'  => $ci['discount_pct'],
                'gst_rate'      => $ci['gst_rate'],
                'net_rate'      => $ci['net_rate'],
                'line_total'    => $ci['line_total'],
            ]);

            if ($postStock) {
            $this->inventory->addStock(
                $clientId,
                $branchId,
                $ci['binding_id'],
                (string) ($ci['batch_no'] ?: 'NA'),
                $ci['expiry_date'] ?: null,
                (float) $ci['unit_mrp'],
                (float) $ci['unit_cost'],
                $ci['base_units'],
                $ci['shelf'],
                (float) $ci['ptr'],
                (float) $ci['pts'],
                'purchase',
                $invoiceDate,
                'purchase',
                $purchaseId
            );
            }

            // Keep binding + batch metadata in sync so sales billing picks up edits.
            $bindMeta->execute([
                'pack'     => $ci['pack_size'],
                'upp'      => $ci['units_per_pack'],
                'mrp_chk'  => (float) $ci['mrp'],
                'mrp_val'  => (float) $ci['mrp'],
                'hsn'      => (string) ($ci['hsn_code'] ?? ''),
                'shelf'    => (string) ($ci['shelf'] ?? ''),
                'gst'      => (float) $ci['gst_rate'],
                'now'      => now(),
                'id'       => $ci['binding_id'],
                'c'        => $clientId,
            ]);

            if ($ci['ptr'] > 0 || $ci['pts'] > 0) {
                $bindUpd->execute([
                    'ptr_chk' => (float) $ci['ptr'],
                    'ptr_val' => (float) $ci['ptr'],
                    'pts_chk' => (float) $ci['pts'],
                    'pts_val' => (float) $ci['pts'],
                    'now'     => now(),
                    'id'      => $ci['binding_id'],
                ]);
            }
        }
    }

    /**
     * Purchase edit stock: apply exact base-unit deltas per product+batch.
     * stock_after = stock_before + (new_units - old_units)
     * so pack-size / UPP edits stay correct after sales or physical stock adjustments.
     *
     * @param array<string, array<string, mixed>> $beforeLines
     * @param list<array<string, mixed>> $computedItems
     * @return list<int> binding ids touched
     */
    private function applyPurchaseEditStockDeltas(
        PDO $db,
        int $clientId,
        int $branchId,
        int $purchaseId,
        array $beforeLines,
        array $computedItems,
        bool $bypassSoldCheck,
        bool $soldEditConfirmed,
        string $invoiceDate
    ): array {
        $newLines = [];
        foreach ($computedItems as $ci) {
            $key = self::editLineKey((int) $ci['binding_id'], $ci['batch_no'] ?? null);
            if (!isset($newLines[$key])) {
                $newLines[$key] = [
                    'binding_id'   => (int) $ci['binding_id'],
                    'product_name' => (string) ($ci['product_name'] ?? ''),
                    'batch_no'     => (string) ($ci['batch_no'] ?: 'NA'),
                    'units'        => 0,
                    'ci'           => $ci,
                ];
            }
            $newLines[$key]['units'] += (int) $ci['base_units'];
            $newLines[$key]['ci'] = $ci;
        }

        $keys = array_unique(array_merge(array_keys($beforeLines), array_keys($newLines)));
        $soldLines = [];
        $blocked = [];
        $plan = [];

        foreach ($keys as $key) {
            $o = $beforeLines[$key] ?? null;
            $n = $newLines[$key] ?? null;
            $oldUnits = (int) ($o['units'] ?? 0);
            $newUnits = (int) ($n['units'] ?? 0);
            $delta = $newUnits - $oldUnits;
            if ($delta === 0) {
                continue;
            }

            $name = (string) (($n['product_name'] ?? null) ?: ($o['product_name'] ?? 'Product'));
            $batchNo = (string) (($n['batch_no'] ?? null) ?: ($o['batch_no'] ?? 'NA'));
            $batchId = $o['batch_id'] ?? null;
            $stockBefore = (int) ($o['stock'] ?? 0);

            // New line / new batch — full add via addStock later.
            if ($batchId === null && $newUnits > 0 && $oldUnits === 0) {
                $plan[] = [
                    'type' => 'add',
                    'ci'   => $n['ci'],
                    'binding_id' => (int) $n['binding_id'],
                ];
                continue;
            }

            if ($batchId === null || (int) $batchId <= 0) {
                $blocked[] = "{$name} (batch {$batchNo}): no inventory batch to adjust";
                continue;
            }

            $projected = $stockBefore + $delta;
            if ($projected < 0) {
                $soldLines[] = [
                    'product_name' => $name,
                    'batch_no'     => $batchNo,
                    'purchased'    => $oldUnits,
                    'in_stock'     => max(0, $stockBefore),
                    'sold'         => max(0, $oldUnits - max(0, $stockBefore)),
                    'new_units'    => $newUnits,
                ];
                $blocked[] = "{$name} (batch {$batchNo}): pack/qty edit needs {$newUnits} units but only {$stockBefore} on hand after prior sales/adjust (was {$oldUnits} from this bill)";
            }

            $plan[] = [
                'type'       => 'delta',
                'batch_id'   => (int) $batchId,
                'binding_id' => (int) (($n['binding_id'] ?? null) ?: ($o['binding_id'] ?? 0)),
                'delta'      => $delta,
                'name'       => $name,
                'batch_no'   => $batchNo,
                'ci'         => $n['ci'] ?? null,
            ];
        }

        if ($bypassSoldCheck && $soldLines !== [] && !$soldEditConfirmed) {
            throw new HttpException('Stock from this purchase was already sold or adjusted.', 409, [
                'sold_edit_confirm' => true,
                'sold_lines' => $soldLines,
            ]);
        }
        if (!$bypassSoldCheck && $blocked !== []) {
            throw HttpException::validation([
                'purchase' => [
                    "Cannot update — stock from this purchase was already sold or adjusted.\n" . implode("\n", $blocked),
                ],
                'sold_lines' => $soldLines,
            ]);
        }

        $bindings = [];
        foreach ($plan as $step) {
            $bindingId = (int) ($step['binding_id'] ?? 0);
            if ($bindingId > 0) {
                $bindings[] = $bindingId;
            }
            if ($step['type'] === 'add') {
                $ci = $step['ci'];
                $this->inventory->addStock(
                    $clientId,
                    $branchId,
                    (int) $ci['binding_id'],
                    (string) ($ci['batch_no'] ?: 'NA'),
                    $ci['expiry_date'] ?: null,
                    (float) $ci['unit_mrp'],
                    (float) $ci['unit_cost'],
                    (int) $ci['base_units'],
                    $ci['shelf'],
                    (float) $ci['ptr'],
                    (float) $ci['pts'],
                    'purchase',
                    $invoiceDate,
                    'purchase',
                    $purchaseId
                );
                continue;
            }

            $delta = (int) $step['delta'];
            $note = 'Purchase edit — pack/qty units delta';
            try {
                $this->inventory->adjustBatchByDelta(
                    (int) $step['batch_id'],
                    $delta,
                    $delta >= 0 ? 'purchase' : 'purchase_return',
                    $invoiceDate,
                    'purchase',
                    $purchaseId,
                    $note,
                    $bypassSoldCheck && $soldEditConfirmed
                );
            } catch (\RuntimeException $e) {
                throw HttpException::validation([
                    'purchase' => [
                        ($step['name'] ?? 'Product') . ' (batch ' . ($step['batch_no'] ?? '-') . '): ' . $e->getMessage(),
                    ],
                ]);
            }

            // Refresh batch MRP/cost/expiry from the edited line when present.
            $ci = $step['ci'] ?? null;
            if (is_array($ci) && $delta !== 0) {
            $db->prepare(
                    'UPDATE inventory_batches
                     SET mrp = COALESCE(NULLIF(:mrp, 0), mrp),
                         purchase_rate = COALESCE(NULLIF(:rate, 0), purchase_rate),
                         expiry_date = COALESCE(:exp, expiry_date),
                         shelf_location = COALESCE(NULLIF(:shelf, ""), shelf_location),
                         ptr = IF(:ptr > 0, :ptr2, ptr),
                         pts = IF(:pts > 0, :pts2, pts),
                         updated_at = :now
                     WHERE id = :id'
            )->execute([
                    'mrp'  => (float) ($ci['unit_mrp'] ?? 0),
                    'rate' => (float) ($ci['unit_cost'] ?? 0),
                    'exp'  => $ci['expiry_date'] ?: null,
                    'shelf'=> (string) ($ci['shelf'] ?? ''),
                    'ptr'  => (float) ($ci['ptr'] ?? 0),
                    'ptr2' => (float) ($ci['ptr'] ?? 0),
                    'pts'  => (float) ($ci['pts'] ?? 0),
                    'pts2' => (float) ($ci['pts'] ?? 0),
                'now'  => now(),
                    'id'   => (int) $step['batch_id'],
                ]);
            }
        }

        return array_values(array_unique($bindings));
    }

    /**
     * Retail parity: Credit purchases keep partial paid amount on edit; only rebalance.
     *
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $totals
     * @return array<string, mixed>
     */
    private function preserveCreditPaymentOnEdit(array $existing, array $totals): array
    {
        if (($totals['cash_credit'] ?? '') !== 'Credit') {
            return $totals;
        }
        $existingPaid = (float) ($existing['paid'] ?? 0);
        if ($existingPaid <= 0) {
            return $totals;
        }
        $net = (float) ($totals['net'] ?? 0);
        $paid = min($existingPaid, $net);
        $balance = max(0, round($net - $paid, 2));
        $totals['paid'] = round($paid, 2);
        $totals['balance'] = $balance;
        $totals['payment_status'] = $balance <= 0 ? 'paid' : 'partial';
        return $totals;
    }

    /** @return array<string, mixed> */
    private function assertPurchase(PDO $db, int $clientId, int $branchId, int $purchaseId): array
    {
        $stmt = $db->prepare(
            'SELECT * FROM purchases WHERE id = :id AND client_id = :c AND branch_id = :b LIMIT 1'
        );
        $stmt->execute(['id' => $purchaseId, 'c' => $clientId, 'b' => $branchId]);
        $purchase = $stmt->fetch();
        if (!$purchase) {
            throw HttpException::notFound('Purchase not found.');
        }
        return $purchase;
    }

    /**
     * @return int[] product_binding_id values affected (for aggregate stock reconcile)
     */
    private static bool $editAuditReady = false;

    private function ensureEditAuditSchema(): void
    {
        if (self::$editAuditReady) {
            return;
        }
        try {
            Connection::ops()->exec(
                "CREATE TABLE IF NOT EXISTS purchase_edit_audit_log (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    client_id BIGINT UNSIGNED NOT NULL,
                    branch_id BIGINT UNSIGNED NOT NULL,
                    purchase_id BIGINT UNSIGNED NOT NULL,
                    po_no VARCHAR(60) NULL,
                    invoice_no VARCHAR(60) NULL,
                    supplier_name VARCHAR(255) NULL,
                    change_type VARCHAR(20) NOT NULL,
                    product_binding_id BIGINT UNSIGNED NULL,
                    batch_id BIGINT UNSIGNED NULL,
                    product_name VARCHAR(255) NOT NULL,
                    batch_no VARCHAR(60) NULL,
                    old_pack VARCHAR(60) NULL,
                    new_pack VARCHAR(60) NULL,
                    old_qty DECIMAL(12,2) NOT NULL DEFAULT 0,
                    new_qty DECIMAL(12,2) NOT NULL DEFAULT 0,
                    old_free DECIMAL(12,2) NOT NULL DEFAULT 0,
                    new_free DECIMAL(12,2) NOT NULL DEFAULT 0,
                    old_units INT NOT NULL DEFAULT 0,
                    new_units INT NOT NULL DEFAULT 0,
                    old_rate DECIMAL(12,2) NOT NULL DEFAULT 0,
                    new_rate DECIMAL(12,2) NOT NULL DEFAULT 0,
                    old_mrp DECIMAL(12,2) NOT NULL DEFAULT 0,
                    new_mrp DECIMAL(12,2) NOT NULL DEFAULT 0,
                    was_sold TINYINT(1) NOT NULL DEFAULT 0,
                    sold_units INT NOT NULL DEFAULT 0,
                    stock_before INT NOT NULL DEFAULT 0,
                    stock_after INT NOT NULL DEFAULT 0,
                    bypass_used TINYINT(1) NOT NULL DEFAULT 0,
                    user_id BIGINT UNSIGNED NULL,
                    user_name VARCHAR(150) NOT NULL,
                    user_role VARCHAR(40) NULL,
                    ip_address VARCHAR(64) NOT NULL,
                    user_agent VARCHAR(255) NULL,
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    KEY idx_peal_scope (client_id, branch_id, created_at),
                    KEY idx_peal_purchase (purchase_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (\PDOException) {
            // Table may already exist with compatible shape.
        }
        self::$editAuditReady = true;
    }

    private static function editLineKey(int $bindingId, ?string $batchNo): string
    {
        $b = trim((string) $batchNo);
        return $bindingId . '|' . strtoupper($b !== '' ? $b : 'NA');
    }

    /**
     * Purchase lines + live batch stock before an edit, keyed by binding|batch.
     *
     * @return array<string, array<string, mixed>>
     */
    private function snapshotEditLines(PDO $db, int $clientId, int $branchId, int $purchaseId): array
    {
        $stmt = $db->prepare(
            'SELECT pi.product_binding_id, pi.product_name, pi.batch_no, pi.quantity, pi.free_quantity,
                    pi.units_per_pack, pi.purchase_rate, pi.mrp, pb.pack_size,
                    ib.id AS batch_id, ib.quantity AS batch_qty
             FROM purchase_items pi
             LEFT JOIN product_bindings pb ON pb.id = pi.product_binding_id
             LEFT JOIN inventory_batches ib
               ON ib.client_id = :c AND ib.branch_id = :b
              AND ib.product_binding_id = pi.product_binding_id
              AND ib.batch_no = COALESCE(NULLIF(pi.batch_no, ""), "NA")
             WHERE pi.purchase_id = :pid
             ORDER BY pi.id'
        );
        $stmt->execute(['c' => $clientId, 'b' => $branchId, 'pid' => $purchaseId]);

        // Units this purchase actually posted per batch (ledger net, same basis as the sold check).
        $ledger = $db->prepare(
            'SELECT batch_id, SUM(qty_in) - SUM(qty_out) AS net_qty
             FROM stock_ledger
             WHERE client_id = :c AND branch_id = :b AND ref_type = "purchase" AND ref_id = :rid AND batch_id IS NOT NULL
             GROUP BY batch_id'
        );
        $ledger->execute(['c' => $clientId, 'b' => $branchId, 'rid' => $purchaseId]);
        $posted = [];
        foreach ($ledger->fetchAll() as $l) {
            $posted[(int) $l['batch_id']] = max(0, (int) $l['net_qty']);
        }

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $key = self::editLineKey((int) $r['product_binding_id'], $r['batch_no'] ?? null);
            $upp = max(1, (int) ($r['units_per_pack'] ?? 1));
            $units = ((float) $r['quantity'] + (float) ($r['free_quantity'] ?? 0)) * $upp;
            if (!isset($out[$key])) {
                $out[$key] = [
                    'binding_id'   => (int) $r['product_binding_id'],
                    'product_name' => (string) $r['product_name'],
                    'batch_no'     => (string) ($r['batch_no'] ?? ''),
                    'pack'         => $r['pack_size'] !== null ? (string) $r['pack_size'] : null,
                    'qty'          => 0.0,
                    'free'         => 0.0,
                    'units'        => 0,
                    'rate'         => (float) $r['purchase_rate'],
                    'mrp'          => (float) $r['mrp'],
                    'batch_id'     => $r['batch_id'] !== null ? (int) $r['batch_id'] : null,
                    'stock'        => (int) ($r['batch_qty'] ?? 0),
                ];
            }
            $out[$key]['qty'] += (float) $r['quantity'];
            $out[$key]['free'] += (float) ($r['free_quantity'] ?? 0);
            $out[$key]['units'] += (int) round($units);
        }
        foreach ($out as &$line) {
            $postedUnits = $line['batch_id'] !== null ? ($posted[$line['batch_id']] ?? $line['units']) : $line['units'];
            $line['units'] = $postedUnits;
            $line['sold'] = max(0, $postedUnits - max(0, (int) $line['stock']));
        }
        unset($line);
        return $out;
    }

    /**
     * One audit row per line (product + batch): before vs after, sold at edit time, user + IP.
     *
     * @param array<string, array<string, mixed>> $before
     * @param list<array<string, mixed>> $after computed items
     */
    private function writeEditAudit(
        PDO $db,
        int $clientId,
        int $branchId,
        int $purchaseId,
        array $header,
        array $before,
        array $after,
        bool $bypassSoldCheck,
        array $audit
    ): void {
        $newLines = [];
        foreach ($after as $ci) {
            $key = self::editLineKey((int) $ci['binding_id'], $ci['batch_no'] ?? null);
            if (!isset($newLines[$key])) {
                $newLines[$key] = [
                    'binding_id'   => (int) $ci['binding_id'],
                    'product_name' => (string) $ci['product_name'],
                    'batch_no'     => (string) ($ci['batch_no'] ?: 'NA'),
                    'pack'         => $ci['pack_size'] !== null ? (string) $ci['pack_size'] : null,
                    'qty'          => 0.0,
                    'free'         => 0.0,
                    'units'        => 0,
                    'rate'         => (float) $ci['purchase_rate'],
                    'mrp'          => (float) $ci['mrp'],
                ];
            }
            $newLines[$key]['qty'] += (float) $ci['quantity'];
            $newLines[$key]['free'] += (float) $ci['free_quantity'];
            $newLines[$key]['units'] += (int) $ci['base_units'];
        }

        $batchSt = $db->prepare(
            'SELECT id, quantity FROM inventory_batches
             WHERE client_id = :c AND branch_id = :b AND product_binding_id = :p AND batch_no = :bn LIMIT 1'
        );
        $ins = $db->prepare(
            'INSERT INTO purchase_edit_audit_log
                (client_id, branch_id, purchase_id, po_no, invoice_no, supplier_name, change_type,
                 product_binding_id, batch_id, product_name, batch_no, old_pack, new_pack,
                 old_qty, new_qty, old_free, new_free, old_units, new_units, old_rate, new_rate, old_mrp, new_mrp,
                 was_sold, sold_units, stock_before, stock_after, bypass_used,
                 user_id, user_name, user_role, ip_address, user_agent, created_at)
             VALUES
                (:c, :b, :pid, :po, :inv, :sup, :ct,
                 :pbid, :bid, :pname, :bno, :opack, :npack,
                 :oqty, :nqty, :ofree, :nfree, :ounits, :nunits, :orate, :nrate, :omrp, :nmrp,
                 :sold, :soldu, :sbefore, :safter, :bypass,
                 :uid, :uname, :urole, :ip, :ua, :now)'
        );
        $userId = isset($audit['user_id']) && (int) $audit['user_id'] > 0 ? (int) $audit['user_id'] : null;
        $userName = mb_substr(trim((string) ($audit['user_name'] ?? '')) ?: 'SYSTEM', 0, 150);
        $userRole = isset($audit['user_role']) ? mb_substr((string) $audit['user_role'], 0, 40) : null;
        $ip = mb_substr(trim((string) ($audit['ip_address'] ?? '')) ?: '0.0.0.0', 0, 64);
        $ua = isset($audit['user_agent']) ? mb_substr((string) $audit['user_agent'], 0, 255) : null;
        $now = now();

        foreach (array_unique(array_merge(array_keys($before), array_keys($newLines))) as $key) {
            $o = $before[$key] ?? null;
            $n = $newLines[$key] ?? null;
            $ref = $n ?? $o;
            $batchNo = (string) ($ref['batch_no'] !== '' ? $ref['batch_no'] : 'NA');
            $batchSt->execute(['c' => $clientId, 'b' => $branchId, 'p' => $ref['binding_id'], 'bn' => $batchNo]);
            $batchNow = $batchSt->fetch();

            if ($o === null) {
                $type = 'ADDED';
            } elseif ($n === null) {
                $type = 'REMOVED';
            } elseif (
                abs($o['qty'] - $n['qty']) > 0.001 || abs($o['free'] - $n['free']) > 0.001
                || $o['units'] !== $n['units'] || abs($o['rate'] - $n['rate']) > 0.001
                || abs($o['mrp'] - $n['mrp']) > 0.001 || (string) $o['pack'] !== (string) $n['pack']
            ) {
                $type = 'EDITED';
            } else {
                $type = 'UNCHANGED';
            }
            $sold = (int) ($o['sold'] ?? 0);

            $ins->execute([
                'c'       => $clientId,
                'b'       => $branchId,
                'pid'     => $purchaseId,
                'po'      => mb_substr($header['po_no'], 0, 60) ?: null,
                'inv'     => mb_substr($header['invoice_no'], 0, 60) ?: null,
                'sup'     => mb_substr($header['supplier_name'], 0, 255) ?: null,
                'ct'      => $type,
                'pbid'    => $ref['binding_id'] ?: null,
                'bid'     => $batchNow ? (int) $batchNow['id'] : ($o['batch_id'] ?? null),
                'pname'   => mb_substr((string) $ref['product_name'], 0, 255),
                'bno'     => mb_substr($batchNo, 0, 60),
                'opack'   => $o['pack'] ?? null,
                'npack'   => $n['pack'] ?? null,
                'oqty'    => $o['qty'] ?? 0,
                'nqty'    => $n['qty'] ?? 0,
                'ofree'   => $o['free'] ?? 0,
                'nfree'   => $n['free'] ?? 0,
                'ounits'  => $o['units'] ?? 0,
                'nunits'  => $n['units'] ?? 0,
                'orate'   => $o['rate'] ?? 0,
                'nrate'   => $n['rate'] ?? 0,
                'omrp'    => $o['mrp'] ?? 0,
                'nmrp'    => $n['mrp'] ?? 0,
                'sold'    => $sold > 0 ? 1 : 0,
                'soldu'   => $sold,
                'sbefore' => (int) ($o['stock'] ?? 0),
                'safter'  => $batchNow ? (int) $batchNow['quantity'] : 0,
                'bypass'  => ($sold > 0 && $bypassSoldCheck) ? 1 : 0,
                'uid'     => $userId,
                'uname'   => $userName,
                'urole'   => $userRole,
                'ip'      => $ip,
                'ua'      => $ua,
                'now'     => $now,
            ]);
        }
    }

    /**
     * Rows for the Purchase Edit Audit Log report.
     *
     * @return list<array<string, mixed>>
     */
    public function editAuditLog(int $clientId, int $branchId, string $from, string $to): array
    {
        $this->ensureEditAuditSchema();
        $params = ['c' => $clientId, 'from' => $from . ' 00:00:00', 'to' => $to . ' 23:59:59'];
        $where = 'client_id = :c AND created_at BETWEEN :from AND :to';
        if ($branchId > 0) {
            $where .= ' AND branch_id = :b';
            $params['b'] = $branchId;
        }
        $stmt = Connection::ops()->prepare(
            "SELECT created_at, purchase_id, po_no, invoice_no, supplier_name, change_type, product_name, batch_no,
                    old_pack, new_pack, old_qty, new_qty, old_free, new_free, old_units, new_units,
                    old_rate, new_rate, old_mrp, new_mrp, was_sold, sold_units, stock_before, stock_after,
                    bypass_used, user_name, user_role, ip_address
             FROM purchase_edit_audit_log
             WHERE {$where}
             ORDER BY created_at DESC, id DESC
             LIMIT 5000"
        );
        $stmt->execute($params);

        $types = ['EDITED' => 'Edited', 'ADDED' => 'Added', 'REMOVED' => 'Removed', 'UNCHANGED' => 'Unchanged'];
        // Compact "before → after" cells; a single value when nothing changed.
        $pair = static function (string $o, string $n): string {
            return $o === $n ? $n : "{$o} → {$n}";
        };
        $num = static fn ($v): string => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
        $money = static fn ($v): string => number_format((float) $v, 2, '.', '');
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $ts = strtotime((string) $r['created_at']) ?: time();
            $po = (string) ($r['po_no'] ?? '');
            $inv = (string) ($r['invoice_no'] ?? '');
            $soldUnits = (int) $r['sold_units'];
            $user = (string) $r['user_name'];
            $out[] = [
                'when'     => date('d-m-Y h:i A', $ts),
                'bill'     => $po !== '' && $inv !== '' ? "{$po} / {$inv}" : ($po !== '' ? $po : $inv),
                'supplier' => (string) ($r['supplier_name'] ?? ''),
                'change'   => $types[(string) $r['change_type']] ?? (string) $r['change_type'],
                'product'  => (string) $r['product_name'] . ((string) ($r['batch_no'] ?? '') !== '' ? ' · ' . $r['batch_no'] : ''),
                'pack'     => $pair((string) ($r['old_pack'] ?? '-'), (string) ($r['new_pack'] ?? '-')),
                'qty'      => $pair($num($r['old_qty']), $num($r['new_qty'])),
                'rate'     => $pair($money($r['old_rate']), $money($r['new_rate'])),
                'mrp'      => $pair($money($r['old_mrp']), $money($r['new_mrp'])),
                'sold'     => (int) $r['was_sold'] === 1 ? "Yes · {$soldUnits}" : 'No',
                'stock'    => $pair((string) (int) $r['stock_before'], (string) (int) $r['stock_after']),
                'user'     => (int) $r['bypass_used'] === 1 ? "{$user} (bypass)" : $user,
                'ip'       => (string) $r['ip_address'],
            ];
        }
        return $out;
    }

    private function reversePurchaseStock(
        PDO $db,
        int $clientId,
        int $branchId,
        int $purchaseId,
        string $action = 'update',
        bool $bypassSoldCheck = false,
        bool $soldEditConfirmed = false
    ): array
    {
        // Net posted qty per batch (IN minus prior edit reversals OUT) — avoids double-reverse on repeat edits.
        $ledger = $db->prepare(
            'SELECT l.product_binding_id, l.batch_id,
                    SUM(l.qty_in) - SUM(l.qty_out) AS net_qty,
                    pb.product_name, ib.batch_no, ib.quantity AS batch_qty
             FROM stock_ledger l
             LEFT JOIN product_bindings pb ON pb.id = l.product_binding_id
             LEFT JOIN inventory_batches ib ON ib.id = l.batch_id
             WHERE l.client_id = :c AND l.branch_id = :b
               AND l.ref_type = :rt AND l.ref_id = :rid
             GROUP BY l.product_binding_id, l.batch_id, pb.product_name, ib.batch_no, ib.quantity
             HAVING net_qty > 0'
        );
        $ledger->execute([
            'c' => $clientId,
            'b' => $branchId,
            'rt' => 'purchase',
            'rid' => $purchaseId,
        ]);
        $rows = [];
        foreach ($ledger->fetchAll() as $row) {
            $rows[] = [
                'product_binding_id' => $row['product_binding_id'],
                'batch_id'           => $row['batch_id'],
                'qty_in'             => (int) $row['net_qty'],
                'product_name'       => $row['product_name'],
                'batch_no'           => $row['batch_no'] ?? 'NA',
                'batch_qty'          => $row['batch_qty'],
            ];
        }

        if ($rows === []) {
            $itemStmt = $db->prepare(
                'SELECT pi.product_binding_id, pi.product_name, pi.batch_no, pi.quantity, pi.units_per_pack, pi.free_quantity,
                        ib.id AS batch_id, ib.quantity AS batch_qty
                 FROM purchase_items pi
                 LEFT JOIN inventory_batches ib
                   ON ib.client_id = :c AND ib.branch_id = :b
                  AND ib.product_binding_id = pi.product_binding_id
                  AND ib.batch_no = COALESCE(NULLIF(pi.batch_no, ""), "NA")
                 WHERE pi.purchase_id = :pid'
            );
            $itemStmt->execute(['c' => $clientId, 'b' => $branchId, 'pid' => $purchaseId]);
            foreach ($itemStmt->fetchAll() as $it) {
                $upp = max(1, (int) ($it['units_per_pack'] ?? 1));
                $qtyIn = (((int) $it['quantity']) + (int) ($it['free_quantity'] ?? 0)) * $upp;
                $rows[] = [
                    'product_binding_id' => $it['product_binding_id'],
                    'batch_id'           => $it['batch_id'],
                    'qty_in'             => $qtyIn,
                    'product_name'       => $it['product_name'],
                    'batch_no'           => $it['batch_no'] ?: 'NA',
                    'batch_qty'          => $it['batch_qty'],
                ];
            }
        }

        // Bypass reverses the full posted qty even if part was sold; the batch may dip below zero
        // until the edited lines are re-posted into it.
        if ($bypassSoldCheck) {
            $rows = array_values(array_filter($rows, static fn (array $r) => !empty($r['batch_id'])));
        }

        $blocked = [];
        $soldLines = [];
        foreach ($rows as $row) {
            $need = (int) $row['qty_in'];
            $have = (int) ($row['batch_qty'] ?? 0);
            if (empty($row['batch_id']) || $have < $need) {
                $name = (string) ($row['product_name'] ?? 'Product');
                $batch = (string) ($row['batch_no'] ?? '-');
                $blocked[] = "{$name} (batch {$batch}): need {$need}, available {$have}";
                $soldLines[] = [
                    'product_name' => $name,
                    'batch_no'     => $batch,
                    'purchased'    => $need,
                    'in_stock'     => max(0, $have),
                    'sold'         => max(0, $need - max(0, $have)),
                ];
            }
        }
        // 409 + sold_lines lets the UI ask "edit anyway?"; the client re-sends with confirm_sold_edit.
        if ($bypassSoldCheck && $soldLines !== [] && !$soldEditConfirmed) {
            throw new HttpException('Stock from this purchase was already sold or adjusted.', 409, [
                'sold_edit_confirm' => true,
                'sold_lines' => $soldLines,
            ]);
        }
        if (!$bypassSoldCheck && $blocked !== []) {
            $verb = $action === 'delete' ? 'delete' : 'update';
            throw HttpException::validation([
                'purchase' => [
                    "Cannot {$verb} — stock from this purchase was already sold or adjusted." . "\n" . implode("\n", $blocked),
                ],
                'sold_lines' => $soldLines,
            ]);
        }

        $bindingIds = [];
        foreach ($rows as $row) {
            $bindingId = (int) $row['product_binding_id'];
            if ($bindingId > 0) {
                $bindingIds[] = $bindingId;
            }
            // Only reverse what is still on the batch. Full purchase qty after sales/adjust
            // used to drive quantity negative and wipe physical-count adjustments (Eyon D3VUE).
            $taken = $this->inventory->reduceBatch(
                (int) $row['batch_id'],
                (int) $row['qty_in'],
                'purchase_return',
                date('Y-m-d'),
                'purchase',
                $purchaseId
            );
            if ($taken > 0 && $bindingId > 0) {
                $this->inventory->bumpAggregate($clientId, $branchId, $bindingId, -$taken);
            }
        }

        return array_values(array_unique($bindingIds));
    }

    /**
     * Next retail-style PO number for a client: PR/{clientId}/{yy}/{seq}.
     *
     * @return array{0: string, 1: int}
     */
    private function nextPoNo(PDO $db, int $clientId): array
    {
        $stmt = $db->prepare(
            'SELECT IFNULL(MAX(po_seq), 0) AS max_seq FROM purchases WHERE client_id = :c FOR UPDATE'
        );
        $stmt->execute(['c' => $clientId]);
        $seq = (int) ($stmt->fetchColumn() ?: 0) + 1;
        $year = date('y');
        $poNo = 'PR/' . $clientId . '/' . $year . '/' . $seq;
        return [$poNo, $seq];
    }

    /** Reject duplicate invoice_no for the same client (Retail checkInvoiceNo). */
    private function assertUniqueInvoice(PDO $db, int $clientId, string $invoiceNo, ?int $excludeId = null): void
    {
        $invoiceNo = trim($invoiceNo);
        if ($invoiceNo === '') {
            return;
        }
        $sql = 'SELECT id, po_no FROM purchases WHERE client_id = :c AND invoice_no = :inv';
        $bindings = ['c' => $clientId, 'inv' => $invoiceNo];
        if ($excludeId !== null) {
            $sql .= ' AND id <> :id';
            $bindings['id'] = $excludeId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $db->prepare($sql);
        $stmt->execute($bindings);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $po = $row['po_no'] ?? $row['id'];
            throw HttpException::validation([
                'invoice_no' => ["Invoice already exists (PO {$po})."],
            ]);
        }
    }

    /**
     * Resolve the distributor id: use the given id, else match by name within the
     * client, else create a new distributor from the typed name. Returns null when
     * no usable distributor name was provided.
     */
    private function resolveSupplier(PDO $db, int $clientId, array $data): ?int
    {
        if (!empty($data['supplier_id'])) {
            return (int) $data['supplier_id'];
        }
        $name = trim((string) ($data['supplier_name'] ?? ''));
        if ($name === '') {
            return null;
        }
        $master = Connection::master();
        $find = $master->prepare('SELECT id FROM distributors WHERE client_id = :c AND name = :n LIMIT 1');
        $find->execute(['c' => $clientId, 'n' => $name]);
        $row = $find->fetch();
        if ($row) {
            return (int) $row['id'];
        }
        $master->prepare(
            'INSERT INTO distributors (client_id, name, is_active, created_at, updated_at) VALUES (:c, :n, 1, :ca, :ua)'
        )->execute(['c' => $clientId, 'n' => $name, 'ca' => now(), 'ua' => now()]);
        return (int) $master->lastInsertId();
    }

    /**
     * Resolve a product binding id for a line. Either an existing master
     * product (bind it) or a brand-new product (create in master + bind).
     */
    private function resolveBinding(int $clientId, int $branchId, array $item): int
    {
        $lineName = ProductName::clean(trim((string) ($item['product_name'] ?? '')));

        if (!empty($item['product_binding_id'])) {
            $bid = (int) $item['product_binding_id'];
            if ($this->bindingMatchesLineName($clientId, $branchId, $bid, $lineName)) {
                return $bid;
            }
        }

        if (!empty($item['master_product_id'])) {
            $binding = $this->binder->bind($clientId, $branchId, (int) $item['master_product_id']);
            return $this->bindingId($clientId, $branchId, (int) $item['master_product_id'], $binding);
        }

        $byName = $this->findBindingByProductName($clientId, $branchId, $lineName);
        if ($byName !== null) {
            return $byName;
        }

        if (!empty($item['new_product']) && is_array($item['new_product'])) {
            $np = $item['new_product'];
            $result = $this->binder->createAndBind($clientId, $branchId, [
                'name'              => $np['name'] ?? ($item['product_name'] ?? 'Unnamed'),
                'generic_id'        => $np['generic_id'] ?? null,
                'manufacturer'      => $np['manufacturer'] ?? null,
                'manufacturer_short' => $np['manufacturer_short'] ?? null,
                'schedule_category' => $np['schedule_category'] ?? 'OTC',
                'product_category'  => $np['product_category'] ?? 'Allopathy',
                'hsn_code'          => $np['hsn_code'] ?? null,
                'default_gst_rate'  => $np['default_gst_rate'] ?? ($item['gst_rate'] ?? 12),
                'mrp'               => $np['mrp'] ?? ($item['mrp'] ?? 0),
                'ptr'               => $np['ptr'] ?? ($item['ptr'] ?? 0),
                'pts'               => $np['pts'] ?? ($item['pts'] ?? 0),
                'pack_size'         => $np['pack_size'] ?? ($item['pack_size'] ?? null),
                'dosage_form'       => $np['dosage_form'] ?? null,
                'strength'          => $np['strength'] ?? null,
                'packing_desc'      => $np['packing_desc'] ?? null,
                'units_per_pack'    => $np['units_per_pack']
                    ?? (isset($item['pack_size']) ? PackSize::parse((string) $item['pack_size'])['units_per_pack'] : ($item['units_per_pack'] ?? 1)),
                'allow_loose'       => $np['allow_loose'] ?? 1,
                'shelf_location'    => $np['shelf_location'] ?? ($item['shelf_location'] ?? null),
                'uom'               => $np['uom'] ?? 'NOS',
                'composition'       => $np['composition'] ?? null,
            ]);
            return (int) $result['id'];
        }

        throw HttpException::validation(['items' => ['Each line needs an existing or new product.']]);
    }

    /**
     * Repair purchase lines and batch stock linked to the wrong product_binding_id
     * (e.g. import/edit reused one binding for every line).
     *
     * @return array{items_fixed:int,batches_moved:int,ledger_rows:int,bindings_created:int,details:list<string>}
     */
    public function repairMislinkedPurchaseBindings(?int $clientId = null, ?int $branchId = null): array
    {
        $db = Connection::ops();
        $params = [];
        $where = 'UPPER(TRIM(pi.product_name)) <> UPPER(TRIM(pb.product_name))';
        if ($clientId !== null && $clientId > 0) {
            $where .= ' AND p.client_id = :c';
            $params['c'] = $clientId;
        }
        if ($branchId !== null && $branchId > 0) {
            $where .= ' AND p.branch_id = :b';
            $params['b'] = $branchId;
        }

        $stmt = $db->prepare(
            "SELECT pi.id AS item_id, pi.product_binding_id AS wrong_binding_id, pi.product_name,
                    pi.batch_no, pi.hsn_code, pi.gst_rate, pi.mrp, pi.units_per_pack,
                    p.client_id, p.branch_id
             FROM purchase_items pi
             INNER JOIN purchases p ON p.id = pi.purchase_id
             INNER JOIN product_bindings pb ON pb.id = pi.product_binding_id
             WHERE {$where}
             ORDER BY pi.id ASC"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $itemsFixed = 0;
        $batchesMoved = 0;
        $ledgerRows = 0;
        $bindingsCreated = 0;
        $details = [];
        $reconcileScopes = [];

        foreach ($rows as $row) {
            $cId = (int) $row['client_id'];
            $bId = (int) $row['branch_id'];
            $wrongBind = (int) $row['wrong_binding_id'];
            $batchNo = trim((string) ($row['batch_no'] ?? ''));
            $lineName = ProductName::clean(trim((string) ($row['product_name'] ?? '')));
            if ($lineName === '' || $batchNo === '') {
                continue;
            }

            $before = $this->findBindingByProductName($cId, $bId, $lineName);
            $newBind = $this->resolveOrCreateBindingForRepair($cId, $bId, $row);
            if ($before === null && $newBind > 0) {
                $bindingsCreated++;
            }
            if ($newBind <= 0 || $newBind === $wrongBind) {
                $details[] = "{$lineName} / {$batchNo}: skipped (could not resolve binding)";
                continue;
            }

            $db->prepare('UPDATE purchase_items SET product_binding_id = :nb WHERE id = :id')
                ->execute(['nb' => $newBind, 'id' => (int) $row['item_id']]);
            $itemsFixed++;

            $batchStmt = $db->prepare(
                'SELECT id FROM inventory_batches
                 WHERE client_id = :c AND branch_id = :b AND product_binding_id = :wb AND batch_no = :bn
                 LIMIT 1'
            );
            $batchStmt->execute(['c' => $cId, 'b' => $bId, 'wb' => $wrongBind, 'bn' => $batchNo]);
            $batchRow = $batchStmt->fetch(PDO::FETCH_ASSOC);
            if ($batchRow) {
                $batchId = (int) $batchRow['id'];
                $db->prepare('UPDATE inventory_batches SET product_binding_id = :nb, updated_at = :now WHERE id = :id')
                    ->execute(['nb' => $newBind, 'now' => now(), 'id' => $batchId]);
                $batchesMoved++;

                $led = $db->prepare(
                    'UPDATE stock_ledger SET product_binding_id = :nb WHERE batch_id = :bid AND product_binding_id = :wb'
                );
                $led->execute(['nb' => $newBind, 'bid' => $batchId, 'wb' => $wrongBind]);
                $ledgerRows += $led->rowCount();
            }

            $reconcileScopes["{$cId}:{$bId}"][] = $wrongBind;
            $reconcileScopes["{$cId}:{$bId}"][] = $newBind;
            $details[] = "{$lineName} / {$batchNo}: binding {$wrongBind} → {$newBind}";
        }

        foreach ($reconcileScopes as $scope => $ids) {
            [$cId, $bId] = array_map('intval', explode(':', $scope, 2));
            $this->inventory->reconcileAggregates($cId, $bId, array_values(array_unique($ids)));
        }

        return [
            'items_fixed'       => $itemsFixed,
            'batches_moved'     => $batchesMoved,
            'ledger_rows'       => $ledgerRows,
            'bindings_created'  => $bindingsCreated,
            'details'           => $details,
        ];
    }

    private function bindingMatchesLineName(int $clientId, int $branchId, int $bindingId, string $lineName): bool
    {
        $lineName = ProductName::clean(trim($lineName));
        if ($lineName === '') {
            return false;
        }
        $stmt = Connection::ops()->prepare(
            'SELECT product_name FROM product_bindings WHERE id = :id AND client_id = :c AND branch_id = :b LIMIT 1'
        );
        $stmt->execute(['id' => $bindingId, 'c' => $clientId, 'b' => $branchId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        return strcasecmp(ProductName::clean(trim((string) $row['product_name'])), $lineName) === 0;
    }

    private function findBindingByProductName(int $clientId, int $branchId, string $lineName): ?int
    {
        $lineName = ProductName::clean(trim($lineName));
        if ($lineName === '') {
            return null;
        }
        $stmt = Connection::ops()->prepare(
            'SELECT id FROM product_bindings
             WHERE client_id = :c AND branch_id = :b AND is_active = 1
               AND UPPER(TRIM(product_name)) = UPPER(TRIM(:n))
             LIMIT 1'
        );
        $stmt->execute(['c' => $clientId, 'b' => $branchId, 'n' => $lineName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int) $row['id'] : null;
    }

    /** @param array<string,mixed> $row purchase_items row + client/branch */
    private function resolveOrCreateBindingForRepair(int $clientId, int $branchId, array $row): int
    {
        $lineName = ProductName::clean(trim((string) ($row['product_name'] ?? '')));
        $existing = $this->findBindingByProductName($clientId, $branchId, $lineName);
        if ($existing !== null) {
            return $existing;
        }

        $master = Connection::master();
        $hide = (new \App\Services\ClientCatalogPolicy())->hideProducts($clientId);
        $sql = 'SELECT id FROM products WHERE is_active = 1 AND UPPER(TRIM(name)) = UPPER(TRIM(:n))';
        $bind = ['n' => $lineName];
        if ($hide) {
            $sql .= ' AND created_by_client = :own_client';
            $bind['own_client'] = $clientId;
        }
        $sql .= ' LIMIT 1';
        $find = $master->prepare($sql);
        $find->execute($bind);
        $mp = $find->fetch(PDO::FETCH_ASSOC);
        if ($mp) {
            $binding = $this->binder->bind($clientId, $branchId, (int) $mp['id']);
            return $this->bindingId($clientId, $branchId, (int) $mp['id'], $binding);
        }

        $upp = max(1, (int) ($row['units_per_pack'] ?? 1));
        $created = $this->binder->createAndBind($clientId, $branchId, [
            'name'              => $lineName,
            'hsn_code'          => $row['hsn_code'] ?? null,
            'default_gst_rate'  => (float) ($row['gst_rate'] ?? 12),
            'mrp'               => (float) ($row['mrp'] ?? 0),
            'schedule_category' => 'H',
            'product_category'  => 'Allopathy',
            'units_per_pack'    => $upp,
            'allow_loose'       => 1,
        ]);
        return (int) ($created['id'] ?? 0);
    }

    /**
     * One-time repair: older purchases posted free qty as loose units
     * (qty×upp + free) instead of packs ((qty+free)×upp).
     * Adds the missing free×(upp−1) units onto the matching batch so sales
     * form available qty updates immediately. Idempotent via ledger note.
     *
     * @return array{lines_checked: int, lines_fixed: int, units_added: int, skipped: int, details: list<string>}
     */
    public function repairFreePackUndercount(?int $clientId = null, ?int $branchId = null): array
    {
        $db = Connection::ops();
        $params = [];
        $where = 'pi.free_quantity > 0 AND COALESCE(pi.units_per_pack, 1) > 1';
        if ($clientId !== null && $clientId > 0) {
            $where .= ' AND p.client_id = :c';
            $params['c'] = $clientId;
        }
        if ($branchId !== null && $branchId > 0) {
            $where .= ' AND p.branch_id = :b';
            $params['b'] = $branchId;
        }

        $stmt = $db->prepare(
            "SELECT pi.id AS item_id, pi.purchase_id, pi.product_binding_id, pi.product_name, pi.batch_no,
                    pi.quantity, pi.free_quantity, pi.units_per_pack, pi.mrp, pi.purchase_rate, pi.ptr, pi.pts,
                    pi.expiry_date, pi.shelf_location, pi.net_rate,
                    p.client_id, p.branch_id, p.invoice_date
             FROM purchase_items pi
             JOIN purchases p ON p.id = pi.purchase_id
             WHERE {$where}
             ORDER BY pi.id ASC"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $checked = 0;
        $fixed = 0;
        $unitsAdded = 0;
        $skipped = 0;
        $details = [];
        $bindingIdsByScope = [];

        $doneChk = $db->prepare(
            "SELECT 1 FROM stock_ledger
             WHERE client_id = :c AND branch_id = :b AND txn_type = 'adjustment'
               AND note = :note
             LIMIT 1"
        );

        foreach ($rows as $row) {
            $checked++;
            $itemId = (int) $row['item_id'];
            $cId = (int) $row['client_id'];
            $bId = (int) $row['branch_id'];
            $upp = max(1, (int) ($row['units_per_pack'] ?? 1));
            $free = (int) ($row['free_quantity'] ?? 0);
            if ($free <= 0 || $upp <= 1) {
                $skipped++;
                continue;
            }

            // Missing units from old formula (qty×upp + free) vs correct ((qty+free)×upp).
            $missing = $free * ($upp - 1);
            if ($missing <= 0) {
                $skipped++;
                continue;
            }

            $note = 'free-pack-fix:pi:' . $itemId;
            $doneChk->execute(['c' => $cId, 'b' => $bId, 'note' => $note]);
            if ($doneChk->fetch()) {
                $skipped++;
                continue;
            }

            $batchNo = trim((string) ($row['batch_no'] ?? '')) ?: 'NA';
            $bindingId = (int) $row['product_binding_id'];
            $unitMrp = $upp > 0 ? ((float) ($row['mrp'] ?? 0)) / $upp : (float) ($row['mrp'] ?? 0);
            $unitCost = (float) ($row['net_rate'] ?? 0);
            if ($unitCost <= 0 && $upp > 0) {
                $unitCost = ((float) ($row['purchase_rate'] ?? 0)) / $upp;
            }

            try {
                $this->inventory->addStock(
                    $cId,
                    $bId,
                    $bindingId,
                    $batchNo,
                    $row['expiry_date'] !== null && $row['expiry_date'] !== '' ? (string) $row['expiry_date'] : null,
                    round($unitMrp, 4),
                    round($unitCost, 4),
                    $missing,
                    $row['shelf_location'] !== null ? (string) $row['shelf_location'] : null,
                    (float) ($row['ptr'] ?? 0),
                    (float) ($row['pts'] ?? 0),
                    'adjustment',
                    $row['invoice_date'] ? (string) $row['invoice_date'] : date('Y-m-d'),
                    'purchase_item',
                    $itemId,
                    $note
                );
                $bindingIdsByScope[$cId . ':' . $bId][] = $bindingId;
                $fixed++;
                $unitsAdded += $missing;
                $details[] = sprintf(
                    '%s / %s: +%d units (free %d × pack %d)',
                    (string) $row['product_name'],
                    $batchNo,
                    $missing,
                    $free,
                    $upp
                );
            } catch (\Throwable $e) {
                $skipped++;
                $details[] = sprintf(
                    '%s / %s: FAILED — %s',
                    (string) $row['product_name'],
                    $batchNo,
                    $e->getMessage()
                );
            }
        }

        foreach ($bindingIdsByScope as $scope => $ids) {
            [$cId, $bId] = array_map('intval', explode(':', $scope, 2));
            $this->inventory->reconcileAggregates($cId, $bId, $ids);
        }

        return [
            'lines_checked' => $checked,
            'lines_fixed'   => $fixed,
            'units_added'   => $unitsAdded,
            'skipped'       => $skipped,
            'details'       => $details,
        ];
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
