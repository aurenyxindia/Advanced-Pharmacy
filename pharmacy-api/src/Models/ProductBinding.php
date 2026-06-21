<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class ProductBinding extends Model
{
    protected string $connection = 'ops';
    protected string $table = 'product_bindings';

    public function findBinding(int $clientId, int $branchId, int $masterProductId): ?array
    {
        return $this->firstWhere([
            'client_id'         => $clientId,
            'branch_id'         => $branchId,
            'master_product_id' => $masterProductId,
        ]);
    }
}
