<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Model;
use App\Models\Doctor;

final class DoctorController extends ResourceController
{
    protected bool $tenantScoped = true;
    protected array $fillable = ['name', 'registration_no', 'phone', 'qualification'];
    protected array $searchColumns = ['name', 'registration_no', 'phone'];
    protected string $defaultOrder = 'name ASC';

    protected function model(): Model
    {
        return new Doctor();
    }

    protected function storeRules(): array
    {
        return [
            'name'            => 'required|string|min:2|max:150',
            'registration_no' => 'string|max:60',
            'phone'           => 'string|max:20',
            'qualification'   => 'string|max:120',
        ];
    }
}
