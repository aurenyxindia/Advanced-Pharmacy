<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\HttpException;
use App\Database\Connection;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
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

        return Connection::transaction('ho', function (PDO $db) use ($data, $ip) {
            $now = date('Y-m-d H:i:s');

            // 1. Create client (tenant) with a trial subscription.
            $stmt = $db->prepare(
                'INSERT INTO clients
                    (business_name, owner_name, email, phone, drug_license_no, gstin,
                     address, city, state, pincode, subscription_plan, subscription_status,
                     trial_ends_at, created_at, updated_at)
                 VALUES
                    (:business_name, :owner_name, :email, :phone, :drug_license_no, :gstin,
                     :address, :city, :state, :pincode, :plan, :status,
                     :trial_ends_at, :created_at, :updated_at)'
            );
            $stmt->execute([
                'business_name'   => $data['business_name'],
                'owner_name'      => $data['owner_name'],
                'email'           => strtolower(trim($data['email'])),
                'phone'           => $data['phone'],
                'drug_license_no' => $data['drug_license_no'],
                'gstin'           => $data['gstin'] ?? null,
                'address'         => $data['address'] ?? null,
                'city'            => $data['city'] ?? null,
                'state'           => $data['state'] ?? null,
                'pincode'         => $data['pincode'] ?? null,
                'plan'            => 'trial',
                'status'          => 'trial',
                'trial_ends_at'   => date('Y-m-d H:i:s', strtotime('+14 days')),
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

            // 3. Create the HO admin user.
            $stmt = $db->prepare(
                'INSERT INTO users
                    (client_id, branch_id, name, email, phone, password_hash, role, status, created_at, updated_at)
                 VALUES
                    (:client_id, :branch_id, :name, :email, :phone, :password_hash, :role, :status, :created_at, :updated_at)'
            );
            $stmt->execute([
                'client_id'     => $clientId,
                'branch_id'     => $branchId,
                'name'          => $data['owner_name'],
                'email'         => strtolower(trim($data['email'])),
                'phone'         => $data['phone'],
                'password_hash' => AuthService::hashPassword($data['password']),
                'role'          => 'ho_admin',
                'status'        => 'active',
                'created_at'    => $now,
                'updated_at'    => $now,
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
            ]);

            return [
                'client_id' => $clientId,
                'branch_id' => $branchId,
                'user_id'   => $userId,
            ];
        });
    }
}
