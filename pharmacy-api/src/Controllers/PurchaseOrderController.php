<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\PurchaseOrderService;
use App\Services\UserRightsService;

/** Retail /purchaseOrder — Customise PO, Auto-Purchase (Short Book), PO list + PDF. */
final class PurchaseOrderController extends Controller
{
    private const MODULE = 'PURCHASE_ORDER';

    public function index(Request $request): Response
    {
        $this->requireRights($request);
        $rows = (new PurchaseOrderService())->list(
            (int) $request->clientId(),
            (int) ($request->branchId() ?: 0),
            [
                'search' => (string) $request->query('search', ''),
                'from_date' => (string) $request->query('from_date', ''),
                'to_date' => (string) $request->query('to_date', ''),
                'po_type' => (string) $request->query('po_type', ''),
            ],
        );
        return $this->success($rows, 'Purchase orders.');
    }

    public function show(Request $request): Response
    {
        $this->requireRights($request);
        return $this->success($this->find($request), 'Purchase order.');
    }

    public function store(Request $request): Response
    {
        $this->requireRights($request, true);
        $user = $request->user() ?? [];
        $result = (new PurchaseOrderService())->create(
            (int) $request->clientId(),
            (int) ($request->branchId() ?: 0),
            isset($user['id']) ? (int) $user['id'] : null,
            $request->all(),
        );
        return $this->created($result, 'Purchase Order saved successfully. PO NO: ' . $result['po_no']);
    }

    public function update(Request $request): Response
    {
        $this->requireRights($request, true);
        $result = (new PurchaseOrderService())->update(
            (int) $request->clientId(),
            (int) ($request->branchId() ?: 0),
            (int) $request->attribute('id'),
            $request->all(),
        );
        return $this->success($result, 'Purchase Order updated successfully. PO NO: ' . $result['po_no']);
    }

    public function shortBook(Request $request): Response
    {
        $this->requireRights($request, true);
        $data = (new PurchaseOrderService())->shortBook(
            (int) $request->clientId(),
            (int) ($request->branchId() ?: 0),
        );
        return $this->success($data, 'Short book.');
    }

    /** Products sold in the last N days (incl. today) for Sales-based PO. */
    public function salesRange(Request $request): Response
    {
        $this->requireRights($request, true);
        $days = (int) $request->query('days', 1);
        $data = (new PurchaseOrderService())->salesRange(
            (int) $request->clientId(),
            (int) ($request->branchId() ?: 0),
            $days,
        );
        return $this->success($data, 'Sales range products.');
    }

    public function pdf(Request $request): Response
    {
        $this->requireRights($request);
        $order = $this->find($request);
        $pdf = (new PurchaseOrderService())->pdf(
            (int) $request->clientId(),
            (int) ($request->branchId() ?: 0),
            $order,
        );
        $file = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $order['po_no']);
        return Response::raw($pdf, 'application/pdf', 200, [
            'Content-Disposition' => 'inline; filename="purchase-order-' . $file . '.pdf"',
        ]);
    }

    /** @return array<string,mixed> */
    private function find(Request $request): array
    {
        $order = (new PurchaseOrderService())->get(
            (int) $request->clientId(),
            (int) ($request->branchId() ?: 0),
            (int) $request->attribute('id'),
        );
        if ($order === null) {
            throw HttpException::notFound('Purchase order not found.');
        }
        return $order;
    }

    private function requireRights(Request $request, bool $edit = false): void
    {
        $user = $request->user() ?? [];
        (new UserRightsService())->requireModule(
            (int) $request->clientId(),
            (int) ($user['id'] ?? 0),
            (string) ($user['role'] ?? ''),
            self::MODULE,
            $edit,
        );
    }
}
