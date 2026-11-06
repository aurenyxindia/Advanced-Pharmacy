<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AgtStoreLocationService;
use App\Services\AuraGharTakService;
use App\Services\UserRightsService;

final class AuraGharTakController extends Controller
{
    private const MODULE = 'AURA_GHAR_TAK';

    private function requireAgt(Request $request, bool $edit = false): void
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

    public function customers(Request $request): Response
    {
        $this->requireAgt($request);
        $q = trim((string) $request->query('q', ''));
        $rows = (new AuraGharTakService())->searchCustomers((int) $request->clientId(), $q);
        return $this->success(['customers' => $rows], 'Customers.');
    }

    public function createShare(Request $request): Response
    {
        $this->requireAgt($request, true);
        $send = filter_var($request->input('send_whatsapp', false), FILTER_VALIDATE_BOOLEAN);
        $data = $this->validate($request, [
            'patient_name' => 'required|string|max:255',
            'patient_contact' => 'required|string|max:20',
            'patient_address' => 'string|max:500',
            'patient_no' => 'string|max:64',
        ]);
        $result = (new AuraGharTakService())->createShareLink(
            (int) $request->clientId(),
            (int) $request->branchId(),
            $data,
            $send
        );
        return $this->success($result, $result['message'] ?? 'Link created.');
    }

    public function orderCounts(Request $request): Response
    {
        $this->requireAgt($request);
        $counts = (new AuraGharTakService())->orderCounts(
            (int) $request->clientId(),
            (int) $request->branchId()
        );
        return $this->success([
            'counts' => $counts,
            'new_orders' => (int) ($counts['Placed'] ?? 0),
        ], 'Counts.');
    }

    public function listOrders(Request $request): Response
    {
        $this->requireAgt($request);
        $status = (string) $request->query('status', '');
        $limit = (int) $request->query('limit', 50);
        $orders = (new AuraGharTakService())->listOrders(
            (int) $request->clientId(),
            (int) $request->branchId(),
            $status !== '' ? $status : null,
            $limit
        );
        return $this->success(['orders' => $orders], 'Orders.');
    }

    public function showOrder(Request $request): Response
    {
        $this->requireAgt($request);
        $orderId = (int) $request->query('order_id', 0);
        $orderNo = trim((string) $request->query('order_no', ''));
        $order = (new AuraGharTakService())->getOrder(
            (int) $request->clientId(),
            (int) $request->branchId(),
            $orderId,
            $orderNo
        );
        if (!$order) {
            throw HttpException::notFound('Order not found.');
        }
        return $this->success(['order' => $order], 'Order.');
    }

    public function pendingByPatient(Request $request): Response
    {
        $this->requireAgt($request);
        $orders = (new AuraGharTakService())->pendingOrdersByPatient(
            (int) $request->clientId(),
            (int) $request->branchId(),
            trim((string) $request->query('patient_no', '')),
            trim((string) $request->query('patient_contact', ''))
        );
        return $this->success(['orders' => $orders, 'order_count' => count($orders)], 'Pending orders.');
    }

    public function markReady(Request $request): Response
    {
        $this->requireAgt($request, true);
        $data = $this->validate($request, [
            'order_id' => 'required|integer',
            'sale_order_no' => 'string|max:64',
            'sale_invoice_no' => 'string|max:64',
            'sale_id' => 'integer',
            'sale_net' => 'numeric',
        ]);
        $invoice = (string) ($data['sale_invoice_no'] ?? $data['sale_order_no'] ?? '');
        (new AuraGharTakService())->markOrderReady(
            (int) $request->clientId(),
            (int) $request->branchId(),
            (int) $data['order_id'],
            $invoice,
            (int) ($data['sale_id'] ?? 0),
            (float) ($data['sale_net'] ?? 0)
        );
        return $this->success(null, 'Order marked Ready.');
    }

    public function updateStatus(Request $request): Response
    {
        $this->requireAgt($request, true);
        $data = $this->validate($request, [
            'order_id' => 'required|integer',
            'status' => 'required|in:Out for Delivery,Delivered,Cancelled',
        ]);
        $sendWa = filter_var($request->input('send_whatsapp', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $result = (new AuraGharTakService())->updateStatus(
            (int) $request->clientId(),
            (int) $request->branchId(),
            (int) $data['order_id'],
            (string) $data['status'],
            $sendWa ?? true
        );
        return $this->success($result, $result['message'] ?? 'Updated.');
    }

    public function branchSettings(Request $request): Response
    {
        $this->requireAgt($request);
        $svc = new AuraGharTakService();
        $settings = $svc->getBranchSettings((int) $request->clientId(), (int) $request->branchId());
        return $this->success($settings, 'AuraGharTak shop policy.');
    }

    public function saveBranchSettings(Request $request): Response
    {
        $this->requireAgt($request, true);
        $allow = filter_var($request->input('allow_customer_cancel', false), FILTER_VALIDATE_BOOLEAN);
        $svc = new AuraGharTakService();
        $settings = $svc->saveBranchSettings(
            (int) $request->clientId(),
            (int) $request->branchId(),
            $allow
        );
        return $this->success($settings, $allow ? 'Customers can cancel new orders online.' : 'Online cancellation disabled.');
    }

    public function storeLocation(Request $request): Response
    {
        $this->requireAgt($request);
        $loc = (new AgtStoreLocationService())->getAdvancedBranchLocation((int) $request->branchId());
        return $this->success(['location' => $loc], 'Shop location.');
    }

    public function saveStoreLocation(Request $request): Response
    {
        $this->requireAgt($request, true);
        $data = $this->validate($request, [
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
        ]);
        $saved = (new AgtStoreLocationService())->saveAdvancedBranchLocation(
            (int) $request->branchId(),
            (float) $data['latitude'],
            (float) $data['longitude'],
            'manual',
        );
        return $this->success(['location' => $saved], 'Shop location saved.');
    }

    // ---- Public (no auth) ----

    public function publicShop(Request $request): Response
    {
        $token = trim((string) $request->query('token', ''));
        $q = trim((string) $request->query('q', ''));
        $payload = (new AuraGharTakService())->publicShop($token, $q);
        return $this->success($payload, 'Shop.');
    }

    public function publicPlaceOrder(Request $request): Response
    {
        $token = trim((string) ($request->input('token') ?? $request->query('token', '')));
        $result = (new AuraGharTakService())->placePublicOrder($token, $request->all());
        return $this->created($result, $result['message'] ?? 'Order placed.');
    }

    public function publicCancelOrder(Request $request): Response
    {
        $token = trim((string) ($request->input('token') ?? $request->query('token', '')));
        $orderId = (int) ($request->input('order_id') ?? 0);
        $result = (new AuraGharTakService())->publicCancelOrder($token, $orderId);
        return $this->success($result, $result['message'] ?? 'Order cancelled.');
    }

    public function publicPrescription(Request $request): Response
    {
        $file = (string) $request->attribute('file');
        $path = (new AuraGharTakService())->prescriptionFilePath($file);
        if ($path === null) {
            throw HttpException::notFound('File not found.');
        }
        $mime = str_ends_with(strtolower($path), '.pdf') ? 'application/pdf' : 'image/jpeg';
        return Response::raw((string) file_get_contents($path), $mime);
    }
}
