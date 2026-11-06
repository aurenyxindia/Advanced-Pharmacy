<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Database\Connection;
use App\Models\AuditLog;
use PDO;

/**
 * Client subscription / trial helpers using existing clients.trial_ends_at
 * and clients.subscription_status / subscription_plan.
 */
final class ClientSubscriptionService
{
    public const ANDROID_TRIAL_DAYS = 3;

    /** @return array<string,mixed>|null */
    public function clientRow(int $clientId): ?array
    {
        if ($clientId <= 0) {
            return null;
        }
        $st = Connection::ho()->prepare(
            'SELECT id, subscription_plan, subscription_status, trial_ends_at
             FROM clients WHERE id = :id LIMIT 1'
        );
        $st->execute(['id' => $clientId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Snapshot for login / me / Android paywall.
     *
     * @param array<string,mixed>|null $user optional (android_expiry_date)
     * @return array<string,mixed>
     */
    public function snapshotForClient(int $clientId, ?array $user = null): array
    {
        $client = $this->clientRow($clientId);
        if (!$client) {
            return [
                'status' => 'active',
                'plan' => 'unknown',
                'trial_ends_at' => null,
                'android_expiry_date' => null,
                'trial_active' => false,
                'needs_payment' => false,
                'days_left' => null,
            ];
        }

        $status = strtolower(trim((string) ($client['subscription_status'] ?? 'trial')));
        $plan = (string) ($client['subscription_plan'] ?? 'trial');
        $trialEnds = trim((string) ($client['trial_ends_at'] ?? ''));
        $trialEnds = $trialEnds !== '' ? $trialEnds : null;
        $androidExpiry = trim((string) ($user['android_expiry_date'] ?? ''));
        $androidExpiry = $androidExpiry !== '' ? substr($androidExpiry, 0, 10) : null;

        $now = time();
        $trialActive = false;
        $daysLeft = null;
        if ($status === 'trial' && $trialEnds !== null) {
            $endsTs = strtotime($trialEnds);
            if ($endsTs !== false) {
                $trialActive = $endsTs >= $now;
                $daysLeft = (int) max(0, ceil(($endsTs - $now) / 86400));
            }
        }

        $channel = strtolower(trim((string) ($user['web_android'] ?? 'both')));
        if ($channel === '' || !in_array($channel, ['both', 'web', 'android'], true)) {
            $channel = 'both';
        }

        $needsPayment = false;
        // Trial / Android paywall only for Android-app registrations (web_android=android).
        // Existing web/both users must never be blocked by trial_ends_at.
        if ($channel === 'android') {
            if (in_array($status, ['past_due', 'suspended', 'cancelled'], true)) {
                $needsPayment = true;
            } elseif ($status === 'trial') {
                // NULL trial_ends_at = legacy open trial (do not block).
                if ($trialEnds !== null && !$trialActive) {
                    $needsPayment = true;
                }
            } elseif ($status === 'active' && $androidExpiry !== null && $androidExpiry < date('Y-m-d')) {
                $needsPayment = true;
            }
        }

        return [
            'status' => $status,
            'plan' => $plan,
            'trial_ends_at' => $trialEnds,
            'android_expiry_date' => $androidExpiry,
            'trial_active' => $trialActive,
            'needs_payment' => $needsPayment,
            'days_left' => $daysLeft,
            'trial_days' => self::ANDROID_TRIAL_DAYS,
        ];
    }

    /**
     * Activate a paid plan after payment confirmation.
     *
     * @return array<string,mixed>
     */
    public function activatePlan(
        int $clientId,
        string $planCode,
        string $paymentRef,
        string $paymentMode,
        int $actorUserId,
        string $ip
    ): array {
        $planCode = strtolower(trim($planCode));
        $paymentRef = trim($paymentRef);
        if ($paymentRef === '') {
            throw HttpException::validation(['payment_ref' => ['Enter the UPI / payment reference.']]);
        }

        $plans = new SubscriptionPlanService();
        $plan = $plans->findByCode($planCode);
        if (!$plan || empty($plan['is_active'])) {
            throw HttpException::validation(['plan_code' => ['Please select a valid subscription plan.']]);
        }

        $client = $this->clientRow($clientId);
        if (!$client) {
            throw HttpException::notFound('Pharmacy not found.');
        }

        (new AdminRegistrationService())->ensureAccessColumns();

        $expiry = $plans->expiryDateForPlan($plan);
        $now = date('Y-m-d H:i:s');
        $db = Connection::ho();

        $db->prepare(
            "UPDATE clients
             SET subscription_plan = :plan,
                 subscription_status = 'active',
                 trial_ends_at = NULL,
                 platform_active = 1,
                 updated_at = :now
             WHERE id = :id"
        )->execute([
            'plan' => $planCode,
            'now' => $now,
            'id' => $clientId,
        ]);

        $db->prepare(
            'UPDATE users
             SET android_expiry_date = :exp,
                 platform_active = 1,
                 updated_at = :now
             WHERE client_id = :cid'
        )->execute([
            'exp' => $expiry,
            'now' => $now,
            'cid' => $clientId,
        ]);

        (new AuditLog())->record([
            'client_id'  => $clientId,
            'branch_id'  => null,
            'user_id'    => $actorUserId,
            'action'     => 'subscription_activate',
            'entity'     => 'client',
            'entity_id'  => $clientId,
            'ip_address' => $ip,
            'meta'       => json_encode([
                'plan_code' => $planCode,
                'payable' => $plan['payable'] ?? null,
                'payment_ref' => $paymentRef,
                'payment_mode' => $paymentMode,
                'android_expiry_date' => $expiry,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $user = ['android_expiry_date' => $expiry];
        return [
            'plan' => $plan,
            'subscription' => $this->snapshotForClient($clientId, $user),
            'android_expiry_date' => $expiry,
        ];
    }
}
