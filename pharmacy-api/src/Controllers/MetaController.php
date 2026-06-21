<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Database\Connection;
use App\Models\Branch;

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

        $client = Connection::ho()->prepare('SELECT business_name, business_type, state, gstin FROM clients WHERE id = :id');
        $client->execute(['id' => $clientId]);
        $clientRow = $client->fetch() ?: [];

        $branch = Connection::ho()->prepare('SELECT id, code, name, state, gstin FROM branches WHERE id = :id');
        $branch->execute(['id' => $branchId]);
        $branchRow = $branch->fetch() ?: [];

        return $this->success([
            'business_type' => $clientRow['business_type'] ?? 'both',
            'business_name' => $clientRow['business_name'] ?? '',
            'state'         => $branchRow['state'] ?? ($clientRow['state'] ?? null),
            'branch'        => $branchRow,
        ], 'Profile.');
    }

    public function roles(Request $request): Response
    {
        $rows = Connection::ho()
            ->query('SELECT name, label FROM roles ORDER BY id ASC')
            ->fetchAll();
        return $this->success($rows, 'Roles.');
    }
}
