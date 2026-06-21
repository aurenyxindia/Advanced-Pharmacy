<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Model;
use App\Models\ProductBinding;

/**
 * Lists / manages the products a branch has bound from the master catalog.
 */
final class ProductBindingController extends ResourceController
{
    protected bool $tenantScoped = true;
    protected bool $branchScoped = true;
    protected array $fillable = ['reorder_level', 'is_active'];
    protected array $searchColumns = ['product_name'];
    protected string $defaultOrder = 'product_name ASC';

    protected function model(): Model
    {
        return new ProductBinding();
    }

    protected function storeRules(): array
    {
        // Creation happens via ProductController::bind; only updates here.
        return [
            'reorder_level' => 'integer|min:0',
            'is_active'     => 'integer|in:0,1',
        ];
    }
}
