<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class Sale extends Model
{
    protected string $connection = 'ops';
    protected string $table = 'sales';

    public function withItems(int $id): ?array
    {
        $sale = $this->find($id);
        if (!$sale) {
            return null;
        }
        $sale['items'] = $this->rawAll(
            'SELECT si.*, pb.units_per_pack,
                    COALESCE(ib.ptr, pb.ptr, 0) AS batch_ptr,
                    COALESCE(ib.pts, pb.pts, 0) AS batch_pts,
                    COALESCE(ib.purchase_rate, 0) AS batch_unit_purchase_rate,
                    COALESCE(ib.mrp, 0) AS batch_unit_mrp,
                    (
                        SELECT pi.purchase_rate
                        FROM purchase_items pi
                        INNER JOIN purchases p ON p.id = pi.purchase_id
                        WHERE pi.product_binding_id = si.product_binding_id
                          AND pi.batch_no = si.batch_no
                          AND p.client_id = s.client_id
                          AND p.branch_id = s.branch_id
                        ORDER BY p.invoice_date DESC, p.id DESC, pi.id DESC
                        LIMIT 1
                    ) AS purchase_rate,
                    (
                        SELECT pi.mrp
                        FROM purchase_items pi
                        INNER JOIN purchases p ON p.id = pi.purchase_id
                        WHERE pi.product_binding_id = si.product_binding_id
                          AND pi.batch_no = si.batch_no
                          AND p.client_id = s.client_id
                          AND p.branch_id = s.branch_id
                        ORDER BY p.invoice_date DESC, p.id DESC, pi.id DESC
                        LIMIT 1
                    ) AS purchase_mrp,
                    (
                        SELECT pi.units_per_pack
                        FROM purchase_items pi
                        INNER JOIN purchases p ON p.id = pi.purchase_id
                        WHERE pi.product_binding_id = si.product_binding_id
                          AND pi.batch_no = si.batch_no
                          AND p.client_id = s.client_id
                          AND p.branch_id = s.branch_id
                        ORDER BY p.invoice_date DESC, p.id DESC, pi.id DESC
                        LIMIT 1
                    ) AS purchase_units_per_pack
             FROM sale_items si
             INNER JOIN sales s ON s.id = si.sale_id
             LEFT JOIN product_bindings pb ON pb.id = si.product_binding_id
             LEFT JOIN inventory_batches ib ON ib.id = si.batch_id
             WHERE si.sale_id = :id
             ORDER BY si.id ASC',
            ['id' => $id]
        );
        return $sale;
    }

    public function nextInvoiceNo(int $clientId, int $branchId): string
    {
        $row = $this->rawOne(
            'SELECT COUNT(*) AS c FROM sales WHERE client_id = :c AND branch_id = :b',
            ['c' => $clientId, 'b' => $branchId]
        );
        $seq = ((int) ($row['c'] ?? 0)) + 1;
        return sprintf('INV-%03d-%06d', $branchId, $seq);
    }
}
