<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Database\Connection;
use PDO;

/**
 * AuraGharTak live delivery: rider assignment, rider GPS pings, customer live map.
 * Riders are HO users (role delivery) — location is shared only while tracking_active = 1.
 */
final class AgtDeliveryService
{
    public const RIDER_ROLE = 'delivery';
    private const TRAIL_POINTS = 60;
    private const LOCATION_RETENTION_DAYS = 7;

    /** @return list<array{id:int,name:string,phone:string,branch_id:int}> */
    public function listRiders(int $clientId, int $branchId): array
    {
        $stmt = Connection::ho()->prepare(
            "SELECT id, name, phone, branch_id FROM users
             WHERE client_id = :c AND status = 'active' AND role = :r
             ORDER BY (branch_id = :b) DESC, name ASC"
        );
        $stmt->execute(['c' => $clientId, 'r' => self::RIDER_ROLE, 'b' => $branchId]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = [
                'id' => (int) $row['id'],
                'name' => (string) ($row['name'] ?? ''),
                'phone' => (string) ($row['phone'] ?? ''),
                'branch_id' => (int) ($row['branch_id'] ?? 0),
            ];
        }
        return $out;
    }

    /** Staff assigns (or clears with riderUserId = 0) the delivery person on an AGT order. */
    public function assignRider(int $clientId, int $branchId, int $orderId, int $riderUserId): array
    {
        $order = $this->findOrder($clientId, $orderId, $branchId);
        if (in_array((string) $order['status'], ['Delivered', 'Cancelled'], true)) {
            throw HttpException::validation(['order_id' => ['This order is already closed.']]);
        }

        if ($riderUserId <= 0) {
            Connection::ops()->prepare(
                'UPDATE agt_orders SET rider_user_id = NULL, rider_name = NULL, rider_phone = NULL, tracking_active = 0
                 WHERE client_id = :c AND id = :id'
            )->execute(['c' => $clientId, 'id' => $orderId]);
            return ['order_id' => $orderId, 'rider' => null, 'message' => 'Rider removed.'];
        }

        $stmt = Connection::ho()->prepare(
            "SELECT id, name, phone FROM users
             WHERE id = :id AND client_id = :c AND status = 'active' AND role = :r LIMIT 1"
        );
        $stmt->execute(['id' => $riderUserId, 'c' => $clientId, 'r' => self::RIDER_ROLE]);
        $rider = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$rider) {
            throw HttpException::validation(['rider_user_id' => ['Select an active delivery staff member.']]);
        }

        $active = (string) $order['status'] === 'Out for Delivery' ? 1 : 0;
        Connection::ops()->prepare(
            'UPDATE agt_orders SET rider_user_id = :r, rider_name = :n, rider_phone = :p, tracking_active = :a
             WHERE client_id = :c AND id = :id'
        )->execute([
            'r' => (int) $rider['id'],
            'n' => (string) $rider['name'],
            'p' => (string) ($rider['phone'] ?? ''),
            'a' => $active,
            'c' => $clientId,
            'id' => $orderId,
        ]);

        return [
            'order_id' => $orderId,
            'rider' => ['id' => (int) $rider['id'], 'name' => (string) $rider['name'], 'phone' => (string) ($rider['phone'] ?? '')],
            'message' => 'Assigned to ' . $rider['name'] . '.',
        ];
    }

    /** Keep tracking flag in sync when staff change status from the web. */
    public function onStatusChanged(int $clientId, int $orderId, string $newStatus): void
    {
        if ($newStatus === 'Out for Delivery') {
            Connection::ops()->prepare(
                'UPDATE agt_orders SET tracking_active = 1
                 WHERE client_id = :c AND id = :id AND rider_user_id IS NOT NULL'
            )->execute(['c' => $clientId, 'id' => $orderId]);
            return;
        }
        if (in_array($newStatus, ['Delivered', 'Cancelled'], true)) {
            Connection::ops()->prepare(
                'UPDATE agt_orders SET tracking_active = 0 WHERE client_id = :c AND id = :id'
            )->execute(['c' => $clientId, 'id' => $orderId]);
        }
    }

    /** @return list<array<string,mixed>> */
    public function riderOrders(int $clientId, int $riderUserId): array
    {
        $stmt = Connection::ops()->prepare(
            "SELECT id, branch_id, order_no, patient_name, patient_contact, patient_address, patient_note,
                    status, item_count, estimated_total, sale_net, sale_invoice_no, entry_date,
                    drop_latitude, drop_longitude, tracking_active
             FROM agt_orders
             WHERE client_id = :c AND rider_user_id = :r
               AND (status IN ('Ready', 'Out for Delivery')
                    OR (status = 'Delivered' AND delivered_at >= :since))
             ORDER BY FIELD(status, 'Out for Delivery', 'Ready', 'Delivered'), id DESC
             LIMIT 50"
        );
        $stmt->execute(['c' => $clientId, 'r' => $riderUserId, 'since' => date('Y-m-d 00:00:00')]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $locSvc = new AgtStoreLocationService();
        $storeCache = [];
        $out = [];
        foreach ($rows as $r) {
            $bid = (int) $r['branch_id'];
            if (!array_key_exists($bid, $storeCache)) {
                $storeCache[$bid] = $locSvc->getAdvancedBranchLocation($bid);
            }
            $out[] = [
                'order_id' => (int) $r['id'],
                'order_no' => (string) $r['order_no'],
                'status' => (string) $r['status'],
                'patient_name' => (string) $r['patient_name'],
                'patient_contact' => (string) $r['patient_contact'],
                'patient_address' => (string) ($r['patient_address'] ?? ''),
                'patient_note' => (string) ($r['patient_note'] ?? ''),
                'item_count' => (int) $r['item_count'],
                'amount' => (float) ($r['sale_net'] ?? 0) > 0 ? (float) $r['sale_net'] : (float) $r['estimated_total'],
                'invoice_no' => (string) ($r['sale_invoice_no'] ?? ''),
                'entry_date' => (string) $r['entry_date'],
                'drop' => $this->point($r['drop_latitude'] ?? null, $r['drop_longitude'] ?? null),
                'store' => $this->point($storeCache[$bid]['latitude'] ?? null, $storeCache[$bid]['longitude'] ?? null),
                'tracking_active' => (int) $r['tracking_active'] === 1,
            ];
        }
        return $out;
    }

    public function riderStart(int $clientId, int $riderUserId, int $orderId): array
    {
        $order = $this->findRiderOrder($clientId, $riderUserId, $orderId);
        if ((string) $order['status'] !== 'Out for Delivery') {
            if ((string) $order['status'] !== 'Ready') {
                throw HttpException::validation(['status' => ['The pharmacy must bill this order (Ready) before delivery starts.']]);
            }
            (new AuraGharTakService())->updateStatus($clientId, (int) $order['branch_id'], $orderId, 'Out for Delivery');
        }
        Connection::ops()->prepare(
            'UPDATE agt_orders SET tracking_active = 1 WHERE client_id = :c AND id = :id'
        )->execute(['c' => $clientId, 'id' => $orderId]);
        return ['order_id' => $orderId, 'status' => 'Out for Delivery', 'message' => 'Delivery started. Live location is on.'];
    }

    public function riderDelivered(int $clientId, int $riderUserId, int $orderId): array
    {
        $order = $this->findRiderOrder($clientId, $riderUserId, $orderId);
        if ((string) $order['status'] !== 'Delivered') {
            (new AuraGharTakService())->updateStatus($clientId, (int) $order['branch_id'], $orderId, 'Delivered');
        }
        Connection::ops()->prepare(
            'UPDATE agt_orders SET tracking_active = 0 WHERE client_id = :c AND id = :id'
        )->execute(['c' => $clientId, 'id' => $orderId]);
        return ['order_id' => $orderId, 'status' => 'Delivered', 'message' => 'Marked delivered.'];
    }

    /**
     * One GPS ping from the rider phone — stored against every order they are actively delivering.
     *
     * @param array<string,mixed> $data
     */
    public function riderPing(int $clientId, int $riderUserId, array $data): array
    {
        $lat = (float) ($data['latitude'] ?? 0);
        $lng = (float) ($data['longitude'] ?? 0);
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat == 0.0 && $lng == 0.0)) {
            throw HttpException::validation(['latitude' => ['Invalid location.']]);
        }

        $db = Connection::ops();
        $stmt = $db->prepare(
            "SELECT id FROM agt_orders
             WHERE client_id = :c AND rider_user_id = :r AND tracking_active = 1 AND status = 'Out for Delivery'"
        );
        $stmt->execute(['c' => $clientId, 'r' => $riderUserId]);
        $orderIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if ($orderIds === []) {
            return ['tracked_orders' => 0, 'keep_tracking' => false];
        }

        $ins = $db->prepare(
            'INSERT INTO agt_rider_locations
                (client_id, order_id, rider_user_id, latitude, longitude, accuracy_m, speed_mps, heading, recorded_at)
             VALUES (:c, :o, :r, :lat, :lng, :acc, :spd, :hd, :at)'
        );
        $now = now();
        foreach ($orderIds as $oid) {
            $ins->execute([
                'c' => $clientId,
                'o' => $oid,
                'r' => $riderUserId,
                'lat' => round($lat, 7),
                'lng' => round($lng, 7),
                'acc' => $this->optionalFloat($data['accuracy'] ?? null),
                'spd' => $this->optionalFloat($data['speed'] ?? null),
                'hd' => $this->optionalFloat($data['heading'] ?? null),
                'at' => $now,
            ]);
        }

        if (random_int(1, 200) === 1) {
            $db->prepare('DELETE FROM agt_rider_locations WHERE recorded_at < :cut')
                ->execute(['cut' => date('Y-m-d H:i:s', time() - self::LOCATION_RETENTION_DAYS * 86400)]);
        }

        return ['tracked_orders' => count($orderIds), 'keep_tracking' => true];
    }

    /**
     * Customer live map payload by AGT order no / share token, or a store order no whose AGT mirror is tracked.
     *
     * @return array<string,mixed>
     */
    public function publicLive(string $lookup, ?string $viewerPhone = null): array
    {
        $lookup = trim($lookup);
        if ($lookup === '') {
            throw HttpException::validation(['order_no' => ['Order number required.']]);
        }
        $order = $this->findAgtForPublic($lookup);
        if ($order === null) {
            throw HttpException::notFound('Order not found.');
        }
        $this->assertLiveViewer($order, $viewerPhone);

        $store = (new AgtStoreLocationService())->getAdvancedBranchLocation((int) $order['branch_id']);
        $active = (int) $order['tracking_active'] === 1 && (string) $order['status'] === 'Out for Delivery';

        $rider = null;
        $trail = [];
        if ($active) {
            $stmt = Connection::ops()->prepare(
                'SELECT latitude, longitude, heading, speed_mps, recorded_at FROM agt_rider_locations
                 WHERE order_id = :o ORDER BY id DESC LIMIT ' . self::TRAIL_POINTS
            );
            $stmt->execute(['o' => (int) $order['id']]);
            $rows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
            foreach ($rows as $row) {
                $trail[] = [(float) $row['latitude'], (float) $row['longitude']];
            }
            $last = $rows === [] ? null : $rows[count($rows) - 1];
            if ($last !== null) {
                $rider = [
                    'latitude' => (float) $last['latitude'],
                    'longitude' => (float) $last['longitude'],
                    'heading' => $last['heading'] !== null ? (float) $last['heading'] : null,
                    'speed_mps' => $last['speed_mps'] !== null ? (float) $last['speed_mps'] : null,
                    'recorded_at' => (string) $last['recorded_at'],
                    'age_seconds' => max(0, time() - (int) strtotime((string) $last['recorded_at'])),
                ];
            }
        }

        return [
            'order_no' => (string) $order['order_no'],
            'status' => (string) $order['status'],
            'tracking_active' => $active,
            'rider_name' => $active || (string) $order['status'] === 'Ready' ? (string) ($order['rider_name'] ?? '') : '',
            'rider_phone' => $active ? (string) ($order['rider_phone'] ?? '') : '',
            'rider' => $rider,
            'trail' => $trail,
            'store' => $this->point($store['latitude'] ?? null, $store['longitude'] ?? null),
            'drop' => $this->point($order['drop_latitude'] ?? null, $order['drop_longitude'] ?? null),
        ];
    }

    /** Save customer drop pin captured by the app at checkout (best effort). */
    public function saveDropLocation(int $clientId, int $orderId, mixed $lat, mixed $lng): void
    {
        $point = $this->point($lat, $lng);
        if ($point === null || $orderId <= 0) {
            return;
        }
        try {
            Connection::ops()->prepare(
                'UPDATE agt_orders SET drop_latitude = :lat, drop_longitude = :lng WHERE client_id = :c AND id = :id'
            )->execute(['lat' => $point['latitude'], 'lng' => $point['longitude'], 'c' => $clientId, 'id' => $orderId]);
        } catch (\Throwable $e) {
            error_log('AGT drop location save failed: ' . $e->getMessage());
        }
    }

    /** @return array<string,mixed> */
    private function findOrder(int $clientId, int $orderId, int $branchId): array
    {
        $stmt = Connection::ops()->prepare(
            'SELECT * FROM agt_orders WHERE client_id = :c AND branch_id = :b AND id = :id LIMIT 1'
        );
        $stmt->execute(['c' => $clientId, 'b' => $branchId, 'id' => $orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw HttpException::notFound('Order not found.');
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function findRiderOrder(int $clientId, int $riderUserId, int $orderId): array
    {
        $stmt = Connection::ops()->prepare(
            'SELECT * FROM agt_orders WHERE client_id = :c AND id = :id AND rider_user_id = :r LIMIT 1'
        );
        $stmt->execute(['c' => $clientId, 'id' => $orderId, 'r' => $riderUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw HttpException::notFound('This order is not assigned to you.');
        }
        if ((string) $row['status'] === 'Cancelled') {
            throw HttpException::validation(['status' => ['This order was cancelled.']]);
        }
        return $row;
    }

    /**
     * Only the order's customer may watch the rider: private share/tracking token, or the
     * logged-in app customer whose phone matches the order.
     *
     * @param array<string,mixed> $order
     */
    private function assertLiveViewer(array $order, ?string $viewerPhone): void
    {
        if (!empty($order['_via_secret'])) {
            return;
        }
        $digits = preg_replace('/\D+/', '', (string) ($order['patient_contact'] ?? '')) ?? '';
        if ($viewerPhone !== null && strlen($digits) >= 10 && substr($digits, -10) === $viewerPhone) {
            return;
        }
        throw HttpException::forbidden(
            'Log in to the AuraGharTak app with the phone number used for this order to see live tracking.'
        );
    }

    /**
     * Row gets `_via_secret` = true when matched by a private token rather than the order number.
     *
     * @return array<string,mixed>|null
     */
    private function findAgtForPublic(string $lookup): ?array
    {
        $db = Connection::ops();
        $stmt = $db->prepare(
            'SELECT * FROM agt_orders WHERE order_no = :q OR share_token = :q2 ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['q' => $lookup, 'q2' => $lookup]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['_via_secret'] = (string) ($row['share_token'] ?? '') !== ''
                && hash_equals((string) $row['share_token'], $lookup);
            return $row;
        }

        // Store checkout order → its AGT mirror (same phone, total within ₹1, placed within 20 min).
        $s = $db->prepare(
            'SELECT order_no, tracking_token, phone, total_estimate, created_at FROM online_orders
             WHERE order_no = :q OR tracking_token = :q2 LIMIT 1'
        );
        $s->execute(['q' => $lookup, 'q2' => $lookup]);
        $store = $s->fetch(PDO::FETCH_ASSOC);
        if (!$store) {
            return null;
        }
        $viaSecret = hash_equals((string) ($store['tracking_token'] ?? ''), $lookup);
        $created = strtotime((string) ($store['created_at'] ?? '')) ?: time();
        $from = date('Y-m-d H:i:s', $created - 7200);
        $to = date('Y-m-d H:i:s', $created + 7200);

        // The mirror's note carries the store order number (placeFromStore) — exact link, survives price edits.
        $storeNo = (string) ($store['order_no'] ?? '');
        if ($storeNo !== '') {
            $byNote = $db->prepare(
                'SELECT * FROM agt_orders
                 WHERE entry_date BETWEEN :from AND :to AND patient_note LIKE :n
                 ORDER BY id DESC LIMIT 1'
            );
            $byNote->execute(['from' => $from, 'to' => $to, 'n' => '%' . $storeNo . '%']);
            $linked = $byNote->fetch(PDO::FETCH_ASSOC);
            if ($linked) {
                $linked['_via_secret'] = $viaSecret;
                return $linked;
            }
        }
        $digits = preg_replace('/\D+/', '', (string) ($store['phone'] ?? '')) ?? '';
        if (strlen($digits) < 10) {
            return null;
        }
        $m = $db->prepare(
            "SELECT * FROM agt_orders
             WHERE entry_date BETWEEN :from AND :to
               AND REPLACE(REPLACE(REPLACE(patient_contact, ' ', ''), '-', ''), '+', '') LIKE :suf
               AND ABS(estimated_total - :tot) < 1.01
               AND ABS(TIMESTAMPDIFF(SECOND, entry_date, :at)) <= 1200
             ORDER BY id DESC LIMIT 1"
        );
        $m->execute([
            'from' => $from,
            'to' => $to,
            'suf' => '%' . substr($digits, -10),
            'tot' => (float) $store['total_estimate'],
            'at' => (string) $store['created_at'],
        ]);
        $mirror = $m->fetch(PDO::FETCH_ASSOC);
        if (!$mirror) {
            return null;
        }
        $mirror['_via_secret'] = $viaSecret;
        return $mirror;
    }

    /** @return array{latitude:float,longitude:float}|null */
    private function point(mixed $lat, mixed $lng): ?array
    {
        if ($lat === null || $lng === null || $lat === '' || $lng === '') {
            return null;
        }
        $la = (float) $lat;
        $lo = (float) $lng;
        if ($la < -90 || $la > 90 || $lo < -180 || $lo > 180 || ($la == 0.0 && $lo == 0.0)) {
            return null;
        }
        return ['latitude' => $la, 'longitude' => $lo];
    }

    private function optionalFloat(mixed $v): ?float
    {
        if ($v === null || $v === '' || !is_numeric($v)) {
            return null;
        }
        return round((float) $v, 2);
    }
}
