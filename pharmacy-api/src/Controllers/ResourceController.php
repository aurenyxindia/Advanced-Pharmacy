<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Model;
use App\Core\Request;
use App\Core\Response;

/**
 * Generic CRUD controller with optional tenant (client/branch) scoping.
 *
 * Subclasses configure: model(), $fillable, $searchColumns, storeRules(),
 * updateRules(), $tenantScoped and $branchScoped.
 */
abstract class ResourceController extends Controller
{
    protected bool $tenantScoped = true;
    protected bool $branchScoped = false;
    protected array $fillable = [];
    protected array $searchColumns = ['name'];
    protected string $defaultOrder = 'id DESC';

    abstract protected function model(): Model;

    abstract protected function storeRules(): array;

    protected function updateRules(): array
    {
        return $this->storeRules();
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

        return $this->success($result, 'List fetched.');
    }

    public function show(Request $request): Response
    {
        $row = $this->findScoped($request);
        return $this->success($row, 'Record fetched.');
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, $this->storeRules());
        $payload = $this->fillData($data, $request);
        $payload['created_at'] = now();
        $payload['updated_at'] = now();

        $id = $this->model()->insert($payload);
        return $this->created(['id' => $id], 'Record created.');
    }

    public function update(Request $request): Response
    {
        $this->findScoped($request);
        $data = $this->validate($request, $this->updateRules());
        $payload = $this->fillData($data, $request, false);
        $payload['updated_at'] = now();

        $this->model()->update((int) $request->attribute('id'), $payload);
        return $this->success(null, 'Record updated.');
    }

    public function destroy(Request $request): Response
    {
        $this->findScoped($request);
        $this->model()->delete((int) $request->attribute('id'));
        return $this->success(null, 'Record deleted.');
    }

    // ---- helpers -------------------------------------------------------

    protected function scopeConditions(Request $request): array
    {
        $conditions = [];
        if ($this->tenantScoped && $request->clientId() !== null) {
            $conditions['client_id'] = $request->clientId();
        }
        if ($this->branchScoped && $request->branchId() !== null) {
            $conditions['branch_id'] = $request->branchId();
        }
        return $conditions;
    }

    protected function fillData(array $data, Request $request, bool $withTenant = true): array
    {
        $payload = array_intersect_key($data, array_flip($this->fillable));
        if ($withTenant && $this->tenantScoped) {
            $payload['client_id'] = $request->clientId();
        }
        if ($withTenant && $this->branchScoped) {
            $payload['branch_id'] = $request->branchId();
        }
        return $payload;
    }

    protected function findScoped(Request $request): array
    {
        $id = (int) $request->attribute('id');
        $row = $this->model()->find($id);

        if (!$row) {
            throw HttpException::notFound('Record not found.');
        }
        if ($this->tenantScoped && isset($row['client_id']) && (int) $row['client_id'] !== $request->clientId()) {
            throw HttpException::forbidden('You cannot access this record.');
        }
        return $row;
    }
}
