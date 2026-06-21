<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class AuditLog extends Model
{
    protected string $connection = 'ho';
    protected string $table = 'audit_logs';

    public function record(array $data): int
    {
        return $this->insert(array_merge([
            'client_id'  => null,
            'branch_id'  => null,
            'user_id'    => null,
            'action'     => '',
            'entity'     => null,
            'entity_id'  => null,
            'meta'       => null,
            'ip_address' => null,
            'created_at' => now(),
        ], $data));
    }
}
