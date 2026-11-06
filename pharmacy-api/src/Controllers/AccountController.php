<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Model;
use App\Models\Account;

final class AccountController extends ResourceController
{
    protected bool $tenantScoped = true;
    protected array $fillable = [
        'name', 'type', 'opening_balance', 'balance_type', 'gstin', 'is_active',
        'bank_name', 'account_no', 'account_holder_name', 'ifsc_code', 'bank_branch', 'account_type',
    ];
    protected array $searchColumns = ['name', 'gstin', 'bank_name', 'account_no', 'ifsc_code'];
    protected string $defaultOrder = 'name ASC';

    protected function model(): Model
    {
        return new Account();
    }

    protected function storeRules(): array
    {
        return [
            'name'                 => 'required|string|min:2|max:150',
            'type'                 => 'required|in:asset,liability,income,expense,equity,customer,supplier,bank,cash',
            'opening_balance'      => 'numeric',
            'balance_type'         => 'in:debit,credit',
            'gstin'                => 'string|max:20',
            'is_active'            => 'integer|in:0,1',
            'bank_name'            => 'string|max:150',
            'account_no'           => 'string|max:40',
            'account_holder_name'  => 'string|max:150',
            'ifsc_code'            => 'string|regex:/^[A-Z]{4}0[A-Z0-9]{6}$/',
            'bank_branch'          => 'string|max:120',
            'account_type'         => 'string|in:CURRENT,SAVING',
        ];
    }

    protected function fillData(array $data, \App\Core\Request $request, bool $withTenant = true): array
    {
        $payload = parent::fillData($data, $request, $withTenant);
        foreach (['name', 'bank_name', 'account_holder_name', 'bank_branch'] as $field) {
            if (isset($payload[$field]) && is_string($payload[$field])) {
                $payload[$field] = strtoupper(trim($payload[$field]));
            }
        }
        if (isset($payload['ifsc_code']) && is_string($payload['ifsc_code'])) {
            $payload['ifsc_code'] = strtoupper(trim($payload['ifsc_code']));
        }
        if (($payload['type'] ?? '') !== 'bank') {
            $payload['bank_name'] = null;
            $payload['account_no'] = null;
            $payload['account_holder_name'] = null;
            $payload['ifsc_code'] = null;
            $payload['bank_branch'] = null;
            $payload['account_type'] = null;
        }
        return $payload;
    }
}
