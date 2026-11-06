<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class HsnCode extends Model
{
    protected string $connection = 'master';
    protected string $table = 'hsn_codes';
}
