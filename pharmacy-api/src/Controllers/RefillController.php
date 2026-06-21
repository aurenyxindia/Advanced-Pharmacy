<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Database\Connection;
use App\Models\RefillReminder;

/**
 * Prescription refill reminders. A reminder can be created automatically from
 * a sale (days-of-supply) or manually. The list supports due-window filters
 * so staff can follow up with patients.
 */
final class RefillController extends Controller
{
    public function index(Request $request): Response
    {
        $where = 'client_id = :c';
        $bind = ['c' => $request->clientId()];
        if (($request->user()['scope'] ?? 'branch') !== 'ho' && $request->branchId() !== null) {
            $where .= ' AND branch_id = :b';
            $bind['b'] = $request->branchId();
        }

        $status = $request->query('status');
        if ($status) {
            $where .= ' AND status = :status';
            $bind['status'] = $status;
        }

        switch ($request->query('due')) {
            case 'overdue':
                $where .= " AND refill_date < CURDATE() AND status IN ('pending','notified')";
                break;
            case 'today':
                $where .= " AND refill_date = CURDATE() AND status IN ('pending','notified')";
                break;
            case 'upcoming':
                $where .= " AND refill_date BETWEEN CURDATE() AND (CURDATE() + INTERVAL 7 DAY) AND status IN ('pending','notified')";
                break;
        }

        $stmt = Connection::ops()->prepare(
            "SELECT * FROM refill_reminders WHERE {$where} ORDER BY refill_date ASC, id DESC LIMIT 200"
        );
        $stmt->execute($bind);
        return $this->success($stmt->fetchAll(), 'Refill reminders.');
    }

    public function summary(Request $request): Response
    {
        $branchClause = '';
        $bind = ['c' => $request->clientId()];
        if (($request->user()['scope'] ?? 'branch') !== 'ho' && $request->branchId() !== null) {
            $branchClause = ' AND branch_id = :b';
            $bind['b'] = $request->branchId();
        }

        $count = function (string $extra) use ($branchClause, $bind): int {
            $stmt = Connection::ops()->prepare(
                "SELECT COUNT(*) FROM refill_reminders WHERE client_id = :c {$branchClause} {$extra}"
            );
            $stmt->execute($bind);
            return (int) $stmt->fetchColumn();
        };

        return $this->success([
            'overdue'  => $count("AND refill_date < CURDATE() AND status IN ('pending','notified')"),
            'today'    => $count("AND refill_date = CURDATE() AND status IN ('pending','notified')"),
            'upcoming' => $count("AND refill_date BETWEEN (CURDATE() + INTERVAL 1 DAY) AND (CURDATE() + INTERVAL 7 DAY) AND status IN ('pending','notified')"),
        ], 'Refill summary.');
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, [
            'customer_name' => 'required|string|max:150',
            'phone'         => 'string|max:20',
            'product_name'  => 'string|max:200',
            'days_supply'   => 'integer|min:1|max:365',
            'refill_date'   => 'string|max:20',
            'sale_id'       => 'integer',
            'notes'         => 'string|max:255',
        ]);

        // Derive refill date from days_supply if an explicit date is not given.
        $refillDate = $data['refill_date'] ?? null;
        if (!$refillDate && !empty($data['days_supply'])) {
            $refillDate = date('Y-m-d', strtotime('+' . (int) $data['days_supply'] . ' days'));
        }
        if (!$refillDate) {
            throw HttpException::validation(['refill_date' => ['Provide a refill date or days of supply.']]);
        }

        $id = (new RefillReminder())->insert([
            'client_id'     => $request->clientId(),
            'branch_id'     => $request->branchId(),
            'sale_id'       => $data['sale_id'] ?? null,
            'customer_name' => $data['customer_name'],
            'phone'         => $data['phone'] ?? null,
            'product_name'  => $data['product_name'] ?? null,
            'refill_date'   => $refillDate,
            'status'        => 'pending',
            'notes'         => $data['notes'] ?? null,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        return $this->created(['id' => $id, 'refill_date' => $refillDate], 'Refill reminder scheduled.');
    }

    public function update(Request $request): Response
    {
        $reminders = new RefillReminder();
        $row = $reminders->find((int) $request->attribute('id'));
        if (!$row || (int) $row['client_id'] !== $request->clientId()) {
            throw HttpException::notFound('Reminder not found.');
        }

        $data = $this->validate($request, [
            'status'  => 'in:pending,notified,completed,cancelled',
            'channel' => 'in:sms,email,call,whatsapp',
            'notes'   => 'string|max:255',
        ]);

        $payload = ['updated_at' => now()];
        if (!empty($data['status'])) {
            $payload['status'] = $data['status'];
            if ($data['status'] === 'notified') {
                $payload['notified_at'] = now();
            }
        }
        if (!empty($data['channel'])) {
            $payload['channel'] = $data['channel'];
        }
        if (isset($data['notes'])) {
            $payload['notes'] = $data['notes'];
        }

        $reminders->update((int) $row['id'], $payload);
        return $this->success(null, 'Reminder updated.');
    }
}
