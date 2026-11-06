<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Model;
use App\Models\Doctor;

final class DoctorController extends ResourceController
{
    protected bool $tenantScoped = true;
    protected array $fillable = [
        'name', 'clinic_hospital_name', 'registration_no', 'phone', 'email', 'address', 'qualification',
    ];
    protected array $searchColumns = ['name', 'clinic_hospital_name', 'registration_no', 'phone', 'email'];
    protected string $defaultOrder = 'name ASC';

    protected function model(): Model
    {
        return new Doctor();
    }

    protected function storeRules(): array
    {
        return [
            'name'                  => 'required|string|min:2|max:150',
            'clinic_hospital_name'  => 'string|max:150',
            'registration_no'       => 'string|max:60',
            'phone'                 => 'string|regex:/^[6-9]\d{9}$/',
            'email'                 => 'email|max:150',
            'address'               => 'required|string|max:255',
            'qualification'         => 'string|max:120',
        ];
    }

    protected function fillData(array $data, \App\Core\Request $request, bool $withTenant = true): array
    {
        $payload = parent::fillData($data, $request, $withTenant);
        foreach (['name', 'clinic_hospital_name', 'registration_no', 'address', 'qualification'] as $field) {
            if (isset($payload[$field]) && is_string($payload[$field])) {
                $payload[$field] = strtoupper(trim($payload[$field]));
            }
        }
        if (isset($payload['email']) && is_string($payload['email'])) {
            $payload['email'] = strtolower(trim($payload['email']));
        }
        return $payload;
    }
}
