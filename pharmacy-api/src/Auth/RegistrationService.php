<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\HttpException;
use App\Database\Connection;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use App\Services\AdminRegistrationService;
use App\Services\ClientProfileService;
use PDO;

/**
 * Handles subscription sign-up: creates the client (with drug license),
 * a primary branch, and the HO admin user atomically.
 */
final class RegistrationService
{
    public function register(array $data, string $ip): array
    {
        $clients = new Client();

        if ($clients->findByEmail($data['email'])) {
            throw HttpException::validation(['email' => ['This email is already registered.']]);
        }
        if ($clients->findByDrugLicense($data['drug_license_no'])) {
            throw HttpException::validation(['drug_license_no' => ['This drug license is already registered.']]);
        }

        (new ClientProfileService())->ensureProfileColumns();
        $adminReg = new AdminRegistrationService();
        $adminReg->ensurePendingStatusColumn();
        $adminReg->ensureAccessColumns();

        $channel = strtolower(trim((string) ($data['access_channel'] ?? '')));
        if ($channel === '') {
            $channel = !empty($data['_from_android']) ? 'android' : 'web';
        }
        if (!in_array($channel, ['web', 'android'], true)) {
            $channel = 'web';
        }

        $planCode = strtolower(trim((string) ($data['plan_code'] ?? '')));
        $androidExpiry = null;
        $subscriptionPlan = 'trial';
        // Android: 3-day free trial (clients.trial_ends_at). Plan is chosen later on paywall.
        // Optional plan_code at register still activates immediately (buy now at signup).
        $trialEndsAt = date('Y-m-d H:i:s', strtotime('+14 days'));
        $platformActive = 0;
        if ($channel === 'android') {
            $trialEndsAt = date(
                'Y-m-d H:i:s',
                strtotime('+' . \App\Services\ClientSubscriptionService::ANDROID_TRIAL_DAYS . ' days')
            );
            $platformActive = 1; // trial login without waiting for admin
            if ($planCode !== '') {
                $plans = new \App\Services\SubscriptionPlanService();
                $plan = $plans->findByCode($planCode);
                if (!$plan || empty($plan['is_active'])) {
                    throw HttpException::validation(['plan_code' => ['Please select a valid subscription plan.']]);
                }
                $androidExpiry = $plans->expiryDateForPlan($plan);
                $subscriptionPlan = (string) $plan['code'];
                $trialEndsAt = null; // paid at signup — no trial gate
                // subscription_status set to active below when paid
            }
        }
        $subscriptionStatus = ($channel === 'android' && $planCode !== '') ? 'active' : 'trial';

        $businessType = strtolower(trim((string) ($data['business_type'] ?? '')));
        if (!in_array($businessType, ['retail', 'wholesale', 'both'], true)) {
            throw HttpException::validation([
                'business_type' => ['Please select retail, wholesale, or both.'],
            ]);
        }

        return Connection::transaction('ho', function (PDO $db) use (
            $data,
            $ip,
            $businessType,
            $channel,
            $androidExpiry,
            $subscriptionPlan,
            $subscriptionStatus,
            $trialEndsAt,
            $platformActive
        ) {
            $now = date('Y-m-d H:i:s');

            // 1. Create client (tenant). Android trial uses clients.trial_ends_at (3 days).
            // Web: platform_active=0 until platform Admin activates.
            $stmt = $db->prepare(
                'INSERT INTO clients
                    (business_name, owner_name, email, phone, drug_license_no, gstin, pan, fssai_no,
                     address, city, state, pincode, subscription_plan, subscription_status, business_type,
                     trial_ends_at, platform_active, created_at, updated_at)
                 VALUES
                    (:business_name, :owner_name, :email, :phone, :drug_license_no, :gstin, :pan, :fssai_no,
                     :address, :city, :state, :pincode, :plan, :status, :business_type,
                     :trial_ends_at, :platform_active, :created_at, :updated_at)'
            );
            $stmt->execute([
                'business_name'   => $data['business_name'],
                'owner_name'      => $data['owner_name'],
                'email'           => strtolower(trim($data['email'])),
                'phone'           => $data['phone'],
                'drug_license_no' => $data['drug_license_no'],
                'gstin'           => $data['gstin'] ?? null,
                'pan'             => isset($data['pan']) && trim((string) $data['pan']) !== ''
                    ? strtoupper(trim((string) $data['pan'])) : null,
                'fssai_no'        => isset($data['fssai_no']) && trim((string) $data['fssai_no']) !== ''
                    ? trim((string) $data['fssai_no']) : null,
                'address'         => $data['address'] ?? null,
                'city'            => $data['city'] ?? null,
                'state'           => $data['state'] ?? null,
                'pincode'         => $data['pincode'] ?? null,
                'plan'            => $subscriptionPlan,
                'status'          => $subscriptionStatus,
                'business_type'   => $businessType,
                'trial_ends_at'   => $trialEndsAt,
                'platform_active' => $platformActive,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
            $clientId = (int) $db->lastInsertId();

            // 2. Create the primary branch (code 001 / HO).
            $stmt = $db->prepare(
                'INSERT INTO branches
                    (client_id, code, name, is_head_office, drug_license_no, gstin,
                     address, city, state, pincode, phone, status, created_at, updated_at)
                 VALUES
                    (:client_id, :code, :name, 1, :drug_license_no, :gstin,
                     :address, :city, :state, :pincode, :phone, :status, :created_at, :updated_at)'
            );
            $stmt->execute([
                'client_id'       => $clientId,
                'code'            => '001',
                'name'            => $data['business_name'] . ' (HO)',
                'drug_license_no' => $data['drug_license_no'],
                'gstin'           => $data['gstin'] ?? null,
                'address'         => $data['address'] ?? null,
                'city'            => $data['city'] ?? null,
                'state'           => $data['state'] ?? null,
                'pincode'         => $data['pincode'] ?? null,
                'phone'           => $data['phone'],
                'status'          => 'active',
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
            $branchId = (int) $db->lastInsertId();

            // 3. HO admin user. Android trial: platform_active=1 so login works for 3 days.
            $stmt = $db->prepare(
                'INSERT INTO users
                    (client_id, branch_id, name, email, phone, password_hash, role, status,
                     platform_active, web_android, android_expiry_date, created_at, updated_at)
                 VALUES
                    (:client_id, :branch_id, :name, :email, :phone, :password_hash, :role, :status,
                     :platform_active, :web_android, :android_expiry_date, :created_at, :updated_at)'
            );
            $stmt->execute([
                'client_id'           => $clientId,
                'branch_id'           => $branchId,
                'name'                => $data['owner_name'],
                'email'               => strtolower(trim($data['email'])),
                'phone'               => $data['phone'],
                'password_hash'       => AuthService::hashPassword($data['password']),
                'role'                => 'ho_admin',
                'status'              => 'active',
                'platform_active'     => $platformActive,
                'web_android'         => $channel,
                'android_expiry_date' => $androidExpiry,
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);
            $userId = (int) $db->lastInsertId();

            (new AuditLog())->record([
                'client_id'  => $clientId,
                'branch_id'  => $branchId,
                'user_id'    => $userId,
                'action'     => 'register',
                'entity'     => 'client',
                'entity_id'  => $clientId,
                'ip_address' => $ip,
                'meta'       => json_encode([
                    'platform_active' => $platformActive,
                    'web_android' => $channel,
                    'android_expiry_date' => $androidExpiry,
                    'subscription_plan' => $subscriptionPlan,
                    'subscription_status' => $subscriptionStatus,
                    'trial_ends_at' => $trialEndsAt,
                ], JSON_UNESCAPED_UNICODE),
            ]);

            $pending = $platformActive === 0;
            return [
                'client_id' => $clientId,
                'branch_id' => $branchId,
                'user_id'   => $userId,
                'pending_activation' => $pending,
                'web_android' => $channel,
                'android_expiry_date' => $androidExpiry,
                'subscription_plan' => $subscriptionPlan,
                'subscription_status' => $subscriptionStatus,
                'trial_ends_at' => $trialEndsAt,
            ];
        });
    }
}
