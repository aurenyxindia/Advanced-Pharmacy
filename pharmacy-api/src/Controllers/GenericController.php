<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Model;
use App\Models\Generic;

final class GenericController extends ResourceController
{
    protected bool $tenantScoped = false;
    protected array $fillable = ['name', 'therapeutic_class', 'is_active'];
    protected array $searchColumns = ['name', 'therapeutic_class'];
    protected string $defaultOrder = 'name ASC';

    protected function model(): Model
    {
        return new Generic();
    }

    protected function storeRules(): array
    {
        return [
            'name'              => 'required|string|min:2|max:180',
            'therapeutic_class' => 'string|max:120',
            'is_active'         => 'integer|in:0,1',
        ];
    }
}
