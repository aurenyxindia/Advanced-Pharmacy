<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\OnlineOrder;
use App\Services\OrderService;

/**
 * Staff-facing order / delivery management.
 */
final class OrderController extends Controller
{
    public function index(Request $request): Response
    {
        $conditions = ['client_id' => $request->clientId()];
        if (($request->user()['scope'] ?? 'branch') !== 'ho' && $request->branchId() !== null) {
            $conditions['branch_id'] = $request->branchId();
        }
        if ($status = $request->query('status')) {
            $conditions['status'] = $status;
        }

        $result = (new OnlineOrder())->paginate(
            $conditions,
            $request->query('search'),
            ['order_no', 'customer_name', 'phone'],
            (int) $request->query('page', 1),
            (int) $request->query('per_page', 25),
            'created_at DESC'
        );
        return $this->success($result, 'Orders.');
    }

    public function show(Request $request): Response
    {
        $order = (new OnlineOrder())->withDetails((int) $request->attribute('id'));
        if (!$order || (int) $order['client_id'] !== $request->clientId()) {
            throw HttpException::notFound('Order not found.');
        }
        return $this->success($order, 'Order.');
    }

    public function updateStatus(Request $request): Response
    {
        $data = $this->validate($request, [
            'status'      => 'required|in:placed,confirmed,packed,out_for_delivery,delivered,cancelled',
            'assigned_to' => 'string|max:120',
            'note'        => 'string|max:255',
        ]);

        (new OrderService())->updateStatus(
            (int) $request->attribute('id'),
            (int) $request->clientId(),
            $data['status'],
            $data['assigned_to'] ?? null,
            $data['note'] ?? null
        );

        return $this->success(null, 'Order status updated.');
    }
}
