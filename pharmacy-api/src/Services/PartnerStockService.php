<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Database\Connection;
use App\Support\Env;

/**
 * Read-only stock visibility for external partners (EyeFlow EMR, etc.).
 * Always scoped to the partner's mapped client_id (+ optional branch allow-list).
 */
final class PartnerStockService
{
    /**
     * @param array{client_id:int,partner:string,branch_ids:list<int>} $partner
     */
    public function clientInfo(array $partner): array
    {
        $clientId = (int) $partner['client_id'];
        $stmt = Connection::ho()->prepare(
            "SELECT id, business_name, subscription_status
             FROM clients WHERE id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $clientId]);
        $client = $stmt->fetch();
        if (!$client) {
            throw HttpException::notFound('Mapped pharmacy client not found.');
        }

        return [
            'partner'              => $partner['partner'],
            'client_id'            => $clientId,
            'business_name'        => $client['business_name'],
            'subscription_status'  => $client['subscription_status'],
            'allowed_branch_ids'   => $partner['branch_ids'],
        ];
    }

    /**
     * @param array{client_id:int,partner:string,branch_ids:list<int>} $partner
     */
    public function branches(array $partner): array
    {
        $clientId = (int) $partner['client_id'];
        $sql = "SELECT b.id AS branch_id, b.code, b.name, b.city, b.status
                FROM branches b
                WHERE b.client_id = :c AND b.status = 'active'";
        $bind = ['c' => $clientId];

        if ($partner['branch_ids'] !== []) {
            $placeholders = [];
            foreach ($partner['branch_ids'] as $i => $bid) {
                $key = 'b' . $i;
                $placeholders[] = ':' . $key;
                $bind[$key] = $bid;
            }
            $sql .= ' AND b.id IN (' . implode(',', $placeholders) . ')';
        }

        $sql .= ' ORDER BY b.name';
        $stmt = Connection::ho()->prepare($sql);
        $stmt->execute($bind);

        return $stmt->fetchAll();
    }

    /**
     * Batch-level stock rows for EMR visibility.
     *
     * @param array{client_id:int,partner:string,branch_ids:list<int>} $partner
     */
    public function stockBatches(array $partner, array $filters): array
    {
        $clientId = (int) $partner['client_id'];
        $branchId = (int) ($filters['branch_id'] ?? 0);
        $q = trim((string) ($filters['q'] ?? ''));
        $inStockOnly = filter_var($filters['in_stock_only'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $includeExpired = filter_var($filters['include_expired'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $limit = min(20000, max(1, (int) ($filters['limit'] ?? 500)));

        $this->assertBranchAllowed($partner, $branchId);

        $master = (string) Env::get('DB_MASTER', 'pharma_master');

        $sql = "SELECT ib.id AS batch_id,
                       ib.branch_id,
                       pb.id AS binding_id,
                       pb.master_product_id,
                       pb.product_name,
                       g.name AS generic_name,
                       pb.manufacturer,
                       pb.manufacturer_short,
                       pb.schedule_category,
                       pb.pack_size,
                       pb.units_per_pack,
                       pb.hsn_code,
                       pb.gst_rate,
                       ib.batch_no,
                       ib.expiry_date,
                       ib.mrp,
                       ib.quantity AS available_qty,
                       CASE WHEN ib.expiry_date IS NOT NULL AND ib.expiry_date < CURDATE() THEN 1 ELSE 0 END AS is_expired
                FROM inventory_batches ib
                INNER JOIN product_bindings pb ON pb.id = ib.product_binding_id
                LEFT JOIN `{$master}`.generics g ON g.id = pb.generic_id
                WHERE ib.client_id = :c AND pb.client_id = :c2 AND pb.is_active = 1";

        $bind = ['c' => $clientId, 'c2' => $clientId];

        if ($branchId > 0) {
            $sql .= ' AND ib.branch_id = :b';
            $bind['b'] = $branchId;
        } elseif ($partner['branch_ids'] !== []) {
            $placeholders = [];
            foreach ($partner['branch_ids'] as $i => $bid) {
                $key = 'ab' . $i;
                $placeholders[] = ':' . $key;
                $bind[$key] = $bid;
            }
            $sql .= ' AND ib.branch_id IN (' . implode(',', $placeholders) . ')';
        }

        if ($inStockOnly) {
            $sql .= ' AND ib.quantity > 0';
        }

        if (!$includeExpired) {
            $sql .= ' AND (ib.expiry_date IS NULL OR ib.expiry_date >= CURDATE())';
        }

        if ($q !== '') {
            $sql .= ' AND (pb.product_name LIKE :q OR g.name LIKE :q2 OR ib.batch_no LIKE :q3 OR pb.manufacturer LIKE :q4)';
            $like = '%' . $q . '%';
            $bind['q'] = $like;
            $bind['q2'] = $like;
            $bind['q3'] = $like;
            $bind['q4'] = $like;
        }

        $sql .= ' ORDER BY pb.product_name ASC, (ib.expiry_date IS NULL), ib.expiry_date ASC LIMIT ' . $limit;

        $stmt = Connection::ops()->prepare($sql);
        $stmt->execute($bind);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['batch_id'] = (int) $row['batch_id'];
            $row['branch_id'] = (int) $row['branch_id'];
            $row['binding_id'] = (int) $row['binding_id'];
            $row['master_product_id'] = $row['master_product_id'] !== null ? (int) $row['master_product_id'] : null;
            $row['available_qty'] = (int) $row['available_qty'];
            $row['units_per_pack'] = (int) ($row['units_per_pack'] ?? 1);
            $row['mrp'] = $row['mrp'] !== null ? (float) $row['mrp'] : null;
            $row['gst_rate'] = $row['gst_rate'] !== null ? (float) $row['gst_rate'] : null;
            $row['is_expired'] = (int) $row['is_expired'] === 1;
            if (!empty($row['expiry_date'])) {
                $row['expiry_display'] = date('m/y', strtotime((string) $row['expiry_date']));
            } else {
                $row['expiry_display'] = null;
            }
        }
        unset($row);

        return [
            'client_id'   => $clientId,
            'branch_id'   => $branchId > 0 ? $branchId : null,
            'count'       => count($rows),
            'qty_unit'    => 'base_units',
            'rows'        => $rows,
        ];
    }

    /**
     * Product-level sellable stock (aggregated across batches).
     *
     * @param array{client_id:int,partner:string,branch_ids:list<int>} $partner
     */
    public function stockProducts(array $partner, array $filters): array
    {
        $clientId = (int) $partner['client_id'];
        $branchId = (int) ($filters['branch_id'] ?? 0);
        $q = trim((string) ($filters['q'] ?? ''));
        $inStockOnly = filter_var($filters['in_stock_only'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $limit = min(2000, max(1, (int) ($filters['limit'] ?? 500)));

        $this->assertBranchAllowed($partner, $branchId);

        $master = (string) Env::get('DB_MASTER', 'pharma_master');
        // Inline qty expressions (self-contained; do not rely on InventoryService helpers that may lag on deploy).
        $sellable = "COALESCE((
            SELECT SUM(ib.quantity)
            FROM inventory_batches ib
            WHERE ib.client_id = pb.client_id
              AND ib.branch_id = pb.branch_id
              AND ib.product_binding_id = pb.id
              AND (ib.expiry_date IS NULL OR ib.expiry_date >= CURDATE())
        ), 0)";
        $available = "COALESCE((
            SELECT SUM(ib.quantity)
            FROM inventory_batches ib
            WHERE ib.client_id = pb.client_id
              AND ib.branch_id = pb.branch_id
              AND ib.product_binding_id = pb.id
        ), 0)";

        $sql = "SELECT pb.id AS binding_id,
                       pb.branch_id,
                       pb.master_product_id,
                       pb.product_name,
                       g.name AS generic_name,
                       pb.manufacturer,
                       pb.manufacturer_short,
                       pb.schedule_category,
                       pb.pack_size,
                       pb.units_per_pack,
                       pb.hsn_code,
                       pb.gst_rate,
                       {$sellable} AS sellable_qty,
                       {$available} AS available_qty,
                       (SELECT ib.mrp FROM inventory_batches ib
                         WHERE ib.product_binding_id = pb.id AND ib.quantity > 0
                           AND (ib.expiry_date IS NULL OR ib.expiry_date >= CURDATE())
                         ORDER BY (ib.expiry_date IS NULL), ib.expiry_date ASC LIMIT 1) AS mrp
                FROM product_bindings pb
                LEFT JOIN `{$master}`.generics g ON g.id = pb.generic_id
                WHERE pb.client_id = :c AND pb.is_active = 1";

        $bind = ['c' => $clientId];

        if ($branchId > 0) {
            $sql .= ' AND pb.branch_id = :b';
            $bind['b'] = $branchId;
        } elseif ($partner['branch_ids'] !== []) {
            $placeholders = [];
            foreach ($partner['branch_ids'] as $i => $bid) {
                $key = 'ab' . $i;
                $placeholders[] = ':' . $key;
                $bind[$key] = $bid;
            }
            $sql .= ' AND pb.branch_id IN (' . implode(',', $placeholders) . ')';
        }

        if ($inStockOnly) {
            $sql .= " AND {$sellable} > 0";
        }

        if ($q !== '') {
            $sql .= ' AND (pb.product_name LIKE :q OR g.name LIKE :q2 OR pb.manufacturer LIKE :q3)';
            $like = '%' . $q . '%';
            $bind['q'] = $like;
            $bind['q2'] = $like;
            $bind['q3'] = $like;
        }

        $sql .= ' ORDER BY pb.product_name ASC LIMIT ' . $limit;

        $stmt = Connection::ops()->prepare($sql);
        $stmt->execute($bind);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['binding_id'] = (int) $row['binding_id'];
            $row['branch_id'] = (int) $row['branch_id'];
            $row['master_product_id'] = $row['master_product_id'] !== null ? (int) $row['master_product_id'] : null;
            $row['sellable_qty'] = (int) $row['sellable_qty'];
            $row['available_qty'] = (int) $row['available_qty'];
            $row['units_per_pack'] = (int) ($row['units_per_pack'] ?? 1);
            $row['mrp'] = $row['mrp'] !== null ? (float) $row['mrp'] : null;
            $row['gst_rate'] = $row['gst_rate'] !== null ? (float) $row['gst_rate'] : null;
            $row['in_stock'] = $row['sellable_qty'] > 0;
        }
        unset($row);

        return [
            'client_id' => $clientId,
            'branch_id' => $branchId > 0 ? $branchId : null,
            'count'     => count($rows),
            'qty_unit'  => 'base_units',
            'rows'      => $rows,
        ];
    }

    /**
     * Fast search for EMR prescription UI (name / generic).
     *
     * @param array{client_id:int,partner:string,branch_ids:list<int>} $partner
     */
    public function search(array $partner, string $term, int $branchId = 0, int $limit = 40): array
    {
        $term = trim($term);
        if (strlen($term) < 2) {
            throw HttpException::validation(['q' => ['Enter at least 2 characters to search.']]);
        }

        return $this->stockProducts($partner, [
            'q'             => $term,
            'branch_id'     => $branchId,
            'in_stock_only' => true,
            'limit'         => min(100, max(1, $limit)),
        ]);
    }

    /**
     * @param array{client_id:int,partner:string,branch_ids:list<int>} $partner
     */
    private function assertBranchAllowed(array $partner, int $branchId): void
    {
        if ($branchId <= 0) {
            return;
        }

        if ($partner['branch_ids'] !== [] && !in_array($branchId, $partner['branch_ids'], true)) {
            throw HttpException::forbidden('This API key is not allowed to access that branch.');
        }

        $stmt = Connection::ho()->prepare(
            'SELECT id FROM branches WHERE id = :b AND client_id = :c LIMIT 1'
        );
        $stmt->execute(['b' => $branchId, 'c' => $partner['client_id']]);
        if (!$stmt->fetch()) {
            throw HttpException::validation(['branch_id' => ['Branch does not belong to this pharmacy client.']]);
        }
    }
}
