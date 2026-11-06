<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Database\Connection;
use App\Models\Branch;
use PDO;

/**
 * Online order (ePharmacy) placement and delivery status workflow.
 * Stock is reserved (FEFO) on place and restored if the order is cancelled.
 */
final class OrderService
{
    private const FLOW = ['placed', 'confirmed', 'packed', 'out_for_delivery', 'delivered'];

    private InventoryService $inventory;

    public function __construct(?InventoryService $inventory = null)
    {
        $this->inventory = $inventory ?? new InventoryService();
    }

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

                if (!$bindingId) {
                    throw HttpException::validation(['items' => ['Each item must include a product.']]);
                }

                // Verify binding belongs to this branch and is active
                $chk = $db->prepare(
                    'SELECT id, product_name FROM product_bindings
                     WHERE id = :id AND branch_id = :b AND is_active = 1 LIMIT 1'
                );
                $chk->execute(['id' => $bindingId, 'b' => $branchId]);
                $binding = $chk->fetch();
                if (!$binding) {
                    throw HttpException::validation([
                        'items' => ['One or more products are not available at this pharmacy.'],
                    ]);
                }

                if ($rate <= 0) {
                    $r = $db->prepare(
                        'SELECT mrp FROM inventory_batches
                         WHERE product_binding_id = :p AND quantity > 0
                           AND (expiry_date IS NULL OR expiry_date >= CURDATE())
                         ORDER BY (expiry_date IS NULL), expiry_date ASC LIMIT 1'
                    );
                    $r->execute(['p' => $bindingId]);
                    $rate = (float) ($r->fetchColumn() ?: 0);
                }

                $lineTotal = $rate * $qty;
                $estimate += $lineTotal;
                $computed[] = [
                    'binding_id' => $bindingId,
                    'name'       => (string) ($item['product_name'] ?? $binding['product_name'] ?? 'Item'),
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
                // Reserve stock immediately (FEFO across batches)
                $this->reserveStock($db, $clientId, $branchId, $c['binding_id'], $c['qty'], $orderId, $c['name']);
            }

            $this->addEvent($db, $orderId, 'placed', 'Order received · stock reserved');

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

        Connection::transaction('ops', function (PDO $db) use ($orderId, $clientId, $status, $assignedTo, $note) {
            $stmt = $db->prepare(
                'SELECT id, status FROM online_orders WHERE id = :id AND client_id = :c LIMIT 1 FOR UPDATE'
            );
            $stmt->execute(['id' => $orderId, 'c' => $clientId]);
            $order = $stmt->fetch();
            if (!$order) {
                throw HttpException::notFound('Order not found.');
            }

            $current = (string) $order['status'];
            if ($current === $status) {
                return null;
            }
            if ($current === 'cancelled') {
                throw HttpException::validation(['status' => ['A cancelled order cannot be changed.']]);
            }
            if ($current === 'delivered' && $status === 'cancelled') {
                throw HttpException::validation(['status' => ['Delivered orders cannot be cancelled.']]);
            }

            if ($status === 'cancelled') {
                $this->restoreStock($db, $orderId);
            }

            $upd = $db->prepare(
                'UPDATE online_orders SET status = :s, assigned_to = COALESCE(:a, assigned_to), updated_at = :now WHERE id = :id'
            );
            $upd->execute(['s' => $status, 'a' => $assignedTo, 'now' => now(), 'id' => $orderId]);

            $eventNote = $note;
            if ($status === 'cancelled' && ($eventNote === null || $eventNote === '')) {
                $eventNote = 'Order cancelled · stock returned';
            }
            $this->addEvent($db, $orderId, $status, $eventNote);

            return null;
        });
    }

    /**
     * Public cancel by tracking token or order number (+ phone verification).
     *
     * @return array{order_no: string, status: string}
     */
    public function cancelPublic(?string $token, ?string $orderNo, ?string $phone): array
    {
        $token = trim((string) $token);
        $orderNo = trim((string) $orderNo);
        $phone = trim((string) $phone);

        if ($token === '' && $orderNo === '') {
            throw HttpException::validation(['order' => ['Provide order number or tracking number.']]);
        }

        return Connection::transaction('ops', function (PDO $db) use ($token, $orderNo, $phone) {
            if ($token !== '') {
                $stmt = $db->prepare(
                    'SELECT id, client_id, order_no, phone, status FROM online_orders WHERE tracking_token = :t LIMIT 1 FOR UPDATE'
                );
                $stmt->execute(['t' => $token]);
            } else {
                $stmt = $db->prepare(
                    'SELECT id, client_id, order_no, phone, status FROM online_orders WHERE order_no = :o LIMIT 1 FOR UPDATE'
                );
                $stmt->execute(['o' => $orderNo]);
            }
            $order = $stmt->fetch();
            if (!$order) {
                throw HttpException::notFound('Order not found.');
            }

            // When cancelling by order number, require matching phone
            if ($token === '') {
                $want = preg_replace('/\D+/', '', $phone) ?? '';
                $have = preg_replace('/\D+/', '', (string) ($order['phone'] ?? '')) ?? '';
                if (strlen($want) < 10 || substr($have, -10) !== substr($want, -10)) {
                    throw HttpException::validation([
                        'phone' => ['Phone number does not match this order.'],
                    ]);
                }
            }

            $current = (string) $order['status'];
            if ($current === 'cancelled') {
                throw HttpException::validation(['status' => ['This order is already cancelled.']]);
            }
            if ($current === 'delivered') {
                throw HttpException::validation(['status' => ['Delivered orders cannot be cancelled.']]);
            }
            if ($current === 'out_for_delivery') {
                throw HttpException::validation([
                    'status' => ['Order is out for delivery. Please contact the pharmacy to cancel.'],
                ]);
            }

            $this->restoreStock($db, (int) $order['id']);

            $upd = $db->prepare(
                'UPDATE online_orders SET status = :s, updated_at = :now WHERE id = :id'
            );
            $upd->execute(['s' => 'cancelled', 'now' => now(), 'id' => $order['id']]);
            $this->addEvent($db, (int) $order['id'], 'cancelled', 'Cancelled by customer · stock returned');

            return [
                'order_no' => (string) $order['order_no'],
                'status'   => 'cancelled',
            ];
        });
    }

    /** FEFO reserve: deduct batches + aggregate; ledger ref = online_order. */
    private function reserveStock(
        PDO $db,
        int $clientId,
        int $branchId,
        int $bindingId,
        int $qty,
        int $orderId,
        string $productName
    ): void {
        $stmt = $db->prepare(
            'SELECT id, batch_no, quantity FROM inventory_batches
             WHERE client_id = :c AND branch_id = :b AND product_binding_id = :p AND quantity > 0
               AND (expiry_date IS NULL OR expiry_date >= CURDATE())
             ORDER BY (expiry_date IS NULL), expiry_date ASC, id ASC
             FOR UPDATE'
        );
        $stmt->execute(['c' => $clientId, 'b' => $branchId, 'p' => $bindingId]);
        $batches = $stmt->fetchAll();

        $available = array_sum(array_map(static fn ($b) => (int) $b['quantity'], $batches));
        if ($available < $qty) {
            throw HttpException::validation([
                'stock' => [$available > 0
                    ? "Only {$available} of {$productName} available. Reduce the quantity and try again."
                    : "{$productName} is out of stock. Remove it from the cart and try again."],
            ]);
        }

        $remaining = $qty;
        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }
            $take = min($remaining, (int) $batch['quantity']);
            $this->inventory->reduceBatch(
                (int) $batch['id'],
                $take,
                'sale',
                date('Y-m-d'),
                'online_order',
                $orderId
            );
            $remaining -= $take;
        }

        $this->inventory->bumpAggregate($clientId, $branchId, $bindingId, -$qty);
    }

    /** Reverse online_order ledger outs for this order (idempotent if none / already restored). */
    private function restoreStock(PDO $db, int $orderId): void
    {
        $stmt = $db->prepare(
            "SELECT batch_id, product_binding_id, client_id, branch_id,
                    SUM(qty_out) AS qty_out, SUM(qty_in) AS qty_in
             FROM stock_ledger
             WHERE ref_type = 'online_order' AND ref_id = :id
               AND txn_type IN ('sale', 'sale_return')
             GROUP BY batch_id, product_binding_id, client_id, branch_id"
        );
        $stmt->execute(['id' => $orderId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            $netOut = (int) $row['qty_out'] - (int) $row['qty_in'];
            if ($netOut <= 0 || empty($row['batch_id'])) {
                continue;
            }

            $batchId = (int) $row['batch_id'];
            $bindingId = (int) $row['product_binding_id'];
            $clientId = (int) $row['client_id'];
            $branchId = (int) $row['branch_id'];

            $upd = $db->prepare(
                'UPDATE inventory_batches SET quantity = quantity + :q, updated_at = :now WHERE id = :id'
            );
            $upd->execute(['q' => $netOut, 'now' => now(), 'id' => $batchId]);

            $this->inventory->bumpAggregate($clientId, $branchId, $bindingId, $netOut);
            $this->inventory->ledger(
                $clientId,
                $branchId,
                $bindingId,
                $batchId,
                'sale_return',
                $netOut,
                0,
                date('Y-m-d'),
                'online_order',
                $orderId,
                'Order cancelled · stock returned'
            );
        }
    }

    private function addEvent(PDO $db, int $orderId, string $status, ?string $note): void
    {
        $stmt = $db->prepare(
            'INSERT INTO online_order_events (order_id, status, note, created_at) VALUES (:o, :s, :n, :now)'
        );
        $stmt->execute(['o' => $orderId, 's' => $status, 'n' => $note, 'now' => now()]);
    }
}
