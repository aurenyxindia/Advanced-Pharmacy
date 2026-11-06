<?php

/**
 * Seed shared demo logins for local + demo parity.
 * Password for all: Demo@12345
 *
 * Run: C:\xampp\php\php.exe database\seed_demo_users.php
 */

$host = '127.0.0.1';
$user = 'root';
$pass = '';

$pdo = new PDO("mysql:host={$host};dbname=pharma_ho;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$passwordHash = password_hash('Demo@12345', PASSWORD_ARGON2ID, [
    'memory_cost' => 65536,
    'time_cost'   => 4,
    'threads'     => 2,
]);

$now = date('Y-m-d H:i:s');

$clientId = (int) $pdo->query('SELECT id FROM clients ORDER BY id ASC LIMIT 1')->fetchColumn();
if ($clientId < 1) {
    fwrite(STDERR, "No client found. Register a pharmacy first.\n");
    exit(1);
}

$hoBranchId = (int) $pdo->query(
    'SELECT id FROM branches WHERE client_id = ' . $clientId . ' AND is_head_office = 1 ORDER BY id ASC LIMIT 1'
)->fetchColumn();

$retailBranchId = (int) $pdo->query(
    'SELECT id FROM branches WHERE client_id = ' . $clientId . ' AND is_head_office = 0 ORDER BY id ASC LIMIT 1'
)->fetchColumn();

if ($retailBranchId < 1) {
    $stmt = $pdo->prepare(
        'INSERT INTO branches
            (client_id, code, name, is_head_office, address, city, state, pincode, phone, status, created_at, updated_at)
         VALUES
            (:client_id, :code, :name, 0, :address, :city, :state, :pincode, :phone, :status, :created_at, :updated_at)'
    );
    $stmt->execute([
        'client_id'  => $clientId,
        'code'       => '002',
        'name'       => 'Demo Retail Branch',
        'address'    => '1 Main Street',
        'city'       => 'Bengaluru',
        'state'      => 'Karnataka',
        'pincode'    => '560001',
        'phone'      => '9876543210',
        'status'     => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $retailBranchId = (int) $pdo->lastInsertId();
    echo "Created retail branch id={$retailBranchId}\n";
}

$wholesaleBranchId = (int) $pdo->query(
    "SELECT id FROM branches WHERE client_id = {$clientId} AND id <> {$retailBranchId} AND is_head_office = 0 ORDER BY id ASC LIMIT 1"
)->fetchColumn();

if ($wholesaleBranchId < 1) {
    $stmt = $pdo->prepare(
        'INSERT INTO branches
            (client_id, code, name, is_head_office, address, city, state, pincode, phone, status, created_at, updated_at)
         VALUES
            (:client_id, :code, :name, 0, :address, :city, :state, :pincode, :phone, :status, :created_at, :updated_at)'
    );
    $stmt->execute([
        'client_id'  => $clientId,
        'code'       => '003',
        'name'       => 'Demo Wholesale Branch',
        'address'    => '2 Market Road',
        'city'       => 'Bengaluru',
        'state'      => 'Karnataka',
        'pincode'    => '560002',
        'phone'      => '9876543211',
        'status'     => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $wholesaleBranchId = (int) $pdo->lastInsertId();
    echo "Created wholesale branch id={$wholesaleBranchId}\n";
}

$users = [
    ['HO Admin', 'hoadmin@demo.pharmacy', 'ho_admin', $hoBranchId],
    ['Admin User', 'admin@pharmacy.com', 'ho_admin', $hoBranchId],
    ['Retail Branch Admin', 'retail@demo.pharmacy', 'branch_admin', $retailBranchId],
    ['Wholesale Branch Admin', 'wholesale@demo.pharmacy', 'branch_admin', $wholesaleBranchId],
    ['Sales Cashier', 'sales@demo.pharmacy', 'cashier', $retailBranchId],
    ['Pharmacist', 'pharmacist@demo.pharmacy', 'pharmacist', $retailBranchId],
    ['Purchase Officer', 'purchase@demo.pharmacy', 'purchase', $retailBranchId],
];

$upsert = $pdo->prepare(
    'INSERT INTO users
        (client_id, branch_id, name, email, phone, password_hash, role, status, created_at, updated_at)
     VALUES
        (:client_id, :branch_id, :name, :email, :phone, :password_hash, :role, :status, :created_at, :updated_at)
     ON DUPLICATE KEY UPDATE
        name = VALUES(name),
        branch_id = VALUES(branch_id),
        password_hash = VALUES(password_hash),
        role = VALUES(role),
        status = VALUES(status),
        updated_at = VALUES(updated_at)'
);

foreach ($users as [$name, $email, $role, $branchId]) {
    $upsert->execute([
        'client_id'     => $clientId,
        'branch_id'     => $branchId ?: null,
        'name'          => $name,
        'email'         => $email,
        'phone'         => '9876543210',
        'password_hash' => $passwordHash,
        'role'          => $role,
        'status'        => 'active',
        'created_at'    => $now,
        'updated_at'    => $now,
    ]);
    echo "Upserted {$email} ({$role})\n";
}

// Clear rate-limit lockouts from failed login attempts
$pdo->exec('DELETE FROM login_attempts');
echo "Cleared login_attempts.\nDone. Password for all demo users: Demo@12345\n";
