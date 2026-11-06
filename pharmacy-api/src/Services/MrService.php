<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Database\Connection;
use PDO;
use PDOException;

/**
 * MR (Medical Representative) module — Retail mr.php / mr_print.php parity.
 *
 * Doctor visits, wholesale orders / quotations against live branch stock, stock follow-ups,
 * sales monitor, daily call reports, outstanding follow-ups and wholesale customers.
 * Visits / orders / dashboard are scoped to the logged-in MR (Retail mr_login); admins see all.
 */
final class MrService
{
    private static bool $schemaReady = false;

    private const TABLES = [
        "CREATE TABLE IF NOT EXISTS mr_doctor_visits (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id BIGINT UNSIGNED NOT NULL,
            branch_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            mr_name VARCHAR(150) NULL,
            visit_date DATE NOT NULL,
            doctor_name VARCHAR(160) NOT NULL,
            clinic_name VARCHAR(160) NULL,
            specialty VARCHAR(120) NULL,
            area_name VARCHAR(120) NULL,
            contact_no VARCHAR(20) NULL,
            products_discussed TEXT NULL,
            indications TEXT NULL,
            dosage_notes TEXT NULL,
            clinical_info TEXT NULL,
            availability_notes TEXT NULL,
            competitor_info TEXT NULL,
            next_visit_date DATE NULL,
            outcome VARCHAR(80) NULL,
            remarks TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Active',
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_mrv_scope (client_id, branch_id, visit_date),
            KEY idx_mrv_user (client_id, user_id, visit_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS mr_orders (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id BIGINT UNSIGNED NOT NULL,
            branch_id BIGINT UNSIGNED NOT NULL,
            order_no VARCHAR(40) NOT NULL,
            order_date DATE NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            mr_name VARCHAR(150) NULL,
            customer_id BIGINT UNSIGNED NULL,
            customer_name VARCHAR(255) NOT NULL,
            customer_contact VARCHAR(60) NULL,
            customer_address TEXT NULL,
            customer_dl_no VARCHAR(120) NULL,
            expected_delivery_date DATE NULL,
            delivery_type VARCHAR(40) NULL,
            order_type VARCHAR(40) NOT NULL DEFAULT 'Wholesaler',
            is_dummy_bill TINYINT(1) NOT NULL DEFAULT 0,
            is_quotation TINYINT(1) NOT NULL DEFAULT 1,
            total_items INT NOT NULL DEFAULT 0,
            total_qty DECIMAL(12,2) NOT NULL DEFAULT 0,
            total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            status VARCHAR(40) NOT NULL DEFAULT 'Quotation',
            remarks TEXT NULL,
            sale_id BIGINT UNSIGNED NULL,
            sale_invoice_no VARCHAR(60) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_mro_no (client_id, order_no),
            KEY idx_mro_scope (client_id, branch_id, order_date),
            KEY idx_mro_customer (client_id, customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS mr_order_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL,
            client_id BIGINT UNSIGNED NOT NULL,
            product_binding_id BIGINT UNSIGNED NULL,
            batch_id BIGINT UNSIGNED NULL,
            product_name VARCHAR(255) NOT NULL,
            pack_size VARCHAR(80) NULL,
            batch_no VARCHAR(80) NULL,
            expiry_date DATE NULL,
            qty DECIMAL(12,2) NOT NULL DEFAULT 0,
            mrp DECIMAL(12,2) NOT NULL DEFAULT 0,
            rate DECIMAL(12,2) NOT NULL DEFAULT 0,
            amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            stock_qty DECIMAL(12,2) NULL,
            gst DECIMAL(8,2) NOT NULL DEFAULT 0,
            hsn VARCHAR(40) NULL,
            m_name VARCHAR(160) NULL,
            remarks VARCHAR(255) NULL,
            PRIMARY KEY (id),
            KEY idx_mroi_order (order_id),
            KEY idx_mroi_binding (client_id, product_binding_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS mr_stock_followups (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id BIGINT UNSIGNED NOT NULL,
            branch_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            mr_name VARCHAR(150) NULL,
            followup_date DATE NOT NULL,
            location_type VARCHAR(40) NOT NULL DEFAULT 'Pharmacy',
            location_name VARCHAR(160) NOT NULL,
            product_binding_id BIGINT UNSIGNED NULL,
            product_name VARCHAR(255) NOT NULL,
            available_qty DECIMAL(12,2) NULL,
            near_expiry_qty DECIMAL(12,2) NULL,
            scheme_notes TEXT NULL,
            stock_status VARCHAR(40) NULL,
            remarks TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_mrs_scope (client_id, branch_id, followup_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS mr_daily_reports (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id BIGINT UNSIGNED NOT NULL,
            branch_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            mr_name VARCHAR(150) NULL,
            report_date DATE NOT NULL,
            calls_planned INT NOT NULL DEFAULT 0,
            calls_done INT NOT NULL DEFAULT 0,
            doctor_visits INT NOT NULL DEFAULT 0,
            orders_taken INT NOT NULL DEFAULT 0,
            order_value DECIMAL(14,2) NOT NULL DEFAULT 0,
            stock_checks INT NOT NULL DEFAULT 0,
            sales_summary TEXT NULL,
            competitor_info TEXT NULL,
            outstanding_notes TEXT NULL,
            remarks TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_mrd_day (client_id, branch_id, report_date, user_id),
            KEY idx_mrd_scope (client_id, branch_id, report_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS mr_outstanding_followups (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id BIGINT UNSIGNED NOT NULL,
            branch_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            mr_name VARCHAR(150) NULL,
            followup_date DATE NOT NULL,
            customer_id BIGINT UNSIGNED NULL,
            customer_name VARCHAR(160) NOT NULL,
            outstanding_amt DECIMAL(14,2) NOT NULL DEFAULT 0,
            followup_note TEXT NULL,
            promised_date DATE NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'Open',
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_mros_scope (client_id, branch_id, followup_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    private PDO $db;

    public function __construct(
        private readonly int $clientId,
        private readonly int $branchId,
        private readonly ?int $userId,
        private readonly string $userName,
        private readonly bool $isAdmin,
    ) {
        if ($branchId <= 0) {
            throw HttpException::forbidden('A branch context is required.');
        }
        self::ensureSchema();
        $this->db = Connection::ops();
    }

    public static function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }
        $db = Connection::ops();
        foreach (self::TABLES as $sql) {
            try {
                $db->exec($sql);
            } catch (PDOException) {
                // Table may already exist with a compatible shape.
            }
        }
        self::$schemaReady = true;
    }

    /** Wholesale bill saved from an MR order (Do Sale) — mark the order Sold once. */
    public static function markSold(int $clientId, int $orderId, string $invoiceNo, int $saleId): void
    {
        try {
            Connection::ops()->prepare(
                "UPDATE mr_orders SET status = 'Sold', sale_id = :sid, sale_invoice_no = :inv, updated_at = NOW()
                 WHERE id = :id AND client_id = :c AND sale_id IS NULL"
            )->execute(['sid' => $saleId, 'inv' => $invoiceNo, 'id' => $orderId, 'c' => $clientId]);
        } catch (PDOException) {
            // MR tables may not exist on every install.
        }
    }

    // =================================================================== Dashboard

    /** @return array<string, int|float> */
    public function dashboard(): array
    {
        $today = date('Y-m-d');
        [$uSql, $uBind] = $this->userScope();
        $base = ['c' => $this->clientId, 'b' => $this->branchId, 'd' => $today] + $uBind;

        $one = function (string $sql, array $bind): array {
            $st = $this->db->prepare($sql);
            $st->execute($bind);
            return $st->fetch(PDO::FETCH_ASSOC) ?: [];
        };

        $visits = $one("SELECT COUNT(*) AS n FROM mr_doctor_visits
            WHERE client_id = :c AND branch_id = :b AND visit_date = :d AND status = 'Active'{$uSql}", $base);
        $orders = $one("SELECT COUNT(*) AS n, COALESCE(SUM(total_amount), 0) AS amt FROM mr_orders
            WHERE client_id = :c AND branch_id = :b AND order_date = :d{$uSql}", $base);
        $checks = $one("SELECT COUNT(*) AS n FROM mr_stock_followups
            WHERE client_id = :c AND branch_id = :b AND followup_date = :d{$uSql}", $base);
        $openOs = $one("SELECT COUNT(*) AS n FROM mr_outstanding_followups
            WHERE client_id = :c AND branch_id = :b AND status = 'Open'{$uSql}",
            ['c' => $this->clientId, 'b' => $this->branchId] + $uBind);
        $customers = $one("SELECT COUNT(*) AS n FROM customers WHERE client_id = :c AND customer_type = 'wholesale'",
            ['c' => $this->clientId]);

        return [
            'visits_today' => (int) ($visits['n'] ?? 0),
            'orders_today' => (int) ($orders['n'] ?? 0),
            'order_value_today' => round((float) ($orders['amt'] ?? 0), 2),
            'stock_checks_today' => (int) ($checks['n'] ?? 0),
            'open_outstanding' => (int) ($openOs['n'] ?? 0),
            'customers' => (int) ($customers['n'] ?? 0),
        ];
    }

    // =================================================================== Visits

    /** @return list<array<string,mixed>> */
    public function visits(?string $from, ?string $to): array
    {
        [$from, $to] = $this->range($from, $to);
        [$uSql, $uBind] = $this->userScope();
        $st = $this->db->prepare(
            "SELECT * FROM mr_doctor_visits
             WHERE client_id = :c AND branch_id = :b AND visit_date BETWEEN :f AND :t AND status = 'Active'{$uSql}
             ORDER BY visit_date DESC, id DESC LIMIT 500"
        );
        $st->execute(['c' => $this->clientId, 'b' => $this->branchId, 'f' => $from, 't' => $to] + $uBind);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string,mixed> $d */
    public function saveVisit(array $d): int
    {
        $doctor = $this->str($d['doctor_name'] ?? null, 160);
        if ($doctor === null) {
            throw HttpException::validation(['doctor_name' => ['Doctor name is required.']]);
        }
        if (!preg_match("/^[A-Za-z][A-Za-z .'-]*$/", $doctor)) {
            throw HttpException::validation(['doctor_name' => ['Doctor name should contain letters only.']]);
        }
        $contact = $this->phone($d['contact_no'] ?? null, 'contact_no');
        $visitDate = $this->date($d['visit_date'] ?? null) ?? date('Y-m-d');
        if ($visitDate > date('Y-m-d')) {
            throw HttpException::validation(['visit_date' => ['Visit date cannot be in the future.']]);
        }
        $nextVisit = $this->date($d['next_visit_date'] ?? null);
        if ($nextVisit !== null && $nextVisit < $visitDate) {
            throw HttpException::validation(['next_visit_date' => ['Next visit must be on or after the visit date.']]);
        }
        $this->db->prepare(
            'INSERT INTO mr_doctor_visits
                (client_id, branch_id, user_id, mr_name, visit_date, doctor_name, clinic_name, specialty, area_name,
                 contact_no, products_discussed, indications, dosage_notes, clinical_info, availability_notes,
                 competitor_info, next_visit_date, outcome, remarks, status, created_at)
             VALUES (:c, :b, :u, :mr, :vd, :dn, :cl, :sp, :ar, :cn, :pd, :ind, :dos, :ci, :av, :comp, :nv, :oc, :rm, \'Active\', NOW())'
        )->execute([
            'c' => $this->clientId,
            'b' => $this->branchId,
            'u' => $this->userId,
            'mr' => $this->userName,
            'vd' => $visitDate,
            'dn' => $doctor,
            'cl' => $this->str($d['clinic_name'] ?? null, 160),
            'sp' => $this->str($d['specialty'] ?? null, 120),
            'ar' => $this->str($d['area_name'] ?? null, 120),
            'cn' => $contact,
            'pd' => $this->str($d['products_discussed'] ?? null, 5000),
            'ind' => $this->str($d['indications'] ?? null, 5000),
            'dos' => $this->str($d['dosage_notes'] ?? null, 5000),
            'ci' => $this->str($d['clinical_info'] ?? null, 5000),
            'av' => $this->str($d['availability_notes'] ?? null, 5000),
            'comp' => $this->str($d['competitor_info'] ?? null, 5000),
            'nv' => $nextVisit,
            'oc' => $this->str($d['outcome'] ?? null, 80),
            'rm' => $this->str($d['remarks'] ?? null, 5000),
        ]);
        return (int) $this->db->lastInsertId();
    }

    // =================================================================== Live stock

    /**
     * Wholesale sellable stock, batch-wise (Retail getStock): packs > 0, not expired.
     * Rates are per pack; sale rate = first non-zero of PTR, PTS, purchase rate, MRP.
     *
     * @return list<array<string,mixed>>
     */
    public function stock(string $q): array
    {
        $sql = 'SELECT ib.id AS batch_id, pb.id AS binding_id, pb.product_name, pb.pack_size, pb.manufacturer,
                       pb.manufacturer_short, pb.product_category, pb.hsn_code, pb.gst_rate, pb.units_per_pack,
                       ib.batch_no, ib.expiry_date, ib.quantity, ib.mrp, ib.ptr, ib.pts, ib.purchase_rate,
                       COALESCE(NULLIF(ib.shelf_location, \'\'), pb.shelf_location) AS shelf
                FROM inventory_batches ib
                INNER JOIN product_bindings pb ON pb.id = ib.product_binding_id
                WHERE ib.client_id = :c AND ib.branch_id = :b AND ib.quantity > 0
                  AND (ib.expiry_date IS NULL OR ib.expiry_date >= CURDATE())';
        $bind = ['c' => $this->clientId, 'b' => $this->branchId];
        $q = trim($q);
        if ($q !== '') {
            $sql .= ' AND (pb.product_name LIKE :q1 OR ib.batch_no LIKE :q2 OR pb.manufacturer LIKE :q3)';
            $bind['q1'] = $bind['q2'] = $bind['q3'] = '%' . $q . '%';
        }
        $sql .= ' ORDER BY pb.product_name ASC, (ib.expiry_date IS NULL), ib.expiry_date ASC LIMIT 800';
        $st = $this->db->prepare($sql);
        $st->execute($bind);

        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $upp = max(1, (int) $r['units_per_pack']);
            $units = (int) $r['quantity'];
            $packs = intdiv($units, $upp);
            if ($packs <= 0) {
                continue;
            }
            $packMrp = round((float) $r['mrp'] * $upp, 2);
            $packCost = round((float) $r['purchase_rate'] * $upp, 2);
            $ptr = round((float) $r['ptr'], 2);
            $pts = round((float) $r['pts'], 2);
            $sale = $ptr > 0 ? $ptr : ($pts > 0 ? $pts : ($packCost > 0 ? $packCost : $packMrp));
            $out[] = [
                'binding_id' => (int) $r['binding_id'],
                'batch_id' => (int) $r['batch_id'],
                'p_name' => (string) $r['product_name'],
                'pack_size' => (string) ($r['pack_size'] ?? ''),
                'batch_no' => (string) $r['batch_no'],
                'expiry_date' => $r['expiry_date'],
                'qty' => $packs,
                'units_qty' => $units,
                'units_per_pack' => $upp,
                'mrp' => $packMrp,
                'purchase_rate' => $packCost,
                'ptr' => $ptr,
                'pts' => $pts,
                'sale_rate' => round($sale, 2),
                'hsn' => (string) ($r['hsn_code'] ?? ''),
                'gst' => (float) $r['gst_rate'],
                'm_name' => (string) ($r['manufacturer'] ?: ($r['manufacturer_short'] ?? '')),
                'category' => (string) ($r['product_category'] ?? ''),
                'shelf' => (string) ($r['shelf'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Previous rates for this customer + product (Retail getPreviousRates): past sales first,
     * then MR orders. Rates are per pack.
     *
     * @return array{previous_rate: float, history: list<array<string,mixed>>}
     */
    public function previousRates(int $customerId, string $customerName, int $bindingId, string $productName): array
    {
        $customerName = trim($customerName);
        $productName = trim($productName);
        if ($customerId <= 0 && $customerName === '') {
            throw HttpException::validation(['customer' => ['Customer is required.']]);
        }

        $history = [];
        $prev = 0.0;

        $where = ['s.client_id = :c', 's.branch_id = :b', 'si.rate > 0'];
        $bind = ['c' => $this->clientId, 'b' => $this->branchId];
        if ($customerId > 0) {
            $where[] = 's.customer_id = :cid';
            $bind['cid'] = $customerId;
        } else {
            $where[] = 's.customer_name = :cname';
            $bind['cname'] = $customerName;
        }
        if ($bindingId > 0) {
            $where[] = 'si.product_binding_id = :bind';
            $bind['bind'] = $bindingId;
        } elseif ($productName !== '') {
            $where[] = 'si.product_name = :pname';
            $bind['pname'] = $productName;
        }
        $st = $this->db->prepare(
            'SELECT s.invoice_date, s.invoice_no, si.product_name, si.batch_no, si.rate, si.unit_type, si.quantity,
                    pb.units_per_pack
             FROM sale_items si
             INNER JOIN sales s ON s.id = si.sale_id
             LEFT JOIN product_bindings pb ON pb.id = si.product_binding_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY s.invoice_date DESC, s.id DESC, si.id DESC
             LIMIT 25'
        );
        $st->execute($bind);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $upp = max(1, (int) ($r['units_per_pack'] ?? 1));
            $isPack = strtolower((string) ($r['unit_type'] ?? '')) === 'pack';
            $rate = $isPack ? round((float) $r['rate'] * $upp, 2) : round((float) $r['rate'], 2);
            $qty = $isPack ? round((float) $r['quantity'] / $upp, 2) : (float) $r['quantity'];
            $history[] = [
                'date' => substr((string) $r['invoice_date'], 0, 10),
                'ref_no' => (string) $r['invoice_no'],
                'p_name' => (string) $r['product_name'],
                'batch_no' => (string) ($r['batch_no'] ?? ''),
                'qty' => $qty,
                'sale_rate' => $rate,
                'source' => 'Sale',
            ];
            if ($prev <= 0 && $rate > 0) {
                $prev = $rate;
            }
        }

        $where = ['o.client_id = :c', 'o.branch_id = :b'];
        $bind = ['c' => $this->clientId, 'b' => $this->branchId];
        if ($customerId > 0) {
            $where[] = 'o.customer_id = :cid';
            $bind['cid'] = $customerId;
        } else {
            $where[] = 'o.customer_name = :cname';
            $bind['cname'] = $customerName;
        }
        if ($bindingId > 0) {
            $where[] = 'i.product_binding_id = :bind';
            $bind['bind'] = $bindingId;
        } elseif ($productName !== '') {
            $where[] = 'i.product_name = :pname';
            $bind['pname'] = $productName;
        }
        $st = $this->db->prepare(
            'SELECT o.order_date, o.order_no, i.product_name, i.batch_no, i.rate, i.qty
             FROM mr_orders o
             INNER JOIN mr_order_items i ON i.order_id = o.id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY o.order_date DESC, o.id DESC
             LIMIT 15'
        );
        $st->execute($bind);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rate = round((float) $r['rate'], 2);
            $history[] = [
                'date' => (string) $r['order_date'],
                'ref_no' => (string) $r['order_no'],
                'p_name' => (string) $r['product_name'],
                'batch_no' => (string) ($r['batch_no'] ?? ''),
                'qty' => (float) $r['qty'],
                'sale_rate' => $rate,
                'source' => 'MR Order',
            ];
            if ($prev <= 0 && $rate > 0) {
                $prev = $rate;
            }
        }

        return ['previous_rate' => $prev, 'history' => $history];
    }

    // =================================================================== Orders / Quotations

    public function nextOrderNo(): string
    {
        $prefix = 'MRQ-' . date('ymd') . '-';
        $st = $this->db->prepare(
            'SELECT order_no FROM mr_orders WHERE client_id = :c AND order_no LIKE :p ORDER BY id DESC LIMIT 1'
        );
        $st->execute(['c' => $this->clientId, 'p' => $prefix . '%']);
        $last = (string) ($st->fetchColumn() ?: '');
        $parts = explode('-', $last);
        $seq = $last !== '' ? ((int) end($parts)) + 1 : 1;
        return $prefix . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }

    /** @return list<array<string,mixed>> */
    public function orders(?string $from, ?string $to): array
    {
        [$from, $to] = $this->range($from, $to);
        [$uSql, $uBind] = $this->userScope();
        $st = $this->db->prepare(
            "SELECT * FROM mr_orders
             WHERE client_id = :c AND branch_id = :b AND order_date BETWEEN :f AND :t{$uSql}
             ORDER BY id DESC LIMIT 500"
        );
        $st->execute(['c' => $this->clientId, 'b' => $this->branchId, 'f' => $from, 't' => $to] + $uBind);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{order: array<string,mixed>, items: list<array<string,mixed>>} */
    public function order(int $id): array
    {
        $order = $this->findOrder($id);
        $st = $this->db->prepare('SELECT * FROM mr_order_items WHERE order_id = :id ORDER BY id ASC');
        $st->execute(['id' => $id]);
        return ['order' => $order, 'items' => $st->fetchAll(PDO::FETCH_ASSOC)];
    }

    /**
     * Save As Order (is_dummy_bill = 1) or Save As Quotation (is_quotation = 1).
     * Passing an `id` updates that order (header + lines replaced), like Retail re-save.
     *
     * @param array<string,mixed> $d
     * @return array{id:int, order_no:string, total_amount:float, status:string}
     */
    public function saveOrder(array $d): array
    {
        $customer = $this->str($d['customer_name'] ?? null, 255);
        if ($customer === null) {
            throw HttpException::validation(['customer_name' => ['Select a customer.']]);
        }
        $this->phone($d['customer_contact'] ?? null, 'customer_contact');
        $orderDate = $this->date($d['order_date'] ?? null);
        $expected = $this->date($d['expected_delivery_date'] ?? null);
        if ($orderDate !== null && $expected !== null && $expected < $orderDate) {
            throw HttpException::validation(['expected_delivery_date' => ['Expected delivery cannot be before the order date.']]);
        }

        $items = [];
        foreach ((array) ($d['items'] ?? []) as $it) {
            if (!is_array($it)) {
                continue;
            }
            $name = $this->str($it['product_name'] ?? null, 255);
            $qty = round((float) ($it['qty'] ?? 0), 2);
            if ($name === null || $qty <= 0) {
                continue;
            }
            $rate = round(max(0.0, (float) ($it['rate'] ?? 0)), 2);
            $mrp = round((float) ($it['mrp'] ?? 0), 2);
            if ($rate <= 0) {
                throw HttpException::validation(['items' => ["Enter a sale rate for {$name}."]]);
            }
            if ($mrp > 0 && $rate > $mrp) {
                throw HttpException::validation(['items' => ["Sale rate for {$name} cannot exceed MRP ({$mrp})."]]);
            }
            $items[] = [
                'binding' => (int) ($it['product_binding_id'] ?? 0) ?: null,
                'batch' => (int) ($it['batch_id'] ?? 0) ?: null,
                'name' => $name,
                'pack' => $this->str($it['pack_size'] ?? null, 80),
                'batch_no' => $this->str($it['batch_no'] ?? null, 80),
                'exp' => $this->date($it['expiry_date'] ?? null),
                'qty' => $qty,
                'mrp' => round((float) ($it['mrp'] ?? 0), 2),
                'rate' => $rate,
                'amount' => round($qty * $rate, 2),
                'stock' => isset($it['stock_qty']) && $it['stock_qty'] !== '' ? (float) $it['stock_qty'] : null,
                'gst' => round((float) ($it['gst'] ?? 0), 2),
                'hsn' => $this->str($it['hsn'] ?? null, 40),
                'mfr' => $this->str($it['m_name'] ?? null, 160),
                'remarks' => $this->str($it['remarks'] ?? null, 255),
            ];
        }
        if ($items === []) {
            throw HttpException::validation(['items' => ['Add at least one product line.']]);
        }

        $status = trim((string) ($d['status'] ?? '')) === 'Order' ? 'Order' : 'Quotation';
        $isOrder = $status === 'Order';
        $totalQty = array_sum(array_column($items, 'qty'));
        $totalAmt = round(array_sum(array_column($items, 'amount')), 2);

        $id = (int) ($d['id'] ?? 0);
        $existing = $id > 0 ? $this->findOrder($id) : null;
        if ($existing !== null && (int) ($existing['sale_id'] ?? 0) > 0) {
            throw HttpException::validation(['id' => ['This order is already sold (' . $existing['sale_invoice_no'] . ') and cannot be edited.']]);
        }

        $header = [
            'od' => $this->date($d['order_date'] ?? null) ?? date('Y-m-d'),
            'cid' => (int) ($d['customer_id'] ?? 0) ?: null,
            'cn' => $customer,
            'cc' => $this->str(preg_replace('/\s+/', ' ', (string) ($d['customer_contact'] ?? '')), 60),
            'ca' => $this->str($d['customer_address'] ?? null, 2000),
            'dl' => $this->str($d['customer_dl_no'] ?? null, 120),
            'edd' => $this->date($d['expected_delivery_date'] ?? null),
            'dt' => $this->str($d['delivery_type'] ?? null, 40) ?? 'Regular',
            'ot' => $this->str($d['order_type'] ?? null, 40) ?? 'Wholesaler',
            'dummy' => $isOrder ? 1 : 0,
            'quot' => $isOrder ? 0 : 1,
            'ti' => count($items),
            'tq' => $totalQty,
            'ta' => $totalAmt,
            'st' => $status,
            'rm' => $this->str($d['remarks'] ?? null, 5000),
        ];

        return Connection::transaction('ops', function (PDO $db) use ($existing, $header, $items, $status, $totalAmt): array {
            if ($existing !== null) {
                $orderId = (int) $existing['id'];
                $orderNo = (string) $existing['order_no'];
                $db->prepare(
                    'UPDATE mr_orders SET order_date = :od, customer_id = :cid, customer_name = :cn, customer_contact = :cc,
                        customer_address = :ca, customer_dl_no = :dl, expected_delivery_date = :edd, delivery_type = :dt,
                        order_type = :ot, is_dummy_bill = :dummy, is_quotation = :quot, total_items = :ti, total_qty = :tq,
                        total_amount = :ta, status = :st, remarks = :rm, updated_at = NOW()
                     WHERE id = :id'
                )->execute($header + ['id' => $orderId]);
                $db->prepare('DELETE FROM mr_order_items WHERE order_id = :id')->execute(['id' => $orderId]);
            } else {
                $orderNo = $this->nextOrderNo();
                $db->prepare(
                    'INSERT INTO mr_orders
                        (client_id, branch_id, order_no, order_date, user_id, mr_name, customer_id, customer_name,
                         customer_contact, customer_address, customer_dl_no, expected_delivery_date, delivery_type,
                         order_type, is_dummy_bill, is_quotation, total_items, total_qty, total_amount, status, remarks,
                         created_at, updated_at)
                     VALUES (:c, :b, :no, :od, :u, :mr, :cid, :cn, :cc, :ca, :dl, :edd, :dt, :ot, :dummy, :quot, :ti, :tq,
                         :ta, :st, :rm, NOW(), NOW())'
                )->execute($header + [
                    'c' => $this->clientId,
                    'b' => $this->branchId,
                    'no' => $orderNo,
                    'u' => $this->userId,
                    'mr' => $this->userName,
                ]);
                $orderId = (int) $db->lastInsertId();
            }

            $ins = $db->prepare(
                'INSERT INTO mr_order_items
                    (order_id, client_id, product_binding_id, batch_id, product_name, pack_size, batch_no, expiry_date,
                     qty, mrp, rate, amount, stock_qty, gst, hsn, m_name, remarks)
                 VALUES (:o, :c, :pb, :bt, :pn, :pk, :bn, :ex, :q, :mrp, :r, :a, :sq, :g, :h, :m, :rm)'
            );
            foreach ($items as $it) {
                $ins->execute([
                    'o' => $orderId,
                    'c' => $this->clientId,
                    'pb' => $it['binding'],
                    'bt' => $it['batch'],
                    'pn' => $it['name'],
                    'pk' => $it['pack'],
                    'bn' => $it['batch_no'],
                    'ex' => $it['exp'],
                    'q' => $it['qty'],
                    'mrp' => $it['mrp'],
                    'r' => $it['rate'],
                    'a' => $it['amount'],
                    'sq' => $it['stock'],
                    'g' => $it['gst'],
                    'h' => $it['hsn'],
                    'm' => $it['mfr'],
                    'rm' => $it['remarks'],
                ]);
            }

            return ['id' => $orderId, 'order_no' => $orderNo, 'total_amount' => $totalAmt, 'status' => $status];
        });
    }

    public function deleteOrder(int $id): string
    {
        $order = $this->findOrder($id);
        Connection::transaction('ops', function (PDO $db) use ($id): void {
            $db->prepare('DELETE FROM mr_order_items WHERE order_id = :id')->execute(['id' => $id]);
            $db->prepare('DELETE FROM mr_orders WHERE id = :id')->execute(['id' => $id]);
        });
        return (string) $order['order_no'];
    }

    // =================================================================== Stock follow-up

    /** @return list<array<string,mixed>> */
    public function stockFollowups(?string $from, ?string $to): array
    {
        [$from, $to] = $this->range($from, $to);
        $st = $this->db->prepare(
            'SELECT * FROM mr_stock_followups
             WHERE client_id = :c AND branch_id = :b AND followup_date BETWEEN :f AND :t
             ORDER BY id DESC LIMIT 500'
        );
        $st->execute(['c' => $this->clientId, 'b' => $this->branchId, 'f' => $from, 't' => $to]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string,mixed> $d */
    public function saveStockFollowup(array $d): int
    {
        $location = $this->str($d['location_name'] ?? null, 160);
        $product = $this->str($d['product_name'] ?? null, 255);
        if ($location === null || $product === null) {
            throw HttpException::validation(['location_name' => ['Location and product are required.']]);
        }
        $this->assertNonNegative($d['available_qty'] ?? null, 'available_qty', 'Available qty');
        $this->assertNonNegative($d['near_expiry_qty'] ?? null, 'near_expiry_qty', 'Near-expiry qty');
        $num = static fn ($v): ?float => ($v === null || $v === '') ? null : (float) $v;
        $this->db->prepare(
            'INSERT INTO mr_stock_followups
                (client_id, branch_id, user_id, mr_name, followup_date, location_type, location_name, product_binding_id,
                 product_name, available_qty, near_expiry_qty, scheme_notes, stock_status, remarks, created_at)
             VALUES (:c, :b, :u, :mr, :fd, :lt, :ln, :pb, :pn, :aq, :nq, :sn, :ss, :rm, NOW())'
        )->execute([
            'c' => $this->clientId,
            'b' => $this->branchId,
            'u' => $this->userId,
            'mr' => $this->userName,
            'fd' => $this->date($d['followup_date'] ?? null) ?? date('Y-m-d'),
            'lt' => $this->str($d['location_type'] ?? null, 40) ?? 'Pharmacy',
            'ln' => $location,
            'pb' => (int) ($d['product_binding_id'] ?? 0) ?: null,
            'pn' => $product,
            'aq' => $num($d['available_qty'] ?? null),
            'nq' => $num($d['near_expiry_qty'] ?? null),
            'sn' => $this->str($d['scheme_notes'] ?? null, 5000),
            'ss' => $this->str($d['stock_status'] ?? null, 40),
            'rm' => $this->str($d['remarks'] ?? null, 5000),
        ]);
        return (int) $this->db->lastInsertId();
    }

    // =================================================================== Sales monitor

    /** @return array{bills: list<array<string,mixed>>, near_expiry: list<array<string,mixed>>, returns: list<array<string,mixed>>} */
    public function monitor(?string $from, ?string $to, string $customer): array
    {
        [$from, $to] = $this->range($from, $to);
        $bind = ['c' => $this->clientId, 'b' => $this->branchId, 'f' => $from, 't' => $to];
        $custSql = '';
        if (($customer = trim($customer)) !== '') {
            $custSql = ' AND customer_name LIKE :cust';
            $bind['cust'] = '%' . $customer . '%';
        }
        $st = $this->db->prepare(
            "SELECT id, invoice_no, invoice_date, customer_name, net_amount, balance, payment_mode
             FROM sales
             WHERE client_id = :c AND branch_id = :b AND DATE(invoice_date) BETWEEN :f AND :t{$custSql}
             ORDER BY invoice_date DESC, id DESC LIMIT 300"
        );
        $st->execute($bind);
        $bills = $st->fetchAll(PDO::FETCH_ASSOC);

        $st = $this->db->prepare(
            'SELECT pb.product_name, pb.pack_size, pb.units_per_pack, ib.batch_no, ib.expiry_date, ib.quantity, ib.mrp
             FROM inventory_batches ib
             INNER JOIN product_bindings pb ON pb.id = ib.product_binding_id
             WHERE ib.client_id = :c AND ib.branch_id = :b AND ib.quantity > 0 AND ib.expiry_date IS NOT NULL
             ORDER BY ib.expiry_date ASC LIMIT 100'
        );
        $st->execute(['c' => $this->clientId, 'b' => $this->branchId]);
        $near = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $upp = max(1, (int) $r['units_per_pack']);
            $near[] = [
                'p_name' => (string) $r['product_name'],
                'pack_size' => (string) ($r['pack_size'] ?? ''),
                'batch_no' => (string) $r['batch_no'],
                'expiry_date' => $r['expiry_date'],
                'qty' => $upp > 1 ? round((int) $r['quantity'] / $upp, 2) : (int) $r['quantity'],
                'mrp' => round((float) $r['mrp'] * $upp, 2),
            ];
        }

        $st = $this->db->prepare(
            'SELECT id, invoice_no, invoice_date, customer_name, return_total, net_amount
             FROM sales
             WHERE client_id = :c AND branch_id = :b AND DATE(invoice_date) BETWEEN :f AND :t AND return_total > 0
             ORDER BY invoice_date DESC, id DESC LIMIT 100'
        );
        $st->execute(['c' => $this->clientId, 'b' => $this->branchId, 'f' => $from, 't' => $to]);

        return ['bills' => $bills, 'near_expiry' => $near, 'returns' => $st->fetchAll(PDO::FETCH_ASSOC)];
    }

    // =================================================================== Daily report

    /** @return list<array<string,mixed>> */
    public function dailyReports(?string $from, ?string $to): array
    {
        [$from, $to] = $this->range($from, $to);
        $st = $this->db->prepare(
            'SELECT * FROM mr_daily_reports
             WHERE client_id = :c AND branch_id = :b AND report_date BETWEEN :f AND :t
             ORDER BY report_date DESC, id DESC LIMIT 200'
        );
        $st->execute(['c' => $this->clientId, 'b' => $this->branchId, 'f' => $from, 't' => $to]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** One report per day per MR — saving again replaces it (Retail parity). @param array<string,mixed> $d */
    public function saveDailyReport(array $d): void
    {
        foreach (['calls_planned' => 'Calls planned', 'calls_done' => 'Calls done', 'doctor_visits' => 'Doctor visits',
                  'orders_taken' => 'Orders taken', 'order_value' => 'Order value', 'stock_checks' => 'Stock checks'] as $k => $label) {
            $this->assertNonNegative($d[$k] ?? null, $k, $label);
        }
        $reportDate = $this->date($d['report_date'] ?? null) ?? date('Y-m-d');
        if ($reportDate > date('Y-m-d')) {
            throw HttpException::validation(['report_date' => ['Report date cannot be in the future.']]);
        }
        $int = static fn ($v): int => max(0, (int) ($v ?? 0));
        $this->db->prepare(
            'INSERT INTO mr_daily_reports
                (client_id, branch_id, user_id, mr_name, report_date, calls_planned, calls_done, doctor_visits,
                 orders_taken, order_value, stock_checks, sales_summary, competitor_info, outstanding_notes, remarks, created_at)
             VALUES (:c, :b, :u, :mr, :rd, :cp, :cd, :dv, :ot, :ov, :sc, :ss, :ci, :on, :rm, NOW())
             ON DUPLICATE KEY UPDATE mr_name = VALUES(mr_name), calls_planned = VALUES(calls_planned),
                calls_done = VALUES(calls_done), doctor_visits = VALUES(doctor_visits), orders_taken = VALUES(orders_taken),
                order_value = VALUES(order_value), stock_checks = VALUES(stock_checks), sales_summary = VALUES(sales_summary),
                competitor_info = VALUES(competitor_info), outstanding_notes = VALUES(outstanding_notes),
                remarks = VALUES(remarks), created_at = NOW()'
        )->execute([
            'c' => $this->clientId,
            'b' => $this->branchId,
            'u' => (int) ($this->userId ?? 0),
            'mr' => $this->userName,
            'rd' => $reportDate,
            'cp' => $int($d['calls_planned'] ?? 0),
            'cd' => $int($d['calls_done'] ?? 0),
            'dv' => $int($d['doctor_visits'] ?? 0),
            'ot' => $int($d['orders_taken'] ?? 0),
            'ov' => round(max(0.0, (float) ($d['order_value'] ?? 0)), 2),
            'sc' => $int($d['stock_checks'] ?? 0),
            'ss' => $this->str($d['sales_summary'] ?? null, 5000),
            'ci' => $this->str($d['competitor_info'] ?? null, 5000),
            'on' => $this->str($d['outstanding_notes'] ?? null, 5000),
            'rm' => $this->str($d['remarks'] ?? null, 5000),
        ]);
    }

    // =================================================================== Outstanding

    /** @return array{outstanding: list<array<string,mixed>>, followups: list<array<string,mixed>>} */
    public function outstanding(): array
    {
        $st = $this->db->prepare(
            'SELECT MAX(customer_id) AS customer_id, customer_name, ROUND(SUM(balance), 2) AS outstanding_amt
             FROM sales
             WHERE client_id = :c AND branch_id = :b AND balance > 0
             GROUP BY customer_name
             HAVING outstanding_amt > 0
             ORDER BY outstanding_amt DESC LIMIT 200'
        );
        $st->execute(['c' => $this->clientId, 'b' => $this->branchId]);
        $list = $st->fetchAll(PDO::FETCH_ASSOC);

        $st = $this->db->prepare(
            'SELECT * FROM mr_outstanding_followups WHERE client_id = :c AND branch_id = :b ORDER BY id DESC LIMIT 200'
        );
        $st->execute(['c' => $this->clientId, 'b' => $this->branchId]);

        return ['outstanding' => $list, 'followups' => $st->fetchAll(PDO::FETCH_ASSOC)];
    }

    /** @param array<string,mixed> $d */
    public function saveOutstandingFollowup(array $d): int
    {
        $customer = $this->str($d['customer_name'] ?? null, 160);
        if ($customer === null) {
            throw HttpException::validation(['customer_name' => ['Customer name is required.']]);
        }
        $status = (string) ($d['status'] ?? 'Open');
        if (!in_array($status, ['Open', 'Promised', 'Closed'], true)) {
            $status = 'Open';
        }
        $this->assertNonNegative($d['outstanding_amt'] ?? null, 'outstanding_amt', 'Amount');
        $followDate = $this->date($d['followup_date'] ?? null) ?? date('Y-m-d');
        $promised = $this->date($d['promised_date'] ?? null);
        if ($status === 'Promised' && $promised === null) {
            throw HttpException::validation(['promised_date' => ['Promised date is required when status is Promised.']]);
        }
        if ($promised !== null && $promised < $followDate) {
            throw HttpException::validation(['promised_date' => ['Promised date cannot be before the follow-up date.']]);
        }
        $this->db->prepare(
            'INSERT INTO mr_outstanding_followups
                (client_id, branch_id, user_id, mr_name, followup_date, customer_id, customer_name, outstanding_amt,
                 followup_note, promised_date, status, created_at)
             VALUES (:c, :b, :u, :mr, :fd, :cid, :cn, :amt, :note, :pd, :st, NOW())'
        )->execute([
            'c' => $this->clientId,
            'b' => $this->branchId,
            'u' => $this->userId,
            'mr' => $this->userName,
            'fd' => $followDate,
            'cid' => (int) ($d['customer_id'] ?? 0) ?: null,
            'cn' => $customer,
            'amt' => round((float) ($d['outstanding_amt'] ?? 0), 2),
            'note' => $this->str($d['followup_note'] ?? null, 5000),
            'pd' => $promised,
            'st' => $status,
        ]);
        return (int) $this->db->lastInsertId();
    }

    // =================================================================== Customers

    /** Wholesale customers only (Retail getCustomers). @return list<array<string,mixed>> */
    public function customers(string $q): array
    {
        $sql = "SELECT id, name, phone, email, address, state, city, area, gstin, credit_days, dl_no
                FROM customers WHERE client_id = :c AND customer_type = 'wholesale'";
        $bind = ['c' => $this->clientId];
        if (($q = trim($q)) !== '') {
            $sql .= ' AND (name LIKE :q1 OR phone LIKE :q2 OR area LIKE :q3 OR city LIKE :q4)';
            $bind['q1'] = $bind['q2'] = $bind['q3'] = $bind['q4'] = '%' . $q . '%';
        }
        $sql .= ' ORDER BY name ASC LIMIT 2000';
        $st = $this->db->prepare($sql);
        $st->execute($bind);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['code'] = 'C-' . str_pad((string) $r['id'], 4, '0', STR_PAD_LEFT);
        }
        unset($r);
        return $rows;
    }

    // =================================================================== Order receipt PDF

    /** Pharma Order / Quotation Receipt (Retail mr_print.php content). */
    public function orderPdf(int $id): array
    {
        ['order' => $o, 'items' => $items] = $this->order($id);

        $client = Connection::ho()->query('SELECT * FROM clients WHERE id = ' . $this->clientId)->fetch(PDO::FETCH_ASSOC) ?: [];
        $branch = Connection::ho()->query('SELECT * FROM branches WHERE id = ' . $this->branchId)->fetch(PDO::FETCH_ASSOC) ?: [];
        $pick = static function (array $keys, array ...$sources): string {
            foreach ($sources as $src) {
                foreach ($keys as $k) {
                    $v = trim((string) ($src[$k] ?? ''));
                    if ($v !== '') {
                        return $v;
                    }
                }
            }
            return '';
        };
        $shopName = $pick(['business_name', 'name'], $client, $branch) ?: 'Medical Store';
        $shopAddr = trim(implode(', ', array_filter([
            $pick(['address'], $branch, $client),
            trim($pick(['city'], $branch, $client) . ' ' . $pick(['pincode'], $branch, $client)),
        ])), ', ');
        $shopPhone = $pick(['phone'], $branch, $client);
        $shopGst = $pick(['gstin'], $branch, $client);

        $custGst = '';
        if ((int) ($o['customer_id'] ?? 0) > 0) {
            $cg = $this->db->prepare('SELECT gstin FROM customers WHERE id = :id AND client_id = :c LIMIT 1');
            $cg->execute(['id' => (int) $o['customer_id'], 'c' => $this->clientId]);
            $custGst = trim((string) ($cg->fetchColumn() ?: ''));
        }

        $e = static fn ($v): string => htmlspecialchars(trim((string) $v), ENT_QUOTES, 'UTF-8');
        $chk = static fn (bool $on): string => $on ? '[X]' : '[ ]';

        $isQuote = (int) $o['is_quotation'] === 1;
        $banner = $isQuote ? 'PHARMA QUOTATION RECEIPT' : 'PHARMA ORDER RECEIPT';

        $type = strtolower(trim((string) ($o['order_type'] ?? '')));
        $isRetailer = str_contains($type, 'retail');
        $isDoctor = str_contains($type, 'doctor') || str_contains($type, 'clinic');
        $isHospital = str_contains($type, 'hospital');
        $isOther = !$isRetailer && !$isDoctor && !$isHospital && $type !== '';
        $otherLabel = $isOther ? (string) $o['order_type'] : '';

        $mrName = trim((string) ($o['mr_name'] ?? '')) ?: $this->userName;
        $orderDate = !empty($o['order_date']) ? date('d / m / Y', strtotime((string) $o['order_date'])) : '__ / __ / ____';
        $time = !empty($o['created_at']) ? date('h:i A', strtotime((string) $o['created_at'])) : '________';
        $delDate = !empty($o['expected_delivery_date']) ? date('d / m / Y', strtotime((string) $o['expected_delivery_date'])) : '__ / __ / ____';
        $delType = strtolower((string) ($o['delivery_type'] ?? ''));

        $teal = '#0f766e';
        $line = '#94a3b8';
        $soft = '#f0fdfa';
        $cell = 'border:1px solid ' . $line . '; font-size:8px;';

        $rows = '';
        $rowCount = max(10, count($items));
        for ($i = 0; $i < $rowCount; $i++) {
            $it = $items[$i] ?? null;
            $name = $pack = $qty = $rem = '';
            if ($it !== null) {
                $name = $e($it['product_name']);
                $pack = $e($it['pack_size'] ?? '');
                $q = (float) $it['qty'];
                $qty = $q == floor($q) ? (string) (int) $q : number_format($q, 2);
                $bits = [];
                if (trim((string) ($it['batch_no'] ?? '')) !== '') {
                    $bits[] = 'B:' . trim((string) $it['batch_no']);
                }
                if (trim((string) ($it['remarks'] ?? '')) !== '') {
                    $bits[] = trim((string) $it['remarks']);
                }
                if ((float) $it['rate'] > 0) {
                    $bits[] = '@' . number_format((float) $it['rate'], 2);
                }
                $rem = $e(implode(' ', $bits));
            }
            $bg = $i % 2 === 0 ? '#ffffff' : '#f8fafc';
            $rows .= '<tr style="background-color:' . $bg . ';" nobr="true">'
                . '<td style="' . $cell . ' text-align:center;">' . ($i + 1) . '</td>'
                . '<td style="' . $cell . '">' . $name . '</td>'
                . '<td style="' . $cell . ' text-align:center;">' . $pack . '</td>'
                . '<td style="' . $cell . ' text-align:center;">' . $qty . '</td>'
                . '<td style="' . $cell . ' font-size:7px;">' . $rem . '</td>'
                . '</tr>';
        }

        $contact = trim(($shopPhone !== '' ? 'Ph: ' . $e($shopPhone) : '')
            . ($shopPhone !== '' && $shopGst !== '' ? ' | ' : '')
            . ($shopGst !== '' ? 'GSTIN: ' . $e($shopGst) : ''));
        $section = static fn (string $title): string =>
            '<table cellpadding="4"><tr><td style="background-color:' . $teal . '; color:#ffffff; font-size:9.5px; font-weight:bold;">' . $title . '</td></tr></table>';
        $box = 'border:1px solid ' . $line . '; font-size:8.5px; color:#0f172a;';

        $html = '
<table cellpadding="2">
  <tr><td style="font-size:15px; color:' . $teal . ';"><b>' . $e($shopName) . '</b></td></tr>'
            . ($shopAddr !== '' ? '<tr><td style="font-size:8px; color:#475569;">' . $e($shopAddr) . '</td></tr>' : '')
            . ($contact !== '' ? '<tr><td style="font-size:8px; color:#64748b;">' . $contact . '</td></tr>' : '') . '
</table>
<br>
<table cellpadding="5">
  <tr>
    <td width="62%" style="background-color:' . $teal . '; color:#ffffff; font-size:12.5px; font-weight:bold;">' . $banner . '</td>
    <td width="38%" style="border:1px solid ' . $line . '; font-size:8.5px;"><b>Order No. :</b> ' . $e($o['order_no']) . '<br><b>Date :</b> ' . $orderDate . '</td>
  </tr>
</table>
<br>
<table cellpadding="5">
  <tr><td style="' . $box . ' background-color:' . $soft . ';"><b>MR Name :</b> ' . $e($mrName ?: '________________')
            . ' &nbsp;&nbsp;&nbsp; <b>Time :</b> ' . $time . '</td></tr>
</table>
<br>
' . $section('ORDER RECEIVED FROM') . '
<table cellpadding="6">
  <tr><td style="' . $box . '">'
            . $chk($isRetailer) . ' Retailer &nbsp;&nbsp; '
            . $chk($isDoctor) . ' Doctor / Clinic &nbsp;&nbsp; '
            . $chk($isHospital) . ' Hospital &nbsp;&nbsp; '
            . $chk($isOther) . ' Other : ' . $e($otherLabel) . '<br><br>
    <b>Name :</b> ' . $e($o['customer_name']) . '<br>
    <b>Pharmacy / Clinic :</b> ' . $e($o['customer_name']) . '<br>
    <b>Address :</b> ' . $e($o['customer_address'] ?? '') . '<br>
    <b>Mobile No. :</b> ' . $e($o['customer_contact'] ?? '') . ' &nbsp;&nbsp;&nbsp; <b>GSTIN :</b> ' . $e($custGst)
            . (trim((string) ($o['customer_dl_no'] ?? '')) !== '' ? ' &nbsp;&nbsp;&nbsp; <b>D.L. No :</b> ' . $e($o['customer_dl_no']) : '') . '
  </td></tr>
</table>
<br>
' . $section('ORDER DETAILS') . '
<table cellpadding="3">
  <thead>
    <tr style="background-color:' . $soft . ';">
      <th width="8%" style="' . $cell . ' text-align:center;"><b>Sr. No.</b></th>
      <th width="42%" style="' . $cell . '"><b>Product / Medicine Name</b></th>
      <th width="16%" style="' . $cell . ' text-align:center;"><b>Pack Size</b></th>
      <th width="12%" style="' . $cell . ' text-align:center;"><b>Qty</b></th>
      <th width="22%" style="' . $cell . '"><b>Remarks</b></th>
    </tr>
  </thead>
  ' . $rows . '
  <tr style="background-color:' . $soft . ';"><td colspan="5" style="' . $cell . ' font-size:9px;"><b>Total Items :</b> ' . max((int) $o['total_items'], count($items)) . '</td></tr>
</table>
<br>
' . $section('SPECIAL INSTRUCTIONS / REQUIREMENTS') . '
<table cellpadding="8">
  <tr><td style="' . $box . '">' . (trim((string) ($o['remarks'] ?? '')) !== '' ? nl2br($e($o['remarks'])) : '&nbsp;') . '<br>&nbsp;</td></tr>
</table>
<br>
' . $section('DELIVERY DETAILS') . '
<table cellpadding="6">
  <tr><td style="' . $box . '">
    <b>Expected Delivery Date :</b> ' . $delDate . '<br><br>
    ' . $chk($delType === 'regular') . ' Regular Delivery &nbsp;&nbsp;
    ' . $chk($delType === 'urgent') . ' Urgent Delivery &nbsp;&nbsp;
    ' . $chk($delType === 'other') . ' Other : ______________
  </td></tr>
</table>
<br>
<table cellpadding="5">
  <tr>
    <td width="48%" style="' . $box . '"><b>Customer / Doctor Signature</b><br><br><br><br>
      <b>Name :</b> ' . $e($o['customer_name']) . '<br>
      <b>Designation :</b> ________________<br>
      <span style="font-size:6.5px; color:#64748b;">(Retailer / Doctor / Other)</span>
    </td>
    <td width="4%"></td>
    <td width="48%" style="' . $box . '"><b>MR Signature</b><br><br><br><br>
      <b>Name :</b> ' . $e($mrName) . '<br>
      <b>Employee Code :</b> ' . ((int) ($o['user_id'] ?? 0) > 0 ? 'U-' . (int) $o['user_id'] : '') . '
    </td>
  </tr>
</table>';

        $pdf = new class ('P', 'mm', 'A4', true, 'UTF-8') extends \TCPDF {
            protected $tcpdflink = false;

            public function Header(): void {}

            public function Footer(): void {}
        };
        $title = ($isQuote ? 'Quotation ' : 'Order ') . $o['order_no'];
        $pdf->SetCreator('Advanced Pharmacy Suite');
        $pdf->SetAuthor($shopName);
        $pdf->SetTitle($title);
        $pdf->setViewerPreferences(['DisplayDocTitle' => true]);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 8);
        $pdf->SetFont('helvetica', '', 9);
        $pdf->AddPage();
        $pdf->writeHTML($html, true, false, true, false, '');

        $file = ($isQuote ? 'quotation-' : 'order-') . preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $o['order_no']) . '.pdf';
        return ['pdf' => $pdf->Output($file, 'S'), 'filename' => $file];
    }

    // =================================================================== Helpers

    /** @return array<string,mixed> */
    private function findOrder(int $id): array
    {
        $st = $this->db->prepare('SELECT * FROM mr_orders WHERE id = :id AND client_id = :c AND branch_id = :b LIMIT 1');
        $st->execute(['id' => $id, 'c' => $this->clientId, 'b' => $this->branchId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw HttpException::notFound('MR order not found.');
        }
        if (!$this->isAdmin && $this->userId !== null && (int) ($row['user_id'] ?? 0) !== $this->userId) {
            throw HttpException::forbidden('This order belongs to another MR.');
        }
        return $row;
    }

    /** Staff see their own entries (Retail mr_login filter); admins see everyone. @return array{0:string,1:array<string,int>} */
    private function userScope(): array
    {
        if ($this->isAdmin || $this->userId === null) {
            return ['', []];
        }
        return [' AND user_id = :uid', ['uid' => $this->userId]];
    }

    /** @return array{0:string,1:string} */
    private function range(?string $from, ?string $to): array
    {
        return [$this->date($from) ?? date('Y-m-01'), $this->date($to) ?? date('Y-m-d')];
    }

    private function date(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
    }

    private function str(mixed $v, int $max): ?string
    {
        $s = trim((string) ($v ?? ''));
        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    /** Optional Indian mobile: empty → null, otherwise exactly 10 digits starting 6–9. */
    private function phone(mixed $v, string $field): ?string
    {
        $s = trim((string) ($v ?? ''));
        if ($s === '') {
            return null;
        }
        if (!preg_match('/^[6-9]\d{9}$/', $s)) {
            throw HttpException::validation([$field => ['Contact must be a valid 10-digit mobile number.']]);
        }
        return $s;
    }

    private function assertNonNegative(mixed $v, string $field, string $label): void
    {
        if ($v !== null && $v !== '' && (!is_numeric($v) || (float) $v < 0)) {
            throw HttpException::validation([$field => [$label . ' cannot be negative.']]);
        }
    }
}
