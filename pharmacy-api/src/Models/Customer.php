<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class Customer extends Model
{
    protected string $connection = 'ops';
    protected string $table = 'customers';
}
