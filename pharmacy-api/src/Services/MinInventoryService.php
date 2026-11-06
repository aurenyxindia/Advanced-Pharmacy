<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Database\Connection;
use PDO;

/**
 * Minimum Inventory Trigger (Retail mi_trigger) on top of the existing reorder_level
 * columns of product_bindings / inventory_stock. Quantities are loose units, same as stock.
 * Every stocked product uses DEFAULT_MIN unless a custom minimum (reorder_level > 0) is set.
 * Short = stock below its minimum → Dashboard "Minimum Inventory Alert" → Purchase Order Auto-Purchase.
 */
final class MinInventoryService
{
    public const DEFAULT_MIN = 15;
    public const SALES_MONTHS = 4;

    private const CUSTOM_SQL = 'GREATEST(pb.reorder_level, COALESCE(inv.reorder_level, 0))';

    /** Effective minimum for a stored reorder level (0 = universal default). */
    public static function effectiveMin(int $customMin): int
    {
        return $customMin > 0 ? $customMin : self::DEFAULT_MIN;
    }

    /** Stock products for the trigger picker (in-stock only unless searching, like Retail). */
    public function stockProducts(int $clientId, int $branchId, string $search): array
    {
        $where = ['pb.client_id = :c', 'pb.is_active = 1'];
        $bind = ['c' => $clientId];
        if ($branchId > 0) {
            $where[] = 'pb.branch_id = :b';
            $bind['b'] = $branchId;
        }
        $search = trim($search);
        if ($search !== '') {
            $where[] = 'pb.product_name LIKE :q';
            $bind['q'] = '%' . $search . '%';
        } else {
            $where[] = 'COALESCE(inv.quantity, 0) > 0';
        }
        $st = Connection::ops()->prepare(
            'SELECT pb.id, pb.product_name, pb.pack_size, pb.units_per_pack,
                    COALESCE(inv.quantity, 0) AS available_qty,
                    ' . self::CUSTOM_SQL . ' AS custom_min
             FROM product_bindings pb
             LEFT JOIN inventory_stock inv
                    ON inv.product_binding_id = pb.id AND inv.client_id = pb.client_id AND inv.branch_id = pb.branch_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY pb.product_name ASC
             LIMIT 100'
        );
        $st->execute($bind);
        return array_map(fn (array $r) => $this->present($r), $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Products with a custom minimum (Opening Stock → Minimum Inventory tab). */
    public function triggers(int $clientId, int $branchId): array
    {
        $where = ['pb.client_id = :c', 'pb.is_active = 1', self::CUSTOM_SQL . ' > 0'];
        $bind = ['c' => $clientId];
        if ($branchId > 0) {
            $where[] = 'pb.branch_id = :b';
            $bind['b'] = $branchId;
        }
        $st = Connection::ops()->prepare(
            'SELECT pb.id, pb.product_name, pb.pack_size, pb.units_per_pack,
                    COALESCE(inv.quantity, 0) AS available_qty,
                    ' . self::CUSTOM_SQL . ' AS custom_min
             FROM product_bindings pb
             LEFT JOIN inventory_stock inv
                    ON inv.product_binding_id = pb.id AND inv.client_id = pb.client_id AND inv.branch_id = pb.branch_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY pb.product_name ASC'
        );
        $st->execute($bind);
        return array_map(fn (array $r) => $this->present($r), $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * All stocked products below their minimum, most-sold first (last SALES_MONTHS months).
     * $limit > 0 → Dashboard top N; total always counts every short product.
     */
    public function shortStock(int $clientId, int $branchId, int $limit = 0): array
    {
        $where = ['pb.client_id = :c', 'pb.is_active = 1'];
        $bind = ['c' => $clientId];
        if ($branchId > 0) {
            $where[] = 'pb.branch_id = :b';
            $bind['b'] = $branchId;
        }
        $where[] = 'inv.quantity < (CASE WHEN ' . self::CUSTOM_SQL . ' > 0 THEN ' . self::CUSTOM_SQL . ' ELSE ' . self::DEFAULT_MIN . ' END)';
        $st = Connection::ops()->prepare(
            'SELECT pb.id, pb.product_name, pb.pack_size, pb.units_per_pack, pb.mrp,
                    inv.quantity AS available_qty,
                    ' . self::CUSTOM_SQL . ' AS custom_min
             FROM product_bindings pb
             JOIN inventory_stock inv
               ON inv.product_binding_id = pb.id AND inv.client_id = pb.client_id AND inv.branch_id = pb.branch_id
             WHERE ' . implode(' AND ', $where)
        );
        $st->execute($bind);
        $sold = $this->soldUnits($clientId, $branchId);

        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r['sold_qty'] = $sold[(int) $r['id']] ?? 0;
            $rows[] = $this->present($r) + ['mrp' => round((float) ($r['mrp'] ?? 0), 2)];
        }
        usort(
            $rows,
            static fn (array $a, array $b) => [$b['sold_qty'], $a['available_qty'], $a['product_name']]
                <=> [$a['sold_qty'], $b['available_qty'], $b['product_name']],
        );

        return [
            'default_min' => self::DEFAULT_MIN,
            'sales_months' => self::SALES_MONTHS,
            'total' => count($rows),
            'products' => $limit > 0 ? array_slice($rows, 0, $limit) : $rows,
        ];
    }

    /** Reports → Stock → Short Stock Report: every short product with MRP and last distributor. */
    public function shortStockReport(int $clientId, int $branchId): array
    {
        $products = $this->shortStock($clientId, $branchId)['products'];
        $lastDist = $this->lastDistributors($clientId, array_map(static fn (array $p) => $p['id'], $products));
        return array_map(static fn (array $p) => [
            'product_name' => $p['product_name'],
            'pack_size' => $p['pack_size'],
            'mrp' => $p['mrp'],
            'sold_qty' => $p['sold_qty'],
            'available_qty' => $p['available_qty'],
            'min_qty' => $p['min_qty'],
            'distributor_name' => $lastDist[$p['id']] ?? '',
        ], $products);
    }

    /**
     * Latest purchase supplier per binding (opening stock excluded).
     * @param int[] $bindingIds
     * @return array<int,string>
     */
    private function lastDistributors(int $clientId, array $bindingIds): array
    {
        if ($bindingIds === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $bindingIds));
        $st = Connection::ops()->prepare(
            "SELECT pi.product_binding_id AS pb, p.supplier_name
             FROM purchase_items pi
             JOIN purchases p ON p.id = pi.purchase_id
             JOIN (
                SELECT pi2.product_binding_id, MAX(p2.id) AS last_id
                FROM purchase_items pi2
                JOIN purchases p2 ON p2.id = pi2.purchase_id
                WHERE p2.client_id = :c1
                  AND pi2.product_binding_id IN ($in)
                  AND COALESCE(p2.supplier_name, '') NOT LIKE 'Opening%'
                GROUP BY pi2.product_binding_id
             ) last ON last.product_binding_id = pi.product_binding_id AND last.last_id = p.id
             WHERE p.client_id = :c2"
        );
        $st->execute(['c1' => $clientId, 'c2' => $clientId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['pb']] = trim((string) ($r['supplier_name'] ?? ''));
        }
        return $out;
    }

    public function save(int $clientId, int $branchId, int $bindingId, int $minQty): array
    {
        if ($minQty < 1) {
            throw HttpException::validation(['min_qty' => ['Minimum qty must be at least 1.']]);
        }
        $binding = $this->binding($clientId, $branchId, $bindingId);
        $this->setLevel($clientId, (int) $binding['branch_id'], $bindingId, $minQty);
        $rows = array_values(array_filter(
            $this->stockProducts($clientId, (int) $binding['branch_id'], (string) $binding['product_name']),
            static fn (array $r) => $r['id'] === $bindingId,
        ));
        return $rows[0] ?? ['id' => $bindingId, 'min_qty' => $minQty];
    }

    /** Back to the universal default minimum. */
    public function remove(int $clientId, int $branchId, int $bindingId): void
    {
        $binding = $this->binding($clientId, $branchId, $bindingId);
        $this->setLevel($clientId, (int) $binding['branch_id'], $bindingId, 0);
    }

    /** @return array<int,float> binding id → units sold (net of returns) in the last SALES_MONTHS months */
    private function soldUnits(int $clientId, int $branchId): array
    {
        $start = date('Y-m', strtotime('first day of -' . (self::SALES_MONTHS - 1) . ' month')) . '-01';
        $sql = "SELECT si.product_binding_id AS pb,
                       SUM(CASE WHEN si.unit_type = 'pack' THEN (si.quantity - COALESCE(si.return_qty, 0)) * GREATEST(pb.units_per_pack, 1)
                                ELSE si.quantity - COALESCE(si.return_qty, 0) END) AS units
                FROM sales s
                JOIN sale_items si ON si.sale_id = s.id
                JOIN product_bindings pb ON pb.id = si.product_binding_id
                WHERE s.client_id = :c AND s.invoice_date >= :start";
        $bind = ['c' => $clientId, 'start' => $start];
        if ($branchId > 0) {
            $sql .= ' AND s.branch_id = :b';
            $bind['b'] = $branchId;
        }
        $st = Connection::ops()->prepare($sql . ' GROUP BY si.product_binding_id');
        $st->execute($bind);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['pb']] = max(0.0, (float) $r['units']);
        }
        return $out;
    }

    private function setLevel(int $clientId, int $branchId, int $bindingId, int $level): void
    {
        $db = Connection::ops();
        $db->prepare(
            'UPDATE product_bindings SET reorder_level = :l, updated_at = :now WHERE id = :id AND client_id = :c'
        )->execute(['l' => $level, 'now' => now(), 'id' => $bindingId, 'c' => $clientId]);
        $db->prepare(
            'UPDATE inventory_stock SET reorder_level = :l
             WHERE product_binding_id = :id AND client_id = :c AND branch_id = :b'
        )->execute(['l' => $level, 'id' => $bindingId, 'c' => $clientId, 'b' => $branchId]);
    }

    /** @return array<string,mixed> */
    private function binding(int $clientId, int $branchId, int $bindingId): array
    {
        $sql = 'SELECT id, branch_id, product_name FROM product_bindings WHERE id = :id AND client_id = :c';
        $bind = ['id' => $bindingId, 'c' => $clientId];
        if ($branchId > 0) {
            $sql .= ' AND branch_id = :b';
            $bind['b'] = $branchId;
        }
        $st = Connection::ops()->prepare($sql . ' LIMIT 1');
        $st->execute($bind);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw HttpException::validation(['product_binding_id' => ['Please select a product.']]);
        }
        return $row;
    }

    /** @param array<string,mixed> $r */
    private function present(array $r): array
    {
        $available = (int) $r['available_qty'];
        $custom = (int) $r['custom_min'];
        $min = self::effectiveMin($custom);
        return [
            'id' => (int) $r['id'],
            'product_name' => (string) $r['product_name'],
            'pack_size' => (string) ($r['pack_size'] ?? ''),
            'units_per_pack' => max(1, (int) $r['units_per_pack']),
            'available_qty' => $available,
            'min_qty' => $min,
            'custom_min' => $custom,
            'is_custom' => $custom > 0,
            'is_low' => $available < $min,
            'sold_qty' => round((float) ($r['sold_qty'] ?? 0), 1),
        ];
    }
}
