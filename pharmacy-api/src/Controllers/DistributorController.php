<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Model;
use App\Core\Request;
use App\Core\Response;
use App\Database\Connection;
use App\Models\Distributor;
use App\Services\ClientCatalogPolicy;

final class DistributorController extends ResourceController
{
    /** Shared catalog — visible to all tenants/users (same as products, generics, HSN). */
    protected bool $tenantScoped = false;
    protected array $fillable = [
        'distributor_code', 'name', 'phone', 'contact_person', 'email', 'state',
        'gstin', 'gst_state_code', 'address', 'dl_no1', 'dl_no2', 'dl_no3', 'dl_no4',
        'gst_type', 'credit_limit_days', 'is_general', 'is_active',
    ];
    protected array $searchColumns = ['name', 'phone', 'contact_person', 'address', 'gstin', 'distributor_code'];
    protected string $defaultOrder = 'name ASC';

    protected function model(): Model
    {
        return new Distributor();
    }

    public function index(Request $request): Response
    {
        $page    = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min(5000, (int) $request->query('per_page', 25)));
        $offset  = ($page - 1) * $perPage;
        $search  = trim((string) ($request->query('search') ?? ''));

        $clauses  = ['is_active = 1'];
        $bindings = [];

        // Hide shared catalogue → only distributors created by this client.
        $clientId = (int) ($request->clientId() ?? 0);
        if ($clientId > 0 && (new ClientCatalogPolicy())->hideDistributors($clientId)) {
            $clauses[] = 'client_id = :own_client';
            $bindings['own_client'] = $clientId;
        }

        if ($search !== '') {
            $likes = [];
            foreach ($this->searchColumns as $i => $column) {
                $param = "s_{$i}";
                $likes[] = "`{$column}` LIKE :{$param}";
                $bindings[$param] = '%' . $search . '%';
            }
            $clauses[] = '(' . implode(' OR ', $likes) . ')';
        }

        $where = 'WHERE ' . implode(' AND ', $clauses);
        $db = Connection::master();

        $countStmt = $db->prepare("SELECT COUNT(*) FROM distributors {$where}");
        $countStmt->execute($bindings);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT * FROM distributors {$where} ORDER BY {$this->defaultOrder} LIMIT {$perPage} OFFSET {$offset}";
        $stmt = $db->prepare($sql);
        $stmt->execute($bindings);

        return $this->success([
            'data'     => $stmt->fetchAll(),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ], 'List fetched.');
    }

    protected function storeRules(): array
    {
        return [
            'distributor_code'  => 'string|max:20',
            'name'              => 'required|string|min:2|max:150',
            'phone'             => 'required|string|regex:/^\d{10,12}$/',
            'contact_person'    => 'required|string|min:2|max:120',
            'email'             => 'email|max:150',
            'state'             => 'required|string|max:80',
            'gstin'             => 'required|string|regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/',
            'gst_state_code'    => 'required|string|regex:/^\d{2}$/',
            'address'           => 'string|max:255',
            'dl_no1'            => 'required|string|max:60',
            'dl_no2'            => 'string|max:60',
            'dl_no3'            => 'string|max:60',
            'dl_no4'            => 'string|max:60',
            'gst_type'          => 'string|max:20',
            'credit_limit_days' => 'integer|min:0',
            'is_general'        => 'integer|in:0,1',
            'is_active'         => 'integer|in:0,1',
        ];
    }

    protected function fillData(array $data, Request $request, bool $withTenant = true): array
    {
        $payload = parent::fillData($data, $request, false);

        // client_id column is NOT NULL — stamp for audit only; listing is not tenant-scoped.
        if ($withTenant && !isset($payload['client_id'])) {
            $payload['client_id'] = $request->clientId() ?? 1;
        }

        foreach (['name', 'contact_person', 'address', 'dl_no1', 'dl_no2', 'dl_no3', 'dl_no4', 'distributor_code'] as $field) {
            if (isset($payload[$field]) && is_string($payload[$field])) {
                $payload[$field] = strtoupper(trim($payload[$field]));
            }
        }
        if (isset($payload['gstin']) && is_string($payload['gstin'])) {
            $payload['gstin'] = strtoupper(trim($payload['gstin']));
        }
        if (isset($payload['gst_state_code']) && is_string($payload['gst_state_code'])) {
            $payload['gst_state_code'] = trim($payload['gst_state_code']);
        }
        if (isset($payload['phone']) && is_string($payload['phone'])) {
            $payload['phone'] = preg_replace('/\D/', '', $payload['phone']) ?? '';
        }
        if (!isset($payload['gst_type']) || $payload['gst_type'] === '') {
            $payload['gst_type'] = 'GST';
        }
        if (!isset($payload['is_active'])) {
            $payload['is_active'] = 1;
        }

        return $payload;
    }
}
