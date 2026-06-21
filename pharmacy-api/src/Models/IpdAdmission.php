<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class IpdAdmission extends Model
{
    protected string $connection = 'ops';
    protected string $table = 'ipd_admissions';

    public function nextAdmissionNo(int $clientId, int $branchId): string
    {
        $row = $this->rawOne(
            'SELECT COUNT(*) c FROM ipd_admissions WHERE client_id = :c AND branch_id = :b',
            ['c' => $clientId, 'b' => $branchId]
        );
        return sprintf('IPD-%03d-%05d', $branchId, ((int) ($row['c'] ?? 0)) + 1);
    }
}
