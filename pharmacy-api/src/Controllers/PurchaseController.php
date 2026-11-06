<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Database\Connection;
use App\Models\Purchase;
use App\Services\PurchaseService;

final class PurchaseController extends Controller
{
    public function index(Request $request): Response
    {
        $clientId = $request->clientId();
        $branchId = $request->branchId();
        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min(200, (int) $request->query('per_page', 100)));
        $offset = ($page - 1) * $perPage;
        $search = trim((string) $request->query('search', ''));
        $from = trim((string) $request->query('from_date', ''));
        $to = trim((string) $request->query('to_date', ''));
        $month = trim((string) $request->query('month', '')); // YYYY-MM

        if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
            $from = $month . '-01';
            $to = date('Y-m-t', strtotime($from));
        }

        $sql = 'SELECT * FROM purchases WHERE client_id = :c';
        $bind = ['c' => $clientId];
        if ($branchId !== null && $branchId > 0) {
            $sql .= ' AND branch_id = :b';
            $bind['b'] = $branchId;
        }
        if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $sql .= ' AND DATE(invoice_date) >= :from_d';
            $bind['from_d'] = $from;
        }
        if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $sql .= ' AND DATE(invoice_date) <= :to_d';
            $bind['to_d'] = $to;
        }
        if ($search !== '') {
            $sql .= ' AND (
                po_no LIKE :q OR invoice_no LIKE :q2 OR supplier_name LIKE :q3
                OR EXISTS (
                    SELECT 1 FROM purchase_items pi
                    WHERE pi.purchase_id = purchases.id
                      AND (pi.product_name LIKE :q4 OR pi.batch_no LIKE :q5)
                )
            )';
            $like = '%' . $search . '%';
            $bind['q'] = $like;
            $bind['q2'] = $like;
            $bind['q3'] = $like;
            $bind['q4'] = $like;
            $bind['q5'] = $like;
        }

        $countStmt = \App\Database\Connection::ops()->prepare('SELECT COUNT(*) FROM (' . $sql . ') t');
        $countStmt->execute($bind);
        $total = (int) $countStmt->fetchColumn();

        $sql .= ' ORDER BY invoice_date DESC, id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
        $stmt = \App\Database\Connection::ops()->prepare($sql);
        $stmt->execute($bind);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row = $this->enrichPurchaseLogRow($row);
        }
        unset($row);

        return $this->success([
            'data' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ], 'Purchases fetched.');
    }

    /** Ensure Paid/Balance present for list/detail (Cash vs Credit). */
    private function enrichPurchaseLogRow(array $row): array
    {
        $net = (float) ($row['net_amount'] ?? 0);
        $mode = strtolower((string) ($row['cash_credit'] ?? 'credit'));
        $paid = (float) ($row['paid'] ?? 0);
        $balance = (float) ($row['balance'] ?? 0);
        if ($paid == 0.0 && $balance == 0.0 && $net > 0) {
            if ($mode === 'cash') {
                $paid = $net;
                $balance = 0.0;
            } else {
                $paid = 0.0;
                $balance = $net;
            }
        }
        $row['paid'] = round($paid, 2);
        // Sub-rupee leftover after round-off payments must not show as outstanding.
        $balance = round($balance, 2);
        if ($balance > 0.009 && $balance < 1.0) {
            $balance = 0.0;
        }
        $row['balance'] = $balance;
        return $row;
    }

    public function show(Request $request): Response
    {
        $purchase = (new Purchase())->withItems((int) $request->attribute('id'));
        if (!$purchase || (int) $purchase['client_id'] !== $request->clientId()) {
            throw HttpException::notFound('Purchase not found.');
        }
        return $this->success($this->enrichPurchaseLogRow($purchase), 'Purchase fetched.');
    }

    public function store(Request $request): Response
    {
        if ($request->branchId() === null) {
            throw HttpException::forbidden('A branch context is required.');
        }

        $this->validate($request, [
            'invoice_no'   => 'required|string|max:60',
            'invoice_date' => 'required|string|max:20',
        ]);

        $result = (new PurchaseService())->create(
            $request->clientId(),
            $request->branchId(),
            $request->user()['id'] ?? null,
            $request->all()
        );

        return $this->created($result, 'Purchase saved and stock updated.');
    }

    public function update(Request $request): Response
    {
        if ($request->branchId() === null) {
            throw HttpException::forbidden('A branch context is required.');
        }

        $this->validate($request, [
            'invoice_no'   => 'required|string|max:60',
            'invoice_date' => 'required|string|max:20',
        ]);

        $user = $request->user() ?? [];
        $userId = (int) ($user['id'] ?? 0);
        $role = strtolower(trim((string) ($user['role'] ?? '')));
        if ($role !== 'ho_admin') {
            (new \App\Services\UserRightsService())->requireModule(
                (int) $request->clientId(),
                $userId,
                $role,
                'PURCHASE',
                true,
            );
        }

        // Purchase edit right alone allows editing even when stock was sold (UI confirms first).
        $result = (new PurchaseService())->update(
            (int) $request->clientId(),
            (int) $request->branchId(),
            (int) $request->attribute('id'),
            $request->all(),
            true,
            [
                'user_id'    => $userId > 0 ? $userId : null,
                'user_name'  => $this->auditUserName($user),
                'user_role'  => (string) ($user['role'] ?? ''),
                'ip_address' => $this->clientIp(),
                'user_agent' => $request->userAgent(),
            ]
        );

        return $this->success($result, 'Purchase updated and stock adjusted.');
    }

    /** Display name from HO users (fresh), else token name / email. */
    private function auditUserName(mixed $user): string
    {
        $name = is_array($user) ? trim((string) ($user['name'] ?? $user['email'] ?? 'SYSTEM')) : 'SYSTEM';
        $userId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
        if ($userId > 0) {
            try {
                $st = Connection::ho()->prepare('SELECT name FROM users WHERE id = :id LIMIT 1');
                $st->execute(['id' => $userId]);
                $dbName = trim((string) ($st->fetchColumn() ?: ''));
                if ($dbName !== '') {
                    $name = $dbName;
                }
            } catch (\Throwable) {
                // Fall back to the name in the token.
            }
        }
        return $name !== '' ? $name : 'SYSTEM';
    }

    /** First valid address from X-Forwarded-For (proxy), else REMOTE_ADDR. */
    private function clientIp(): string
    {
        foreach (explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')) as $part) {
            $ip = trim($part);
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
    }

    public function destroy(Request $request): Response
    {
        if ($request->branchId() === null) {
            throw HttpException::forbidden('A branch context is required.');
        }

        (new PurchaseService())->delete(
            (int) $request->clientId(),
            (int) $request->branchId(),
            (int) $request->attribute('id')
        );

        return $this->success(null, 'Purchase deleted and stock updated.');
    }
}
