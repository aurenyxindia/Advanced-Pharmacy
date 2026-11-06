<?php

/**
 * CLI: import a Medivision staging JSON (built offline from the Medivision backup) into ONE client/branch.
 *
 * Usage: ea-php82 bin/import_medivision.php <json_path> <client_id> <branch_id> [--commit]
 *
 * Without --commit everything runs inside transactions and is rolled back (dry run).
 * Refuses to run when the target branch already has products, stock, sales, purchases or payments,
 * or when client/branch do not match the JSON header.
 *
 * Bills are inserted as history only (no stock movement). Current stock is loaded once as
 * opening stock on the JSON stock_date.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\App;
use App\Database\Connection;
use App\Services\InventoryService;
use App\Services\PaymentSettlementService;

new App(dirname(__DIR__));

$path = $argv[1] ?? '';
$clientId = (int) ($argv[2] ?? 0);
$branchId = (int) ($argv[3] ?? 0);
$commit = in_array('--commit', $argv, true);

if ($path === '' || !is_file($path) || $clientId <= 0 || $branchId <= 0) {
    fwrite(STDERR, "Usage: import_medivision.php <json_path> <client_id> <branch_id> [--commit]\n");
    exit(1);
}

$data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
if ((int) ($data['client_id'] ?? 0) !== $clientId || (int) ($data['branch_id'] ?? 0) !== $branchId) {
    fwrite(STDERR, "JSON is for client {$data['client_id']} / branch {$data['branch_id']} — refusing.\n");
    exit(1);
}

$ho = Connection::ho();
$br = $ho->prepare('SELECT id FROM branches WHERE id = :b AND client_id = :c');
$br->execute(['b' => $branchId, 'c' => $clientId]);
if (!$br->fetchColumn()) {
    fwrite(STDERR, "Branch $branchId does not belong to client $clientId.\n");
    exit(1);
}
$u = $ho->prepare("SELECT id FROM users WHERE client_id = :c ORDER BY (role = 'ho_admin') DESC, id ASC LIMIT 1");
$u->execute(['c' => $clientId]);
$userId = (int) ($u->fetchColumn() ?: 0) ?: null;

$ops = Connection::ops();
$master = Connection::master();

foreach (['product_bindings', 'inventory_batches', 'sales', 'purchases', 'payment_vouchers'] as $t) {
    $st = $ops->prepare("SELECT COUNT(*) FROM $t WHERE client_id = :c AND branch_id = :b");
    $st->execute(['c' => $clientId, 'b' => $branchId]);
    if ((int) $st->fetchColumn() > 0) {
        fwrite(STDERR, "Target already has rows in $t — refusing to import twice.\n");
        exit(1);
    }
}
$st = $ops->prepare('SELECT COUNT(*) FROM customers WHERE client_id = :c');
$st->execute(['c' => $clientId]);
if ((int) $st->fetchColumn() > 0) {
    fwrite(STDERR, "Client already has customers — refusing to import twice.\n");
    exit(1);
}

(new PaymentSettlementService())->ensureSchema();

$now = date('Y-m-d H:i:s');
$norm = static fn (?string $s): string => strtoupper(preg_replace('/\s+/', ' ', trim((string) $s)) ?? '');
$cut = static fn (mixed $s, int $n): ?string => $s === null || $s === '' ? null : mb_substr((string) $s, 0, $n);
$stats = [];
$inc = static function (string $k, float $by = 1) use (&$stats): void {
    $stats[$k] = round(($stats[$k] ?? 0) + $by, 2);
};

$master->beginTransaction();
$ops->beginTransaction();
try {
    // ------------------------------------------------------------ products -> master + bindings
    $existing = [];
    foreach ($master->query('SELECT id, name, units_per_pack, created_by_client FROM products WHERE is_active = 1') as $r) {
        $existing[$norm($r['name']) . '|' . (int) $r['units_per_pack']][] = (int) $r['id'];
    }
    $insProduct = $master->prepare(
        'INSERT INTO products
            (name, product_type, manufacturer, manufacturer_short, schedule_category, product_category, hsn_code,
             default_gst_rate, mrp, pack_size, packing_desc, units_per_pack, allow_loose, uom, composition,
             is_active, created_by_client, created_at, updated_at)
         VALUES
            (:name, \'Medicine\', :mfg, :mfg_short, :sched, \'Allopathy\', :hsn,
             :gst, :mrp, :pack, :packing, :upp, :loose, \'NOS\', :comp,
             1, :client, :now, :now2)'
    );
    $insBinding = $ops->prepare(
        'INSERT INTO product_bindings
            (client_id, branch_id, master_product_id, product_name, manufacturer, manufacturer_short, schedule_category,
             product_category, gst_rate, ptr, pts, mrp, units_per_pack, allow_loose, pack_size, hsn_code,
             reorder_level, is_active, created_at, updated_at)
         VALUES
            (:c, :b, :mp, :name, :mfg, :mfg_short, :sched,
             \'Allopathy\', :gst, 0, 0, :mrp, :upp, :loose, :pack, :hsn,
             0, 1, :now, :now2)'
    );
    $bindingOf = [];
    $productOf = [];
    $usedMaster = [];
    foreach ($data['products'] as $p) {
        $upp = max(1, (int) $p['units_per_pack']);
        $key = $norm($p['name']) . '|' . $upp;
        $masterId = null;
        $cands = array_values(array_diff($existing[$key] ?? [], array_keys($usedMaster)));
        if (count($cands) === 1) {
            $masterId = $cands[0];
            $inc('products_matched_master');
        } else {
            $insProduct->execute([
                'name' => $cut($p['name'], 200), 'mfg' => $cut($p['manufacturer'], 150), 'mfg_short' => $cut($p['manufacturer_short'], 20),
                'sched' => $p['schedule_category'], 'hsn' => $cut($p['hsn_code'], 20), 'gst' => $p['gst_rate'],
                'mrp' => $p['mrp'], 'pack' => $cut($p['pack_size'], 40), 'packing' => $cut($p['unit'], 120), 'upp' => $upp,
                'loose' => $upp > 1 ? 1 : 0, 'comp' => $p['composition'], 'client' => $clientId,
                'now' => $now, 'now2' => $now,
            ]);
            $masterId = (int) $master->lastInsertId();
            $inc('products_created_master');
        }
        $usedMaster[$masterId] = true;
        $insBinding->execute([
            'c' => $clientId, 'b' => $branchId, 'mp' => $masterId, 'name' => $cut($p['name'], 200),
            'mfg' => $cut($p['manufacturer'], 150), 'mfg_short' => $cut($p['manufacturer_short'], 20), 'sched' => $p['schedule_category'],
            'gst' => $p['gst_rate'], 'mrp' => $p['mrp'], 'upp' => $upp, 'loose' => $upp > 1 ? 1 : 0,
            'pack' => $cut($p['pack_size'], 40), 'hsn' => $cut($p['hsn_code'], 20), 'now' => $now, 'now2' => $now,
        ]);
        $bindingOf[(int) $p['mv_product_id']] = (int) $ops->lastInsertId();
        $productOf[(int) $p['mv_product_id']] = $p;
        $inc('bindings');
    }
    $bind = static function (int $mvId) use ($bindingOf): int {
        if (!isset($bindingOf[$mvId])) {
            throw new RuntimeException("Unknown Medivision product $mvId");
        }
        return $bindingOf[$mvId];
    };

    // ------------------------------------------------------------ customers
    $insCustomer = $ops->prepare(
        'INSERT INTO customers
            (client_id, name, phone, email, address, dob, gstin, credit_days, state, city, area,
             customer_type, gender, gst_registered, created_at, updated_at)
         VALUES
            (:c, :name, :phone, :email, :address, :dob, :gstin, :cd, :state, :city, :area,
             \'retail\', :gender, :gstreg, :now, :now2)'
    );
    $customerByKey = [];
    $customerOf = [];
    foreach ($data['customers'] as $cu) {
        if (isset($cu['key'])) {
            $insCustomer->execute([
                'c' => $clientId, 'name' => $cut($cu['name'], 150), 'phone' => $cut($cu['phone'], 20), 'email' => $cut($cu['email'], 150),
                'address' => $cut($cu['address'], 255), 'dob' => $cu['dob'], 'gstin' => $cut($cu['gstin'], 20), 'cd' => (int) $cu['credit_days'],
                'state' => $cu['state'], 'city' => $cu['city'], 'area' => $cu['area'] !== null ? mb_substr($cu['area'], 0, 120) : null,
                'gender' => $cu['gender'], 'gstreg' => $cu['gstin'] ? 1 : 0, 'now' => $now, 'now2' => $now,
            ]);
            $customerByKey[$cu['key']] = (int) $ops->lastInsertId();
            $customerOf[$cu['ref']] = $customerByKey[$cu['key']];
            $inc('customers');
        }
    }
    foreach ($data['customers'] as $cu) {
        if (isset($cu['dup_of'])) {
            $customerOf[$cu['ref']] = $customerByKey[$cu['dup_of']] ?? null;
        }
    }

    // ------------------------------------------------------------ distributors (master, client-scoped)
    $insDist = $master->prepare(
        'INSERT INTO distributors
            (client_id, name, phone, email, state, gstin, gst_state_code, address, dl_no1, dl_no2, dl_no3, dl_no4,
             gst_type, credit_limit_days, is_general, is_active, created_at, updated_at)
         VALUES
            (:c, :name, :phone, :email, :state, :gstin, :gsc, :address, :dl1, :dl2, :dl3, :dl4,
             \'GST\', :cld, 0, 1, :now, :now2)'
    );
    $distOf = [];
    $distName = [];
    foreach ($data['distributors'] as $d) {
        $insDist->execute([
            'c' => $clientId, 'name' => $cut($d['name'], 150), 'phone' => $cut($d['phone'], 20), 'email' => $cut($d['email'], 150),
            'state' => $d['state'], 'gstin' => $cut($d['gstin'], 20), 'gsc' => $d['gst_state_code'], 'address' => $cut($d['address'], 255),
            'dl1' => $cut($d['dl_no1'], 60), 'dl2' => $cut($d['dl_no2'], 60), 'dl3' => $cut($d['dl_no3'], 60), 'dl4' => $cut($d['dl_no4'], 60),
            'cld' => $d['credit_limit_days'], 'now' => $now, 'now2' => $now,
        ]);
        $distOf[(int) $d['acc']] = (int) $master->lastInsertId();
        $distName[(int) $d['acc']] = $d['name'];
        $inc('distributors');
    }

    // ------------------------------------------------------------ opening stock (current Medivision stock)
    $inventory = new InventoryService();
    $stockDate = (string) $data['stock_date'];
    $batchOf = [];
    foreach ($data['opening_stock'] as $o) {
        $bid = $bind((int) $o['mv_product_id']);
        $batchId = $inventory->addStock(
            $clientId,
            $branchId,
            $bid,
            (string) $cut($o['batch_no'], 60),
            $o['expiry_date'],
            (float) $o['mrp'],
            (float) $o['purchase_rate'],
            (int) $o['quantity'],
            null,
            0,
            0,
            'opening',
            $stockDate,
            'opening',
            null,
            'Medivision closing stock ' . date('d-m-Y', strtotime($stockDate))
        );
        $batchOf[$bid . '|' . $cut($o['batch_no'], 60)] = $batchId;
        $inc('opening_batches');
        $inc('opening_units', (int) $o['quantity']);
    }

    // ------------------------------------------------------------ purchases (history only)
    $insPurchase = $ops->prepare(
        'INSERT INTO purchases
            (client_id, branch_id, po_no, po_seq, supplier_id, supplier_name, invoice_no, invoice_date,
             cash_credit, due_date, cd_mode, cd_pct, cd_amount, gross_amount, scheme_amount, discount_amount,
             gst_amount, net_amount, debit_deduction_amt, bill_net_before_debit,
             paid, balance, payment_status, created_by, created_at)
         VALUES
            (:c, :b, :po, :seq, :sid, :sname, :inv, :idate,
             :cc, :due, \'Percentage\', 0, 0, :gross, :scheme, :disc,
             :gst, :net, :dn, :before_dn,
             :paid, :bal, :status, :by, :created)'
    );
    $insPItem = $ops->prepare(
        'INSERT INTO purchase_items
            (purchase_id, product_binding_id, product_name, hsn_code, batch_no, expiry_date, quantity, units_per_pack,
             free_quantity, purchase_rate, mrp, ptr, pts, scheme_pct, discount_pct, gst_rate, net_rate, line_total)
         VALUES
            (:pid, :bid, :name, :hsn, :batch, :exp, :qty, :upp,
             :free, :rate, :mrp, :ptr, 0, :scheme, :disc, :gst, :net_rate, :total)'
    );
    $purchaseOf = [];
    foreach ($data['purchases'] as $p) {
        $acc = (int) $p['supplier_acc'];
        $insPurchase->execute([
            'c' => $clientId, 'b' => $branchId, 'po' => $p['po_no'], 'seq' => (int) $p['po_seq'],
            'sid' => $distOf[$acc] ?? null, 'sname' => $distName[$acc] ?? null,
            'inv' => $cut($p['invoice_no'], 60), 'idate' => $p['invoice_date'], 'cc' => $p['cash_credit'], 'due' => $p['due_date'],
            'gross' => $p['gross_amount'], 'scheme' => $p['scheme_amount'], 'disc' => $p['discount_amount'],
            'gst' => $p['gst_amount'], 'net' => $p['net_amount'], 'dn' => $p['debit_deduction_amt'],
            'before_dn' => $p['bill_net_before_debit'], 'paid' => $p['paid'], 'bal' => $p['balance'],
            'status' => $p['payment_status'], 'by' => $userId, 'created' => $p['created_at'],
        ]);
        $purchaseId = (int) $ops->lastInsertId();
        $purchaseOf[$p['mv_key']] = ['id' => $purchaseId] + $p;
        foreach ($p['items'] as $it) {
            $mv = (int) $it['mv_product_id'];
            $insPItem->execute([
                'pid' => $purchaseId, 'bid' => $bind($mv), 'name' => $cut($productOf[$mv]['name'], 200),
                'hsn' => $cut($productOf[$mv]['hsn_code'], 20), 'batch' => $cut($it['batch_no'], 60), 'exp' => $it['expiry_date'],
                'qty' => (int) $it['quantity'], 'upp' => (int) $it['units_per_pack'], 'free' => (int) $it['free_quantity'],
                'rate' => $it['purchase_rate'], 'mrp' => $it['mrp'], 'ptr' => $it['ptr'], 'scheme' => $it['scheme_pct'],
                'disc' => $it['discount_pct'], 'gst' => $it['gst_rate'], 'net_rate' => $it['net_rate'], 'total' => $it['line_total'],
            ]);
            $inc('purchase_lines');
        }
        $inc('purchases');
        $inc('purchase_net', (float) $p['net_amount']);
        $inc('purchase_balance', (float) $p['balance']);
    }

    // ------------------------------------------------------------ sales (history only)
    $insSale = $ops->prepare(
        'INSERT INTO sales
            (client_id, branch_id, sale_type, bill_mode, invoice_no, invoice_date, customer_id, customer_name, patient_name,
             contact_no, address, narration, doctor_name, gross_amount, taxable_amount, discount_amount, gst_amount,
             is_interstate, cgst, sgst, igst, net_amount, profit_amount, payment_mode, paid, balance, created_by, created_at)
         VALUES
            (:c, :b, \'Regular\', \'retail\', :inv, :idate, :cid, :cname, :pname,
             :contact, :address, :narr, :doctor, :gross, :taxable, :disc, :gst,
             0, :cgst, :sgst, 0, :net, :profit, :mode, :paid, :bal, :by, :created)'
    );
    $insSItem = $ops->prepare(
        'INSERT INTO sale_items
            (sale_id, product_binding_id, batch_id, product_name, manufacturer_short, hsn_code, pack, batch_no, expiry_date,
             schedule_category, quantity, rate, base_rate, net_rate, mrp, unit_type, discount_pct, gst_rate, taxable,
             cost_price, line_total, total_amt, profit)
         VALUES
            (:sid, :bid, :batch_id, :name, :mfg, :hsn, :pack, :batch, :exp,
             :sched, :qty, :rate, :rate2, :rate3, :mrp, \'unit\', :disc, :gst, :taxable,
             :cost, :total, :gross, :profit)'
    );
    foreach ($data['sales'] as $s) {
        $gst = (float) $s['gst_amount'];
        $cgst = round($gst / 2, 2);
        $insSale->execute([
            'c' => $clientId, 'b' => $branchId, 'inv' => $cut($s['invoice_no'], 60), 'idate' => $s['invoice_date'],
            'cid' => $s['customer_ref'] !== null ? ($customerOf[$s['customer_ref']] ?? null) : null,
            'cname' => $cut($s['customer_name'], 150), 'pname' => $cut($s['patient_name'], 150), 'contact' => $cut($s['contact_no'], 20),
            'address' => $cut($s['address'], 255), 'narr' => $cut($s['narration'], 255),
            'doctor' => $cut($s['doctor_name'], 150), 'gross' => $s['gross_amount'], 'taxable' => $s['taxable_amount'],
            'disc' => $s['discount_amount'], 'gst' => $gst, 'cgst' => $cgst, 'sgst' => round($gst - $cgst, 2),
            'net' => $s['net_amount'], 'profit' => $s['profit_amount'], 'mode' => $s['payment_mode'],
            'paid' => $s['paid'], 'bal' => $s['balance'], 'by' => $userId, 'created' => $s['created_at'],
        ]);
        $saleId = (int) $ops->lastInsertId();
        foreach ($s['items'] as $it) {
            $mv = (int) $it['mv_product_id'];
            $bid = $bind($mv);
            $p = $productOf[$mv];
            $insSItem->execute([
                'sid' => $saleId, 'bid' => $bid, 'batch_id' => $batchOf[$bid . '|' . $cut($it['batch_no'], 60)] ?? null,
                'name' => $cut($p['name'], 200), 'mfg' => $cut($p['manufacturer_short'], 20), 'hsn' => $cut($p['hsn_code'], 20),
                'pack' => $cut($p['pack_size'], 40), 'batch' => $cut($it['batch_no'], 60), 'exp' => $it['expiry_date'],
                'sched' => $p['schedule_category'], 'qty' => (int) $it['quantity'], 'rate' => $it['rate'],
                'rate2' => $it['rate'], 'rate3' => $it['rate'], 'mrp' => $it['mrp'], 'disc' => $it['discount_pct'],
                'gst' => $it['gst_rate'], 'taxable' => $it['taxable'], 'cost' => $it['cost_price'],
                'total' => $it['line_total'], 'gross' => $it['total_amt'], 'profit' => $it['profit'],
            ]);
            $inc('sale_lines');
        }
        $inc('sales');
        $inc('sales_net', (float) $s['net_amount']);
        $inc('sales_balance', (float) $s['balance']);
    }

    // ------------------------------------------------------------ distributor payments + bill allocations
    $insVoucher = $ops->prepare(
        'INSERT INTO payment_vouchers
            (client_id, branch_id, voucher_no, voucher_type, party_type, party_name, amount, mode, ref_no,
             voucher_date, narration, created_by, created_at, updated_at)
         VALUES
            (:c, :b, :no, \'payment\', \'supplier\', :party, :amt, :mode, :ref,
             :vdate, :narr, :by, :created, :created2)'
    );
    $insAlloc = $ops->prepare(
        'INSERT INTO payment_allocations
            (client_id, branch_id, voucher_id, party_type, party_name, party_phone, source, bill_id, bill_no, bill_date,
             bill_amount, paid, remaining, mode, transaction_no, entry_date, created_by)
         VALUES
            (:c, :b, :v, \'distributor\', :party, NULL, \'purchase\', :bill, :bno, :bdate,
             :bamt, :paid, :rem, :mode, :txn, :edate, :by)'
    );
    foreach ($data['payments'] as $pay) {
        $acc = (int) $pay['supplier_acc'];
        $party = $distName[$acc] ?? ('Medivision supplier ' . $acc);
        $insVoucher->execute([
            'c' => $clientId, 'b' => $branchId, 'no' => $cut($pay['voucher_no'], 40), 'party' => $party, 'amt' => $pay['amount'],
            'mode' => $pay['mode'], 'ref' => $cut($pay['ref_no'], 60), 'vdate' => $pay['voucher_date'],
            'narr' => mb_substr((string) $pay['narration'], 0, 255), 'by' => $userId,
            'created' => $pay['created_at'], 'created2' => $pay['created_at'],
        ]);
        $voucherId = (int) $ops->lastInsertId();
        foreach ($pay['allocations'] as $al) {
            $pr = $purchaseOf[$al['purchase_mv_key']] ?? null;
            if ($pr === null) {
                continue;
            }
            $insAlloc->execute([
                'c' => $clientId, 'b' => $branchId, 'v' => $voucherId, 'party' => $party, 'bill' => $pr['id'],
                'bno' => $cut($pr['invoice_no'], 60), 'bdate' => $pr['invoice_date'], 'bamt' => $pr['net_amount'],
                'paid' => $al['paid'], 'rem' => $al['remaining'], 'mode' => $pay['mode'], 'txn' => (string) $cut($pay['ref_no'], 80),
                'edate' => $al['entry_date'] . ' 12:00:00', 'by' => $userId,
            ]);
            $inc('payment_allocations');
        }
        $inc('payment_vouchers');
        $inc('payment_amount', (float) $pay['amount']);
    }

    // ------------------------------------------------------------ verify inside the transaction
    $chk = static function (string $sql) use ($ops, $clientId, $branchId) {
        $st = $ops->prepare($sql);
        $st->execute(['c' => $clientId, 'b' => $branchId]);
        return $st->fetch(PDO::FETCH_ASSOC);
    };
    $stats['verify_stock'] = $chk('SELECT COUNT(*) batches, SUM(quantity) units, ROUND(SUM(quantity*purchase_rate),2) cost, ROUND(SUM(quantity*mrp),2) mrp FROM inventory_batches WHERE client_id=:c AND branch_id=:b');
    $stats['verify_aggregate'] = $chk('SELECT SUM(quantity) units FROM inventory_stock WHERE client_id=:c AND branch_id=:b');
    $stats['verify_purchases'] = $chk('SELECT COUNT(*) n, SUM(net_amount) net, SUM(balance) balance FROM purchases WHERE client_id=:c AND branch_id=:b');
    $stats['verify_sales'] = $chk('SELECT COUNT(*) n, SUM(net_amount) net, SUM(balance) balance FROM sales WHERE client_id=:c AND branch_id=:b');
    $stats['verify_ledger'] = $chk("SELECT COUNT(*) n, SUM(qty_in) qty FROM stock_ledger WHERE client_id=:c AND branch_id=:b AND txn_type='opening'");

    if ($commit) {
        $master->commit();
        $ops->commit();
        $stats['mode'] = 'COMMITTED';
    } else {
        $ops->rollBack();
        $master->rollBack();
        $stats['mode'] = 'DRY RUN (rolled back)';
    }
} catch (Throwable $e) {
    if ($ops->inTransaction()) {
        $ops->rollBack();
    }
    if ($master->inTransaction()) {
        $master->rollBack();
    }
    fwrite(STDERR, 'FAILED (rolled back): ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n");
    exit(1);
}

echo json_encode($stats, JSON_PRETTY_PRINT) . PHP_EOL;
