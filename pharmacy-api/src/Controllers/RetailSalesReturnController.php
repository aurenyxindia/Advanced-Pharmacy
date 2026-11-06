<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Database\Connection;
use App\Services\RetailSalesReturnService;
use App\Services\ReturnNotePdfService;

final class RetailSalesReturnController extends Controller
{
    public function sales(Request $request): Response
    {
        $branchId = (int) ($request->branchId() ?: 0);
        $search = trim((string) ($request->query('search') ?? ''));
        $limit = (int) ($request->query('limit') ?? 30);
        $rows = (new RetailSalesReturnService())->listSalesForReturn(
            (int) $request->clientId(),
            $branchId,
            $search !== '' ? $search : null,
            $limit
        );
        return $this->success($rows, 'Sales for return.');
    }

    public function saleDetails(Request $request): Response
    {
        $branchId = (int) ($request->branchId() ?: 0);
        $id = (int) $request->attribute('id');
        $row = (new RetailSalesReturnService())->saleDetails((int) $request->clientId(), $branchId, $id);
        return $this->success($row, 'Sale details.');
    }

    public function store(Request $request): Response
    {
        $branchId = (int) ($request->branchId() ?: 0);
        $userId = isset($request->user()['id']) ? (int) $request->user()['id'] : null;
        $result = (new RetailSalesReturnService())->save(
            (int) $request->clientId(),
            $branchId,
            $userId,
            $request->all()
        );
        return $this->created($result, 'Retail sale return saved.');
    }

    /** External return — no system sale bill. */
    public function storeManual(Request $request): Response
    {
        $branchId = (int) ($request->branchId() ?: 0);
        $userId = isset($request->user()['id']) ? (int) $request->user()['id'] : null;
        $result = (new RetailSalesReturnService())->saveManual(
            (int) $request->clientId(),
            $branchId,
            $userId,
            $request->all()
        );
        return $this->created($result, 'External retail sale return saved.');
    }

    public function history(Request $request): Response
    {
        $branchId = (int) ($request->branchId() ?: 0);
        $search = trim((string) ($request->query('search') ?? ''));
        $rows = (new RetailSalesReturnService())->history(
            (int) $request->clientId(),
            $branchId,
            $search !== '' ? $search : null
        );
        return $this->success($rows, 'Return history.');
    }

    public function historyDetails(Request $request): Response
    {
        $branchId = (int) ($request->branchId() ?: 0);
        $id = (int) $request->attribute('id');
        $row = (new RetailSalesReturnService())->historyDetails((int) $request->clientId(), $branchId, $id);
        return $this->success($row, 'Return details.');
    }

    /** Retail-parity Retail Sales Return PDF (TCPDF). */
    public function historyPdf(Request $request): Response
    {
        $branchId = (int) ($request->branchId() ?: 0);
        $id = (int) $request->attribute('id');
        $clientId = (int) $request->clientId();
        $row = (new RetailSalesReturnService())->historyDetails($clientId, $branchId, $id);

        $preparedBy = null;
        $createdBy = (int) ($row['created_by'] ?? 0);
        if ($createdBy > 0) {
            $st = Connection::ho()->prepare('SELECT name FROM users WHERE id = :id LIMIT 1');
            $st->execute(['id' => $createdBy]);
            $preparedBy = $st->fetchColumn() ?: null;
        }
        if (!$preparedBy) {
            $preparedBy = (string) ($request->user()['name'] ?? '');
        }

        $pdf = (new ReturnNotePdfService())->retailSalesReturn($row, $clientId, $branchId, is_string($preparedBy) ? $preparedBy : null);
        $file = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) ($row['return_no'] ?? 'retail-return'));
        return Response::raw($pdf, 'application/pdf', 200, [
            'Content-Disposition' => 'inline; filename="retail-sale-return-' . $file . '.pdf"',
        ]);
    }
}
