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

    public function roles(Request $request): Response
    {
        $rows = Connection::ho()
            ->query('SELECT name, label FROM roles ORDER BY id ASC')
            ->fetchAll();
        return $this->success($rows, 'Roles.');
    }
}
