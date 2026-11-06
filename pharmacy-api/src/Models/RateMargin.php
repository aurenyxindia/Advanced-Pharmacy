<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class RateMargin extends Model
{
    /** Common (Fixed) margin row — used when a product has no category or no row for its category. */
    public const COMMON = 'COMMON';

    protected string $connection = 'ops';
    protected string $table = 'rate_margins';

    public function forCategory(int $clientId, string $category): ?array
    {
        $category = trim($category);
        if ($category !== '' && strcasecmp($category, self::COMMON) !== 0) {
            $row = $this->firstWhere(['client_id' => $clientId, 'category' => $category]);
            if ($row) {
                return $row;
            }
        }
        return $this->firstWhere(['client_id' => $clientId, 'category' => self::COMMON]);
    }
}
