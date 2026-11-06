<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Database\Connection;
use App\Support\Env;
use App\Support\PackSize;
use PDO;

final class AuraGharTakService
{
    private const STATUSES = ['Placed', 'Ready', 'Out for Delivery', 'Delivered', 'Cancelled'];

    /** @return list<array<string,mixed>> */
    public function searchCustomers(int $clientId, string $q): array
    {
        $q = trim($q);
        $sql = "SELECT id, name AS c_name, phone AS c_cont, address AS c_add, email AS c_mail
                FROM customers
                WHERE client_id = :c AND name <> '' AND name <> 'Cash Sale'";
        $bind = ['c' => $clientId];
        if ($q !== '') {
            $sql .= ' AND (name LIKE :q OR phone LIKE :q2 OR CAST(id AS CHAR) LIKE :q3)';
            $like = '%' . $q . '%';
            $bind['q'] = $like;
            $bind['q2'] = $like;
            $bind['q3'] = $like;
        }
        $sql .= ' ORDER BY name ASC LIMIT 200';
        $stmt = Connection::ops()->prepare($sql);
        $stmt->execute($bind);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['c_no'] = (string) ($r['id'] ?? '');
        }
        unset($r);
        return $rows;
    }

    /** @param array<string,mixed> $input */
    public function createShareLink(int $clientId, int $branchId, array $input, bool $sendWhatsApp): array
    {
        $name = trim((string) ($input['patient_name'] ?? ''));
        $contact = $this->digits((string) ($input['patient_contact'] ?? ''));
        $address = trim((string) ($input['patient_address'] ?? ''));
        $patientNo = trim((string) ($input['patient_no'] ?? ''));

        if (strlen($contact) < 10) {
            throw HttpException::validation(['patient_contact' => ['Valid WhatsApp / mobile number required.']]);
        }
        $contact = substr($contact, -10);
        if ($name === '') {
            throw HttpException::validation(['patient_name' => ['Patient name required.']]);
        }

        $token = bin2hex(random_bytes(16));
        $now = now();
        $expires = date('Y-m-d H:i:s', strtotime('+30 days'));

        $db = Connection::ops();
        $ins = $db->prepare(
            'INSERT INTO agt_share_links
                (client_id, branch_id, token, patient_no, patient_name, patient_contact, patient_address, status, created_at, expires_at)
             VALUES (:c, :b, :t, :pno, :pn, :pc, :pa, \'Active\', :now, :exp)'
        );
        $ins->execute([
            'c' => $clientId, 'b' => $branchId, 't' => $token,
            'pno' => $patientNo !== '' ? $patientNo : null,
            'pn' => $name, 'pc' => $contact, 'pa' => $address !== '' ? $address : null,
            'now' => $now, 'exp' => $expires,
        ]);

        $shopUrl = $this->shopUrl($token);
        $profile = (new ClientProfileService())->getProfile($clientId, $branchId);
        $wa = ['ok' => false, 'skipped' => true];

        if ($sendWhatsApp) {
            $wa = (new AuraGharTakWhatsAppService())->sendStockCatalogueLink(
                $contact,
                $name,
                (string) ($profile['business_name'] ?? 'Pharmacy'),
                $shopUrl,
                (string) ($profile['phone'] ?? '')
            );
        }

        $waOk = !empty($wa['ok']);
        if ($waOk) {
            $message = 'Stock link sent on WhatsApp';
        } elseif ($sendWhatsApp) {
            $message = 'Link created but WhatsApp was not sent';
            if (!empty($wa['error'])) {
                $message .= ': ' . $wa['error'];
            }
        } else {
            $message = 'Stock link created. Share it with the patient.';
        }

        return [
            'token' => $token,
            'shop_url' => $shopUrl,
            'patient_contact' => $contact,
            'whatsapp' => $wa,
            'whatsapp_sent' => $waOk,
            'message' => $message,
        ];
    }

    /**
     * Customer app order for a pharmacy branch. Lands on the AuraGharTak Placed list
     * the pharmacy sees after login (same tables as a share-link order).
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function placeFromStore(int $clientId, int $branchId, array $data): array
    {
        $address = trim((string) ($data['address'] ?? ''));
        $pin = trim((string) ($data['pincode'] ?? ''));
        if ($pin !== '') {
            $address = trim($address . ', ' . $pin, ', ');
        }

        $link = $this->createShareLink($clientId, $branchId, [
            'patient_name' => (string) ($data['customer_name'] ?? ''),
            'patient_contact' => (string) ($data['phone'] ?? ''),
            'patient_address' => $address,
        ], false);

        $items = [];
        foreach ($data['items'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $bindingId = (int) ($item['product_binding_id'] ?? 0);
            $batch = trim((string) ($item['batch_no'] ?? ''));
            if ($batch === '' && $bindingId > 0) {
                $batch = $this->firstBatchNo($clientId, $branchId, $bindingId);
            }
            $items[] = [
                'p_name' => (string) ($item['product_name'] ?? ''),
                'batch_no' => $batch,
                'qty' => $item['quantity'] ?? $item['qty'] ?? 1,
                'unit_mrp' => $item['rate'] ?? $item['mrp'] ?? 0,
                'mrp' => $item['rate'] ?? $item['mrp'] ?? 0,
            ];
        }

        $note = trim((string) ($data['notes'] ?? ''));
        $slot = trim((string) ($data['delivery_slot'] ?? ''));
        if ($slot !== '') {
            $note = trim($note . ($note !== '' ? ' · ' : '') . 'Preferred delivery: ' . $slot);
        }

        return $this->placePublicOrder($link['token'], [
            'patient_note' => $note,
            'drop_latitude' => $data['drop_latitude'] ?? null,
            'drop_longitude' => $data['drop_longitude'] ?? null,
            'items' => $items,
            'prescription_base64' => is_string($data['prescription_base64'] ?? null)
                ? (string) $data['prescription_base64'] : '',
        ]);
    }

    /** @return array<string,int> */
    public function orderCounts(int $clientId, int $branchId): array
    {
        $counts = ['Placed' => 0, 'Ready' => 0, 'Out for Delivery' => 0, 'Delivered' => 0, 'Cancelled' => 0];
        $stmt = Connection::ops()->prepare(
            'SELECT status, COUNT(*) AS cnt FROM agt_orders
             WHERE client_id = :c AND branch_id = :b GROUP BY status'
        );
        $stmt->execute(['c' => $clientId, 'b' => $branchId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $st = (string) ($row['status'] ?? '');
            if (isset($counts[$st])) {
                $counts[$st] = (int) $row['cnt'];
            }
        }
        return $counts;
    }

    /** @return list<array<string,mixed>> */
    public function listOrders(int $clientId, int $branchId, ?string $status, int $limit): array
    {
        $limit = max(1, min(100, $limit));
        $sql = 'SELECT * FROM agt_orders WHERE client_id = :c AND branch_id = :b';
        $bind = ['c' => $clientId, 'b' => $branchId];
        if ($status !== null && $status !== '' && $status !== 'All') {
            $sql .= ' AND status = :st';
            $bind['st'] = $status;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . $limit;
        $stmt = Connection::ops()->prepare($sql);
        $stmt->execute($bind);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($orders as &$o) {
            $o['prescription_url'] = $this->prescriptionPublicUrl((string) ($o['prescription_file'] ?? ''));
        }
        unset($o);
        return $orders;
    }

    /** @return array<string,mixed>|null */
    public function getOrder(int $clientId, int $branchId, int $orderId, string $orderNo = ''): ?array
    {
        $db = Connection::ops();
        if ($orderId > 0) {
            $stmt = $db->prepare(
                'SELECT * FROM agt_orders WHERE client_id = :c AND branch_id = :b AND id = :id LIMIT 1'
            );
            $stmt->execute(['c' => $clientId, 'b' => $branchId, 'id' => $orderId]);
        } else {
            $stmt = $db->prepare(
                'SELECT * FROM agt_orders WHERE client_id = :c AND branch_id = :b AND order_no = :ono LIMIT 1'
            );
            $stmt->execute(['c' => $clientId, 'b' => $branchId, 'ono' => $orderNo]);
        }
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            return null;
        }
        $items = $db->prepare('SELECT * FROM agt_order_items WHERE order_id = :id ORDER BY id ASC');
        $items->execute(['id' => (int) $order['id']]);
        $rows = $items->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$it) {
            $it['p_name'] = $it['product_name'];
            $it['p_cat'] = $it['category'];
            $it['exp_date'] = $it['expiry_date'];
            $this->enrichItemStock($clientId, $branchId, $it);
        }
        unset($it);
        $order['items'] = $rows;
        $order['prescription_url'] = $this->prescriptionPublicUrl((string) ($order['prescription_file'] ?? ''));
        return $order;
    }

    /** @return list<array<string,mixed>> */
    public function pendingOrdersByPatient(int $clientId, int $branchId, string $patientNo, string $contact): array
    {
        $contact = $this->digits($contact);
        if (strlen($contact) >= 10) {
            $contact = substr($contact, -10);
        }
        $match = [];
        $bind = ['c' => $clientId, 'b' => $branchId];
        if ($patientNo !== '') {
            $match[] = 'patient_no = :pno';
            $bind['pno'] = $patientNo;
        }
        if (strlen($contact) >= 10) {
            $match[] = 'patient_contact LIKE :pc';
            $bind['pc'] = '%' . $contact . '%';
        }
        if ($match === []) {
            return [];
        }
        $sql = 'SELECT id FROM agt_orders WHERE client_id = :c AND branch_id = :b AND status = \'Placed\' AND ('
            . implode(' OR ', $match) . ') ORDER BY id DESC LIMIT 20';
        $stmt = Connection::ops()->prepare($sql);
        $stmt->execute($bind);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $full = $this->getOrder($clientId, $branchId, (int) $row['id']);
            if ($full) {
                $out[] = $full;
            }
        }
        return $out;
    }

    public function markOrderReady(int $clientId, int $branchId, int $orderId, string $saleInvoiceNo, int $saleId, float $saleNet): void
    {
        $order = $this->getOrder($clientId, $branchId, $orderId);
        if (!$order) {
            throw HttpException::notFound('Order not found.');
        }
        $existingSaleId = (int) ($order['sale_id'] ?? 0);
        if ($order['status'] !== 'Placed') {
            if ($existingSaleId > 0 && $existingSaleId === $saleId) {
                return;
            }
            throw HttpException::validation([
                'status' => ['This order is already billed (status ' . ($order['status'] ?? '') . ').'],
            ]);
        }
        if ($existingSaleId > 0 && $existingSaleId !== $saleId) {
            throw HttpException::validation([
                'sale_id' => ['This order is already linked to another sale bill.'],
            ]);
        }
        $db = Connection::ops()->prepare(
            'UPDATE agt_orders SET status = \'Ready\', sale_invoice_no = :sno, sale_id = :sid, sale_net = :net, ready_at = :now
             WHERE client_id = :c AND branch_id = :b AND id = :id'
        );
        $db->execute([
            'sno' => $saleInvoiceNo, 'sid' => $saleId > 0 ? $saleId : null,
            'net' => round($saleNet, 2), 'now' => now(),
            'c' => $clientId, 'b' => $branchId, 'id' => $orderId,
        ]);
    }

    public function updateStatus(int $clientId, int $branchId, int $orderId, string $newStatus, bool $sendWhatsApp = true): array
    {
        if (!in_array($newStatus, ['Out for Delivery', 'Delivered', 'Cancelled'], true)) {
            throw HttpException::validation(['status' => ['Invalid status.']]);
        }
        $order = $this->getOrder($clientId, $branchId, $orderId);
        if (!$order) {
            throw HttpException::notFound('Order not found.');
        }

        $profile = (new ClientProfileService())->getProfile($clientId, $branchId);
        $waResult = null;
        $db = Connection::ops();

        if ($newStatus === 'Out for Delivery') {
            if ($order['status'] !== 'Ready' && empty($order['sale_invoice_no'])) {
                throw HttpException::validation(['status' => ['Generate sale bill first (status must be Ready).']]);
            }
            $saleId = (int) ($order['sale_id'] ?? 0);
            if ($saleId <= 0) {
                throw HttpException::validation(['sale_id' => ['No linked sale bill.']]);
            }
            if ($sendWhatsApp) {
                $waResult = (new AuraGharTakWhatsAppService())->sendDeliveryBill(
                    $saleId,
                    $clientId,
                    (string) ($order['order_no'] ?? ''),
                    (string) ($order['patient_contact'] ?? '')
                );
            }
            $upd = $db->prepare(
                'UPDATE agt_orders SET status = \'Out for Delivery\', out_for_delivery_at = :now
                 WHERE client_id = :c AND branch_id = :b AND id = :id'
            );
            $upd->execute(['now' => now(), 'c' => $clientId, 'b' => $branchId, 'id' => $orderId]);
        } elseif ($newStatus === 'Delivered') {
            if (!in_array($order['status'], ['Out for Delivery', 'Ready'], true)) {
                throw HttpException::validation(['status' => ['Mark Out for Delivery before Delivered.']]);
            }
            $note = 'Order delivered. Please pay using pharmacy QR on the bill / My Profile QR.';
            $waResult = (new AuraGharTakWhatsAppService())->sendDeliveredNotice(
                (string) $order['patient_contact'],
                (string) $order['patient_name'],
                (string) ($profile['business_name'] ?? 'Pharmacy'),
                $note
            );
            $upd = $db->prepare(
                'UPDATE agt_orders SET status = \'Delivered\', delivered_at = :now
                 WHERE client_id = :c AND branch_id = :b AND id = :id'
            );
            $upd->execute(['now' => now(), 'c' => $clientId, 'b' => $branchId, 'id' => $orderId]);
        } else {
            $upd = $db->prepare(
                'UPDATE agt_orders SET status = \'Cancelled\'
                 WHERE client_id = :c AND branch_id = :b AND id = :id'
            );
            $upd->execute(['c' => $clientId, 'b' => $branchId, 'id' => $orderId]);
        }

        try {
            (new AgtDeliveryService())->onStatusChanged($clientId, $orderId, $newStatus);
        } catch (\Throwable $e) {
            error_log('AGT tracking flag sync failed: ' . $e->getMessage());
        }

        return [
            'message' => 'Status updated to ' . $newStatus,
            'whatsapp' => $waResult,
        ];
    }

    public function ensureBranchSettingsSchema(): void
    {
        Connection::ops()->exec(
            'CREATE TABLE IF NOT EXISTS agt_branch_settings (
                client_id BIGINT UNSIGNED NOT NULL,
                branch_id BIGINT UNSIGNED NOT NULL,
                allow_customer_cancel TINYINT(1) NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (client_id, branch_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** @return array{allow_customer_cancel: bool} */
    public function getBranchSettings(int $clientId, int $branchId): array
    {
        $this->ensureBranchSettingsSchema();
        $stmt = Connection::ops()->prepare(
            'SELECT allow_customer_cancel FROM agt_branch_settings WHERE client_id = :c AND branch_id = :b LIMIT 1'
        );
        $stmt->execute(['c' => $clientId, 'b' => $branchId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            'allow_customer_cancel' => $row ? ((int) ($row['allow_customer_cancel'] ?? 0) === 1) : false,
        ];
    }

    /** @return array{allow_customer_cancel: bool} */
    public function saveBranchSettings(int $clientId, int $branchId, bool $allowCustomerCancel): array
    {
        $this->ensureBranchSettingsSchema();
        $db = Connection::ops();
        $db->prepare(
            'INSERT INTO agt_branch_settings (client_id, branch_id, allow_customer_cancel, updated_at)
             VALUES (:c, :b, :a, :now)
             ON DUPLICATE KEY UPDATE allow_customer_cancel = VALUES(allow_customer_cancel), updated_at = VALUES(updated_at)'
        )->execute([
            'c' => $clientId,
            'b' => $branchId,
            'a' => $allowCustomerCancel ? 1 : 0,
            'now' => now(),
        ]);
        return ['allow_customer_cancel' => $allowCustomerCancel];
    }

    /** Patient cancels own order via shop link — separate from staff cancel / sale flow. */
    public function publicCancelOrder(string $token, int $orderId): array
    {
        $link = $this->activeShareLink($token);
        $clientId = (int) $link['client_id'];
        $branchId = (int) $link['branch_id'];
        $settings = $this->getBranchSettings($clientId, $branchId);
        if (!$settings['allow_customer_cancel']) {
            throw HttpException::validation([
                'cancel' => ['This pharmacy does not allow online order cancellation. Please call the store.'],
            ]);
        }
        if ($orderId <= 0) {
            throw HttpException::validation(['order_id' => ['Order id is required.']]);
        }
        $order = $this->getOrder($clientId, $branchId, $orderId);
        if (!$order) {
            throw HttpException::notFound('Order not found.');
        }
        $linkContact = substr($this->digits((string) $link['patient_contact']), -10);
        $orderContact = substr($this->digits((string) ($order['patient_contact'] ?? '')), -10);
        if ($linkContact !== '' && $orderContact !== '' && $linkContact !== $orderContact) {
            throw HttpException::forbidden('This order does not belong to your link.');
        }
        if (($order['status'] ?? '') !== 'Placed') {
            throw HttpException::validation([
                'status' => ['Only new (Placed) orders can be cancelled online. Contact the pharmacy.'],
            ]);
        }
        if ((int) ($order['sale_id'] ?? 0) > 0) {
            throw HttpException::validation(['status' => ['This order is already being processed and cannot be cancelled online.']]);
        }
        Connection::ops()->prepare(
            'UPDATE agt_orders SET status = \'Cancelled\' WHERE client_id = :c AND branch_id = :b AND id = :id'
        )->execute(['c' => $clientId, 'b' => $branchId, 'id' => $orderId]);
        return [
            'order_id' => $orderId,
            'order_no' => (string) ($order['order_no'] ?? ''),
            'status' => 'Cancelled',
            'message' => 'Order cancelled.',
        ];
    }

    /** Public token shop bootstrap. */
    public function publicShop(string $token, string $q): array
    {
        $link = $this->activeShareLink($token);
        $clientId = (int) $link['client_id'];
        $branchId = (int) $link['branch_id'];
        $profile = (new ClientProfileService())->getProfile($clientId, $branchId);
        $settings = $this->getBranchSettings($clientId, $branchId);

        return [
            'pharmacy' => [
                'name' => $profile['business_name'] ?? 'Pharmacy',
                'contact' => $profile['phone'] ?? '',
                'address' => $profile['address'] ?? '',
            ],
            'patient' => [
                'name' => $link['patient_name'],
                'contact' => $link['patient_contact'],
                'address' => $link['patient_address'],
                'patient_no' => $link['patient_no'],
            ],
            'policy' => [
                'allow_customer_cancel' => $settings['allow_customer_cancel'],
                'cancel_hint' => $settings['allow_customer_cancel']
                    ? 'You may cancel a new order online before the pharmacy starts billing.'
                    : 'Orders cannot be cancelled online. Contact the pharmacy if you need help.',
            ],
            'token' => $token,
            'products' => $this->catalog($clientId, $branchId, $q, 300),
        ];
    }

    /** @param array<string,mixed> $input */
    public function placePublicOrder(string $token, array $input): array
    {
        $link = $this->activeShareLink($token);
        $clientId = (int) $link['client_id'];
        $branchId = (int) $link['branch_id'];
        $items = $input['items'] ?? [];
        if (is_string($items)) {
            $decoded = json_decode($items, true);
            $items = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($items) || $items === []) {
            throw HttpException::validation(['items' => ['Cart is empty.']]);
        }

        $note = trim((string) ($input['patient_note'] ?? ''));
        $payment = trim((string) ($input['payment_method'] ?? ''));
        if ($payment !== '' && stripos($note, 'Payment:') === false) {
            $note = trim('Payment: ' . $payment . ($note !== '' ? ' · ' . $note : ''));
        }
        $rxFile = $this->savePrescription(
            is_string($input['prescription_base64'] ?? null) ? $input['prescription_base64'] : ''
        );

        $orderNo = $this->nextOrderNo($clientId);
        $db = Connection::ops();
        $db->beginTransaction();
        try {
            $ins = $db->prepare(
                'INSERT INTO agt_orders
                    (client_id, branch_id, order_no, share_token, patient_no, patient_name, patient_contact,
                     patient_address, patient_note, prescription_file, status, item_count, estimated_total, entry_date)
                 VALUES (:c, :b, :ono, :tok, :pno, :pn, :pc, :pa, :note, :rx, \'Placed\', 0, 0, :ed)'
            );
            $ins->execute([
                'c' => $clientId, 'b' => $branchId, 'ono' => $orderNo, 'tok' => $token,
                'pno' => $link['patient_no'], 'pn' => $link['patient_name'], 'pc' => $link['patient_contact'],
                'pa' => $link['patient_address'], 'note' => $note !== '' ? $note : null,
                'rx' => $rxFile !== '' ? $rxFile : null, 'ed' => now(),
            ]);
            $orderId = (int) $db->lastInsertId();
            $total = 0.0;
            $count = 0;

            foreach ($items as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $pName = trim((string) ($it['p_name'] ?? $it['product_name'] ?? ''));
                $batch = trim((string) ($it['batch_no'] ?? ''));
                $qty = (int) round((float) ($it['qty'] ?? 0));
                if ($pName === '' || $qty <= 0) {
                    continue;
                }
                $stock = $this->fetchBatchStock($clientId, $branchId, $pName, $batch);
                $unit = $stock ? (float) ($stock['unit_mrp'] ?? 0) : $this->patientStripMrp($it);
                if ($unit <= 0) {
                    $unit = (float) ($it['unit_mrp'] ?? $it['mrp'] ?? 0);
                }
                if ($stock && $qty > (int) floor((float) $stock['available_qty'])) {
                    $qty = (int) floor((float) $stock['available_qty']);
                }
                if ($qty <= 0) {
                    continue;
                }
                $line = round($unit * $qty, 2);
                $total += $line;
                $count++;

                $itemIns = $db->prepare(
                    'INSERT INTO agt_order_items
                        (client_id, order_id, order_no, product_binding_id, product_name, batch_no, pack_size, category,
                         mrp, unit_mrp, qty, line_total, expiry_date)
                     VALUES (:c, :oid, :ono, :bid, :pn, :bn, :ps, :cat, :mrp, :um, :qty, :lt, :exp)'
                );
                $itemIns->execute([
                    'c' => $clientId, 'oid' => $orderId, 'ono' => $orderNo,
                    'bid' => $stock['binding_id'] ?? null,
                    'pn' => $pName, 'bn' => $batch,
                    'ps' => $stock['pack_size'] ?? ($it['pack_size'] ?? ''),
                    'cat' => $stock['p_cat'] ?? ($it['p_cat'] ?? ''),
                    'mrp' => $unit, 'um' => $unit, 'qty' => $qty, 'lt' => $line,
                    'exp' => $stock['exp_date'] ?? ($it['expiry_date'] ?? $it['exp_date'] ?? ''),
                ]);
            }

            if ($count === 0) {
                throw HttpException::validation(['items' => ['No valid in-stock items in cart.']]);
            }

            $upd = $db->prepare(
                'UPDATE agt_orders SET item_count = :cnt, estimated_total = :tot WHERE id = :id'
            );
            $upd->execute(['cnt' => $count, 'tot' => round($total, 2), 'id' => $orderId]);
            $db->commit();

            (new AgtDeliveryService())->saveDropLocation(
                $clientId,
                $orderId,
                $input['drop_latitude'] ?? null,
                $input['drop_longitude'] ?? null
            );

            return [
                'order_no' => $orderNo,
                'order_id' => $orderId,
                'estimated_total' => round($total, 2),
                'message' => 'Order placed successfully',
            ];
        } catch (\Throwable $e) {
            $db->rollBack();
            if ($e instanceof HttpException) {
                throw $e;
            }
            throw new HttpException($e->getMessage(), 400);
        }
    }

    /** @return list<array<string,mixed>> */
    private function catalog(int $clientId, int $branchId, string $q, int $limit): array
    {
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT pb.id AS binding_id, pb.product_name AS p_name, pb.pack_size, pb.schedule_category AS p_cat,
                       ib.batch_no, ib.quantity AS availableQty, ib.mrp, ib.expiry_date, pb.units_per_pack
                FROM inventory_batches ib
                INNER JOIN product_bindings pb ON pb.id = ib.product_binding_id
                WHERE pb.client_id = :c AND pb.branch_id = :b AND pb.is_active = 1 AND ib.quantity > 0
                  AND (ib.expiry_date IS NULL OR ib.expiry_date >= CURDATE())';
        $bind = ['c' => $clientId, 'b' => $branchId];
        $q = trim($q);
        if ($q !== '') {
            $sql .= ' AND (pb.product_name LIKE :q OR ib.batch_no LIKE :q2)';
            $like = '%' . $q . '%';
            $bind['q'] = $like;
            $bind['q2'] = $like;
        }
        $sql .= ' ORDER BY pb.product_name ASC, (ib.expiry_date IS NULL), ib.expiry_date ASC LIMIT ' . $limit;
        $stmt = Connection::ops()->prepare($sql);
        $stmt->execute($bind);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[] = $this->mapCatalogRow($row);
        }
        return $out;
    }

    /** @param array<string,mixed> $row */
    private function mapCatalogRow(array $row): array
    {
        $stripMrp = $this->patientStripMrp($row);
        return [
            'p_name' => $row['p_name'],
            'batch_no' => $row['batch_no'] ?? '',
            'pack_size' => $row['pack_size'] ?? '',
            'p_cat' => $row['p_cat'] ?? '',
            'mrp' => $stripMrp,
            'unit_mrp' => $stripMrp,
            'availableQty' => (float) ($row['availableQty'] ?? 0),
            'expiry_date' => $row['expiry_date'] ?? '',
            'binding_id' => (int) ($row['binding_id'] ?? 0),
        ];
    }

    /** @param array<string,mixed> $row */
    private function patientStripMrp(array $row): float
    {
        $packMrp = (float) ($row['mrp'] ?? 0);
        $unitMrp = (float) ($row['unit_mrp'] ?? 0);
        $parsed = PackSize::parse((string) ($row['pack_size'] ?? ''));
        $units = max(1, (int) ($row['units_per_pack'] ?? $parsed['units_per_pack'] ?? 1));
        if ($packMrp > 0) {
            return round($packMrp, 2);
        }
        if ($unitMrp > 0 && $units > 1) {
            return round($unitMrp * $units, 2);
        }
        if ($unitMrp > 0) {
            return round($unitMrp, 2);
        }
        return 0.0;
    }

    /** @return array<string,mixed>|null */
    private function firstBatchNo(int $clientId, int $branchId, int $bindingId): string
    {
        $stmt = Connection::ops()->prepare(
            'SELECT batch_no FROM inventory_batches
             WHERE client_id = :c AND branch_id = :b AND product_binding_id = :p AND quantity > 0
             ORDER BY (expiry_date IS NULL), expiry_date ASC, id ASC
             LIMIT 1'
        );
        $stmt->execute(['c' => $clientId, 'b' => $branchId, 'p' => $bindingId]);
        $batch = $stmt->fetchColumn();
        return is_string($batch) ? $batch : '';
    }

    private function fetchBatchStock(int $clientId, int $branchId, string $productName, string $batchNo): ?array
    {
        $sql = 'SELECT pb.id AS binding_id, pb.product_name, pb.pack_size, pb.schedule_category AS p_cat,
                       pb.units_per_pack, ib.id AS batch_id, ib.batch_no, ib.quantity AS available_qty,
                       ib.mrp, ib.expiry_date AS exp_date, ib.ptr, ib.pts, ib.purchase_rate
                FROM inventory_batches ib
                INNER JOIN product_bindings pb ON pb.id = ib.product_binding_id
                WHERE pb.client_id = :c AND pb.branch_id = :b AND pb.product_name = :pn AND ib.batch_no = :bn
                  AND ib.quantity > 0 LIMIT 1';
        $stmt = Connection::ops()->prepare($sql);
        $stmt->execute(['c' => $clientId, 'b' => $branchId, 'pn' => $productName, 'bn' => $batchNo]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        // inventory_batches.mrp is per base unit (tablet / ml), same as sales billing.
        $upp = max(1, (int) $row['units_per_pack']);
        $unitMrp = (float) $row['mrp'];
        $row['unit_mrp'] = round($unitMrp, 4);
        $row['pack_mrp'] = round($unitMrp * $upp, 2);
        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function fetchOtherBatches(int $clientId, int $branchId, string $productName, string $excludeBatch): array
    {
        $sql = 'SELECT pb.id AS binding_id, pb.pack_size, pb.schedule_category AS p_cat, pb.units_per_pack,
                       ib.id AS batch_id, ib.batch_no, ib.quantity AS available_qty, ib.mrp, ib.expiry_date AS exp_date,
                       ib.ptr, ib.pts, ib.purchase_rate
                FROM inventory_batches ib
                INNER JOIN product_bindings pb ON pb.id = ib.product_binding_id
                WHERE pb.client_id = :c AND pb.branch_id = :b AND pb.product_name = :pn
                  AND ib.batch_no <> :eb AND ib.quantity > 0
                  AND (ib.expiry_date IS NULL OR ib.expiry_date >= CURDATE())
                ORDER BY (ib.expiry_date IS NULL), ib.expiry_date ASC, ib.batch_no ASC';
        $stmt = Connection::ops()->prepare($sql);
        $stmt->execute(['c' => $clientId, 'b' => $branchId, 'pn' => $productName, 'eb' => $excludeBatch]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $upp = max(1, (int) $row['units_per_pack']);
            $unitMrp = (float) $row['mrp'];
            $row['unit_mrp'] = round($unitMrp, 4);
            $row['pack_mrp'] = round($unitMrp * $upp, 2);
            $out[] = $row;
        }
        return $out;
    }

    /** @param array<string,mixed> $item */
    private function enrichItemStock(int $clientId, int $branchId, array &$item): void
    {
        $orderQty = (float) ($item['qty'] ?? 0);
        $oldBatch = trim((string) ($item['batch_no'] ?? ''));
        $orderedRate = (float) ($item['unit_mrp'] ?? 0);
        $pName = (string) ($item['product_name'] ?? $item['p_name'] ?? '');

        $item['historical_unit_mrp'] = $orderedRate;
        $item['fulfillment_segments'] = [];
        $item['alternate_batches'] = [];
        $item['available_qty'] = 0;
        $item['in_stock'] = false;
        $item['stock_sufficient'] = false;
        $item['qty_unfulfilled'] = $orderQty;
        $item['stock_status'] = 'none';

        if ($orderQty <= 0 || $pName === '') {
            return;
        }

        $oldStock = $oldBatch !== '' ? $this->fetchBatchStock($clientId, $branchId, $pName, $oldBatch) : null;
        $oldAvail = $oldStock ? (float) $oldStock['available_qty'] : 0;
        $item['available_qty'] = $oldAvail;
        $others = $this->fetchOtherBatches($clientId, $branchId, $pName, $oldBatch);
        $item['alternate_batches'] = $others;

        $segments = [];
        $remainder = $orderQty;

        if ($oldAvail > 0 && $oldStock) {
            $take = min($oldAvail, $remainder);
            $segments[] = $this->segmentFromStock($oldStock, $take, 'historical', $orderedRate, $item);
            $remainder -= $take;
        }

        foreach ($others as $alt) {
            if ($remainder <= 0) {
                break;
            }
            $altAvail = (float) ($alt['available_qty'] ?? 0);
            if ($altAvail <= 0) {
                continue;
            }
            $take = min($altAvail, $remainder);
            $segments[] = $this->segmentFromStock($alt, $take, 'current', (float) $alt['unit_mrp'], $item);
            $remainder -= $take;
        }

        $item['fulfillment_segments'] = $segments;
        $item['qty_unfulfilled'] = max(0, $remainder);
        $fulfilled = $orderQty - $item['qty_unfulfilled'];
        $item['in_stock'] = $fulfilled > 0;
        $item['stock_sufficient'] = $item['qty_unfulfilled'] <= 0;
        $item['stock_status'] = $fulfilled <= 0 ? 'none' : ($item['qty_unfulfilled'] > 0 ? 'partial' : 'same_batch');
    }

    /** @param array<string,mixed> $stock @param array<string,mixed> $item */
    private function segmentFromStock(array $stock, float $qty, string $rateSource, float $orderedRate, array $item): array
    {
        return [
            'binding_id' => (int) ($stock['binding_id'] ?? 0),
            'batch_id' => (int) ($stock['batch_id'] ?? 0),
            'batch_no' => $stock['batch_no'] ?? '',
            'qty' => $qty,
            'rate_source' => $rateSource,
            'unit_mrp' => $orderedRate > 0 && $rateSource === 'historical'
                ? $orderedRate
                : (float) ($stock['unit_mrp'] ?? 0),
            'pack_mrp' => (float) ($stock['pack_mrp'] ?? $stock['mrp'] ?? 0),
            'exp_date' => ($item['exp_date'] ?? '') !== '' ? $item['exp_date'] : ($stock['exp_date'] ?? ''),
            'available_qty' => (float) ($stock['available_qty'] ?? 0),
            'pack_size' => ($item['pack_size'] ?? '') !== '' ? $item['pack_size'] : ($stock['pack_size'] ?? ''),
            'p_cat' => ($item['p_cat'] ?? '') !== '' ? $item['p_cat'] : ($stock['p_cat'] ?? ''),
        ];
    }

    /** @return array<string,mixed> */
    private function activeShareLink(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            throw HttpException::validation(['token' => ['Invalid link.']]);
        }
        $stmt = Connection::ops()->prepare(
            'SELECT * FROM agt_share_links WHERE token = :t AND status = \'Active\' LIMIT 1'
        );
        $stmt->execute(['t' => $token]);
        $link = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$link) {
            throw HttpException::notFound('Link expired or invalid.');
        }
        if (!empty($link['expires_at']) && strtotime((string) $link['expires_at']) < time()) {
            throw HttpException::notFound('Link expired.');
        }
        return $link;
    }

    private function nextOrderNo(int $clientId): string
    {
        $year = date('Y');
        $prefix = 'AGT/' . $year . '/';
        $stmt = Connection::ops()->prepare(
            'SELECT IFNULL(MAX(CAST(SUBSTRING_INDEX(order_no, \'/\', -1) AS UNSIGNED)), 0) AS seq
             FROM agt_orders WHERE client_id = :c AND order_no LIKE :pfx'
        );
        $stmt->execute(['c' => $clientId, 'pfx' => $prefix . '%']);
        $seq = (int) ($stmt->fetch(PDO::FETCH_ASSOC)['seq'] ?? 0);
        return $prefix . str_pad((string) ($seq + 1), 4, '0', STR_PAD_LEFT);
    }

    private function shopUrl(string $token): string
    {
        // Public patient shop — standalone PHP (no Angular login), Retail aura_ghar_tak_shop.php parity.
        $base = rtrim((string) Env::get('APP_URL', ''), '/');
        if ($base === '') {
            $base = rtrim((string) Env::get('PUBLIC_FRONTEND_URL', ''), '/');
        }
        if ($base === '') {
            $base = 'http://localhost:4200';
        }
        return $base . '/agt-shop?token=' . rawurlencode($token);
    }

    private function prescriptionDir(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/agt_prescriptions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    private function savePrescription(string $base64): string
    {
        $base64 = trim($base64);
        if ($base64 === '') {
            return '';
        }
        $ext = 'jpg';
        if (preg_match('/^data:([^;]+);base64,(.+)$/s', $base64, $m)) {
            $mime = strtolower(trim($m[1]));
            $base64 = $m[2];
            $map = [
                'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png',
                'image/webp' => 'webp', 'image/gif' => 'gif', 'application/pdf' => 'pdf',
            ];
            $ext = $map[$mime] ?? '';
            if ($ext === '') {
                return '';
            }
        }
        $bin = base64_decode($base64, true);
        if ($bin === false || strlen($bin) < 32 || strlen($bin) > 8 * 1024 * 1024) {
            return '';
        }
        $name = 'agt_rx_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $path = $this->prescriptionDir() . DIRECTORY_SEPARATOR . $name;
        if (@file_put_contents($path, $bin) === false) {
            return '';
        }
        return $name;
    }

    public function prescriptionPublicUrl(string $fileName): string
    {
        $fileName = trim($fileName);
        if ($fileName === '') {
            return '';
        }
        $app = rtrim((string) Env::get('APP_URL', ''), '/');
        if ($app === '') {
            return '';
        }
        return $app . '/api/public/agt/prescription/' . rawurlencode($fileName);
    }

    public function prescriptionFilePath(string $fileName): ?string
    {
        $fileName = basename($fileName);
        if ($fileName === '' || !preg_match('/^agt_rx_[A-Za-z0-9_.-]+$/', $fileName)) {
            return null;
        }
        $path = $this->prescriptionDir() . DIRECTORY_SEPARATOR . $fileName;
        return is_file($path) ? $path : null;
    }

    private function digits(string $s): string
    {
        return preg_replace('/[^0-9]/', '', $s) ?? '';
    }

    /** Public patient track — single order by order_no or share_token (no auth). */
    public function trackPublicByLookup(string $lookup): ?array
    {
        $lookup = trim($lookup);
        if ($lookup === '') {
            return null;
        }
        $stmt = Connection::ops()->prepare(
            'SELECT id, order_no, patient_name, patient_contact, patient_address, status, estimated_total, entry_date,
                    ready_at, out_for_delivery_at, delivered_at
             FROM agt_orders
             WHERE order_no = :q OR share_token = :q2
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute(['q' => $lookup, 'q2' => $lookup]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return $this->publicTrackDetailFromAgtRow($row);
    }

    /** True when the lookup is an order's private share token (not merely its order number). */
    public function isShareToken(string $lookup): bool
    {
        $lookup = trim($lookup);
        if ($lookup === '') {
            return false;
        }
        $stmt = Connection::ops()->prepare('SELECT 1 FROM agt_orders WHERE share_token = :t LIMIT 1');
        $stmt->execute(['t' => $lookup]);
        return (bool) $stmt->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    public function trackPublicByPhone(string $phoneSuffix): array
    {
        $suffix = $this->digits($phoneSuffix);
        if (strlen($suffix) >= 10) {
            $suffix = substr($suffix, -10);
        }
        if (strlen($suffix) < 10) {
            return [];
        }
        $stmt = Connection::ops()->prepare(
            "SELECT id, order_no, patient_name, patient_contact, status, estimated_total, entry_date
             FROM agt_orders
             WHERE REPLACE(REPLACE(REPLACE(patient_contact, ' ', ''), '-', ''), '+', '') LIKE :suf
             ORDER BY id DESC
             LIMIT 30"
        );
        $stmt->execute(['suf' => '%' . $suffix]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $p = $this->digits((string) ($row['patient_contact'] ?? ''));
            if ($p === '' || substr($p, -10) !== $suffix) {
                continue;
            }
            $out[] = $this->publicTrackSummaryFromAgtRow($row);
        }
        return $out;
    }

    /** @param array<string,mixed> $row */
    private function publicTrackSummaryFromAgtRow(array $row): array
    {
        return [
            'order_no' => (string) ($row['order_no'] ?? ''),
            'customer_name' => (string) ($row['patient_name'] ?? ''),
            'status' => (string) ($row['status'] ?? 'Placed'),
            'total_estimate' => (float) ($row['estimated_total'] ?? 0),
            'created_at' => (string) ($row['entry_date'] ?? ''),
            'order_kind' => 'agt',
        ];
    }

    /** @param array<string,mixed> $row */
    private function publicTrackDetailFromAgtRow(array $row): array
    {
        $status = (string) ($row['status'] ?? 'Placed');
        $events = [];
        $placed = (string) ($row['entry_date'] ?? '');
        if ($placed !== '') {
            $events[] = ['status' => 'Placed', 'note' => 'Order received', 'created_at' => $placed];
        }
        $ready = (string) ($row['ready_at'] ?? '');
        if ($ready !== '') {
            $events[] = ['status' => 'Ready', 'note' => 'Bill prepared at pharmacy', 'created_at' => $ready];
        }
        $ofd = (string) ($row['out_for_delivery_at'] ?? '');
        if ($ofd !== '') {
            $events[] = ['status' => 'Out for Delivery', 'note' => 'On the way', 'created_at' => $ofd];
        }
        $del = (string) ($row['delivered_at'] ?? '');
        if ($del !== '') {
            $events[] = ['status' => 'Delivered', 'note' => 'Delivered', 'created_at' => $del];
        }
        if ($status === 'Cancelled') {
            $events[] = ['status' => 'Cancelled', 'note' => 'Order cancelled', 'created_at' => $placed];
        }
        $cancellable = !in_array($status, ['Delivered', 'Cancelled', 'Out for Delivery'], true);

        $items = [];
        $orderId = (int) ($row['id'] ?? 0);
        if ($orderId > 0) {
            $itemStmt = Connection::ops()->prepare(
                'SELECT product_name, qty, unit_mrp, line_total FROM agt_order_items WHERE order_id = :id ORDER BY id ASC'
            );
            $itemStmt->execute(['id' => $orderId]);
            while ($it = $itemStmt->fetch(PDO::FETCH_ASSOC)) {
                $name = trim((string) ($it['product_name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $items[] = [
                    'name' => $name,
                    'product_name' => $name,
                    'qty' => (int) round((float) ($it['qty'] ?? 0)),
                    'rate' => (float) ($it['unit_mrp'] ?? 0),
                ];
            }
        }

        return [
            'mode' => 'detail',
            'order_kind' => 'agt',
            'order_no' => (string) ($row['order_no'] ?? ''),
            'customer_name' => (string) ($row['patient_name'] ?? ''),
            'phone' => (string) ($row['patient_contact'] ?? ''),
            'address' => (string) ($row['patient_address'] ?? ''),
            'status' => $status,
            'total_estimate' => (float) ($row['estimated_total'] ?? 0),
            'created_at' => $placed,
            'cancellable' => $cancellable,
            'events' => $events,
            'items' => $items,
        ];
    }
}
