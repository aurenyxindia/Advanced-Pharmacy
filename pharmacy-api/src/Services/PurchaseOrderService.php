<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Database\Connection;
use PDO;

/**
 * Purchase Orders (Retail /purchaseOrder).
 *  - Customise PO: one distributor, hand-picked products + qty  → POC-{yyyy}-{seq}
 *  - Auto PO (Short Book): low-stock products grouped by last distributor → POA-{yyyy}-{seq}
 *  - Sales PO: products sold in last N days (incl. today), user picks order qty + distributor → POS-{yyyy}-{seq}
 * A PO is an order sheet only (no rates, no stock / account effect).
 */
final class PurchaseOrderService
{
    public const TYPE_CUSTOMISE = 'Customise';
    public const TYPE_AUTO = 'Auto';
    public const TYPE_SALES = 'Sales';

    /** Short Book looks at the current month + previous 3 months (Retail: 4 month buckets). */
    private const SALES_MONTHS = 4;

    /** @return list<string> */
    public static function types(): array
    {
        return [self::TYPE_CUSTOMISE, self::TYPE_AUTO, self::TYPE_SALES];
    }

    public function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $db = Connection::ops();
        $db->exec(
            "CREATE TABLE IF NOT EXISTS purchase_orders (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                client_id BIGINT UNSIGNED NOT NULL,
                branch_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                po_no VARCHAR(40) NOT NULL,
                po_seq INT UNSIGNED NOT NULL,
                po_type VARCHAR(20) NOT NULL DEFAULT 'Customise',
                distributor_id BIGINT UNSIGNED NULL,
                distributor_name VARCHAR(150) NOT NULL DEFAULT '',
                total_items INT NOT NULL DEFAULT 0,
                order_date DATE NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'Active',
                created_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_po_client_no (client_id, po_no),
                KEY idx_po_tenant (client_id, branch_id, order_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS purchase_order_items (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                purchase_order_id BIGINT UNSIGNED NOT NULL,
                product_binding_id BIGINT UNSIGNED NULL,
                master_product_id BIGINT UNSIGNED NULL,
                product_name VARCHAR(200) NOT NULL,
                pack_size VARCHAR(40) NULL,
                product_category VARCHAR(80) NULL,
                dosage_form VARCHAR(60) NULL,
                mrp DECIMAL(12,2) NOT NULL DEFAULT 0,
                hsn_code VARCHAR(20) NULL,
                manufacturer VARCHAR(150) NULL,
                generic_name VARCHAR(255) NULL,
                qty DECIMAL(12,2) NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY idx_poi_order (purchase_order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /**
     * @param array{search?:string,from_date?:string,to_date?:string,po_type?:string} $filters
     * @return list<array<string,mixed>>
     */
    public function list(int $clientId, int $branchId, array $filters = []): array
    {
        $this->ensureSchema();
        $where = ['client_id = :c', 'branch_id = :b', "status = 'Active'"];
        $bind = ['c' => $clientId, 'b' => $branchId];

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(po_no LIKE :q1 OR distributor_name LIKE :q2 OR EXISTS (
                SELECT 1 FROM purchase_order_items i
                WHERE i.purchase_order_id = purchase_orders.id AND i.product_name LIKE :q3))';
            $bind['q1'] = $bind['q2'] = $bind['q3'] = '%' . $search . '%';
        }
        $from = $this->validDate($filters['from_date'] ?? null);
        if ($from !== null) {
            $where[] = 'order_date >= :from';
            $bind['from'] = $from;
        }
        $to = $this->validDate($filters['to_date'] ?? null);
        if ($to !== null) {
            $where[] = 'order_date <= :to';
            $bind['to'] = $to;
        }
        $type = trim((string) ($filters['po_type'] ?? ''));
        if (in_array($type, self::types(), true)) {
            $where[] = 'po_type = :t';
            $bind['t'] = $type;
        }

        $st = Connection::ops()->prepare(
            'SELECT id, po_no, po_type, distributor_id, distributor_name, total_items, order_date, status, created_at
             FROM purchase_orders WHERE ' . implode(' AND ', $where) . '
             ORDER BY id DESC LIMIT 1000'
        );
        $st->execute($bind);
        return array_map(fn (array $r) => $this->presentOrder($r), $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null */
    public function get(int $clientId, int $branchId, int $id): ?array
    {
        $this->ensureSchema();
        $st = Connection::ops()->prepare(
            'SELECT * FROM purchase_orders WHERE id = :id AND client_id = :c AND branch_id = :b LIMIT 1'
        );
        $st->execute(['id' => $id, 'c' => $clientId, 'b' => $branchId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $order = $this->presentOrder($row);
        $it = Connection::ops()->prepare(
            'SELECT * FROM purchase_order_items WHERE purchase_order_id = :id ORDER BY id ASC'
        );
        $it->execute(['id' => $id]);
        $order['items'] = array_map(static fn (array $i) => [
            'id' => (int) $i['id'],
            'product_binding_id' => $i['product_binding_id'] !== null ? (int) $i['product_binding_id'] : null,
            'master_product_id' => $i['master_product_id'] !== null ? (int) $i['master_product_id'] : null,
            'product_name' => (string) $i['product_name'],
            'pack_size' => (string) ($i['pack_size'] ?? ''),
            'product_category' => (string) ($i['product_category'] ?? ''),
            'dosage_form' => (string) ($i['dosage_form'] ?? ''),
            'mrp' => (float) $i['mrp'],
            'hsn_code' => (string) ($i['hsn_code'] ?? ''),
            'manufacturer' => (string) ($i['manufacturer'] ?? ''),
            'generic_name' => (string) ($i['generic_name'] ?? ''),
            'qty' => (float) $i['qty'],
        ], $it->fetchAll(PDO::FETCH_ASSOC));
        return $order;
    }

    /**
     * @param array<string,mixed> $data {po_type, distributor_id?, distributor_name, items:[{product_name, qty, ...}]}
     * @return array{id:int,po_no:string}
     */
    public function create(int $clientId, int $branchId, ?int $userId, array $data): array
    {
        $this->ensureSchema();
        $type = (string) ($data['po_type'] ?? self::TYPE_CUSTOMISE);
        if (!in_array($type, self::types(), true)) {
            $type = self::TYPE_CUSTOMISE;
        }

        [$distributorId, $distributorName] = $this->resolveDistributor($clientId, $data);
        $items = $this->normalizeItems($data);

        return Connection::transaction('ops', function (PDO $db) use ($clientId, $branchId, $userId, $type, $distributorId, $distributorName, $items) {
            $seqSt = $db->prepare('SELECT COALESCE(MAX(po_seq), 0) FROM purchase_orders WHERE client_id = :c FOR UPDATE');
            $seqSt->execute(['c' => $clientId]);
            $seq = (int) $seqSt->fetchColumn() + 1;
            $prefix = match ($type) {
                self::TYPE_AUTO => 'POA',
                self::TYPE_SALES => 'POS',
                default => 'POC',
            };
            $poNo = $prefix . '-' . date('Y') . '-' . $seq;

            $db->prepare(
                'INSERT INTO purchase_orders
                    (client_id, branch_id, po_no, po_seq, po_type, distributor_id, distributor_name,
                     total_items, order_date, status, created_by, created_at)
                 VALUES (:c, :b, :no, :seq, :t, :did, :dn, :ti, :od, :st, :by, :now)'
            )->execute([
                'c' => $clientId,
                'b' => $branchId,
                'no' => $poNo,
                'seq' => $seq,
                't' => $type,
                'did' => $distributorId > 0 ? $distributorId : null,
                'dn' => mb_substr($distributorName, 0, 150),
                'ti' => count($items),
                'od' => date('Y-m-d'),
                'st' => 'Active',
                'by' => $userId,
                'now' => now(),
            ]);
            $poId = (int) $db->lastInsertId();
            $this->insertItems($db, $poId, $items);
            return ['id' => $poId, 'po_no' => $poNo];
        });
    }

    /**
     * Edit a saved PO: distributor + full item list (add / remove products, change qty).
     * PO no, type and date stay unchanged.
     *
     * @param array<string,mixed> $data {distributor_id?, distributor_name, items:[{product_name, qty, ...}]}
     * @return array{id:int,po_no:string}
     */
    public function update(int $clientId, int $branchId, int $id, array $data): array
    {
        $this->ensureSchema();
        $order = $this->get($clientId, $branchId, $id);
        if ($order === null || $order['status'] !== 'Active') {
            throw HttpException::notFound('Purchase order not found.');
        }
        [$distributorId, $distributorName] = $this->resolveDistributor($clientId, $data);
        $items = $this->normalizeItems($data);

        Connection::transaction('ops', function (PDO $db) use ($clientId, $branchId, $id, $distributorId, $distributorName, $items) {
            $db->prepare(
                'UPDATE purchase_orders
                 SET distributor_id = :did, distributor_name = :dn, total_items = :ti
                 WHERE id = :id AND client_id = :c AND branch_id = :b'
            )->execute([
                'did' => $distributorId > 0 ? $distributorId : null,
                'dn' => mb_substr($distributorName, 0, 150),
                'ti' => count($items),
                'id' => $id,
                'c' => $clientId,
                'b' => $branchId,
            ]);
            $db->prepare('DELETE FROM purchase_order_items WHERE purchase_order_id = :id')->execute(['id' => $id]);
            $this->insertItems($db, $id, $items);
        });
        return ['id' => $id, 'po_no' => (string) $order['po_no']];
    }

    /** @return array{0:int,1:string} [distributor_id, distributor_name] */
    private function resolveDistributor(int $clientId, array $data): array
    {
        $distributorId = (int) ($data['distributor_id'] ?? 0);
        $distributorName = trim((string) ($data['distributor_name'] ?? ''));
        if ($distributorId > 0) {
            $dist = $this->distributor($clientId, $distributorId);
            if ($dist === null) {
                throw HttpException::validation(['distributor_id' => ['Distributor not found.']]);
            }
            $distributorName = (string) $dist['name'];
        }
        if ($distributorName === '') {
            throw HttpException::validation(['distributor_id' => ['Please select a distributor.']]);
        }
        return [$distributorId, $distributorName];
    }

    /** @return list<array<string,mixed>> */
    private function normalizeItems(array $data): array
    {
        $items = [];
        foreach ((array) ($data['items'] ?? []) as $i => $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $name = trim((string) ($raw['product_name'] ?? ''));
            $qty = round((float) ($raw['qty'] ?? 0), 2);
            if ($name === '') {
                continue;
            }
            if ($qty <= 0) {
                throw HttpException::validation(["items.$i" => ["Enter qty for {$name}."]]);
            }
            $items[] = [
                'product_binding_id' => (int) ($raw['product_binding_id'] ?? 0) ?: null,
                'master_product_id' => (int) ($raw['master_product_id'] ?? 0) ?: null,
                'product_name' => mb_substr($name, 0, 200),
                'pack_size' => $this->str($raw['pack_size'] ?? null, 40),
                'product_category' => $this->str($raw['product_category'] ?? null, 80),
                'dosage_form' => $this->str($raw['dosage_form'] ?? null, 60),
                'mrp' => round((float) ($raw['mrp'] ?? 0), 2),
                'hsn_code' => $this->str($raw['hsn_code'] ?? null, 20),
                'manufacturer' => $this->str($raw['manufacturer'] ?? null, 150),
                'generic_name' => $this->str($raw['generic_name'] ?? null, 255),
                'qty' => $qty,
            ];
        }
        if ($items === []) {
            throw HttpException::validation(['items' => ['Add at least one product.']]);
        }
        return $items;
    }

    /** @param list<array<string,mixed>> $items */
    private function insertItems(PDO $db, int $poId, array $items): void
    {
        $ins = $db->prepare(
            'INSERT INTO purchase_order_items
                (purchase_order_id, product_binding_id, master_product_id, product_name, pack_size,
                 product_category, dosage_form, mrp, hsn_code, manufacturer, generic_name, qty)
             VALUES (:po, :pb, :mp, :pn, :ps, :pc, :df, :mrp, :hsn, :mf, :gn, :qty)'
        );
        foreach ($items as $it) {
            $ins->execute([
                'po' => $poId,
                'pb' => $it['product_binding_id'],
                'mp' => $it['master_product_id'],
                'pn' => $it['product_name'],
                'ps' => $it['pack_size'],
                'pc' => $it['product_category'],
                'df' => $it['dosage_form'],
                'mrp' => $it['mrp'],
                'hsn' => $it['hsn_code'],
                'mf' => $it['manufacturer'],
                'gn' => $it['generic_name'],
                'qty' => $it['qty'],
            ]);
        }
    }

    /**
     * Short Book (Retail Auto-Purchase): products that are low on stock.
     * Low = stock ≤ reorder level (when a level is set), or stock ≤ 0 while sold in the last 4 months.
     * Quantities are returned in packs (stock is stored in loose units).
     *
     * @return array{months:list<string>,products:list<array<string,mixed>>}
     */
    public function shortBook(int $clientId, int $branchId): array
    {
        $db = Connection::ops();
        $months = [];
        for ($i = self::SALES_MONTHS - 1; $i >= 0; $i--) {
            $months[] = date('Y-m', strtotime('first day of -' . $i . ' month'));
        }
        $startDate = $months[0] . '-01';

        $salesSt = $db->prepare(
            "SELECT si.product_binding_id AS pb, DATE_FORMAT(s.invoice_date, '%Y-%m') AS ym,
                    SUM(CASE WHEN si.unit_type = 'pack' THEN (si.quantity - COALESCE(si.return_qty, 0)) * GREATEST(pb.units_per_pack, 1)
                             ELSE si.quantity - COALESCE(si.return_qty, 0) END) AS units
             FROM sales s
             JOIN sale_items si ON si.sale_id = s.id
             JOIN product_bindings pb ON pb.id = si.product_binding_id
             WHERE s.client_id = :c AND s.branch_id = :b AND s.invoice_date >= :start
             GROUP BY si.product_binding_id, ym"
        );
        $salesSt->execute(['c' => $clientId, 'b' => $branchId, 'start' => $startDate]);
        $sold = [];
        foreach ($salesSt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $sold[(int) $r['pb']][(string) $r['ym']] = (float) $r['units'];
        }

        $st = $db->prepare(
            'SELECT pb.id, pb.master_product_id, pb.product_name, pb.pack_size, pb.product_category, pb.mrp,
                    pb.hsn_code, pb.manufacturer, pb.units_per_pack, pb.reorder_level,
                    COALESCE(inv.quantity, 0) AS stock_units, COALESCE(inv.reorder_level, 0) AS stock_reorder,
                    inv.id AS stock_id
             FROM product_bindings pb
             LEFT JOIN inventory_stock inv
                    ON inv.product_binding_id = pb.id AND inv.client_id = pb.client_id AND inv.branch_id = pb.branch_id
             WHERE pb.client_id = :c AND pb.branch_id = :b AND pb.is_active = 1'
        );
        $st->execute(['c' => $clientId, 'b' => $branchId]);

        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $pbId = (int) $p['id'];
            $stock = (float) $p['stock_units'];
            $reorder = MinInventoryService::effectiveMin(max((int) $p['reorder_level'], (int) $p['stock_reorder']));
            $byMonth = $sold[$pbId] ?? [];
            $totalSold = array_sum($byMonth);
            $low = ($p['stock_id'] !== null && $stock < $reorder) || ($stock <= 0 && $totalSold > 0);
            if (!$low) {
                continue;
            }
            $upp = max(1, (int) $p['units_per_pack']);
            $soldByMonth = [];
            foreach ($months as $m) {
                $soldByMonth[$m] = round(($byMonth[$m] ?? 0) / $upp, 1);
            }
            $rows[$pbId] = [
                'product_binding_id' => $pbId,
                'master_product_id' => (int) $p['master_product_id'],
                'product_name' => (string) $p['product_name'],
                'pack_size' => (string) ($p['pack_size'] ?? ''),
                'product_category' => (string) ($p['product_category'] ?? ''),
                'mrp' => (float) $p['mrp'],
                'hsn_code' => (string) ($p['hsn_code'] ?? ''),
                'manufacturer' => (string) ($p['manufacturer'] ?? ''),
                'units_per_pack' => $upp,
                'reorder_level' => $reorder,
                'available_qty' => round($stock / $upp, 1),
                'sold_by_month' => $soldByMonth,
                'total_sold_qty' => round($totalSold / $upp, 1),
                'distributor_id' => null,
                'distributor_name' => '',
            ];
        }

        if ($rows !== []) {
            $this->attachLastDistributor($clientId, $branchId, $rows);
        }

        $list = array_values($rows);
        usort($list, static fn ($a, $b) => [$a['available_qty'], $a['product_name']] <=> [$b['available_qty'], $b['product_name']]);
        return ['months' => $months, 'products' => $list];
    }

    /**
     * Sales-based PO helper: products sold in the last N calendar days (inclusive of today).
     * days=1 → today only. Quantities in packs (same unit as Auto / Short Book).
     *
     * @return array{from_date:string,to_date:string,days:int,products:list<array<string,mixed>>}
     */
    public function salesRange(int $clientId, int $branchId, int $days): array
    {
        $days = max(1, min(365, $days));
        $toDate = date('Y-m-d');
        $fromDate = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));

        $db = Connection::ops();
        $salesSt = $db->prepare(
            "SELECT si.product_binding_id AS pb,
                    SUM(CASE WHEN si.unit_type = 'pack'
                             THEN (si.quantity - COALESCE(si.return_qty, 0)) * GREATEST(pb.units_per_pack, 1)
                             ELSE si.quantity - COALESCE(si.return_qty, 0) END) AS units
             FROM sales s
             JOIN sale_items si ON si.sale_id = s.id
             JOIN product_bindings pb ON pb.id = si.product_binding_id
             WHERE s.client_id = :c AND s.branch_id = :b
               AND s.invoice_date >= :from AND s.invoice_date <= :to
               AND si.product_binding_id IS NOT NULL
             GROUP BY si.product_binding_id
             HAVING units > 0"
        );
        $salesSt->execute(['c' => $clientId, 'b' => $branchId, 'from' => $fromDate, 'to' => $toDate]);
        $soldUnits = [];
        foreach ($salesSt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $soldUnits[(int) $r['pb']] = (float) $r['units'];
        }
        if ($soldUnits === []) {
            return [
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'days' => $days,
                'products' => [],
            ];
        }

        $ids = implode(',', array_map('intval', array_keys($soldUnits)));
        $st = $db->prepare(
            "SELECT pb.id, pb.master_product_id, pb.product_name, pb.pack_size, pb.product_category,
                    pb.mrp, pb.hsn_code, pb.manufacturer, pb.units_per_pack,
                    COALESCE(inv.quantity, 0) AS stock_units
             FROM product_bindings pb
             LEFT JOIN inventory_stock inv
                    ON inv.product_binding_id = pb.id AND inv.client_id = pb.client_id AND inv.branch_id = pb.branch_id
             WHERE pb.client_id = :c AND pb.branch_id = :b AND pb.id IN ($ids)"
        );
        $st->execute(['c' => $clientId, 'b' => $branchId]);

        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $pbId = (int) $p['id'];
            $upp = max(1, (int) $p['units_per_pack']);
            $soldPacks = round(($soldUnits[$pbId] ?? 0) / $upp, 1);
            $availPacks = round(((float) $p['stock_units']) / $upp, 1);
            $rows[$pbId] = [
                'product_binding_id' => $pbId,
                'master_product_id' => (int) $p['master_product_id'],
                'product_name' => (string) $p['product_name'],
                'pack_size' => (string) ($p['pack_size'] ?? ''),
                'product_category' => (string) ($p['product_category'] ?? ''),
                'mrp' => (float) $p['mrp'],
                'hsn_code' => (string) ($p['hsn_code'] ?? ''),
                'manufacturer' => (string) ($p['manufacturer'] ?? ''),
                'units_per_pack' => $upp,
                'sold_qty' => $soldPacks,
                'available_qty' => $availPacks,
                'suggested_qty' => (int) max(0, round($soldPacks)),
                'distributor_id' => null,
                'distributor_name' => '',
            ];
        }

        if ($rows !== []) {
            $this->attachLastDistributor($clientId, $branchId, $rows);
        }

        $list = array_values($rows);
        usort($list, static fn ($a, $b) => [$b['sold_qty'], $a['product_name']] <=> [$a['sold_qty'], $b['product_name']]);
        return [
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'days' => $days,
            'products' => $list,
        ];
    }

    /** Retail PO PDF: FROM (pharmacy) / TO (distributor) + Sr / Product / Pack / Category / Qty. */
    public function pdf(int $clientId, int $branchId, array $order): string
    {
        $client = Connection::ho()->query('SELECT * FROM clients WHERE id = ' . $clientId)->fetch(PDO::FETCH_ASSOC) ?: [];
        $branch = $branchId > 0
            ? (Connection::ho()->query('SELECT * FROM branches WHERE id = ' . $branchId)->fetch(PDO::FETCH_ASSOC) ?: [])
            : [];
        $dist = !empty($order['distributor_id']) ? ($this->distributor($clientId, (int) $order['distributor_id']) ?? []) : [];

        $e = static fn ($v): string => htmlspecialchars(trim((string) $v), ENT_QUOTES, 'UTF-8');
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

        $shopName = $pick(['business_name', 'name'], $client, $branch) ?: 'Pharmacy';
        $shopAddr = trim(implode(', ', array_filter([
            $pick(['address'], $branch, $client),
            trim($pick(['city'], $branch, $client) . ' ' . $pick(['pincode'], $branch, $client)),
        ])), ', ');
        $shopPhone = $pick(['phone'], $branch, $client);
        $shopContact = $pick(['owner_name'], $client) ?: $shopPhone;
        $shopDl = $pick(['drug_license_no'], $client, $branch);
        $shopGst = $pick(['gstin'], $branch, $client);

        $distName = (string) ($order['distributor_name'] ?? '');
        $distAddr = (string) ($dist['address'] ?? '');
        $distPhone = (string) ($dist['phone'] ?? '');
        $distGst = (string) ($dist['gstin'] ?? '');

        $orderDate = !empty($order['order_date']) ? date('d-m-Y', strtotime((string) $order['order_date'])) : '';

        $th = 'background-color:#0f766e; color:#ffffff; border:1px solid #334155; font-weight:bold; font-size:9px;';
        $td = 'border:1px solid #94a3b8; font-size:9px;';
        $rowsHtml = '';
        foreach ((array) ($order['items'] ?? []) as $n => $it) {
            $qty = (float) ($it['qty'] ?? 0);
            $rowsHtml .= '<tr>
                <td style="' . $td . ' text-align:center;">' . ($n + 1) . '</td>
                <td style="' . $td . '">' . $e($it['product_name'] ?? '') . '</td>
                <td style="' . $td . ' text-align:center;">' . $e($it['pack_size'] ?? '') . '</td>
                <td style="' . $td . ' text-align:center;">' . $e($it['product_category'] ?? '') . '</td>
                <td style="' . $td . ' text-align:center;">' . $e(rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.')) . '</td>
            </tr>';
        }

        $html = '
<table cellpadding="6" style="border:1px solid #334155;">
  <tr><td style="text-align:center; font-size:13px; border-bottom:1px solid #334155;"><b>PURCHASE ORDER</b></td></tr>
</table>
<table cellpadding="5" style="border:1px solid #334155;">
  <tr>
    <td style="width:60%; background-color:#0f766e; color:#ffffff; font-size:10px;"><b>FROM</b></td>
    <td style="width:40%; background-color:#0f766e; color:#ffffff; font-size:10px;"><b>TO</b> (Distributor)</td>
  </tr>
  <tr>
    <td style="width:60%; font-size:9px; border-right:1px solid #334155;"><b>' . $e($shopName) . '</b><br>'
            . $e($shopAddr) . '<br>'
            . '<b>Contact :</b> ' . $e($shopContact) . ($shopPhone !== '' && $shopPhone !== $shopContact ? ' (' . $e($shopPhone) . ')' : '') . '<br>'
            . '<b>D. L. No :</b> ' . $e($shopDl) . ' &nbsp;/&nbsp; <b>GST No :</b> ' . $e($shopGst) . '</td>
    <td style="width:40%; font-size:9px;"><b>' . $e($distName) . '</b><br>'
            . '<b>Address :</b> ' . $e($distAddr) . '<br>'
            . '<b>Contact no. :</b> ' . $e($distPhone) . '<br>'
            . ($distGst !== '' ? '<b>GST No :</b> ' . $e($distGst) : '') . '</td>
  </tr>
  <tr>
    <td style="width:60%; font-size:9px; border-top:1px solid #334155;"><b>PO No :</b> ' . $e($order['po_no'] ?? '') . '</td>
    <td style="width:40%; font-size:9px; border-top:1px solid #334155;"><b>PO Date :</b> ' . $e($orderDate) . '</td>
  </tr>
</table>
<br>
<table cellpadding="4">
  <tr>
    <th style="' . $th . ' width:8%; text-align:center;">Sr.</th>
    <th style="' . $th . ' width:47%;">Product Name</th>
    <th style="' . $th . ' width:15%; text-align:center;">Pack Size</th>
    <th style="' . $th . ' width:18%; text-align:center;">Product Category</th>
    <th style="' . $th . ' width:12%; text-align:center;">QTY</th>
  </tr>' . $rowsHtml . '
</table>';

        $pdf = new class ('P', 'mm', 'A4', true, 'UTF-8') extends \TCPDF {
            public function Header(): void {}

            public function Footer(): void
            {
                $this->SetY(-12);
                $this->SetFont('helvetica', 'I', 8);
                $this->Cell(0, 8, 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'C');
            }
        };
        $pdf->SetCreator('Advanced Pharmacy Suite');
        $pdf->SetAuthor($shopName);
        $pdf->SetTitle('Purchase Order ' . (string) ($order['po_no'] ?? ''));
        $pdf->SetMargins(8, 10, 8);
        $pdf->SetAutoPageBreak(true, 14);
        $pdf->SetFont('helvetica', '', 9);
        $pdf->AddPage('P');
        $pdf->writeHTML($html, true, false, true, false, '');
        return $pdf->Output('purchase-order.pdf', 'S');
    }

    /**
     * Last distributor each product was purchased from (excluding opening stock).
     * @param array<int,array<string,mixed>> $rows keyed by product_binding_id
     */
    private function attachLastDistributor(int $clientId, int $branchId, array &$rows): void
    {
        $ids = array_map('intval', array_keys($rows));
        $in = implode(',', $ids);
        $st = Connection::ops()->prepare(
            "SELECT pi.product_binding_id AS pb, p.supplier_id, p.supplier_name
             FROM purchase_items pi
             JOIN purchases p ON p.id = pi.purchase_id
             JOIN (
                SELECT pi2.product_binding_id, MAX(p2.id) AS last_id
                FROM purchase_items pi2
                JOIN purchases p2 ON p2.id = pi2.purchase_id
                WHERE p2.client_id = :c1 AND p2.branch_id = :b1
                  AND pi2.product_binding_id IN ($in)
                  AND COALESCE(p2.supplier_name, '') NOT LIKE 'Opening%'
                GROUP BY pi2.product_binding_id
             ) last ON last.product_binding_id = pi.product_binding_id AND last.last_id = p.id
             WHERE p.client_id = :c2 AND p.branch_id = :b2"
        );
        $st->execute(['c1' => $clientId, 'b1' => $branchId, 'c2' => $clientId, 'b2' => $branchId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $pb = (int) $r['pb'];
            if (!isset($rows[$pb])) {
                continue;
            }
            $rows[$pb]['distributor_id'] = $r['supplier_id'] !== null ? (int) $r['supplier_id'] : null;
            $rows[$pb]['distributor_name'] = trim((string) ($r['supplier_name'] ?? ''));
        }
    }

    /**
     * Distributors are a shared catalog (DistributorController is not tenant-scoped).
     * @return array<string,mixed>|null
     */
    private function distributor(int $clientId, int $id): ?array
    {
        $st = Connection::master()->prepare(
            'SELECT id, name, address, phone, email, gstin FROM distributors WHERE id = :id LIMIT 1'
        );
        $st->execute(['id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @param array<string,mixed> $r */
    private function presentOrder(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'po_no' => (string) $r['po_no'],
            'po_type' => (string) $r['po_type'],
            'distributor_id' => $r['distributor_id'] !== null ? (int) $r['distributor_id'] : null,
            'distributor_name' => (string) $r['distributor_name'],
            'total_items' => (int) $r['total_items'],
            'order_date' => (string) $r['order_date'],
            'status' => (string) $r['status'],
            'created_at' => (string) $r['created_at'],
        ];
    }

    private function validDate(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
    }

    private function str(mixed $v, int $max): ?string
    {
        $s = trim((string) ($v ?? ''));
        return $s === '' ? null : mb_substr($s, 0, $max);
    }
}
