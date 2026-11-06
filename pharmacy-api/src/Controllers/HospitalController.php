<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Model;
use App\Core\Request;
use App\Core\Response;
use App\Models\Hospital;
use App\Services\DispensingService;

/** Hospital Master (Retail master/hospital) — per branch, one default for Dispensing. */
final class HospitalController extends ResourceController
{
    protected bool $tenantScoped = true;
    protected bool $branchScoped = true;
    protected array $fillable = ['name', 'address', 'phone', 'is_default'];
    protected array $searchColumns = ['name', 'address', 'phone'];
    protected string $defaultOrder = 'is_default DESC, name ASC';

    protected function model(): Model
    {
        DispensingService::ensureSchema();
        return new Hospital();
    }

    protected function storeRules(): array
    {
        return [
            'name'       => 'required|string|min:2|max:150',
            'address'    => 'required|string|max:255',
            'phone'      => 'required|string|regex:/^\d{6,15}$/',
            'is_default' => 'in:0,1',
        ];
    }

    public function index(Request $request): Response
    {
        $result = $this->model()->paginate(
            $this->scopeConditions($request),
            $request->query('search'),
            $this->searchColumns,
            (int) $request->query('page', 1),
            (int) $request->query('per_page', 25),
            $this->defaultOrder
        );
        foreach ($result['data'] as &$row) {
            $row['default_label'] = (int) ($row['is_default'] ?? 0) === 1 ? 'Default' : '';
        }
        unset($row);
        return $this->success($result, 'List fetched.');
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, $this->storeRules());
        $payload = $this->fillData($data, $request);
        $payload['created_at'] = now();
        $payload['updated_at'] = now();
        $id = $this->model()->insert($payload);
        $this->syncDefault($request, $id);
        return $this->created(['id' => $id], 'Hospital added.');
    }

    public function update(Request $request): Response
    {
        $response = parent::update($request);
        $this->syncDefault($request, (int) $request->attribute('id'));
        return $response;
    }

    protected function fillData(array $data, Request $request, bool $withTenant = true): array
    {
        $payload = parent::fillData($data, $request, $withTenant);
        foreach (['name', 'address'] as $field) {
            if (isset($payload[$field]) && is_string($payload[$field])) {
                $payload[$field] = strtoupper(trim($payload[$field]));
            }
        }
        if (array_key_exists('is_default', $payload)) {
            $payload['is_default'] = (int) $payload['is_default'] === 1 ? 1 : 0;
        }
        return $payload;
    }

    private function syncDefault(Request $request, int $id): void
    {
        if ($id > 0 && (string) $request->input('is_default', '0') === '1') {
            DispensingService::makeDefault((int) $request->clientId(), (int) $request->branchId(), $id);
        }
    }
}
