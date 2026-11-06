<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Model;
use App\Core\Request;
use App\Core\Response;
use App\Database\Connection;
use App\Models\Product;
use App\Services\ClientCatalogPolicy;
use App\Services\ProductBindingService;
use App\Support\ProductName;

final class ProductController extends ResourceController
{
    protected bool $tenantScoped = false;
    protected array $fillable = [
        'name', 'product_type', 'generic_id', 'manufacturer', 'manufacturer_short', 'schedule_category',
        'product_category', 'hsn_code', 'default_gst_rate', 'mrp', 'old_mrp', 'ptr', 'pts', 'pack_size',
        'dosage_form', 'strength', 'packing_desc', 'units_per_pack', 'allow_loose', 'shelf_location', 'uom',
        'composition', 'is_active',
    ];
    protected array $searchColumns = ['name', 'manufacturer', 'composition', 'dosage_form', 'strength', 'packing_desc'];
    protected string $defaultOrder = 'name ASC';

    protected function model(): Model
    {
        return new Product();
    }

    protected function storeRules(): array
    {
        return [
            'name'              => 'required|string|min:2|max:200',
            'product_type'      => 'string|max:40|in:Medicine,General,Food/Nutra,Cosmetics,Patanjali',
            'generic_id'        => 'integer',
            'manufacturer'      => 'string|max:150',
            'manufacturer_short' => 'string|max:20',
            'schedule_category' => 'required|in:OTC,H,H1,X,G,RESTRICTED',
            'product_category'  => 'string|max:80',
            'hsn_code'          => 'string|max:20',
            'default_gst_rate'  => 'numeric|min:0|max:28',
            'mrp'               => 'required|numeric|min:0',
            'old_mrp'           => 'numeric|min:0',
            'ptr'               => 'numeric|min:0',
            'pts'               => 'numeric|min:0',
            'pack_size'         => 'required|string|min:1|max:40',
            'dosage_form'       => 'string|max:60',
            'strength'          => 'string|max:80',
            'packing_desc'      => 'string|max:120',
            'units_per_pack'    => 'integer|min:1|max:10000',
            'allow_loose'       => 'integer|in:0,1',
            'shelf_location'    => 'string|max:40',
            'uom'               => 'string|max:20',
            'composition'       => 'string|max:255',
            'is_active'         => 'integer|in:0,1',
        ];
    }

    public function index(Request $request): Response
    {
        $conditions = $this->scopeConditions($request);

        $schedule = trim((string) ($request->query('schedule_category') ?? ''));
        if ($schedule !== '' && in_array($schedule, ['OTC', 'H', 'H1', 'X', 'G', 'RESTRICTED'], true)) {
            $conditions['schedule_category'] = $schedule;
        }

        $clientId = (int) ($request->clientId() ?? 0);
        if ($clientId > 0 && (new ClientCatalogPolicy())->hideProducts($clientId)) {
            $conditions['created_by_client'] = $clientId;
        }

        $result = $this->model()->paginate(
            $conditions,
            $request->query('search'),
            $this->searchColumns,
            (int) $request->query('page', 1),
            (int) $request->query('per_page', 25),
            $this->defaultOrder
        );

        if (!empty($result['data']) && is_array($result['data'])) {
            foreach ($result['data'] as &$row) {
                if (isset($row['name'])) {
                    $row['name'] = ProductName::clean((string) $row['name']);
                }
            }
            unset($row);
        }

        return $this->success($result, 'List fetched.');
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, $this->storeRules());
        $payload = array_intersect_key($data, array_flip($this->fillable));
        $payload = $this->normalizeProductPayload($payload);
        $payload['product_type'] = $payload['product_type'] ?? 'Medicine';
        $payload['name'] = ProductName::cleanUpper((string) ($payload['name'] ?? ''));
        $payload['pack_size'] = strtoupper(trim((string) ($payload['pack_size'] ?? '')));
        $clientId = (int) ($request->clientId() ?? 0);
        $ownForUnique = ($clientId > 0 && (new ClientCatalogPolicy())->hideProducts($clientId)) ? $clientId : null;
        $this->assertUniqueNamePack($payload['name'], $payload['pack_size'], null, $ownForUnique);
        $payload['created_by_client'] = $request->clientId();
        $payload['created_at'] = now();
        $payload['updated_at'] = now();

        $id = (new Product())->insert($payload);
        return $this->created(['id' => $id], 'Product added to master catalog.');
    }

    /** Update master catalog and sync denormalized fields on all branch bindings. */
    public function update(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $existing = (new Product())->find($id);
        if (!$existing) {
            throw HttpException::notFound('Product not found.');
        }

        $data = $this->validate($request, $this->updateRules());
        $payload = array_intersect_key($data, array_flip($this->fillable));
        $payload = $this->normalizeProductPayload($payload);
        if (isset($payload['name'])) {
            $payload['name'] = ProductName::cleanUpper((string) $payload['name']);
        }
        if (isset($payload['pack_size'])) {
            $payload['pack_size'] = strtoupper(trim((string) $payload['pack_size']));
        }
        $name = ProductName::cleanUpper((string) ($payload['name'] ?? $existing['name'] ?? ''));
        $pack = (string) ($payload['pack_size'] ?? $existing['pack_size'] ?? '');
        if (isset($payload['name'])) {
            $payload['name'] = $name;
        }
        $clientId = (int) ($request->clientId() ?? 0);
        $ownForUnique = ($clientId > 0 && (new ClientCatalogPolicy())->hideProducts($clientId)) ? $clientId : null;
        $this->assertUniqueNamePack($name, $pack, $id, $ownForUnique);
        // Retail-style: when MRP changes, keep previous MRP as old_mrp.
        if (array_key_exists('mrp', $payload)) {
            $newMrp = (float) $payload['mrp'];
            $oldMrp = (float) ($existing['mrp'] ?? 0);
            if ($newMrp !== $oldMrp && $oldMrp > 0 && !array_key_exists('old_mrp', $payload)) {
                $payload['old_mrp'] = $oldMrp;
            }
        }
        $payload['updated_at'] = now();

        (new Product())->update($id, $payload);
        $sync = (new ProductBindingService())->syncFromMaster($id);
        $bindingCount = (int) ($sync['bindings'] ?? 0);
        $purchaseCount = (int) ($sync['purchase_items'] ?? 0);
        $batchCount = (int) ($sync['batches'] ?? 0);
        $touched = array_sum(array_map('intval', $sync));

        $parts = [];
        if ($bindingCount > 0) {
            $parts[] = "{$bindingCount} branch binding(s)";
        }
        if ($purchaseCount > 0) {
            $parts[] = "{$purchaseCount} purchase line(s)";
        }
        if ($batchCount > 0) {
            $parts[] = "{$batchCount} stock batch(es)";
        }

        return $this->success(
            [
                'synced_bindings' => $bindingCount,
                'synced' => $sync,
            ],
            $touched > 0
                ? 'Product updated and synced to ' . (count($parts) ? implode(', ', $parts) : 'related stock/purchase records') . '.'
                : 'Product updated.'
        );
    }

    /** Drop null optional fields (DB defaults) and normalize NOT NULL columns. */
    private function normalizeProductPayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if ($value === null) {
                unset($payload[$key]);
            }
        }
        if (!isset($payload['product_category']) || trim((string) $payload['product_category']) === '') {
            $payload['product_category'] = 'Allopathy';
        }
        if (array_key_exists('strength', $payload)) {
            $s = strtoupper(trim((string) $payload['strength']));
            if ($s === '') {
                unset($payload['strength']);
            } else {
                $payload['strength'] = $s;
            }
        }
        return $payload;
    }

    /** Retail: unique product by name + pack size (own catalogue when hide master is ON). */
    private function assertUniqueNamePack(string $name, string $packSize, ?int $excludeId = null, ?int $ownClientId = null): void
    {
        $name = strtoupper(trim($name));
        $packSize = strtoupper(trim($packSize));
        if ($name === '' || $packSize === '') {
            return;
        }
        $sql = 'SELECT id FROM products WHERE LOWER(TRIM(name)) = LOWER(:n) AND TRIM(pack_size) = :p AND is_active = 1';
        $bindings = ['n' => $name, 'p' => $packSize];
        if ($ownClientId !== null && $ownClientId > 0) {
            $sql .= ' AND created_by_client = :own_client';
            $bindings['own_client'] = $ownClientId;
        }
        if ($excludeId !== null) {
            $sql .= ' AND id <> :id';
            $bindings['id'] = $excludeId;
        }
        $sql .= ' LIMIT 1';
        $stmt = Connection::master()->prepare($sql);
        $stmt->execute($bindings);
        if ($stmt->fetch()) {
            throw HttpException::validation([
                'name' => ['This product already exists with the same name and pack size.'],
            ]);
        }
    }

    /** Catalogue counts: global HO master vs the chemist's subscribed (bound) products. */
    public function stats(Request $request): Response
    {
        $clientId = (int) $request->clientId();
        $branchId = (int) ($request->branchId() ?? 0);
        $hideProducts = $clientId > 0 && (new ClientCatalogPolicy())->hideProducts($clientId);

        if ($hideProducts) {
            $cnt = Connection::master()->prepare(
                'SELECT COUNT(*) FROM products WHERE created_by_client = :c'
            );
            $cnt->execute(['c' => $clientId]);
            $masterTotal = (int) $cnt->fetchColumn();
            $active = Connection::master()->prepare(
                'SELECT COUNT(*) FROM products WHERE is_active = 1 AND created_by_client = :c'
            );
            $active->execute(['c' => $clientId]);
            $activeMaster = (int) $active->fetchColumn();
        } else {
            $masterTotal = (int) Connection::master()->query('SELECT COUNT(*) FROM products')->fetchColumn();
            $activeMaster = (int) Connection::master()->query('SELECT COUNT(*) FROM products WHERE is_active = 1')->fetchColumn();
        }

        $bound = Connection::ops()->prepare('SELECT COUNT(DISTINCT master_product_id) FROM product_bindings WHERE client_id = :c');
        $bound->execute(['c' => $clientId]);
        $boundTotal = (int) $bound->fetchColumn();

        $branchBound = Connection::ops()->prepare('SELECT COUNT(*) FROM product_bindings WHERE client_id = :c AND branch_id = :b');
        $branchBound->execute(['c' => $clientId, 'b' => $branchId]);
        $branchTotal = (int) $branchBound->fetchColumn();

        if ($hideProducts) {
            $sched = Connection::master()->prepare(
                "SELECT schedule_category AS code, COUNT(*) AS cnt
                 FROM products
                 WHERE created_by_client = :c
                 GROUP BY schedule_category
                 ORDER BY FIELD(schedule_category, 'OTC','H','H1','X','G','RESTRICTED')"
            );
            $sched->execute(['c' => $clientId]);
            $scheduleRows = $sched->fetchAll();
        } else {
            $scheduleRows = Connection::master()->query(
                "SELECT schedule_category AS code, COUNT(*) AS cnt
                 FROM products
                 GROUP BY schedule_category
                 ORDER BY FIELD(schedule_category, 'OTC','H','H1','X','G','RESTRICTED')"
            )->fetchAll();
        }

        $scheduleCounts = [];
        foreach ($scheduleRows as $row) {
            $scheduleCounts[(string) $row['code']] = (int) $row['cnt'];
        }

        $masterLabel = $hideProducts ? 'My Products' : 'Master Product List';
        return $this->success([
            'cards' => [
                ['label' => $masterLabel, 'value' => $masterTotal, 'sub' => $activeMaster . ' active', 'icon' => 'bi-globe2'],
                ['label' => 'My Subscription', 'value' => $boundTotal, 'sub' => 'products carried', 'icon' => 'bi-shop'],
                ['label' => 'This Branch', 'value' => $branchTotal, 'sub' => 'bound here', 'icon' => 'bi-shop-window'],
                ['label' => 'Schedule H', 'value' => $scheduleCounts['H'] ?? 0, 'sub' => 'prescription', 'icon' => 'bi-prescription2'],
                ['label' => 'Schedule H1', 'value' => $scheduleCounts['H1'] ?? 0, 'sub' => 'strict control', 'icon' => 'bi-shield-exclamation'],
                ['label' => 'OTC', 'value' => $scheduleCounts['OTC'] ?? 0, 'sub' => 'over the counter', 'icon' => 'bi-bag-check'],
            ],
            'schedule_counts' => $scheduleCounts,
        ], 'Product catalogue stats.');
    }

    /** Type-ahead search for billing/purchase screens. */
    public function search(Request $request): Response
    {
        $clientId = (int) ($request->clientId() ?? 0);
        $ownClientId = ($clientId > 0 && (new ClientCatalogPolicy())->hideProducts($clientId))
            ? $clientId
            : null;
        $results = (new Product())->search(
            $request->query('q'),
            (int) $request->query('limit', 20),
            $ownClientId
        );
        return $this->success($results, 'Search results.');
    }

    /** Generic-based substitute suggestions. */
    public function substitutes(Request $request): Response
    {
        $clientId = (int) ($request->clientId() ?? 0);
        $ownClientId = ($clientId > 0 && (new ClientCatalogPolicy())->hideProducts($clientId))
            ? $clientId
            : null;
        $results = (new Product())->substitutes((int) $request->attribute('id'), $ownClientId);
        return $this->success($results, 'Substitutes.');
    }

    /** Bind a master product to the current tenant (client + branch). */
    public function bind(Request $request): Response
    {
        $clientId = $request->clientId();
        $branchId = $request->branchId();
        if ($clientId === null || $branchId === null) {
            throw HttpException::forbidden('A branch context is required to bind products.');
        }

        $data = $this->validate($request, [
            'master_product_id' => 'required|integer',
            'reorder_level'     => 'integer|min:0',
        ]);

        $masterId = (int) $data['master_product_id'];
        if ((new ClientCatalogPolicy())->hideProducts((int) $clientId)) {
            $row = (new Product())->find($masterId);
            $owner = (int) ($row['created_by_client'] ?? 0);
            if (!$row || $owner !== (int) $clientId) {
                throw HttpException::forbidden('Shared master catalogue is hidden for this pharmacy. Add your own product instead.');
            }
        }

        $binding = (new ProductBindingService())->bind(
            $clientId,
            $branchId,
            $masterId,
            (int) ($data['reorder_level'] ?? 0)
        );

        return $this->created($binding, 'Product bound to your branch.');
    }
}
