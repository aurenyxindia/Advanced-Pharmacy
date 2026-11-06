<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Database\Connection;
use PDO;
use PDOException;

/** Client / pharmacy profile (Retail myacc parity). */
final class ClientProfileService
{
    private static bool $columnsChecked = false;

    public function ensureProfileColumns(): void
    {
        if (self::$columnsChecked) {
            return;
        }
        self::$columnsChecked = true;

        $db = Connection::ho();
        $adds = [
            'pan'             => 'VARCHAR(10) NULL',
            'fssai_no'        => 'VARCHAR(14) NULL',
            'bank_name'       => 'VARCHAR(120) NULL',
            'account_no'      => 'VARCHAR(30) NULL',
            'ifsc_code'       => 'VARCHAR(11) NULL',
            'dob'             => 'DATE NULL',
            'stamp_file'      => 'VARCHAR(255) NULL',
            'logo_file'       => 'VARCHAR(255) NULL',
            'scanner_file'    => 'VARCHAR(255) NULL',
            'photo_file'      => 'VARCHAR(255) NULL',
            'dl_file'         => 'VARCHAR(255) NULL',
            // Extra WhatsApp recipient for daily closing (editable in My Profile).
            'alternate_phone' => 'VARCHAR(20) NULL',
            // Hide shared master catalogue — own products/distributors only.
            'hide_master_products'     => 'TINYINT(1) NOT NULL DEFAULT 0',
            'hide_master_distributors' => 'TINYINT(1) NOT NULL DEFAULT 0',
        ];
        foreach ($adds as $col => $def) {
            try {
                $db->exec("ALTER TABLE clients ADD COLUMN {$col} {$def}");
            } catch (PDOException) {
                // column exists
            }
        }
    }

    /** @return array<string,mixed> */
    public function getProfile(int $clientId, int $branchId): array
    {
        $this->ensureProfileColumns();
        $db = Connection::ho();

        $client = $db->prepare(
            'SELECT id, business_name, owner_name, email, phone, alternate_phone, drug_license_no, gstin,
                    address, city, state, pincode, subscription_plan AS plan_type,
                    pan, fssai_no, bank_name, account_no, ifsc_code, dob,
                    stamp_file, logo_file, scanner_file, photo_file, dl_file, business_type,
                    hide_master_products, hide_master_distributors
             FROM clients WHERE id = :id LIMIT 1'
        );
        $client->execute(['id' => $clientId]);
        $row = $client->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw HttpException::notFound('Client not found.');
        }

        $branch = $db->prepare('SELECT id, code, name, state, gstin FROM branches WHERE id = :id LIMIT 1');
        $branch->execute(['id' => $branchId]);
        $branchRow = $branch->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'business_name'   => $row['business_name'] ?? '',
            'contact_person'  => $row['owner_name'] ?? '',
            'email'           => $row['email'] ?? '',
            'phone'           => $row['phone'] ?? '',
            'alternate_phone' => $row['alternate_phone'] ?? '',
            'gstin'           => $row['gstin'] ?? '',
            'pan'             => $row['pan'] ?? '',
            'fssai_no'        => $row['fssai_no'] ?? '',
            'bank_name'       => $row['bank_name'] ?? '',
            'account_no'      => $row['account_no'] ?? '',
            'ifsc_code'       => $row['ifsc_code'] ?? '',
            'dob'             => $row['dob'] ?? null,
            'address'         => $row['address'] ?? '',
            'state'           => $branchRow['state'] ?? ($row['state'] ?? ''),
            'city'            => $row['city'] ?? '',
            'pincode'         => $row['pincode'] ?? '',
            'drug_license_no' => $row['drug_license_no'] ?? '',
            /** Set at registration — not editable via profile update. */
            'registration_locked' => true,
            'plan_type'       => $row['plan_type'] ?? '',
            'business_type'   => $row['business_type'] ?? 'both',
            'hide_master_products'     => !empty($row['hide_master_products']),
            'hide_master_distributors' => !empty($row['hide_master_distributors']),
            'client_id'       => (int) $row['id'],
            'branch'          => $branchRow,
            'assets'          => $this->assetUrls($clientId, $row),
        ];
    }

    /** @param array<string,mixed> $data */
    public function updateProfile(int $clientId, array $data, int $userId = 0): void
    {
        $this->ensureProfileColumns();
        $db = Connection::ho();
        $email = isset($data['email']) ? strtolower(trim((string) $data['email'])) : null;

        // clients.email and users.email are unique — reject before saving anything instead of failing mid-update.
        if ($email !== null && $email !== '') {
            $dupClient = $db->prepare('SELECT id FROM clients WHERE email = :email AND id <> :cid LIMIT 1');
            $dupClient->execute(['email' => $email, 'cid' => $clientId]);
            if ($dupClient->fetchColumn()) {
                throw HttpException::validation(['email' => ['This email is already registered to another pharmacy.']]);
            }
            $dup = $db->prepare('SELECT id FROM users WHERE email = :email AND id <> :uid LIMIT 1');
            $dup->execute(['email' => $email, 'uid' => $userId]);
            if ($dup->fetchColumn()) {
                throw HttpException::validation(['email' => ['This email is already used by another login.']]);
            }
        }

        // Registration-sourced fields (business name, owner, phone, address, licenses) are read-only here.
        // alternate_phone is editable — used for daily closing WhatsApp.
        $altPhone = $this->normalizeAlternatePhone($data['alternate_phone'] ?? null);

        $sets = [
            'email = :email',
            'bank_name = :bank',
            'account_no = :acct',
            'ifsc_code = :ifsc',
            'dob = :dob',
            'alternate_phone = :alt_phone',
            'updated_at = :now',
        ];
        $bindings = [
            'email'     => $email,
            'bank'      => $data['bank_name'] ?? null,
            'acct'      => $data['account_no'] ?? null,
            'ifsc'      => isset($data['ifsc_code']) ? strtoupper(trim((string) $data['ifsc_code'])) : null,
            'dob'       => $data['dob'] ?: null,
            'alt_phone' => $altPhone,
            'now'       => now(),
            'id'        => $clientId,
        ];

        if (array_key_exists('hide_master_products', $data)) {
            $sets[] = 'hide_master_products = :hmp';
            $bindings['hmp'] = !empty($data['hide_master_products']) ? 1 : 0;
        }
        if (array_key_exists('hide_master_distributors', $data)) {
            $sets[] = 'hide_master_distributors = :hmd';
            $bindings['hmd'] = !empty($data['hide_master_distributors']) ? 1 : 0;
        }

        $stmt = $db->prepare('UPDATE clients SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $stmt->execute($bindings);

        if (
            array_key_exists('hide_master_products', $data)
            || array_key_exists('hide_master_distributors', $data)
        ) {
            ClientCatalogPolicy::forget($clientId);
        }

        // Only the signed-in admin's own login follows the profile email (one email per login).
        if ($email !== null && $email !== '' && $userId > 0) {
            $u = $db->prepare(
                'UPDATE users SET email = :email, updated_at = :now
                 WHERE id = :uid AND client_id = :cid AND role IN (\'ho_admin\', \'branch_admin\')'
            );
            $u->execute(['email' => $email, 'now' => now(), 'uid' => $userId, 'cid' => $clientId]);
        }
    }

    /** @return string|null 10-digit mobile or null when cleared */
    private function normalizeAlternatePhone(mixed $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) ($raw ?? '')) ?? '';
        if ($digits === '') {
            return null;
        }
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) !== 10) {
            throw HttpException::validation([
                'alternate_phone' => ['Enter a valid 10-digit mobile number.'],
            ]);
        }
        return $digits;
    }

    public function saveUpload(int $clientId, string $type, string $filename): void
    {
        $this->ensureProfileColumns();
        $col = match ($type) {
            'stamp'   => 'stamp_file',
            'logo'    => 'logo_file',
            'scanner' => 'scanner_file',
            'photo'   => 'photo_file',
            'dl'      => 'dl_file',
            default   => throw HttpException::validation(['type' => ['Invalid upload type.']]),
        };

        $db = Connection::ho();
        $stmt = $db->prepare("UPDATE clients SET {$col} = :f, updated_at = :now WHERE id = :id");
        $stmt->execute(['f' => $filename, 'now' => now(), 'id' => $clientId]);
    }

    /** @param array<string,mixed> $row @return array<string,?string> */
    private function assetUrls(int $clientId, array $row): array
    {
        $base = '/api/profile/assets/' . $clientId . '/';
        $map = ['stamp', 'logo', 'scanner', 'photo', 'dl'];
        $out = [];
        foreach ($map as $t) {
            $key = $t === 'dl' ? 'dl_file' : $t . '_file';
            $out[$t] = !empty($row[$key]) ? $base . $t : null;
        }
        return $out;
    }

    public function assetPath(int $clientId, string $type): ?string
    {
        $this->ensureProfileColumns();
        $col = match ($type) {
            'stamp'   => 'stamp_file',
            'logo'    => 'logo_file',
            'scanner' => 'scanner_file',
            'photo'   => 'photo_file',
            'dl'      => 'dl_file',
            default   => null,
        };
        if (!$col) {
            return null;
        }

        $db = Connection::ho();
        $stmt = $db->prepare("SELECT {$col} AS f FROM clients WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $clientId]);
        $file = (string) ($stmt->fetchColumn() ?: '');
        if ($file === '') {
            return null;
        }

        $path = storage_path('uploads/profile/' . $clientId . '/' . $file);
        return is_file($path) ? $path : null;
    }
}
