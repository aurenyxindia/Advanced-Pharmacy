<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Model;
use App\Core\Request;
use App\Models\Manufacturer;

final class ManufacturerController extends ResourceController
{
    protected bool $tenantScoped = false;
    protected array $fillable = ['name', 's_code', 'is_active'];
    protected array $searchColumns = ['name', 's_code'];
    protected string $defaultOrder = 'name ASC';

    protected function model(): Model
    {
        return new Manufacturer();
    }

    protected function storeRules(): array
    {
        return [
            'name'      => 'required|string|min:2|max:150',
            's_code'    => 'required|string|min:1|max:20',
            'is_active' => 'integer|in:0,1',
        ];
    }

    protected function fillData(array $data, Request $request, bool $withTenant = true): array
    {
        $payload = parent::fillData($data, $request, $withTenant);
        if (isset($payload['name']) && is_string($payload['name'])) {
            $payload['name'] = strtoupper(trim($payload['name']));
        }
        if (isset($payload['s_code']) && is_string($payload['s_code'])) {
            $payload['s_code'] = strtoupper(trim($payload['s_code']));
        }
        return $payload;
    }
}
