<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Database\Connection;
use App\Services\OrderService;

/**
 * Unauthenticated, customer-facing ePharmacy endpoints: list pharmacies,
 * browse a branch's catalog, place an order, and track delivery.
 */
final class PublicController extends Controller
{
    /** Active pharmacy branches available for online ordering. */
    public function branches(Request $request): Response
    {
        $rows = Connection::ho()->query(
            "SELECT b.id, b.name, b.city, c.business_name
             FROM branches b JOIN clients c ON c.id = b.client_id
             WHERE b.status = 'active' AND c.subscription_status IN ('trial','active')
             ORDER BY c.business_name, b.name"
        )->fetchAll();
        return $this->success($rows, 'Pharmacies.');
    }

    /** In-stock products for a branch (public catalog). */
    public function catalog(Request $request): Response
    {
        $branchId = (int) $request->query('branch_id', 0);
        if ($branchId <= 0) {
            throw HttpException::validation(['branch_id' => ['Select a pharmacy first.']]);
        }
        $q = '%' . (string) $request->query('q', '') . '%';
        $stmt = Connection::ops()->prepare(
            "SELECT pb.id AS binding_id, pb.product_name, pb.schedule_category,
                    COALESCE(st.quantity, 0) AS available_qty,
                    (SELECT mrp FROM inventory_batches ib WHERE ib.product_binding_id = pb.id AND ib.quantity > 0
                       ORDER BY (ib.expiry_date IS NULL), ib.expiry_date ASC LIMIT 1) AS mrp
             FROM product_bindings pb
             LEFT JOIN inventory_stock st ON st.product_binding_id = pb.id
             WHERE pb.branch_id = :b AND pb.is_active = 1 AND COALESCE(st.quantity,0) > 0 AND pb.product_name LIKE :q
             ORDER BY pb.product_name ASC LIMIT 40"
        );
        $stmt->execute(['b' => $branchId, 'q' => $q]);
        return $this->success($stmt->fetchAll(), 'Catalog.');
    }

    public function placeOrder(Request $request): Response
    {
        $data = $this->validate($request, [
            'branch_id'     => 'required|integer',
            'customer_name' => 'required|string|max:150',
            'phone'         => 'required|string|max:20',
            'address'       => 'required|string|max:400',
            'pincode'       => 'string|max:12',
            'email'         => 'email|max:150',
        ]);

        $result = (new OrderService())->place((int) $data['branch_id'], $request->all());
        return $this->created($result, 'Order placed. Save your tracking number!');
    }

    public function track(Request $request): Response
    {
        $token = (string) $request->query('token', '');
        $orderNo = (string) $request->query('order_no', '');
        if ($token === '' && $orderNo === '') {
            throw HttpException::validation(['token' => ['Provide a tracking token or order number.']]);
        }

        $stmt = Connection::ops()->prepare(
            'SELECT id, order_no, customer_name, status, total_estimate, created_at
             FROM online_orders WHERE tracking_token = :t OR order_no = :o LIMIT 1'
        );
        $stmt->execute(['t' => $token, 'o' => $orderNo]);
        $order = $stmt->fetch();
        if (!$order) {
            throw HttpException::notFound('Order not found. Check your tracking number.');
        }

        $events = Connection::ops()->prepare(
            'SELECT status, note, created_at FROM online_order_events WHERE order_id = :id ORDER BY id ASC'
        );
        $events->execute(['id' => $order['id']]);
        $order['events'] = $events->fetchAll();
        unset($order['id']);

        return $this->success($order, 'Order status.');
    }
}
