<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class Supplier extends Model
{
    protected string $connection = 'ops';
    protected string $table = 'suppliers';
}
