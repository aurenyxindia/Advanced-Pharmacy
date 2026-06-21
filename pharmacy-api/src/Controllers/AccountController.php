<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Model;
use App\Models\Account;

final class AccountController extends ResourceController
{
    protected bool $tenantScoped = true;
    protected array $fillable = ['name', 'type', 'opening_balance', 'balance_type', 'gstin', 'is_active'];
    protected array $searchColumns = ['name', 'gstin'];
    protected string $defaultOrder = 'name ASC';

    protected function model(): Model
    {
        return new Account();
    }

    protected function storeRules(): array
    {
        return [
            'name'            => 'required|string|min:2|max:150',
            'type'            => 'required|in:asset,liability,income,expense,equity,customer,supplier,bank,cash',
            'opening_balance' => 'numeric',
            'balance_type'    => 'in:debit,credit',
            'gstin'           => 'string|max:20',
            'is_active'       => 'integer|in:0,1',
        ];
    }
}
