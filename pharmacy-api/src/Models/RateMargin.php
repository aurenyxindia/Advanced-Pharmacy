<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class RateMargin extends Model
{
    protected string $connection = 'ops';
    protected string $table = 'rate_margins';

    public function forCategory(int $clientId, string $category): ?array
    {
        return $this->firstWhere(['client_id' => $clientId, 'category' => $category]);
    }
}
