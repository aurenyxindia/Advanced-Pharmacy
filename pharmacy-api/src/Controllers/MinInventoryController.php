<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\MinInventoryService;
use App\Services\UserRightsService;

/** Retail Minimum Inventory Trigger (Opening Stock tab) + Dashboard Minimum Inventory Alert. */
final class MinInventoryController extends Controller
{
    public function index(Request $request): Response
    {
        $rows = (new MinInventoryService())->triggers((int) $request->clientId(), (int) ($request->branchId() ?: 0));
        return $this->success($rows, 'Minimum inventory triggers.');
    }

    public function alerts(Request $request): Response
    {
        $data = (new MinInventoryService())->shortStock(
            (int) $request->clientId(),
            (int) ($request->branchId() ?: 0),
            max(0, (int) $request->query('limit', 0)),
        );
        return $this->success($data, 'Minimum inventory alerts.');
    }

    public function products(Request $request): Response
    {
        $rows = (new MinInventoryService())->stockProducts(
            (int) $request->clientId(),
            (int) ($request->branchId() ?: 0),
            (string) $request->query('search', ''),
        );
        return $this->success($rows, 'Stock products.');
    }

    public function store(Request $request): Response
    {
        $this->requireStockEdit($request);
        $row = (new MinInventoryService())->save(
            (int) $request->clientId(),
            (int) ($request->branchId() ?: 0),
            (int) $request->input('product_binding_id', 0),
            (int) $request->input('min_qty', -1),
        );
        return $this->success($row, 'Minimum inventory trigger saved.');
    }

    public function destroy(Request $request): Response
    {
        $this->requireStockEdit($request);
        (new MinInventoryService())->remove(
            (int) $request->clientId(),
            (int) ($request->branchId() ?: 0),
            (int) $request->attribute('id'),
        );
        return $this->success(null, 'Minimum reset to default (' . MinInventoryService::DEFAULT_MIN . ').');
    }

    /** Same access as the Opening Stock screen — OPENING_STOCK edit only. */
    private function requireStockEdit(Request $request): void
    {
        $user = $request->user() ?? [];
        $rights = new UserRightsService();
        $clientId = (int) $request->clientId();
        $userId = (int) ($user['id'] ?? 0);
        $role = (string) ($user['role'] ?? '');
        $rights->requireModule($clientId, $userId, $role, 'OPENING_STOCK', true);
    }
}
