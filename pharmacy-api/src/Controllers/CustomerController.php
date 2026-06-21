<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Model;
use App\Core\Request;
use App\Core\Response;
use App\Models\Customer;

final class CustomerController extends ResourceController
{
    protected bool $tenantScoped = true;
    protected array $fillable = ['name', 'phone', 'email', 'address', 'dob', 'gstin', 'is_chronic'];
    protected array $searchColumns = ['name', 'phone', 'email'];
    protected string $defaultOrder = 'name ASC';

    protected function model(): Model
    {
        return new Customer();
    }

    protected function storeRules(): array
    {
        return [
            'name'       => 'required|string|min:2|max:150',
            'phone'      => 'string|max:20',
            'email'      => 'email|max:150',
            'address'    => 'string|max:255',
            'gstin'      => 'string|max:20',
            'is_chronic' => 'integer|in:0,1',
        ];
    }

    /** Phone-based patient lookup for the billing screen. Returns the customer or null. */
    public function lookup(Request $request): Response
    {
        $phone = trim((string) $request->query('phone', ''));
        if ($phone === '') {
            return $this->success(null, 'No phone supplied.');
        }
        $row = (new Customer())->firstWhere([
            'client_id' => $request->clientId(),
            'phone'     => $phone,
        ]);
        return $this->success($row, $row ? 'Customer found.' : 'New customer.');
    }
}
