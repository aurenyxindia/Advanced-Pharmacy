<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Database\Connection;
use App\Models\Branch;
use App\Services\AuraGharTakService;
use App\Services\AuraGharTakUserService;
use App\Services\AgtStoreLocationService;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Models\Product;
use PDO;

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
            "SELECT b.id, b.name,
                    COALESCE(NULLIF(TRIM(b.city), ''), NULLIF(TRIM(c.city), '')) AS city,
                    COALESCE(NULLIF(TRIM(b.state), ''), NULLIF(TRIM(c.state), '')) AS state,
                    COALESCE(
                        NULLIF(TRIM(b.pincode), ''),
                        NULLIF(TRIM(c.pincode), '')
                    ) AS pincode,
                    COALESCE(NULLIF(TRIM(b.address), ''), NULLIF(TRIM(c.address), '')) AS address,
                    COALESCE(NULLIF(TRIM(b.phone), ''), NULLIF(TRIM(c.phone), '')) AS phone,
                    c.business_name
             FROM branches b
             INNER JOIN clients c ON c.id = b.client_id
             WHERE b.status = 'active'
               AND c.subscription_status IN ('trial','active')
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
        $avail = InventoryService::sellableQtyExpr('pb');
        $stmt = Connection::ops()->prepare(
            "SELECT pb.id AS binding_id, pb.product_name, pb.schedule_category,
                    {$avail} AS available_qty,
                    (SELECT mrp FROM inventory_batches ib WHERE ib.product_binding_id = pb.id AND ib.quantity > 0
                       AND (ib.expiry_date IS NULL OR ib.expiry_date >= CURDATE())
                       ORDER BY (ib.expiry_date IS NULL), ib.expiry_date ASC LIMIT 1) AS mrp,
                    GREATEST(1, COALESCE(pb.units_per_pack, 1)) AS units_per_pack, pb.pack_size
             FROM product_bindings pb
             WHERE pb.branch_id = :b AND pb.is_active = 1 AND {$avail} > 0 AND pb.product_name LIKE :q
             ORDER BY pb.product_name ASC LIMIT 40"
        );
        $stmt->execute(['b' => $branchId, 'q' => $q]);
        return $this->success($stmt->fetchAll(), 'Catalog.');
    }

    /** Same-generic substitutes in stock at this branch (customer app). */
    public function catalogSubstitutes(Request $request): Response
    {
        $branchId = (int) $request->query('branch_id', 0);
        $bindingId = (int) $request->query('binding_id', 0);
        if ($branchId <= 0 || $bindingId <= 0) {
            throw HttpException::validation([
                'query' => ['branch_id and binding_id are required.'],
            ]);
        }
        $branch = (new Branch())->find($branchId);
        if (!$branch || ($branch['status'] ?? '') !== 'active') {
            throw HttpException::notFound('Pharmacy not found.');
        }
        $clientId = (int) $branch['client_id'];
        $binding = Connection::ops()->prepare(
            'SELECT master_product_id, product_name FROM product_bindings
             WHERE id = :id AND branch_id = :b AND is_active = 1 LIMIT 1'
        );
        $binding->execute(['id' => $bindingId, 'b' => $branchId]);
        $row = $binding->fetch();
        if (!$row || (int) ($row['master_product_id'] ?? 0) <= 0) {
            return $this->success([], 'No substitutes.');
        }
        $subs = (new Product())->substitutes((int) $row['master_product_id']);
        $avail = InventoryService::sellableQtyExpr('pb');
        $out = [];
        foreach ($subs as $sub) {
            $b = Connection::ops()->prepare(
                "SELECT pb.id AS binding_id, pb.product_name, pb.schedule_category,
                        {$avail} AS available_qty,
                        (SELECT mrp FROM inventory_batches ib WHERE ib.product_binding_id = pb.id AND ib.quantity > 0
                           AND (ib.expiry_date IS NULL OR ib.expiry_date >= CURDATE())
                           ORDER BY (ib.expiry_date IS NULL), ib.expiry_date ASC LIMIT 1) AS mrp,
                        GREATEST(1, COALESCE(pb.units_per_pack, 1)) AS units_per_pack, pb.pack_size
                 FROM product_bindings pb
                 WHERE pb.client_id = :c AND pb.branch_id = :b AND pb.master_product_id = :mp
                   AND pb.is_active = 1 AND {$avail} > 0
                 LIMIT 1"
            );
            $b->execute(['c' => $clientId, 'b' => $branchId, 'mp' => (int) $sub['id']]);
            $bound = $b->fetch();
            if (!$bound) {
                continue;
            }
            $out[] = [
                'binding_id'         => (int) $bound['binding_id'],
                'product_name'       => (string) $bound['product_name'],
                'schedule_category'  => (string) ($bound['schedule_category'] ?? ''),
                'available_qty'      => (float) $bound['available_qty'],
                'mrp'                => (float) ($bound['mrp'] ?? 0),
                'units_per_pack'     => (int) ($bound['units_per_pack'] ?? 1),
                'pack_size'          => (string) ($bound['pack_size'] ?? ''),
                'generic_name'       => (string) ($sub['generic_name'] ?? ''),
            ];
        }
        return $this->success($out, 'Substitutes.');
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

        $branchId = (int) $data['branch_id'];
        $payload = $request->all();
        $payment = trim((string) ($payload['payment_method'] ?? ''));
        if ($payment !== '' && stripos((string) ($payload['notes'] ?? ''), 'Payment:') === false) {
            $note = trim((string) ($payload['notes'] ?? ''));
            $payload['notes'] = trim($note . ($note !== '' ? ' · ' : '') . 'Payment: ' . $payment);
        }
        $slot = trim((string) ($payload['delivery_slot'] ?? ''));
        if ($slot !== '') {
            $note = trim((string) ($payload['notes'] ?? ''));
            $payload['notes'] = trim($note . ($note !== '' ? ' · ' : '') . 'Preferred delivery: ' . $slot);
        }
        $result = (new OrderService())->place($branchId, $payload);

        $branch = (new Branch())->find($branchId);
        if ($branch) {
            $note = trim((string) ($payload['notes'] ?? ''));
            $payload['notes'] = trim($note . ($note !== '' ? ' · ' : '') . (string) ($result['order_no'] ?? ''));
            try {
                (new AuraGharTakService())->placeFromStore((int) $branch['client_id'], $branchId, $payload);
            } catch (\Throwable $e) {
                error_log('AuraGharTak store order copy failed: ' . $e->getMessage());
            }
        }

        return $this->created($result, 'Order placed. Save your tracking number!');
    }

    /**
     * Public order lookup by tracking token, order number, or phone (last 10 digits).
     * Phone with multiple matches returns a list; otherwise returns full status detail.
     *
     * Privacy: private tracking token → full detail. Order number → full detail only for the
     * logged-in app customer whose phone matches; otherwise status only. Phone search → only
     * for the logged-in app customer with that phone.
     */
    public function track(Request $request): Response
    {
        $token = trim((string) $request->query('token', ''));
        $orderNo = trim((string) $request->query('order_no', ''));
        $phone = trim((string) $request->query('phone', ''));
        $viewerPhone = (new AuraGharTakUserService())->phoneForToken($request->bearerToken());

        if ($token === '' && $orderNo === '' && $phone === '') {
            throw HttpException::validation([
                'query' => ['Enter your phone number, order number, or tracking number.'],
            ]);
        }

        // Token / order number → single detail (store + AuraGharTak)
        if ($token !== '' || $orderNo !== '') {
            $lookup = $token !== '' ? $token : $orderNo;
            $stmt = Connection::ops()->prepare(
                'SELECT id, order_no, tracking_token, customer_name, phone, address, status, total_estimate, created_at
                 FROM online_orders
                 WHERE tracking_token = :q OR order_no = :q2
                 LIMIT 1'
            );
            $stmt->execute(['q' => $lookup, 'q2' => $lookup]);
            $order = $stmt->fetch();
            if ($order) {
                $detail = $this->orderDetail($order);
                $detail['order_kind'] = 'store';
                $viaSecret = hash_equals((string) ($order['tracking_token'] ?? ''), $lookup);
                if (!$viaSecret && !self::samePhone($viewerPhone, (string) ($order['phone'] ?? ''))) {
                    $detail = self::limitedDetail($detail);
                }
                return $this->success($detail, 'Order status.');
            }
            $agtSvc = new AuraGharTakService();
            $agt = $agtSvc->trackPublicByLookup($lookup);
            if ($agt !== null) {
                if (!$agtSvc->isShareToken($lookup) && !self::samePhone($viewerPhone, (string) ($agt['phone'] ?? ''))) {
                    $agt = self::limitedDetail($agt);
                }
                return $this->success($agt, 'Order status.');
            }
            throw HttpException::notFound('Order not found. Check your tracking or order number.');
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) < 10) {
            throw HttpException::validation([
                'phone' => ['Enter a valid 10-digit mobile number.'],
            ]);
        }
        $suffix = substr($digits, -10);
        if ($viewerPhone === null || $viewerPhone !== $suffix) {
            throw HttpException::unauthorized(
                'Log in to the AuraGharTak app with this phone number to see its orders, or enter your tracking number.'
            );
        }

        $stmt = Connection::ops()->prepare(
            "SELECT id, order_no, customer_name, phone, address, status, total_estimate, created_at
             FROM online_orders
             WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '(', ''), ')', '')
                   LIKE :suffix
             ORDER BY id DESC
             LIMIT 20"
        );
        $stmt->execute(['suffix' => '%' . $suffix]);
        $rows = $stmt->fetchAll();

        // Extra filter: last 10 digits must match (avoids partial mid-string hits)
        $matched = [];
        foreach ($rows as $row) {
            $p = preg_replace('/\D+/', '', (string) ($row['phone'] ?? '')) ?? '';
            if ($p !== '' && substr($p, -10) === $suffix) {
                $matched[] = $row;
            }
        }

        $agtSvc = new AuraGharTakService();
        $agtList = $agtSvc->trackPublicByPhone($suffix);

        if ($matched === [] && $agtList === []) {
            throw HttpException::notFound('No orders found for this phone number.');
        }

        if (count($matched) === 1 && $agtList === []) {
            $detail = $this->orderDetail($matched[0]);
            $detail['order_kind'] = 'store';
            return $this->success($detail, 'Order status.');
        }

        $list = array_map(static function (array $r): array {
            return [
                'order_no'       => $r['order_no'],
                'customer_name'  => $r['customer_name'],
                'status'         => $r['status'],
                'total_estimate' => (float) $r['total_estimate'],
                'created_at'     => $r['created_at'],
                'order_kind'     => 'store',
            ];
        }, $matched);

        foreach ($agtList as $row) {
            $exists = false;
            foreach ($list as $existing) {
                if (($existing['order_no'] ?? '') === ($row['order_no'] ?? '')) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $list[] = $row;
            }
        }

        usort($list, static function (array $a, array $b): int {
            return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
        });

        $list = self::dedupeStoreAgtPhoneOrders($list);

        if (count($list) === 1) {
            $only = $list[0];
            if (($only['order_kind'] ?? '') === 'agt') {
                $detail = $agtSvc->trackPublicByLookup((string) $only['order_no']);
                if ($detail !== null) {
                    return $this->success($detail, 'Order status.');
                }
            }
            $stmt = Connection::ops()->prepare(
                'SELECT id, order_no, customer_name, phone, address, status, total_estimate, created_at
                 FROM online_orders WHERE order_no = :ono LIMIT 1'
            );
            $stmt->execute(['ono' => (string) $only['order_no']]);
            $order = $stmt->fetch();
            if ($order) {
                $detail = $this->orderDetail($order);
                $detail['order_kind'] = 'store';
                return $this->success($detail, 'Order status.');
            }
        }

        return $this->success([
            'mode'   => 'list',
            'phone'  => $suffix,
            'orders' => $list,
        ], 'Orders for this phone.');
    }

    private static function samePhone(?string $viewerLast10, string $orderPhone): bool
    {
        if ($viewerLast10 === null || $viewerLast10 === '') {
            return false;
        }
        $digits = preg_replace('/\D+/', '', $orderPhone) ?? '';
        return strlen($digits) >= 10 && substr($digits, -10) === $viewerLast10;
    }

    /**
     * Status-only view for someone who knows the order number but is not the customer.
     *
     * @param array<string,mixed> $detail
     * @return array<string,mixed>
     */
    private static function limitedDetail(array $detail): array
    {
        foreach (['customer_name', 'phone', 'address', 'patient_name', 'patient_contact', 'patient_address'] as $key) {
            if (array_key_exists($key, $detail)) {
                $detail[$key] = '';
            }
        }
        $detail['items'] = [];
        $detail['limited'] = true;
        return $detail;
    }

    /** Customer cancel — restores reserved stock. */
    public function cancelOrder(Request $request): Response
    {
        $data = $request->all();
        $result = (new OrderService())->cancelPublic(
            isset($data['token']) ? (string) $data['token'] : null,
            isset($data['order_no']) ? (string) $data['order_no'] : null,
            isset($data['phone']) ? (string) $data['phone'] : null
        );
        return $this->success($result, 'Order cancelled. Stock has been returned.');
    }

    /** @param array<string, mixed> $order */
    private function orderDetail(array $order): array
    {
        $events = Connection::ops()->prepare(
            'SELECT status, note, created_at FROM online_order_events WHERE order_id = :id ORDER BY id ASC'
        );
        $events->execute(['id' => $order['id']]);

        $itemStmt = Connection::ops()->prepare(
            'SELECT product_binding_id, product_name, quantity, rate, line_total
             FROM online_order_items WHERE order_id = :id ORDER BY id ASC'
        );
        $itemStmt->execute(['id' => $order['id']]);
        $items = [];
        while ($row = $itemStmt->fetch(PDO::FETCH_ASSOC)) {
            $items[] = [
                'binding_id' => (int) ($row['product_binding_id'] ?? 0),
                'name' => (string) ($row['product_name'] ?? ''),
                'product_name' => (string) ($row['product_name'] ?? ''),
                'qty' => (int) ($row['quantity'] ?? 0),
                'rate' => (float) ($row['rate'] ?? 0),
            ];
        }

        $status = (string) ($order['status'] ?? 'placed');
        $agtStatus = $this->agtMirrorStatusForStoreOrder($order);
        if ($agtStatus !== null && $agtStatus !== '') {
            $status = self::pickBetterPublicStatus($status, $agtStatus);
        }

        $cancellable = !in_array(strtolower($status), ['delivered', 'cancelled', 'out for delivery', 'out_for_delivery'], true);

        return [
            'mode'           => 'detail',
            'order_kind'     => 'store',
            'order_no'       => $order['order_no'],
            'customer_name'  => $order['customer_name'],
            'phone'          => $order['phone'] ?? null,
            'address'        => (string) ($order['address'] ?? ''),
            'status'         => $status,
            'total_estimate' => (float) $order['total_estimate'],
            'created_at'     => $order['created_at'],
            'cancellable'    => $cancellable,
            'events'         => $events->fetchAll(),
            'items'          => $items,
        ];
    }

    /**
     * Store checkout also creates a mirror AGT order — hide duplicate AGT row in phone search.
     *
     * @param list<array<string,mixed>> $list
     * @return list<array<string,mixed>>
     */
    private static function dedupeStoreAgtPhoneOrders(array $list): array
    {
        foreach ($list as &$row) {
            if (($row['order_kind'] ?? 'store') === 'agt') {
                continue;
            }
            $linked = self::linkedAgtForStoreOrder((string) ($row['order_no'] ?? ''), (string) ($row['created_at'] ?? ''));
            if ($linked !== null) {
                $row['linked_agt_no'] = $linked['order_no'];
                $row['status'] = self::pickBetterPublicStatus((string) ($row['status'] ?? ''), $linked['status']);
            }
            foreach ($list as $agt) {
                if (($agt['order_kind'] ?? '') !== 'agt') {
                    continue;
                }
                if (self::isStoreAgtMirrorRow($row, $agt)) {
                    $row['status'] = self::pickBetterPublicStatus(
                        (string) ($row['status'] ?? ''),
                        (string) ($agt['status'] ?? '')
                    );
                }
            }
        }
        unset($row);

        $stores = [];
        foreach ($list as $row) {
            if (($row['order_kind'] ?? 'store') !== 'agt') {
                $stores[] = $row;
            }
        }
        if ($stores === []) {
            return $list;
        }
        $out = [];
        foreach ($list as $row) {
            if (($row['order_kind'] ?? '') !== 'agt') {
                $out[] = $row;
                continue;
            }
            $duplicate = false;
            foreach ($stores as $store) {
                if (self::isStoreAgtMirrorRow($store, $row)) {
                    $duplicate = true;
                    break;
                }
            }
            if (!$duplicate) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * AGT mirror whose note carries this store order number (written by placeFromStore).
     *
     * @return array{order_no:string,status:string}|null
     */
    private static function linkedAgtForStoreOrder(string $storeOrderNo, string $storeCreatedAt = ''): ?array
    {
        $storeOrderNo = trim($storeOrderNo);
        if ($storeOrderNo === '') {
            return null;
        }
        $created = strtotime($storeCreatedAt) ?: 0;
        if ($created > 0) {
            // Mirror is written during checkout — a window keeps the note scan on the entry_date index.
            $stmt = Connection::ops()->prepare(
                'SELECT order_no, status FROM agt_orders
                 WHERE entry_date BETWEEN :from AND :to AND patient_note LIKE :n
                 ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([
                'from' => date('Y-m-d H:i:s', $created - 7200),
                'to'   => date('Y-m-d H:i:s', $created + 7200),
                'n'    => '%' . $storeOrderNo . '%',
            ]);
        } else {
            $stmt = Connection::ops()->prepare(
                'SELECT order_no, status FROM agt_orders WHERE patient_note LIKE :n ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute(['n' => '%' . $storeOrderNo . '%']);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return ['order_no' => (string) $row['order_no'], 'status' => (string) $row['status']];
    }

    /** @param array<string,mixed> $store @param array<string,mixed> $agt */
    private static function isStoreAgtMirrorRow(array $store, array $agt): bool
    {
        $linkedNo = (string) ($store['linked_agt_no'] ?? '');
        if ($linkedNo !== '') {
            return $linkedNo === (string) ($agt['order_no'] ?? '');
        }
        $t1 = strtotime((string) ($store['created_at'] ?? '')) ?: 0;
        $t2 = strtotime((string) ($agt['created_at'] ?? '')) ?: 0;
        $totalClose = abs((float) ($store['total_estimate'] ?? 0) - (float) ($agt['total_estimate'] ?? 0)) < 1.01;
        return $totalClose && $t1 > 0 && $t2 > 0 && abs($t1 - $t2) <= 1200;
    }

    private static function publicStatusRank(string $status): int
    {
        $s = strtolower(str_replace(['-', '_'], ' ', trim($status)));
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return match ($s) {
            'placed', 'sent' => 1,
            'confirmed', 'ready', 'packed' => 2,
            'out for delivery' => 3,
            'delivered' => 4,
            'cancelled', 'canceled' => 5,
            default => 0,
        };
    }

    private static function pickBetterPublicStatus(string $current, string $candidate): string
    {
        $cur = trim($current);
        $cand = trim($candidate);
        if ($cand === '') {
            return $cur;
        }
        if ($cur === '') {
            return $cand;
        }
        return self::publicStatusRank($cand) > self::publicStatusRank($cur) ? $cand : $cur;
    }

    /**
     * Store checkout mirrors into agt_orders — staff update AGT status only.
     *
     * @param array<string,mixed> $order online_orders row
     */
    private function agtMirrorStatusForStoreOrder(array $order): ?string
    {
        $linked = self::linkedAgtForStoreOrder((string) ($order['order_no'] ?? ''), (string) ($order['created_at'] ?? ''));
        if ($linked !== null) {
            return $linked['status'];
        }
        $digits = preg_replace('/\D+/', '', (string) ($order['phone'] ?? '')) ?? '';
        if (strlen($digits) < 10) {
            return null;
        }
        $suffix = substr($digits, -10);
        $agtList = (new AuraGharTakService())->trackPublicByPhone($suffix);
        $storeRow = [
            'created_at' => (string) ($order['created_at'] ?? ''),
            'total_estimate' => (float) ($order['total_estimate'] ?? 0),
        ];
        foreach ($agtList as $agt) {
            if (self::isStoreAgtMirrorRow($storeRow + ['order_kind' => 'store'], $agt)) {
                return (string) ($agt['status'] ?? '');
            }
        }
        return null;
    }

    /** GPS coordinates for AuraGharTak pharmacy list (Advanced + Retail). */
    public function storeLocations(Request $request): Response
    {
        $rows = (new AgtStoreLocationService())->listForPublic();
        return $this->success(['locations' => $rows], 'Store locations.');
    }

    /** Register Retail client_no rows (no coords yet) when app discovers new shops. */
    public function syncStoreLocationStubs(Request $request): Response
    {
        $raw = $request->input('stores');
        if (!is_array($raw)) {
            throw HttpException::validation(['stores' => ['Send stores as a list.']]);
        }
        $stores = array_slice($raw, 0, 120);
        $added = (new AgtStoreLocationService())->syncRetailStubs($stores);
        return $this->success(['added' => $added], 'Store location stubs synced.');
    }
}
