<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class Product extends Model
{
    protected string $connection = 'master';
    protected string $table = 'products';

    /** Product Master list with generic name for each brand. */
    public function paginate(
        array $conditions = [],
        ?string $search = null,
        array $searchColumns = [],
        int $page = 1,
        int $perPage = 25,
        string $orderBy = 'id DESC'
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(5000, $perPage));
        $offset = ($page - 1) * $perPage;

        $clauses = [];
        $bindings = [];
        foreach ($conditions as $column => $value) {
            $param = 'c_' . str_replace('.', '_', $column);
            $clauses[] = "p.`{$column}` = :{$param}";
            $bindings[$param] = $value;
        }

        if ($search !== null && $search !== '' && $searchColumns !== []) {
            $likes = [];
            foreach ($searchColumns as $i => $column) {
                $param = "s_{$i}";
                $likes[] = "p.`{$column}` LIKE :{$param}";
                $bindings[$param] = '%' . $search . '%';
            }
            $likes[] = 'g.`name` LIKE :s_generic';
            $bindings['s_generic'] = '%' . $search . '%';
            $clauses[] = '(' . implode(' OR ', $likes) . ')';
        }

        $where = $clauses !== [] ? 'WHERE ' . implode(' AND ', $clauses) : '';
        $orderSql = preg_replace(
            '/\b(?!ASC\b|DESC\b)([a-zA-Z_][a-zA-Z0-9_]*)\b/i',
            'p.$1',
            $orderBy
        ) ?? ('p.' . $orderBy);

        $countStmt = $this->db()->prepare(
            "SELECT COUNT(*) FROM `{$this->table}` p
             LEFT JOIN generics g ON g.id = p.generic_id
             {$where}"
        );
        $countStmt->execute($bindings);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT p.*, g.name AS generic_name
                FROM `{$this->table}` p
                LEFT JOIN generics g ON g.id = p.generic_id
                {$where}
                ORDER BY {$orderSql}
                LIMIT {$perPage} OFFSET {$offset}";
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($bindings);

        return [
            'data' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Product list joined with generic name + substitute search.
     * @param int|null $ownClientId When set, only products created by that client (hide master catalogue).
     */
    public function search(?string $term, int $limit = 20, ?int $ownClientId = null): array
    {
        $sql = "SELECT p.*, g.name AS generic_name
                FROM products p
                LEFT JOIN generics g ON g.id = p.generic_id
                WHERE p.is_active = 1";
        $bindings = [];
        if ($ownClientId !== null && $ownClientId > 0) {
            $sql .= ' AND p.created_by_client = :own_client';
            $bindings['own_client'] = $ownClientId;
        }
        if ($term !== null && $term !== '') {
            $sql .= " AND (p.name LIKE :t1 OR g.name LIKE :t2 OR p.composition LIKE :t3 OR p.strength LIKE :t4)";
            $like = '%' . $term . '%';
            $bindings['t1'] = $like;
            $bindings['t2'] = $like;
            $bindings['t3'] = $like;
            $bindings['t4'] = $like;
        }
        // Newest catalog rows first so a just-added product appears at the top of type-ahead.
        $sql .= ' ORDER BY p.id DESC LIMIT ' . (int) $limit;
        $rows = $this->rawAll($sql, $bindings);
        foreach ($rows as &$row) {
            if (isset($row['name'])) {
                $row['name'] = \App\Support\ProductName::clean((string) $row['name']);
            }
        }
        unset($row);
        return $rows;
    }

    /**
     * Find substitutes for a product, matched by shared generic.
     * @param int|null $ownClientId When set, only substitutes created by that client.
     */
    public function substitutes(int $productId, ?int $ownClientId = null): array
    {
        $sql = "SELECT DISTINCT p2.id, p2.name, p2.manufacturer, p2.schedule_category, p2.default_gst_rate, g.name AS generic_name
             FROM products p1
             JOIN products p2 ON p2.generic_id = p1.generic_id AND p2.id <> p1.id
             LEFT JOIN generics g ON g.id = p2.generic_id
             WHERE p1.id = :pid AND p2.is_active = 1";
        $bindings = ['pid' => $productId];
        if ($ownClientId !== null && $ownClientId > 0) {
            $sql .= ' AND p2.created_by_client = :own_client';
            $bindings['own_client'] = $ownClientId;
        }
        $sql .= ' ORDER BY p2.name ASC';
        return $this->rawAll($sql, $bindings);
    }
}
