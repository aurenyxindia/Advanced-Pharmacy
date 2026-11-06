<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Model;
use App\Models\Branch;

final class BranchController extends ResourceController
{
    protected bool $tenantScoped = true;
    protected array $fillable = [
        'code', 'name', 'is_head_office', 'drug_license_no', 'gstin',
        'address', 'city', 'state', 'pincode', 'phone', 'status',
    ];
    protected array $searchColumns = ['code', 'name', 'city'];
    protected string $defaultOrder = 'is_head_office DESC, name ASC';

    protected function model(): Model
    {
        return new Branch();
    }

    protected function storeRules(): array
    {
        return [
            'code'            => 'required|string|max:20',
            'name'            => 'required|string|min:2|max:150',
            'is_head_office'  => 'integer|in:0,1',
            'drug_license_no' => 'string|max:60',
            'gstin'           => 'required|string|regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/',
            'address'         => 'string|max:255',
            'city'            => 'string|max:80',
            'state'           => 'string|max:80',
            'pincode'         => 'string|regex:/^[1-9][0-9]{5}$/',
            'phone'           => 'string|regex:/^[6-9]\d{9}$/',
            'status'          => 'in:active,inactive',
        ];
    }
}
