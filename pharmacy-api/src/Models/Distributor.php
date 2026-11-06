<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class Distributor extends Model
{
    protected string $connection = 'master';
    protected string $table = 'distributors';
}
