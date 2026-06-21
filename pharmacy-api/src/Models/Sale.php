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
            'SELECT * FROM sale_items WHERE sale_id = :id ORDER BY id ASC',
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
