<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Database\Connection;
use App\Models\Sale;
use App\Services\InvoiceService;
use App\Services\InventoryService;
use App\Services\PurchaseService;
use App\Services\PatientRetailHistoryService;
use App\Services\SalesService;
use App\Support\Env;
use App\Support\Logger;

final class SalesController extends Controller
{
    /** Safe master-DB name for cross-database joins (live vs local). */
    private function masterDb(): string
    {
        $name = (string) Env::get('DB_MASTER', 'pharma_master');
        return preg_replace('/[^a-zA-Z0-9_]/', '', $name) ?: 'pharma_master';
    }

    public function index(Request $request): Response
    {
        $clientId = $request->clientId();
        $branchId = $request->branchId();
        $conditions = ['client_id' => $clientId];
        // Same branch log for everyone on that branch; HO (no branch) sees client-wide.
        if ($branchId !== null && $branchId > 0) {
            $conditions['branch_id'] = $branchId;
        }
        $billMode = trim((string) $request->query('bill_mode', ''));
        if ($billMode === 'retail' || $billMode === 'wholesale') {
            $conditions['bill_mode'] = $billMode;
        } elseif ($billMode === 'ipd') {
            // IPD bills are tagged via ipd_admission_id; filter in SQL below.
        }

        $searchCols = ['invoice_no', 'customer_name', 'patient_name', 'contact_no', 'payment_mode'];
        $search = trim((string) $request->query('search', ''));

        if ($billMode === 'ipd' || $search !== '') {
            $page = max(1, (int) $request->query('page', 1));
            $perPage = max(1, min($billMode === 'ipd' ? 200 : 5000, (int) $request->query('per_page', 50)));
            $offset = ($page - 1) * $perPage;
            $sql = 'SELECT * FROM sales WHERE client_id = :c';
            $bind = ['c' => $clientId];
            if ($billMode === 'ipd') {
                $sql .= ' AND ipd_admission_id IS NOT NULL';
            } elseif (isset($conditions['bill_mode'])) {
                $sql .= ' AND bill_mode = :bm';
                $bind['bm'] = $conditions['bill_mode'];
            }
            if ($branchId !== null && $branchId > 0) {
                $sql .= ' AND branch_id = :b';
                $bind['b'] = $branchId;
            }
            if ($search !== '') {
                $sql .= ' AND (
                    invoice_no LIKE :q OR customer_name LIKE :q2 OR patient_name LIKE :q3
                    OR contact_no LIKE :q4 OR payment_mode LIKE :q5
                    OR EXISTS (
                        SELECT 1 FROM sale_items si
                        WHERE si.sale_id = sales.id
                          AND (si.product_name LIKE :q6 OR si.batch_no LIKE :q7)
                    )
                )';
                $like = '%' . $search . '%';
                foreach (['q', 'q2', 'q3', 'q4', 'q5', 'q6', 'q7'] as $k) {
                    $bind[$k] = $like;
                }
            }
            $countSql = 'SELECT COUNT(*) FROM (' . $sql . ') t';
            $countStmt = Connection::ops()->prepare($countSql);
            $countStmt->execute($bind);
            $total = (int) $countStmt->fetchColumn();
            $sql .= ' ORDER BY invoice_date DESC, id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
            $stmt = Connection::ops()->prepare($sql);
            $stmt->execute($bind);
            $result = [
                'data' => $stmt->fetchAll(),
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
            ];
        } else {
            $result = (new Sale())->paginate(
                $conditions,
                $request->query('search'),
                $searchCols,
                (int) $request->query('page', 1),
                (int) $request->query('per_page', 50),
                'invoice_date DESC, id DESC'
            );
        }

        foreach ($result['data'] as &$row) {
            $row = $this->enrichSaleLogRow($row);
        }
        unset($row);

        return $this->success($result, 'Sales fetched.');
    }

    /** Derive Paid/Balance for list when columns empty (Cash/UPI/Card = paid in full). */
    private function enrichSaleLogRow(array $row): array
    {
        $net = (float) ($row['net_amount'] ?? 0);
        $mode = strtolower((string) ($row['payment_mode'] ?? 'cash'));
        $hasPaidCol = array_key_exists('paid', $row);
        $paid = $hasPaidCol ? (float) ($row['paid'] ?? 0) : null;
        $balance = $hasPaidCol ? (float) ($row['balance'] ?? 0) : null;
        if ($paid === null || ($paid == 0.0 && $balance == 0.0 && $net > 0)) {
            if ($mode === 'credit') {
                $paid = 0.0;
                $balance = $net;
            } else {
                $paid = $net;
                $balance = 0.0;
            }
        }
        $row['paid'] = round($paid, 2);
        $balance = round((float) $balance, 2);
        // Sub-rupee leftover after round-off payments must not show as outstanding.
        if ($balance > 0.009 && $balance < 1.0) {
            $balance = 0.0;
        }
        $row['balance'] = $balance;
        $row['cash_credit'] = $row['payment_mode'] ?? '';
        return $row;
    }

    public function show(Request $request): Response
    {
        $sale = $this->enrichSaleLogRow($this->findSale($request));
        return $this->success($sale, 'Sale fetched.');
    }

    /** Sales log: edit payment mode only (recalculates paid / balance). */
    public function updatePaymentMode(Request $request): Response
    {
        $saleId = (int) $request->attribute('id');
        $this->findSale($request); // tenant check

        $user = $request->user() ?? [];
        $role = strtolower(trim((string) ($user['role'] ?? '')));
        if (!in_array($role, ['ho_admin', 'branch_admin'], true)) {
            (new \App\Services\UserRightsService())->requireModule(
                (int) $request->clientId(),
                (int) ($user['id'] ?? 0),
                $role,
                'SALES',
                true,
            );
        }

        $mode = trim((string) ($request->input('payment_mode') ?? ''));
        $accountId = $request->input('account_id');
        $acc = $accountId !== null && $accountId !== '' ? (int) $accountId : null;

        $result = (new SalesService())->updatePaymentMode(
            $request->clientId(),
            $saleId,
            $mode,
            $acc
        );

        return $this->success($result, 'Payment mode updated. Paid / balance and customer dues will reflect this change.');
    }

    public function update(Request $request): Response
    {
        if ($request->branchId() === null) {
            throw HttpException::forbidden('A branch context is required.');
        }
        $user = $request->user() ?? [];
        $role = strtolower(trim((string) ($user['role'] ?? '')));
        if (!in_array($role, ['ho_admin', 'branch_admin'], true)) {
            throw HttpException::forbidden('Only admins can edit the full bill. Staff can change the payment mode only.');
        }

        $saleId = (int) $request->attribute('id');
        $result = (new SalesService())->update(
            $request->clientId(),
            $request->branchId(),
            $saleId,
            $request->user()['id'] ?? null,
            $request->all()
        );

        return $this->success($result, 'Sale bill updated.');
    }

    /** Delete sale bill + restore stock. Admins always; staff need SALES Delete right. */
    public function destroy(Request $request): Response
    {
        if ($request->branchId() === null) {
            throw HttpException::forbidden('A branch context is required.');
        }

        $user = $request->user() ?? [];
        $role = strtolower(trim((string) ($user['role'] ?? '')));
        if (!\App\Services\UserRightsService::isAdminRole($role)) {
            $mine = (new \App\Services\UserRightsService())->myRights(
                (int) $request->clientId(),
                (int) ($user['id'] ?? 0),
                $role
            );
            $allowed = false;
            foreach ($mine['rights'] ?? [] as $r) {
                if (
                    strtoupper((string) ($r['module_code'] ?? '')) === 'SALES'
                    && (int) ($r['can_delete'] ?? 0) === 1
                ) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                throw HttpException::forbidden('You do not have delete access for Sales.');
            }
        }

        (new SalesService())->delete(
            (int) $request->clientId(),
            (int) $request->branchId(),
            (int) $request->attribute('id')
        );

        return $this->success(null, 'Sale deleted and stock restored.');
    }

    public function store(Request $request): Response
    {
        if ($request->branchId() === null) {
            throw HttpException::forbidden('A branch context is required.');
        }

        $result = (new SalesService())->create(
            $request->clientId(),
            $request->branchId(),
            $request->user()['id'] ?? null,
            $request->all()
        );

        if ($this->wantsWhatsApp($request)) {
            try {
                $wa = (new \App\Services\WhatsAppBillService())->sendSaleBill(
                    (int) $result['id'],
                    (int) $request->clientId()
                );
                $result['whatsapp_sent'] = true;
                $result['whatsapp_message'] = $wa['message'] ?? 'WhatsApp bill sent.';
            } catch (HttpException $e) {
                $result['whatsapp_sent'] = false;
                $result['whatsapp_message'] = $e->getMessage();
            } catch (\Throwable $e) {
                Logger::error('whatsapp_on_save', ['sale_id' => $result['id'], 'error' => $e->getMessage()]);
                $result['whatsapp_sent'] = false;
                $result['whatsapp_message'] = 'Bill saved but WhatsApp could not be sent.';
            }
        }

        return $this->created($result, 'Sale completed.');
    }

    private function wantsWhatsApp(Request $request): bool
    {
        $v = $request->input('send_whatsapp');
        return $v === 1 || $v === true || $v === '1' || $v === 'true';
    }

    /**
     * Quick stock-in from the sales counter: when a chemist has physically
     * received material that wasn't entered in Purchase yet, create a minimal
     * one-line purchase (binds the product + adds the batch to stock) and return
     * the now-sellable product so it can be added straight to the bill.
     */
    public function quickPurchase(Request $request): Response
    {
        if ($request->branchId() === null) {
            throw HttpException::forbidden('A branch context is required.');
        }
        $clientId = (int) $request->clientId();
        $branchId = (int) $request->branchId();

        $name    = trim((string) $request->input('product_name', ''));
        $batchNo = trim((string) $request->input('batch_no', ''));
        $qty     = (int) $request->input('quantity', 0);
        $mrp     = (float) $request->input('mrp', 0);
        $rateRaw = $request->input('purchase_rate');
        $rate    = ($rateRaw === null || $rateRaw === '') ? 0.0 : (float) $rateRaw;
        $gst     = (float) $request->input('gst_rate', 12);
        $expiry  = trim((string) $request->input('expiry_date', ''));
        $unitsPerPack = max(1, (int) $request->input('units_per_pack', 1));
        $packSize = trim((string) $request->input('pack_size', ''));
        if ($packSize === '' && $unitsPerPack > 1) {
            $packSize = '1x' . $unitsPerPack;
        }
        // Normalize "1 X 10" → "1x10" for PackSize::parse / purchase validation.
        if ($packSize !== '') {
            $packSize = preg_replace('/\s*[xX×*]\s*/u', 'x', $packSize) ?? $packSize;
            $packSize = preg_replace('/\s+/', '', $packSize) ?? $packSize;
        }

        // Mandatory: name, batch, qty, MRP, purchase rate, expiry, pack size.
        $errors = [];
        if ($name === '') { $errors['product_name'] = ['Product name is required.']; }
        if ($batchNo === '') { $errors['batch_no'] = ['Batch No is required.']; }
        if ($qty <= 0) { $errors['quantity'] = ['Quantity must be greater than zero.']; }
        if ($mrp <= 0) { $errors['mrp'] = ['MRP must be greater than zero.']; }
        if ($rate <= 0) { $errors['purchase_rate'] = ['Purchase Rate is required.']; }
        if ($expiry === '') { $errors['expiry_date'] = ['Expiry is required.']; }
        if ($packSize === '') { $errors['pack_size'] = ['Pack size is required.']; }
        if ($errors) {
            throw HttpException::validation($errors);
        }

        // Prefer an existing master product with the same name; else create new.
        $masterId = $request->input('master_product_id');
        if (empty($masterId)) {
            $clientId = (int) ($request->clientId() ?? 0);
            $hide = $clientId > 0 && (new \App\Services\ClientCatalogPolicy())->hideProducts($clientId);
            $sql = 'SELECT id FROM products WHERE name = :n AND is_active = 1';
            $bind = ['n' => $name];
            if ($hide) {
                $sql .= ' AND created_by_client = :own_client';
                $bind['own_client'] = $clientId;
            }
            $sql .= ' LIMIT 1';
            $find = Connection::master()->prepare($sql);
            $find->execute($bind);
            $row = $find->fetch();
            $masterId = $row ? (int) $row['id'] : null;
        }

        $item = [
            'product_name'   => $name,
            'batch_no'       => $batchNo,
            'expiry_date'    => $expiry,
            'quantity'       => $qty,
            'units_per_pack' => $unitsPerPack,
            'pack_size'      => $packSize,
            'free_quantity'  => 0,
            'purchase_rate'  => $rate,
            'mrp'            => $mrp,
            'gst_rate'       => $gst,
            'discount_pct'   => 0,
        ];
        if ($masterId) {
            $item['master_product_id'] = (int) $masterId;
        } else {
            // Brand-new product -> create a complete master catalog entry.
            $item['new_product'] = [
                'name'               => $name,
                'manufacturer'       => $request->input('manufacturer') ?: null,
                'manufacturer_short' => $request->input('manufacturer_short') ?: null,
                'schedule_category'  => $request->input('schedule_category') ?: 'OTC',
                'product_category'   => $request->input('product_category') ?: 'Allopathy',
                'hsn_code'           => $request->input('hsn_code') ?: null,
                'default_gst_rate'   => $gst,
                'mrp'                => $mrp,
                'pack_size'          => $packSize,
                'dosage_form'        => $request->input('dosage_form') ?: null,
                'strength'           => $request->input('strength') ?: null,
                'packing_desc'       => $request->input('packing_desc') ?: null,
                'units_per_pack'     => $unitsPerPack,
            ];
        }

        (new PurchaseService())->create($clientId, $branchId, $request->user()['id'] ?? null, [
            'invoice_no'    => 'CTR-' . time(),
            'invoice_date'  => date('Y-m-d'),
            'supplier_name' => 'Direct / Counter Stock',
            'items'         => [$item],
        ]);

        // Return the freshly bound product (SaleProduct shape) for the bill.
        $avail = InventoryService::availableQtyExpr('pb');
        $sql =
            "SELECT pb.id AS binding_id, pb.product_name, pb.manufacturer, pb.manufacturer_short,
                    pb.schedule_category, pb.gst_rate, pb.master_product_id,
                    pb.units_per_pack, pb.allow_loose, pb.shelf_location, pb.pack_size, pb.generic_id,
                    pb.hsn_code,
                    {$avail} AS available_qty, pb.mrp
             FROM product_bindings pb
             WHERE pb.client_id = :c AND pb.branch_id = :b ";
        $bind = ['c' => $clientId, 'b' => $branchId];
        if ($masterId) {
            $sql .= 'AND pb.master_product_id = :m '; $bind['m'] = (int) $masterId;
        } else {
            $sql .= 'AND pb.product_name = :n '; $bind['n'] = $name;
        }
        $sql .= 'ORDER BY pb.id DESC LIMIT 1';
        $stmt = Connection::ops()->prepare($sql);
        $stmt->execute($bind);
        $product = $stmt->fetch();

        return $this->created($product ?: [], 'Stock added. Product is ready to bill.');
    }

    /** Type-ahead over the branch's bound products with live stock + MRP.
     *  Supports search by product name OR by generic (substitute) name. */
    /** Previous wholesale sale rates for a party + product (Retail getPrevCustomerProductRates). */
    /** Retail: previous retail bills for patient (repeat order / reorder). */
    public function patientRetailHistory(Request $request): Response
    {
        $limit = max(1, min(80, (int) $request->query('limit', 40)));
        $result = (new PatientRetailHistoryService())->getHistory(
            (int) $request->clientId(),
            (int) $request->branchId(),
            trim((string) $request->query('patient_no', '')),
            trim((string) $request->query('patient_contact', '')),
            $limit,
        );
        return $this->success($result, 'Patient history.');
    }

    public function prevCustomerRates(Request $request): Response
    {
        $customerId = (int) $request->query('customer_id', 0);
        $bindingId = (int) $request->query('product_binding_id', 0);
        $limit = max(1, min(20, (int) $request->query('limit', 8)));

        if ($customerId <= 0 || $bindingId <= 0) {
            return $this->success([], 'Previous rates.');
        }

        $stmt = Connection::ops()->prepare(
            'SELECT si.rate, si.unit_type, si.discount_pct, si.batch_no,
                    s.invoice_no, s.invoice_date, s.payment_mode,
                    pb.units_per_pack
             FROM sale_items si
             INNER JOIN sales s ON s.id = si.sale_id
             INNER JOIN product_bindings pb ON pb.id = si.product_binding_id
             WHERE s.client_id = :c AND s.branch_id = :b
               AND s.bill_mode = \'wholesale\'
               AND s.customer_id = :cust
               AND si.product_binding_id = :bind
               AND si.rate > 0
             ORDER BY s.invoice_date DESC, s.id DESC, si.id DESC
             LIMIT ' . $limit
        );
        $stmt->execute([
            'c'    => $request->clientId(),
            'b'    => $request->branchId(),
            'cust' => $customerId,
            'bind' => $bindingId,
        ]);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rate = (float) $row['rate'];
            $upp = max(1, (int) ($row['units_per_pack'] ?? 1));
            $unitType = strtolower((string) ($row['unit_type'] ?? 'unit'));
            $packRate = $unitType === 'pack' ? round($rate * $upp, 2) : round($rate, 2);
            $rows[] = [
                'sale_rate'     => $packRate,
                'discount_pct'  => (float) ($row['discount_pct'] ?? 0),
                'batch_no'      => $row['batch_no'] ?? null,
                'invoice_no'    => (string) ($row['invoice_no'] ?? ''),
                'invoice_date'  => (string) ($row['invoice_date'] ?? ''),
                'payment_mode'  => $row['payment_mode'] ?? null,
            ];
        }

        return $this->success($rows, 'Previous rates.');
    }

    public function searchProducts(Request $request): Response
    {
        $term = trim((string) $request->query('q', ''));
        $q = '%' . $term . '%';
        $genericId = (int) $request->query('generic_id', 0);
        $clientId = (int) $request->clientId();
        $branchId = (int) ($request->branchId() ?: 0);
        $master = $this->masterDb();

        // Client-wide catalog (all branches) so staff can see products purchased elsewhere.
        // Available qty / MRP must be THIS branch only — otherwise stock transfer looks like a no-op.
        $stockKey = static fn (string $a): string =>
            "CASE WHEN {$a}.master_product_id > 0 THEN CONCAT('m', {$a}.master_product_id) ELSE CONCAT('b', {$a}.id) END";
        $pbKey = $stockKey('pb');
        $stockBranchSql = $branchId > 0 ? ' AND ib.branch_id = :b_stock AND pb2.branch_id = :b_stock2' : '';

        $sql =
            "SELECT pb.id AS binding_id, pb.product_name, pb.manufacturer, pb.manufacturer_short,
                    pb.schedule_category, pb.gst_rate, pb.master_product_id,
                    pb.units_per_pack, pb.allow_loose, pb.shelf_location, pb.pack_size, pb.generic_id,
                    pb.hsn_code,
                    g.name AS generic_name,
                    COALESCE(st.avail, 0) AS available_qty,
                    COALESCE(st.expired, 0) AS expired_qty,
                    pb.mrp AS binding_mrp,
                    {$pbKey} AS stock_key
             FROM product_bindings pb
             LEFT JOIN `{$master}`.generics g ON g.id = pb.generic_id
             LEFT JOIN (
                SELECT {$stockKey('pb2')} AS stock_key,
                       SUM(CASE WHEN ib.expiry_date IS NULL OR ib.expiry_date >= CURDATE() THEN ib.quantity ELSE 0 END) AS avail,
                       SUM(CASE WHEN ib.expiry_date IS NOT NULL AND ib.expiry_date < CURDATE() THEN ib.quantity ELSE 0 END) AS expired
                FROM inventory_batches ib
                INNER JOIN product_bindings pb2 ON pb2.id = ib.product_binding_id
                WHERE pb2.client_id = :c_stock AND pb2.is_active = 1 AND ib.quantity > 0{$stockBranchSql}
                GROUP BY stock_key
             ) st ON st.stock_key = {$pbKey}
             WHERE pb.client_id = :c AND pb.is_active = 1";
        $bind = ['c' => $clientId, 'c_stock' => $clientId];
        if ($branchId > 0) {
            $bind['b_stock'] = $branchId;
            $bind['b_stock2'] = $branchId;
        }

        if ($genericId > 0) {
            $sql .= ' AND pb.generic_id = :gid';
            $bind['gid'] = $genericId;
        } elseif ($term !== '') {
            // Name / generic / mfr, or any in-stock batch number (this branch when set).
            $batchBranchSql = $branchId > 0 ? ' AND ibs.branch_id = :b_batch' : '';
            $sql .= ' AND (
                pb.product_name LIKE :q OR g.name LIKE :q2 OR pb.manufacturer LIKE :q3
                OR EXISTS (
                    SELECT 1 FROM inventory_batches ibs
                    WHERE ibs.product_binding_id = pb.id
                      AND ibs.quantity > 0
                      AND ibs.batch_no LIKE :q4
                      AND (ibs.expiry_date IS NULL OR ibs.expiry_date >= CURDATE())
                      ' . $batchBranchSql . '
                )
            )';
            $bind['q'] = $q;
            $bind['q2'] = $q;
            $bind['q3'] = $q;
            $bind['q4'] = $q;
            if ($branchId > 0) {
                $bind['b_batch'] = $branchId;
            }
        }

        // Prefer this branch's binding, then in-stock, then name. Cap high so full catalog shows.
        $sql .= ' ORDER BY (pb.branch_id = :pref_b) DESC, (COALESCE(st.avail, 0) > 0) DESC, pb.product_name ASC LIMIT 500';
        $bind['pref_b'] = $branchId;

        $db = Connection::ops();
        $rows = $db->prepare($sql);
        $rows->execute($bind);
        $raw = $rows->fetchAll();

        // Only products with an unexpired batch in THIS branch can resolve a batch MRP.
        $mrpBranchSql = $branchId > 0 ? ' AND ib.branch_id = :b AND pb3.branch_id = :b2' : '';
        $keySt = $db->prepare(
            "SELECT DISTINCT {$stockKey('pb3')} FROM inventory_batches ib
             INNER JOIN product_bindings pb3 ON pb3.id = ib.product_binding_id
             WHERE pb3.client_id = :c AND ib.quantity > 0
               AND (ib.expiry_date IS NULL OR ib.expiry_date >= CURDATE()){$mrpBranchSql}"
        );
        $keyBind = ['c' => $clientId];
        if ($branchId > 0) {
            $keyBind['b'] = $branchId;
            $keyBind['b2'] = $branchId;
        }
        $keySt->execute($keyBind);
        $mrpKeys = array_flip(array_map('strval', $keySt->fetchAll(\PDO::FETCH_COLUMN)));

        // MRP: purchase-line strip MRP of the earliest-expiry batch, else batch unit MRP × pack, else binding MRP.
        $mrpStmts = [];
        foreach (['master' => 'pb3.master_product_id = :k', 'binding' => 'pb3.id = :k'] as $kind => $match) {
            $mrpStmts[$kind] = [
                $db->prepare(
                    "SELECT ROUND(pi.mrp, 2)
                     FROM inventory_batches ib
                     INNER JOIN product_bindings pb3 ON pb3.id = ib.product_binding_id
                     INNER JOIN purchase_items pi ON pi.product_binding_id = pb3.id
                       AND pi.batch_no = ib.batch_no AND pi.mrp > 0
                     INNER JOIN purchases pur ON pur.id = pi.purchase_id AND pur.client_id = :c2
                     WHERE pb3.client_id = :c AND ib.quantity > 0 AND {$match}
                       AND (ib.expiry_date IS NULL OR ib.expiry_date >= CURDATE()){$mrpBranchSql}
                     ORDER BY (ib.expiry_date IS NULL), ib.expiry_date ASC,
                              pur.invoice_date DESC, pur.id DESC, pi.id DESC
                     LIMIT 1"
                ),
                $db->prepare(
                    "SELECT ROUND(NULLIF(ib.mrp, 0) * GREATEST(1, COALESCE(pb3.units_per_pack, CAST(:upp AS SIGNED), 1)), 2)
                     FROM inventory_batches ib
                     INNER JOIN product_bindings pb3 ON pb3.id = ib.product_binding_id
                     WHERE pb3.client_id = :c AND ib.quantity > 0 AND {$match}
                       AND (ib.expiry_date IS NULL OR ib.expiry_date >= CURDATE()){$mrpBranchSql}
                     ORDER BY (ib.expiry_date IS NULL), ib.expiry_date ASC LIMIT 1"
                ),
            ];
        }

        // One row per product (master_product_id), preferring current-branch binding.
        $seen = [];
        $data = [];
        foreach ($raw as $row) {
            $mp = (int) ($row['master_product_id'] ?? 0);
            $key = $mp > 0 ? 'm' . $mp : 'b' . (int) $row['binding_id'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $mrp = $row['binding_mrp'];
            if (isset($mrpKeys[(string) $row['stock_key']])) {
                [$purchaseMrp, $batchMrp] = $mrpStmts[$mp > 0 ? 'master' : 'binding'];
                $k = $mp > 0 ? $mp : (int) $row['binding_id'];
                $pBind = ['c' => $clientId, 'c2' => $clientId, 'k' => $k];
                $bBind = ['c' => $clientId, 'k' => $k, 'upp' => (int) ($row['units_per_pack'] ?? 1)];
                if ($branchId > 0) {
                    $pBind['b'] = $pBind['b2'] = $branchId;
                    $bBind['b'] = $bBind['b2'] = $branchId;
                }
                $purchaseMrp->execute($pBind);
                $v = $purchaseMrp->fetchColumn();
                if ($v === false || $v === null) {
                    $batchMrp->execute($bBind);
                    $v = $batchMrp->fetchColumn();
                }
                if ($v !== false && $v !== null) {
                    $mrp = $v;
                }
            }

            $row['available_qty'] = (int) ($row['available_qty'] ?? 0);
            $row['expired_qty'] = (int) ($row['expired_qty'] ?? 0);
            $row['is_expired'] = $row['available_qty'] <= 0 && $row['expired_qty'] > 0;
            $row['mrp'] = $mrp;
            unset($row['binding_mrp'], $row['stock_key']);
            $data[] = $row;
        }

        return $this->success($data, 'Products.');
    }

    /**
     * Resolve a scanned barcode (= inventory batch id) to a billing product
     * with the exact batch. Used by the sales form scanner.
     */
    public function scan(Request $request): Response
    {
        $code = preg_replace('/\D/', '', (string) $request->query('code', ''));
        $batchId = (int) ltrim($code, '0');
        if ($batchId <= 0) {
            throw HttpException::validation(['code' => ['Invalid barcode.']]);
        }

        $master = $this->masterDb();
        $stmt = Connection::ops()->prepare(
            "SELECT pb.id AS binding_id, pb.product_name, pb.manufacturer, pb.manufacturer_short,
                    pb.schedule_category, pb.gst_rate, pb.master_product_id,
                    pb.units_per_pack, pb.allow_loose, pb.shelf_location, pb.pack_size, pb.generic_id,
                    pb.hsn_code,
                    ib.id AS batch_id, ib.batch_no, ib.expiry_date, ib.mrp AS unit_mrp, ib.ptr, ib.pts,
                    ib.purchase_rate, ib.quantity AS available_qty,
                    g.name AS generic_name
             FROM inventory_batches ib
             JOIN product_bindings pb ON pb.id = ib.product_binding_id
             LEFT JOIN `{$master}`.generics g ON g.id = pb.generic_id
             WHERE ib.id = :id AND ib.client_id = :c AND ib.branch_id = :b LIMIT 1"
        );
        $stmt->execute(['id' => $batchId, 'c' => $request->clientId(), 'b' => $request->branchId()]);
        $row = $stmt->fetch();
        if (!$row) {
            // Allow scanning batches from any branch of this client (shared catalog).
            $stmt = Connection::ops()->prepare(
                "SELECT pb.id AS binding_id, pb.product_name, pb.manufacturer, pb.manufacturer_short,
                        pb.schedule_category, pb.gst_rate, pb.master_product_id,
                        pb.units_per_pack, pb.allow_loose, pb.shelf_location, pb.pack_size, pb.generic_id,
                        pb.hsn_code,
                        ib.id AS batch_id, ib.batch_no, ib.expiry_date, ib.mrp AS unit_mrp, ib.ptr, ib.pts,
                        ib.purchase_rate, ib.quantity AS available_qty,
                        g.name AS generic_name
                 FROM inventory_batches ib
                 JOIN product_bindings pb ON pb.id = ib.product_binding_id
                 LEFT JOIN `{$master}`.generics g ON g.id = pb.generic_id
                 WHERE ib.id = :id AND ib.client_id = :c LIMIT 1"
            );
            $stmt->execute(['id' => $batchId, 'c' => $request->clientId()]);
            $row = $stmt->fetch();
        }
        if (!$row) {
            throw HttpException::notFound('Barcode not recognised in this branch.');
        }
        $row['mrp'] = $row['unit_mrp'];
        $row['binding_id'] = (int) $row['binding_id'];
        $row['batch_id'] = (int) $row['batch_id'];
        $row['available_qty'] = (int) $row['available_qty'];
        $row['units_per_pack'] = max(1, (int) ($row['units_per_pack'] ?? 1));
        $row['gst_rate'] = (float) ($row['gst_rate'] ?? 0);
        $row['unit_mrp'] = (float) ($row['unit_mrp'] ?? 0);
        $row['ptr'] = (float) ($row['ptr'] ?? 0);
        $row['pts'] = (float) ($row['pts'] ?? 0);
        $row['purchase_rate'] = (float) ($row['purchase_rate'] ?? 0);
        $row['is_expired'] = InventoryService::isBatchExpired($row['expiry_date'] ?? null);
        if ($row['is_expired']) {
            throw HttpException::validation([
                'code' => ['This batch is expired and cannot be sold.'],
            ]);
        }
        if ($row['available_qty'] <= 0) {
            throw HttpException::validation([
                'code' => ['This barcode batch has no stock left.'],
            ]);
        }
        return $this->success($row, 'Product resolved.');
    }

    /** Available batches (B.No, expiry, MRP, shelf, stock) for a bound product. */
    public function batches(Request $request): Response
    {
        $bindingId = (int) $request->attribute('id');
        $binding = Connection::ops()->prepare(
            'SELECT id, units_per_pack FROM product_bindings WHERE id = :id AND client_id = :c AND branch_id = :b LIMIT 1'
        );
        $binding->execute(['id' => $bindingId, 'c' => $request->clientId(), 'b' => $request->branchId()]);
        $b = $binding->fetch();
        if (!$b) {
            throw HttpException::notFound('Product not found in your branch.');
        }
        $upp = max(1, (int) $b['units_per_pack']);

        // Batches are per product_binding only — never pool by master_product_id (wrong master links merge unrelated products).
        $stmt = Connection::ops()->prepare(
            'SELECT ib.id AS batch_id, ib.batch_no, ib.expiry_date, ib.mrp, ib.ptr, ib.pts, ib.purchase_rate, ib.quantity, ib.shelf_location
             FROM inventory_batches ib
             WHERE ib.client_id = :c AND ib.branch_id = :b AND ib.product_binding_id = :p AND ib.quantity > 0
             ORDER BY (ib.expiry_date IS NULL), ib.expiry_date ASC'
        );
        $stmt->execute([
            'c' => $request->clientId(),
            'b' => $request->branchId(),
            'p' => $bindingId,
        ]);
        $batches = $stmt->fetchAll();

        $purchaseStmt = Connection::ops()->prepare(
            'SELECT pi.purchase_rate, pi.mrp, pi.ptr, pi.pts, pi.net_rate, pi.units_per_pack, pi.scheme_pct, pi.discount_pct
             FROM purchase_items pi
             INNER JOIN purchases p ON p.id = pi.purchase_id
             WHERE p.client_id = :c AND p.branch_id = :b
               AND pi.product_binding_id = :bind
               AND pi.batch_no = :batch
             ORDER BY p.invoice_date DESC, p.id DESC, pi.id DESC
             LIMIT 1'
        );

        foreach ($batches as &$batch) {
            // MRP/cost stored per base (loose) unit; PTR/PTS per pack.
            $batch['unit_mrp'] = round((float) $batch['mrp'], 2);
            $batch['purchase_rate'] = round((float) $batch['purchase_rate'], 2);
            $batch['ptr'] = round((float) $batch['ptr'], 2);
            $batch['pts'] = round((float) $batch['pts'], 2);
            $batch['units_per_pack'] = $upp;
            $batch['packs'] = $upp > 0 ? intdiv((int) $batch['quantity'], $upp) : (int) $batch['quantity'];
            $batch['pack_mrp'] = round($batch['unit_mrp'] * $upp, 2);
            $batch['pack_purchase_rate'] = round($batch['purchase_rate'] * $upp, 2);
            $batch['is_expired'] = InventoryService::isBatchExpired($batch['expiry_date'] ?? null);

            $purchaseStmt->execute([
                'c'     => $request->clientId(),
                'b'     => $request->branchId(),
                'bind'  => $bindingId,
                'batch' => (string) $batch['batch_no'],
            ]);
            $purchase = $purchaseStmt->fetch();
            if ($purchase) {
                $piUpp = max(1, (int) ($purchase['units_per_pack'] ?? $upp));
                $batch['pack_mrp'] = round((float) $purchase['mrp'], 2);
                $batch['pack_purchase_rate'] = round((float) $purchase['purchase_rate'], 2);
                $batch['unit_mrp'] = round($batch['pack_mrp'] / $piUpp, 2);
                if ((float) $purchase['ptr'] > 0) {
                    $batch['ptr'] = round((float) $purchase['ptr'], 2);
                }
                if ((float) $purchase['pts'] > 0) {
                    $batch['pts'] = round((float) $purchase['pts'], 2);
                }
                $batch['pack_net_cost'] = round((float) $purchase['net_rate'] * $piUpp, 4);
                $batch['scheme_pct'] = round((float) ($purchase['scheme_pct'] ?? 0), 2);
                $batch['discount_pct'] = round((float) ($purchase['discount_pct'] ?? 0), 2);
            } else {
                $batch['pack_net_cost'] = $batch['pack_purchase_rate'];
                $batch['scheme_pct'] = 0;
                $batch['discount_pct'] = 0;
            }
        }
        unset($batch);

        return $this->success($batches, 'Batches.');
    }

    /** Substitutes (same generic) available, flagged with branch stock. */
    public function substitutes(Request $request): Response
    {
        $bindingId = (int) $request->attribute('id');
        $binding = Connection::ops()->prepare(
            'SELECT master_product_id FROM product_bindings WHERE id = :id AND client_id = :c LIMIT 1'
        );
        $binding->execute(['id' => $bindingId, 'c' => $request->clientId()]);
        $row = $binding->fetch();
        if (!$row) {
            throw HttpException::notFound('Product not found.');
        }

        $subs = (new \App\Models\Product())->substitutes((int) $row['master_product_id']);

        // Annotate with this branch's binding + sellable stock where available.
        $avail = InventoryService::sellableQtyExpr('pb');
        foreach ($subs as &$sub) {
            $b = Connection::ops()->prepare(
                "SELECT pb.id AS binding_id, {$avail} AS available_qty
                 FROM product_bindings pb
                 WHERE pb.client_id = :c AND pb.branch_id = :b AND pb.master_product_id = :mp LIMIT 1"
            );
            $b->execute(['c' => $request->clientId(), 'b' => $request->branchId(), 'mp' => $sub['id']]);
            $bound = $b->fetch();
            $sub['binding_id'] = $bound['binding_id'] ?? null;
            $sub['available_qty'] = (int) ($bound['available_qty'] ?? 0);
        }
        unset($sub);

        return $this->success($subs, 'Substitutes.');
    }

    public function invoice(Request $request): Response
    {
        $sale = $this->findSale($request);
        $pdf = (new InvoiceService())->forSale($sale);

        return Response::raw($pdf, 'application/pdf', 200, [
            'Content-Disposition' => 'inline; filename="' . ($sale['invoice_no'] ?? 'invoice') . '.pdf"',
        ]);
    }

    /** Dual print — Customer Copy + Shop Copy (Retail sales_print_dual). */
    public function invoiceDual(Request $request): Response
    {
        $sale = $this->findSale($request);
        $pdf = (new InvoiceService())->forSaleDual($sale);

        return Response::raw($pdf, 'application/pdf', 200, [
            'Content-Disposition' => 'inline; filename="' . ($sale['invoice_no'] ?? 'invoice') . '-dual.pdf"',
        ]);
    }

    /** Dot matrix print — 80-column text bill page (Retail sales_print_dotmatrix). */
    public function invoiceDotMatrix(Request $request): Response
    {
        $sale = $this->findSale($request);
        $html = (new InvoiceService())->forSaleDotMatrix($sale);

        return Response::raw($html, 'text/html; charset=utf-8');
    }

    /** Send bill PDF on WhatsApp (Retail medicin_bill template). */
    public function whatsapp(Request $request): Response
    {
        $saleId = (int) $request->attribute('id');
        $result = (new \App\Services\WhatsAppBillService())->sendSaleBill($saleId, $request->clientId());
        return $this->success($result, $result['message'] ?? 'WhatsApp bill sent.');
    }

    private function findSale(Request $request): array
    {
        $sale = (new Sale())->withItems((int) $request->attribute('id'));
        if (!$sale || (int) $sale['client_id'] !== $request->clientId()) {
            throw HttpException::notFound('Sale not found.');
        }
        return (new \App\Services\SaleDisplayService())->enrichSale($sale);
    }
}
