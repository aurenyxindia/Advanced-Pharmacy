<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Database\Connection;
use App\Models\Branch;
use App\Services\ClientCatalogPolicy;
use App\Services\ClientProfileService;

/**
 * Read-only lookup endpoints used to populate dropdowns across the app.
 */
final class MetaController extends Controller
{
    public function scheduleCategories(Request $request): Response
    {
        $rows = Connection::master()
            ->query('SELECT code, name, requires_prescription, locked_after_sale, description FROM schedule_categories ORDER BY id ASC')
            ->fetchAll();
        return $this->success($rows, 'Schedule categories.');
    }

    public function branches(Request $request): Response
    {
        $branches = (new Branch())->forClient((int) $request->clientId());
        return $this->success($branches, 'Branches.');
    }

    /** Client profile (business type) + current branch context for the UI. */
    public function profile(Request $request): Response
    {
        $clientId = (int) $request->clientId();
        $branchId = (int) $request->branchId();

        (new ClientProfileService())->ensureProfileColumns();

        $client = Connection::ho()->prepare(
            'SELECT business_name, owner_name, business_type, state, gstin,
                    hide_master_products, hide_master_distributors
             FROM clients WHERE id = :id'
        );
        $client->execute(['id' => $clientId]);
        $clientRow = $client->fetch() ?: [];

        $branch = Connection::ho()->prepare('SELECT id, code, name, state, gstin FROM branches WHERE id = :id');
        $branch->execute(['id' => $branchId]);
        $branchRow = $branch->fetch() ?: [];

        $catalog = (new ClientCatalogPolicy())->flags($clientId);

        return $this->success([
            'business_type'   => $clientRow['business_type'] ?? 'both',
            'business_name'   => $clientRow['business_name'] ?? '',
            'contact_person'  => $clientRow['owner_name'] ?? '',
            'state'           => $branchRow['state'] ?? ($clientRow['state'] ?? null),
            'branch'          => $branchRow,
            'hide_master_products'     => $catalog['products'],
            'hide_master_distributors' => $catalog['distributors'],
        ], 'Profile.');
    }

    /** Retail master pack sizes for purchase entry dropdown. */
    public function packSizes(Request $request): Response
    {
        return $this->success(\App\Support\PackSize::retailMaster(), 'Pack sizes.');
    }

    /** Normalise any distributor pack text to a standard pack + units-per-pack. */
    public function parsePack(Request $request): Response
    {
        $parsed = \App\Support\PackSize::parse((string) $request->query('raw', ''));
        return $this->success([
            'pack'  => $parsed['pack_size'],
            'units' => (int) $parsed['units_per_pack'],
            'form'  => (string) $parsed['form'],
        ], 'Parsed pack.');
    }

    public function roles(Request $request): Response
    {
        $rows = Connection::ho()
            ->query('SELECT name, label FROM roles ORDER BY id ASC')
            ->fetchAll();
        return $this->success($rows, 'Roles.');
    }
}
