<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class Generic extends Model
{
    protected string $connection = 'master';
    protected string $table = 'generics';
}
