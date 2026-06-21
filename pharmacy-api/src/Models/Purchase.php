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
            'SELECT * FROM purchase_items WHERE purchase_id = :id ORDER BY id ASC',
            ['id' => $id]
        );
        return $purchase;
    }
}
