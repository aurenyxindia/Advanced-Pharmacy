<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class Purchase extends Model
{
    protected string $connection = 'ops';
    protected string $table = 'purchases';

    public function withItems(int $id): ?array
    {
        $purchase = $this->find($id);
        if (!$purchase) {
            return null;
        }
        $purchase['items'] = $this->rawAll(
            'SELECT pi.*, pb.pack_size AS pack_size
             FROM purchase_items pi
             LEFT JOIN product_bindings pb ON pb.id = pi.product_binding_id
             WHERE pi.purchase_id = :id
             ORDER BY pi.id ASC',
            ['id' => $id]
        );
        return $purchase;
    }
}
