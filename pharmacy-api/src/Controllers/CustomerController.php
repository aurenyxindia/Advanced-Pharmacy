<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Model;
use App\Models\Customer;

final class CustomerController extends ResourceController
{
    protected bool $tenantScoped = true;
    protected array $fillable = ['name', 'phone', 'email', 'address', 'dob', 'gstin'];
    protected array $searchColumns = ['name', 'phone', 'email'];
    protected string $defaultOrder = 'name ASC';

    protected function model(): Model
    {
        return new Customer();
    }

    protected function storeRules(): array
    {
        return [
            'name'    => 'required|string|min:2|max:150',
            'phone'   => 'string|max:20',
            'email'   => 'email|max:150',
            'address' => 'string|max:255',
            'gstin'   => 'string|max:20',
        ];
    }
}
