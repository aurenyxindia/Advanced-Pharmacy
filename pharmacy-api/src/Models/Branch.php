<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class Branch extends Model
{
    protected string $connection = 'ho';
    protected string $table = 'branches';

    public function forClient(int $clientId): array
    {
        return $this->where(['client_id' => $clientId], 'name ASC');
    }
}
