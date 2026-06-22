<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Model;
use App\Core\Request;
use App\Core\Response;
use App\Database\Connection;
use App\Models\Product;
use App\Services\ProductBindingService;

final class ProductController extends ResourceController
{
    protected bool $tenantScoped = false;
    protected array $fillable = [
        'name', 'generic_id', 'manufacturer', 'manufacturer_short', 'schedule_category', 'product_category',
        'hsn_code', 'default_gst_rate', 'mrp', 'ptr', 'pts', 'pack_size', 'units_per_pack', 'allow_loose',
        'shelf_location', 'uom', 'composition', 'is_active',
    ];
    protected array $searchColumns = ['name', 'manufacturer', 'composition'];
    protected string $defaultOrder = 'name ASC';

    protected function model(): Model
    {
        return new Product();
    }

    protected function storeRules(): array
    {
        return [
            'name'              => 'required|string|min:2|max:200',
            'generic_id'        => 'integer',
            'manufacturer'      => 'string|max:150',
            'manufacturer_short' => 'string|max:20',
            'schedule_category' => 'required|in:OTC,H,H1,X,G,RESTRICTED',
            'product_category'  => 'string|max:30',
            'hsn_code'          => 'string|max:20',
            'default_gst_rate'  => 'numeric|min:0|max:28',
            'mrp'               => 'numeric|min:0',
            'ptr'               => 'numeric|min:0',
            'pts'               => 'numeric|min:0',
            'pack_size'         => 'string|max:40',
            'units_per_pack'    => 'integer|min:1|max:10000',
            'allow_loose'       => 'integer|in:0,1',
            'shelf_location'    => 'string|max:40',
            'uom'               => 'string|max:20',
            'composition'       => 'string|max:255',
            'is_active'         => 'integer|in:0,1',
        ];
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, $this->storeRules());
        $payload = array_intersect_key($data, array_flip($this->fillable));
        $payload['created_by_client'] = $request->clientId();
        $payload['created_at'] = now();
        $payload['updated_at'] = now();

        $id = (new Product())->insert($payload);
        return $this->created(['id' => $id], 'Product added to master catalog.');
    }

    /** Catalogue counts: global HO master vs the chemist's subscribed (bound) products. */
    public function stats(Request $request): Response
    {
        $clientId = (int) $request->clientId();
        $branchId = (int) ($request->branchId() ?? 0);

        $masterTotal = (int) Connection::master()->query('SELECT COUNT(*) FROM products')->fetchColumn();
        $activeMaster = (int) Connection::master()->query('SELECT COUNT(*) FROM products WHERE is_active = 1')->fetchColumn();

        $bound = Connection::ops()->prepare('SELECT COUNT(DISTINCT master_product_id) FROM product_bindings WHERE client_id = :c');
        $bound->execute(['c' => $clientId]);
        $boundTotal = (int) $bound->fetchColumn();

        $branchBound = Connection::ops()->prepare('SELECT COUNT(*) FROM product_bindings WHERE client_id = :c AND branch_id = :b');
        $branchBound->execute(['c' => $clientId, 'b' => $branchId]);
        $branchTotal = (int) $branchBound->fetchColumn();

        return $this->success([
            'cards' => [
                ['label' => 'Master Catalog (HO)', 'value' => $masterTotal, 'sub' => $activeMaster . ' active', 'icon' => 'bi-globe2'],
                ['label' => 'My Subscription', 'value' => $boundTotal, 'sub' => 'products carried', 'icon' => 'bi-shop'],
                ['label' => 'This Branch', 'value' => $branchTotal, 'sub' => 'bound here', 'icon' => 'bi-shop-window'],
            ],
        ], 'Product catalogue stats.');
    }

    /** Type-ahead search for billing/purchase screens. */
    public function search(Request $request): Response
    {
        $results = (new Product())->search($request->query('q'), (int) $request->query('limit', 20));
        return $this->success($results, 'Search results.');
    }

    /** Generic-based substitute suggestions. */
    public function substitutes(Request $request): Response
    {
        $results = (new Product())->substitutes((int) $request->attribute('id'));
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

        $binding = (new ProductBindingService())->bind(
            $clientId,
            $branchId,
            (int) $data['master_product_id'],
            (int) ($data['reorder_level'] ?? 0)
        );

        return $this->created($binding, 'Product bound to your branch.');
    }
}
