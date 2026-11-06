<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Database\Connection;
use App\Models\AuditLog;
use PDO;

/**
 * Razorpay Checkout orders + verified activation for Android subscriptions.
 */
final class SubscriptionPaymentService
{
    public function ensureTable(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        Connection::ho()->exec(
            "CREATE TABLE IF NOT EXISTS subscription_payments (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                client_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NULL,
                plan_code VARCHAR(32) NOT NULL,
                amount_paise INT UNSIGNED NOT NULL,
                currency VARCHAR(8) NOT NULL DEFAULT 'INR',
                razorpay_order_id VARCHAR(64) NOT NULL,
                razorpay_payment_id VARCHAR(64) NULL,
                razorpay_signature VARCHAR(255) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'created',
                meta JSON NULL,
                created_at DATETIME NOT NULL,
                paid_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_subscription_payments_order (razorpay_order_id),
                KEY idx_subscription_payments_client (client_id),
                KEY idx_subscription_payments_payment (razorpay_payment_id),
                KEY idx_subscription_payments_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    /**
     * Create Razorpay order for selected plan. Amount is locked server-side.
     *
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    public function createCheckout(int $clientId, string $planCode, array $user, string $ip): array
    {
        $this->ensureTable();
        $rz = new RazorpayClient();
        $rz->assertConfigured();

        $planCode = strtolower(trim($planCode));
        $plans = new SubscriptionPlanService();
        $plan = $plans->findByCode($planCode);
        if (!$plan || empty($plan['is_active'])) {
            throw HttpException::validation(['plan_code' => ['Please select a valid subscription plan.']]);
        }

        $payable = round((float) ($plan['payable'] ?? 0), 2);
        if ($payable < 1) {
            throw HttpException::validation(['plan_code' => ['Plan amount is invalid.']]);
        }
        $amountPaise = (int) round($payable * 100);

        $receipt = 'sub_' . $clientId . '_' . time();
        $order = $rz->createOrder([
            'amount' => $amountPaise,
            'currency' => 'INR',
            'receipt' => substr($receipt, 0, 40),
            'notes' => [
                'client_id' => (string) $clientId,
                'plan_code' => $planCode,
                'user_id' => (string) (int) ($user['id'] ?? 0),
            ],
        ]);

        $orderId = (string) ($order['id'] ?? '');
        if ($orderId === '') {
            throw new HttpException('Could not create payment order.', 502);
        }

        $now = date('Y-m-d H:i:s');
        $db = Connection::ho();
        $db->prepare(
            'INSERT INTO subscription_payments
                (client_id, user_id, plan_code, amount_paise, currency, razorpay_order_id, status, meta, created_at)
             VALUES
                (:client_id, :user_id, :plan_code, :amount_paise, :currency, :order_id, :status, :meta, :created_at)'
        )->execute([
            'client_id' => $clientId,
            'user_id' => (int) ($user['id'] ?? 0) ?: null,
            'plan_code' => $planCode,
            'amount_paise' => $amountPaise,
            'currency' => 'INR',
            'order_id' => $orderId,
            'status' => 'created',
            'meta' => json_encode([
                'payable' => $payable,
                'plan_name' => $plan['name'] ?? $planCode,
                'ip' => $ip,
                'razorpay_order' => [
                    'id' => $orderId,
                    'amount' => $order['amount'] ?? $amountPaise,
                    'currency' => $order['currency'] ?? 'INR',
                    'receipt' => $order['receipt'] ?? $receipt,
                ],
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
        ]);

        return [
            'key_id' => $rz->keyId(),
            'order_id' => $orderId,
            'amount' => $amountPaise,
            'currency' => 'INR',
            'plan' => $plan,
            'prefill' => [
                'name' => (string) ($user['name'] ?? ''),
                'email' => (string) ($user['email'] ?? ''),
                'contact' => (string) ($user['phone'] ?? $user['mobile'] ?? ''),
            ],
            'theme' => [
                'color' => '#0D9488',
            ],
            'company' => 'Paperless Pharmacy',
            'description' => (string) ($plan['name'] ?? 'Subscription') . ' plan',
        ];
    }

    /**
     * Verify Checkout success payload and activate plan.
     *
     * @return array<string,mixed>
     */
    public function verifyAndActivate(
        int $clientId,
        string $orderId,
        string $paymentId,
        string $signature,
        int $actorUserId,
        string $ip
    ): array {
        $this->ensureTable();
        $rz = new RazorpayClient();
        $rz->assertConfigured();

        $orderId = trim($orderId);
        $paymentId = trim($paymentId);
        $signature = trim($signature);
        if ($orderId === '' || $paymentId === '' || $signature === '') {
            throw HttpException::validation([
                'payment' => ['Incomplete payment response from Razorpay.'],
            ]);
        }

        if (!$rz->verifyPaymentSignature($orderId, $paymentId, $signature)) {
            throw HttpException::forbidden('Payment signature verification failed.');
        }

        $row = $this->findByOrderId($orderId);
        if (!$row) {
            throw HttpException::notFound('Payment order not found.');
        }
        if ((int) $row['client_id'] !== $clientId) {
            throw HttpException::forbidden('This payment does not belong to your pharmacy.');
        }

        if (($row['status'] ?? '') === 'paid') {
            return $this->activationPayload($clientId, (string) $row['plan_code'], $actorUserId);
        }

        // Double-check payment with Razorpay API.
        $payment = $rz->fetchPayment($paymentId);
        $status = strtolower((string) ($payment['status'] ?? ''));
        $payOrder = (string) ($payment['order_id'] ?? '');
        $payAmount = (int) ($payment['amount'] ?? 0);
        if ($payOrder !== $orderId) {
            throw HttpException::forbidden('Payment order mismatch.');
        }
        if ($payAmount !== (int) $row['amount_paise']) {
            throw HttpException::forbidden('Payment amount mismatch.');
        }
        if (!in_array($status, ['captured', 'authorized'], true)) {
            throw new HttpException('Payment is not completed yet. Status: ' . $status, 400);
        }

        return $this->markPaidAndActivate($row, $paymentId, $signature, $payment, $actorUserId, $ip);
    }

    /**
     * Razorpay webhook — activates if app closed before verify.
     *
     * @return array{handled:bool, event:string}
     */
    public function handleWebhook(string $rawBody, ?string $signatureHeader): array
    {
        $this->ensureTable();
        $rz = new RazorpayClient();
        if ($rz->webhookSecret() !== '') {
            if ($signatureHeader === null || $signatureHeader === ''
                || !$rz->verifyWebhookSignature($rawBody, $signatureHeader)) {
                throw HttpException::forbidden('Invalid webhook signature.');
            }
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            throw HttpException::validation(['body' => ['Invalid webhook payload.']]);
        }

        $event = (string) ($payload['event'] ?? '');
        $paymentEntity = $payload['payload']['payment']['entity']
            ?? $payload['payload']['order']['entity']
            ?? null;

        if ($event === 'payment.captured' || $event === 'order.paid') {
            $payment = is_array($paymentEntity) ? $paymentEntity : [];
            // For order.paid, payment may be nested differently — try payment entity first.
            if ($event === 'order.paid' && empty($payment['order_id'])) {
                $orderEntity = $payload['payload']['order']['entity'] ?? [];
                $orderId = (string) ($orderEntity['id'] ?? '');
                $row = $orderId !== '' ? $this->findByOrderId($orderId) : null;
                if ($row && ($row['status'] ?? '') !== 'paid') {
                    // Prefer payment id from payments array if present
                    $payments = $payload['payload']['order']['entity']['payments'] ?? null;
                    // Fallback: mark using order notes if we only have order
                    if (is_array($payment) && !empty($payment['id'])) {
                        $this->markPaidAndActivate(
                            $row,
                            (string) $payment['id'],
                            'webhook:' . $event,
                            $payment,
                            (int) ($row['user_id'] ?? 0),
                            'webhook'
                        );
                    } elseif (!empty($orderId)) {
                        // Fetch latest — without payment id we still activate if order amount matches
                        $this->activateFromWebhookOrder($row, $orderId, $event);
                    }
                }
                return ['handled' => true, 'event' => $event];
            }

            $orderId = (string) ($payment['order_id'] ?? '');
            $paymentId = (string) ($payment['id'] ?? '');
            if ($orderId === '' || $paymentId === '') {
                return ['handled' => false, 'event' => $event];
            }
            $row = $this->findByOrderId($orderId);
            if (!$row) {
                return ['handled' => false, 'event' => $event];
            }
            if (($row['status'] ?? '') === 'paid') {
                return ['handled' => true, 'event' => $event];
            }
            $amount = (int) ($payment['amount'] ?? 0);
            if ($amount > 0 && $amount !== (int) $row['amount_paise']) {
                return ['handled' => false, 'event' => $event];
            }
            $this->markPaidAndActivate(
                $row,
                $paymentId,
                'webhook:' . $event,
                $payment,
                (int) ($row['user_id'] ?? 0),
                'webhook'
            );
            return ['handled' => true, 'event' => $event];
        }

        return ['handled' => false, 'event' => $event];
    }

    /** @param array<string,mixed> $row */
    private function activateFromWebhookOrder(array $row, string $orderId, string $event): void
    {
        $this->markPaidAndActivate(
            $row,
            'order:' . $orderId,
            'webhook:' . $event,
            ['order_id' => $orderId, 'status' => 'paid'],
            (int) ($row['user_id'] ?? 0),
            'webhook'
        );
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $paymentMeta
     * @return array<string,mixed>
     */
    private function markPaidAndActivate(
        array $row,
        string $paymentId,
        string $signature,
        array $paymentMeta,
        int $actorUserId,
        string $ip
    ): array {
        $db = Connection::ho();
        $now = date('Y-m-d H:i:s');
        $meta = [];
        if (!empty($row['meta'])) {
            $decoded = json_decode((string) $row['meta'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }
        $meta['razorpay_payment'] = [
            'id' => $paymentId,
            'status' => $paymentMeta['status'] ?? null,
            'method' => $paymentMeta['method'] ?? null,
            'vpa' => $paymentMeta['vpa'] ?? null,
            'email' => $paymentMeta['email'] ?? null,
            'contact' => $paymentMeta['contact'] ?? null,
        ];

        $db->prepare(
            "UPDATE subscription_payments
             SET razorpay_payment_id = :pid,
                 razorpay_signature = :sig,
                 status = 'paid',
                 paid_at = :paid_at,
                 meta = :meta
             WHERE id = :id AND status <> 'paid'"
        )->execute([
            'pid' => $paymentId,
            'sig' => substr($signature, 0, 255),
            'paid_at' => $now,
            'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
            'id' => (int) $row['id'],
        ]);

        $clientId = (int) $row['client_id'];
        $planCode = (string) $row['plan_code'];
        $result = (new ClientSubscriptionService())->activatePlan(
            $clientId,
            $planCode,
            $paymentId,
            (string) ($paymentMeta['method'] ?? 'razorpay'),
            $actorUserId > 0 ? $actorUserId : (int) ($row['user_id'] ?? 0),
            $ip
        );

        (new AuditLog())->record([
            'client_id'  => $clientId,
            'branch_id'  => null,
            'user_id'    => $actorUserId > 0 ? $actorUserId : (int) ($row['user_id'] ?? 0),
            'action'     => 'subscription_razorpay_paid',
            'entity'     => 'subscription_payment',
            'entity_id'  => (int) $row['id'],
            'ip_address' => $ip,
            'meta'       => json_encode([
                'plan_code' => $planCode,
                'order_id' => $row['razorpay_order_id'] ?? null,
                'payment_id' => $paymentId,
                'amount_paise' => $row['amount_paise'] ?? null,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        return $result;
    }

    /** @return array<string,mixed> */
    private function activationPayload(int $clientId, string $planCode, int $actorUserId): array
    {
        $plan = (new SubscriptionPlanService())->findByCode($planCode);
        $user = null;
        if ($actorUserId > 0) {
            $st = Connection::ho()->prepare(
                'SELECT android_expiry_date FROM users WHERE id = :id LIMIT 1'
            );
            $st->execute(['id' => $actorUserId]);
            $user = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        return [
            'plan' => $plan,
            'subscription' => (new ClientSubscriptionService())->snapshotForClient($clientId, $user ?: null),
            'android_expiry_date' => $user['android_expiry_date'] ?? null,
            'already_activated' => true,
        ];
    }

    /** @return array<string,mixed>|null */
    private function findByOrderId(string $orderId): ?array
    {
        $st = Connection::ho()->prepare(
            'SELECT * FROM subscription_payments WHERE razorpay_order_id = :oid LIMIT 1'
        );
        $st->execute(['oid' => $orderId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
