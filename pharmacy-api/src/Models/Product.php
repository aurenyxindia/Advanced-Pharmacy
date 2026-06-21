<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class Product extends Model
{
    protected string $connection = 'master';
    protected string $table = 'products';

    /** Product list joined with generic name + substitute search. */
    public function search(?string $term, int $limit = 20): array
    {
        $sql = "SELECT p.*, g.name AS generic_name
                FROM products p
                LEFT JOIN generics g ON g.id = p.generic_id
                WHERE p.is_active = 1";
        $bindings = [];
        if ($term !== null && $term !== '') {
            $sql .= " AND (p.name LIKE :t1 OR g.name LIKE :t2 OR p.composition LIKE :t3)";
            $like = '%' . $term . '%';
            $bindings['t1'] = $like;
            $bindings['t2'] = $like;
            $bindings['t3'] = $like;
        }
        $sql .= ' ORDER BY p.name ASC LIMIT ' . (int) $limit;
        return $this->rawAll($sql, $bindings);
    }

    /** Find substitutes for a product, matched by shared generic. */
    public function substitutes(int $productId): array
    {
        return $this->rawAll(
            "SELECT DISTINCT p2.id, p2.name, p2.manufacturer, p2.schedule_category, p2.default_gst_rate, g.name AS generic_name
             FROM products p1
             JOIN products p2 ON p2.generic_id = p1.generic_id AND p2.id <> p1.id
             LEFT JOIN generics g ON g.id = p2.generic_id
             WHERE p1.id = :pid AND p2.is_active = 1
             ORDER BY p2.name ASC",
            ['pid' => $productId]
        );
    }
}
