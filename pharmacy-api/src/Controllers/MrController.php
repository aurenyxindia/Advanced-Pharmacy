<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Database\Connection;
use App\Services\MrService;
use App\Services\UserRightsService;

/** MR (Medical Representative) — Retail mr.php parity. */
final class MrController extends Controller
{
    private const MODULE = 'MR';

    public function dashboard(Request $request): Response
    {
        return $this->success($this->svc($request)->dashboard(), 'MR dashboard.');
    }

    public function visits(Request $request): Response
    {
        $rows = $this->svc($request)->visits((string) $request->query('from', ''), (string) $request->query('to', ''));
        return $this->success($rows, 'Visits.');
    }

    public function saveVisit(Request $request): Response
    {
        $id = $this->svc($request, true)->saveVisit($request->all());
        return $this->created(['id' => $id], 'Visit saved.');
    }

    public function stock(Request $request): Response
    {
        return $this->success($this->svc($request)->stock((string) $request->query('q', '')), 'Stock.');
    }

    public function previousRates(Request $request): Response
    {
        $data = $this->svc($request)->previousRates(
            (int) $request->query('customer_id', 0),
            (string) $request->query('customer_name', ''),
            (int) $request->query('product_binding_id', 0),
            (string) $request->query('product_name', ''),
        );
        return $this->success($data, 'Previous rates.');
    }

    public function nextOrderNo(Request $request): Response
    {
        return $this->success(['order_no' => $this->svc($request)->nextOrderNo()], 'Next order no.');
    }

    public function orders(Request $request): Response
    {
        $rows = $this->svc($request)->orders((string) $request->query('from', ''), (string) $request->query('to', ''));
        return $this->success($rows, 'Orders.');
    }

    public function showOrder(Request $request): Response
    {
        return $this->success($this->svc($request)->order((int) $request->attribute('id')), 'Order.');
    }

    public function saveOrder(Request $request): Response
    {
        $result = $this->svc($request, true)->saveOrder($request->all());
        return $this->created($result, $result['status'] . ' saved: ' . $result['order_no']);
    }

    public function deleteOrder(Request $request): Response
    {
        $svc = $this->svc($request, true);
        $this->requireDelete($request);
        $no = $svc->deleteOrder((int) $request->attribute('id'));
        return $this->success(['order_no' => $no], 'Deleted ' . $no);
    }

    public function orderPdf(Request $request): Response
    {
        $out = $this->svc($request)->orderPdf((int) $request->attribute('id'));
        return Response::raw($out['pdf'], 'application/pdf', 200, [
            'Content-Disposition' => 'inline; filename="' . $out['filename'] . '"',
        ]);
    }

    public function stockFollowups(Request $request): Response
    {
        $rows = $this->svc($request)->stockFollowups((string) $request->query('from', ''), (string) $request->query('to', ''));
        return $this->success($rows, 'Stock follow-ups.');
    }

    public function saveStockFollowup(Request $request): Response
    {
        $id = $this->svc($request, true)->saveStockFollowup($request->all());
        return $this->created(['id' => $id], 'Stock follow-up saved.');
    }

    public function monitor(Request $request): Response
    {
        $data = $this->svc($request)->monitor(
            (string) $request->query('from', ''),
            (string) $request->query('to', ''),
            (string) $request->query('customer', ''),
        );
        return $this->success($data, 'Sales monitor.');
    }

    public function dailyReports(Request $request): Response
    {
        $rows = $this->svc($request)->dailyReports((string) $request->query('from', ''), (string) $request->query('to', ''));
        return $this->success($rows, 'Daily reports.');
    }

    public function saveDailyReport(Request $request): Response
    {
        $this->svc($request, true)->saveDailyReport($request->all());
        return $this->created([], 'Daily report submitted.');
    }

    public function outstanding(Request $request): Response
    {
        return $this->success($this->svc($request)->outstanding(), 'Outstanding.');
    }

    public function saveOutstandingFollowup(Request $request): Response
    {
        $id = $this->svc($request, true)->saveOutstandingFollowup($request->all());
        return $this->created(['id' => $id], 'Outstanding follow-up saved.');
    }

    public function customers(Request $request): Response
    {
        return $this->success($this->svc($request)->customers((string) $request->query('q', '')), 'Wholesale customers.');
    }

    private function svc(Request $request, bool $edit = false): MrService
    {
        $user = $request->user() ?? [];
        $userId = (int) ($user['id'] ?? 0);
        $role = (string) ($user['role'] ?? '');
        (new UserRightsService())->requireModule((int) $request->clientId(), $userId, $role, self::MODULE, $edit);

        $userName = trim((string) ($user['name'] ?? $user['email'] ?? ''));
        if ($userId > 0) {
            try {
                $st = Connection::ho()->prepare('SELECT name FROM users WHERE id = :id LIMIT 1');
                $st->execute(['id' => $userId]);
                $userName = trim((string) ($st->fetchColumn() ?: '')) ?: $userName;
            } catch (\Throwable) {
                // Fall back to the name in the token.
            }
        }

        return new MrService(
            (int) $request->clientId(),
            (int) ($request->branchId() ?: 0),
            $userId > 0 ? $userId : null,
            $userName,
            UserRightsService::isAdminRole($role),
        );
    }

    private function requireDelete(Request $request): void
    {
        $user = $request->user() ?? [];
        $role = (string) ($user['role'] ?? '');
        if (UserRightsService::isAdminRole($role)) {
            return;
        }
        $mine = (new UserRightsService())->myRights((int) $request->clientId(), (int) ($user['id'] ?? 0), $role);
        foreach ($mine['rights'] ?? [] as $r) {
            if (strtoupper((string) ($r['module_code'] ?? '')) === self::MODULE && (int) ($r['can_delete'] ?? 0) === 1) {
                return;
            }
        }
        throw HttpException::forbidden('You do not have delete access for MR.');
    }
}
