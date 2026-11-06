<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Model;
use App\Core\Request;
use App\Core\Response;
use App\Models\HsnCode;

final class HsnCodeController extends ResourceController
{
    protected bool $tenantScoped = false;
    protected array $fillable = ['code', 'is_active'];
    protected array $searchColumns = ['code'];
    protected string $defaultOrder = 'code ASC';

    protected function model(): Model
    {
        return new HsnCode();
    }

    protected function storeRules(): array
    {
        return [
            'code'      => 'required|string|regex:/^[0-9]{4}$|^[0-9]{6}$|^[0-9]{8}$/',
            'is_active' => 'integer|in:0,1',
        ];
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, $this->storeRules());
        $code = trim((string) ($data['code'] ?? ''));

        $existing = $this->model()->firstWhere(['code' => $code]);
        if ($existing) {
            throw new HttpException(422, 'This HSN already exists. Duplicate not allowed.');
        }

        $payload = $this->fillData(['code' => $code, 'is_active' => $data['is_active'] ?? 1], $request);
        $payload['created_at'] = now();
        $payload['updated_at'] = now();

        $id = $this->model()->insert($payload);
        return $this->created(['id' => $id], 'HSN added successfully.');
    }

    protected function fillData(array $data, Request $request, bool $withTenant = true): array
    {
        $payload = parent::fillData($data, $request, $withTenant);
        if (isset($payload['code']) && is_string($payload['code'])) {
            $payload['code'] = trim($payload['code']);
        }
        return $payload;
    }
}
