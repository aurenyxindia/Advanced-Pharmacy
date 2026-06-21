<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Database\Connection;
use App\Models\Branch;
use PDO;

/**
 * Online order (ePharmacy) placement and delivery status workflow.
 */
final class OrderService
{
    private const FLOW = ['placed', 'confirmed', 'packed', 'out_for_delivery', 'delivered'];

    public function place(int $branchId, array $data): array
    {
        $branch = (new Branch())->find($branchId);
        if (!$branch || ($branch['status'] ?? '') !== 'active') {
            throw HttpException::notFound('Selected pharmacy branch is not available.');
        }
        $clientId = (int) $branch['client_id'];

        $items = $data['items'] ?? [];
        if (!is_array($items) || $items === []) {
            throw HttpException::validation(['items' => ['Your cart is empty.']]);
        }

        return Connection::transaction('ops', function (PDO $db) use ($clientId, $branchId, $data, $items) {
            $orderNo = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
            $token = bin2hex(random_bytes(16));

            $estimate = 0.0;
            $computed = [];
            foreach ($items as $item) {
                $bindingId = !empty($item['product_binding_id']) ? (int) $item['product_binding_id'] : null;
                $qty = max(1, (int) ($item['quantity'] ?? 1));
                $rate = (float) ($item['rate'] ?? 0);

                if ($bindingId && $rate <= 0) {
                    $r = $db->prepare(
                        'SELECT mrp FROM inventory_batches WHERE product_binding_id = :p AND quantity > 0
                         ORDER BY (expiry_date IS NULL), expiry_date ASC LIMIT 1'
                    );
                    $r->execute(['p' => $bindingId]);
                    $rate = (float) ($r->fetchColumn() ?: 0);
                }
                $lineTotal = $rate * $qty;
                $estimate += $lineTotal;
                $computed[] = [
                    'binding_id' => $bindingId,
                    'name'       => $item['product_name'] ?? 'Item',
                    'qty'        => $qty,
                    'rate'       => $rate,
                    'total'      => $lineTotal,
                ];
            }

            $stmt = $db->prepare(
                'INSERT INTO online_orders
                    (client_id, branch_id, order_no, tracking_token, customer_name, phone, email,
                     address, pincode, total_estimate, status, prescription_id, notes, created_at, updated_at)
                 VALUES
                    (:c, :b, :no, :tok, :name, :phone, :email, :addr, :pin, :est, :status, :presc, :notes, :now, :now2)'
            );
            $stmt->execute([
                'c' => $clientId, 'b' => $branchId, 'no' => $orderNo, 'tok' => $token,
                'name' => $data['customer_name'] ?? '', 'phone' => $data['phone'] ?? '',
                'email' => $data['email'] ?? null, 'addr' => $data['address'] ?? '',
                'pin' => $data['pincode'] ?? null, 'est' => round($estimate, 2), 'status' => 'placed',
                'presc' => $data['prescription_id'] ?? null, 'notes' => $data['notes'] ?? null,
                'now' => now(), 'now2' => now(),
            ]);
            $orderId = (int) $db->lastInsertId();

            $itemStmt = $db->prepare(
                'INSERT INTO online_order_items (order_id, product_binding_id, product_name, quantity, rate, line_total)
                 VALUES (:o, :p, :n, :q, :r, :t)'
            );
            foreach ($computed as $c) {
                $itemStmt->execute([
                    'o' => $orderId, 'p' => $c['binding_id'], 'n' => $c['name'],
                    'q' => $c['qty'], 'r' => $c['rate'], 't' => $c['total'],
                ]);
            }

            $this->addEvent($db, $orderId, 'placed', 'Order received');

            return [
                'order_no'       => $orderNo,
                'tracking_token' => $token,
                'total_estimate' => round($estimate, 2),
            ];
        });
    }

    public function updateStatus(int $orderId, int $clientId, string $status, ?string $assignedTo, ?string $note): void
    {
        if (!in_array($status, [...self::FLOW, 'cancelled'], true)) {
            throw HttpException::validation(['status' => ['Invalid status.']]);
        }

        $db = Connection::ops();
        $stmt = $db->prepare('SELECT id FROM online_orders WHERE id = :id AND client_id = :c LIMIT 1');
        $stmt->execute(['id' => $orderId, 'c' => $clientId]);
        if (!$stmt->fetch()) {
            throw HttpException::notFound('Order not found.');
        }

        $upd = $db->prepare(
            'UPDATE online_orders SET status = :s, assigned_to = COALESCE(:a, assigned_to), updated_at = :now WHERE id = :id'
        );
        $upd->execute(['s' => $status, 'a' => $assignedTo, 'now' => now(), 'id' => $orderId]);

        $this->addEvent($db, $orderId, $status, $note);
    }

    private function addEvent(PDO $db, int $orderId, string $status, ?string $note): void
    {
        $stmt = $db->prepare(
            'INSERT INTO online_order_events (order_id, status, note, created_at) VALUES (:o, :s, :n, :now)'
        );
        $stmt->execute(['o' => $orderId, 's' => $status, 'n' => $note, 'now' => now()]);
    }
}
