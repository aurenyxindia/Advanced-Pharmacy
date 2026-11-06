<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Database\Connection;
use App\Support\Env;
use PDO;

final class AuraGharTakUserService
{
    /** @param array<string,mixed> $input */
    public function register(array $input, string $photoBase64 = ''): array
    {
        $name = trim((string) ($input['full_name'] ?? $input['name'] ?? ''));
        $phone = $this->normalizePhone((string) ($input['phone'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $confirm = (string) ($input['password_confirm'] ?? $input['confirm_password'] ?? '');

        if ($name === '') {
            throw HttpException::validation(['full_name' => ['Enter your full name.']]);
        }
        if (strlen($phone) < 10) {
            throw HttpException::validation(['phone' => ['Enter a valid 10-digit mobile number.']]);
        }
        if (strlen($password) < 4) {
            throw HttpException::validation(['password' => ['Password must be at least 4 characters.']]);
        }
        if ($confirm !== '' && $confirm !== $password) {
            throw HttpException::validation(['password_confirm' => ['Passwords do not match.']]);
        }

        $db = Connection::ops();
        $chk = $db->prepare('SELECT id FROM auraghatak_users WHERE phone = :p LIMIT 1');
        $chk->execute(['p' => $phone]);
        if ($chk->fetch()) {
            throw HttpException::validation(['phone' => ['This mobile number is already registered.']]);
        }

        $photo = $this->savePhoto($photoBase64);
        $token = bin2hex(random_bytes(32));
        $now = now();

        $stmt = $db->prepare(
            'INSERT INTO auraghatak_users
                (full_name, email, phone, password, address, pincode, city, state, date_of_birth, gender,
                 photo_file, access_token, status, created_at, updated_at)
             VALUES
                (:name, :email, :phone, :pass, :addr, :pin, :city, :state, :dob, :gender,
                 :photo, :tok, \'active\', :now, :now2)'
        );
        $stmt->execute([
            'name' => $name,
            'email' => $this->nullableEmail($input['email'] ?? null),
            'phone' => $phone,
            'pass' => $password,
            'addr' => $this->nullableStr($input['address'] ?? null, 500),
            'pin' => $this->nullableStr($input['pincode'] ?? null, 12),
            'city' => $this->nullableStr($input['city'] ?? null, 100),
            'state' => $this->nullableStr($input['state'] ?? null, 100),
            'dob' => $this->nullableDate($input['date_of_birth'] ?? $input['dob'] ?? null),
            'gender' => $this->nullableStr($input['gender'] ?? null, 20),
            'photo' => $photo !== '' ? $photo : null,
            'tok' => $token,
            'now' => $now,
            'now2' => $now,
        ]);

        $id = (int) $db->lastInsertId();
        $user = $this->findById($id);
        if (!$user) {
            throw new HttpException('Registration failed.', 500);
        }

        return [
            'token' => $token,
            'user' => $this->publicUser($user),
        ];
    }

    /** @return array{token: string, user: array<string,mixed>} */
    public function login(string $phoneRaw, string $password): array
    {
        $phone = $this->normalizePhone($phoneRaw);
        if (strlen($phone) < 10) {
            throw HttpException::validation(['phone' => ['Enter a valid 10-digit mobile number.']]);
        }
        if ($password === '') {
            throw HttpException::validation(['password' => ['Enter your password.']]);
        }

        $db = Connection::ops();
        $stmt = $db->prepare(
            'SELECT * FROM auraghatak_users WHERE phone = :p AND status = \'active\' LIMIT 1'
        );
        $stmt->execute(['p' => $phone]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || (string) ($user['password'] ?? '') !== $password) {
            throw HttpException::validation(['login' => ['Invalid mobile number or password.']]);
        }

        $token = bin2hex(random_bytes(32));
        $upd = $db->prepare(
            'UPDATE auraghatak_users SET access_token = :t, updated_at = :now WHERE id = :id'
        );
        $upd->execute(['t' => $token, 'now' => now(), 'id' => (int) $user['id']]);
        $user['access_token'] = $token;

        return [
            'token' => $token,
            'user' => $this->publicUser($user),
        ];
    }

    public function logout(?string $token): void
    {
        if ($token === null || $token === '') {
            return;
        }
        $db = Connection::ops();
        $stmt = $db->prepare(
            'UPDATE auraghatak_users SET access_token = NULL, updated_at = :now WHERE access_token = :t'
        );
        $stmt->execute(['now' => now(), 't' => $token]);
    }

    /** @return array<string,mixed>|null */
    public function userByToken(?string $token): ?array
    {
        if ($token === null || $token === '') {
            return null;
        }
        $db = Connection::ops();
        $stmt = $db->prepare(
            'SELECT * FROM auraghatak_users WHERE access_token = :t AND status = \'active\' LIMIT 1'
        );
        $stmt->execute(['t' => $token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ? $this->publicUser($user) : null;
    }

    /** Last 10 digits of the logged-in app customer's phone, or null when the token is missing/invalid. */
    public function phoneForToken(?string $token): ?string
    {
        $token = trim((string) $token);
        if ($token === '') {
            return null;
        }
        $stmt = Connection::ops()->prepare(
            'SELECT phone FROM auraghatak_users WHERE access_token = :t AND status = \'active\' LIMIT 1'
        );
        $stmt->execute(['t' => $token]);
        $digits = preg_replace('/\D+/', '', (string) ($stmt->fetchColumn() ?: '')) ?? '';
        return strlen($digits) >= 10 ? substr($digits, -10) : null;
    }

    /** @param array<string,mixed> $input */
    public function updateProfile(string $token, string $password, array $input, string $photoBase64 = ''): array
    {
        $row = $this->requireTokenRow($token);
        if ((string) ($row['password'] ?? '') !== $password) {
            throw HttpException::validation(['password' => ['Incorrect password.']]);
        }

        $name = trim((string) ($input['full_name'] ?? $input['name'] ?? $row['full_name']));
        if ($name === '') {
            throw HttpException::validation(['full_name' => ['Enter your full name.']]);
        }

        $newPhone = $this->normalizePhone((string) ($input['phone'] ?? $row['phone']));
        if (strlen($newPhone) < 10) {
            throw HttpException::validation(['phone' => ['Enter a valid 10-digit mobile number.']]);
        }
        if ($newPhone !== (string) $row['phone']) {
            $db = Connection::ops();
            $chk = $db->prepare('SELECT id FROM auraghatak_users WHERE phone = :p AND id <> :id LIMIT 1');
            $chk->execute(['p' => $newPhone, 'id' => (int) $row['id']]);
            if ($chk->fetch()) {
                throw HttpException::validation(['phone' => ['This mobile number is already registered.']]);
            }
        }

        $photoFile = (string) ($row['photo_file'] ?? '');
        $removePhoto = $this->truthy($input['remove_photo'] ?? false);
        if ($removePhoto) {
            $this->deletePhotoFile($photoFile);
            $photoFile = '';
        } elseif ($photoBase64 !== '') {
            $saved = $this->savePhoto($photoBase64);
            if ($saved !== '') {
                if ($photoFile !== '' && $photoFile !== $saved) {
                    $this->deletePhotoFile($photoFile);
                }
                $photoFile = $saved;
            }
        }

        $newPassword = (string) ($input['new_password'] ?? '');
        $storedPassword = (string) $row['password'];
        if ($newPassword !== '') {
            if (strlen($newPassword) < 4) {
                throw HttpException::validation(['new_password' => ['New password must be at least 4 characters.']]);
            }
            $storedPassword = $newPassword;
        }

        $db = Connection::ops();
        $stmt = $db->prepare(
            'UPDATE auraghatak_users SET
                full_name = :name, email = :email, phone = :phone, password = :pass,
                address = :addr, pincode = :pin, city = :city, state = :state,
                date_of_birth = :dob, gender = :gender, photo_file = :photo, updated_at = :now
             WHERE id = :id'
        );
        $stmt->execute([
            'name' => $name,
            'email' => $this->nullableEmail($input['email'] ?? $row['email']),
            'phone' => $newPhone,
            'pass' => $storedPassword,
            'addr' => $this->nullableStr($input['address'] ?? $row['address'], 500),
            'pin' => $this->nullableStr($input['pincode'] ?? $row['pincode'], 12),
            'city' => $this->nullableStr($input['city'] ?? $row['city'], 100),
            'state' => $this->nullableStr($input['state'] ?? $row['state'], 100),
            'dob' => $this->nullableDate($input['date_of_birth'] ?? $input['dob'] ?? $row['date_of_birth']),
            'gender' => $this->nullableStr($input['gender'] ?? $row['gender'], 20),
            'photo' => $photoFile !== '' ? $photoFile : null,
            'now' => now(),
            'id' => (int) $row['id'],
        ]);

        $fresh = $this->findById((int) $row['id']);
        if (!$fresh) {
            throw HttpException::notFound('User not found.');
        }

        return $this->publicUser($fresh);
    }

    public function photoFilePath(string $fileName): ?string
    {
        $fileName = basename($fileName);
        if ($fileName === '' || !preg_match('/^agt_user_[a-zA-Z0-9._-]+$/', $fileName)) {
            return null;
        }
        $path = $this->photoDir() . DIRECTORY_SEPARATOR . $fileName;
        return is_file($path) ? $path : null;
    }

    /** @param array<string,mixed> $row */
    private function publicUser(array $row): array
    {
        $photo = trim((string) ($row['photo_file'] ?? ''));
        return [
            'id' => (int) $row['id'],
            'full_name' => (string) $row['full_name'],
            'email' => (string) ($row['email'] ?? ''),
            'phone' => (string) $row['phone'],
            'address' => (string) ($row['address'] ?? ''),
            'pincode' => (string) ($row['pincode'] ?? ''),
            'city' => (string) ($row['city'] ?? ''),
            'state' => (string) ($row['state'] ?? ''),
            'date_of_birth' => (string) ($row['date_of_birth'] ?? ''),
            'gender' => (string) ($row['gender'] ?? ''),
            'photo_url' => $photo !== '' ? $this->photoPublicUrl($photo) : '',
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    /** @return array<string,mixed> */
    private function requireTokenRow(string $token): array
    {
        $db = Connection::ops();
        $stmt = $db->prepare(
            'SELECT * FROM auraghatak_users WHERE access_token = :t AND status = \'active\' LIMIT 1'
        );
        $stmt->execute(['t' => $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw HttpException::unauthorized('Please log in again.');
        }
        return $row;
    }

    /** @return array<string,mixed>|null */
    private function findById(int $id): ?array
    {
        $stmt = Connection::ops()->prepare('SELECT * FROM auraghatak_users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function normalizePhone(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (strlen($digits) >= 10) {
            return substr($digits, -10);
        }
        return $digits;
    }

    private function nullableStr(mixed $value, int $max): ?string
    {
        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }
        return mb_substr($s, 0, $max);
    }

    private function nullableEmail(mixed $value): ?string
    {
        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }
        if (!filter_var($s, FILTER_VALIDATE_EMAIL)) {
            throw HttpException::validation(['email' => ['Enter a valid email address.']]);
        }
        return mb_substr($s, 0, 150);
    }

    private function nullableDate(mixed $value): ?string
    {
        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return $s;
        }
        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $s, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        return null;
    }

    private function photoDir(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/agt_users';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    private function savePhoto(string $base64): string
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
                'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
            ];
            $ext = $map[$mime] ?? '';
            if ($ext === '') {
                return '';
            }
        }
        $bin = base64_decode($base64, true);
        if ($bin === false || strlen($bin) < 32 || strlen($bin) > 4 * 1024 * 1024) {
            return '';
        }
        $name = 'agt_user_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $path = $this->photoDir() . DIRECTORY_SEPARATOR . $name;
        if (@file_put_contents($path, $bin) === false) {
            return '';
        }
        return $name;
    }

    private function photoPublicUrl(string $fileName): string
    {
        $base = rtrim((string) Env::get('APP_URL', ''), '/');
        if ($base === '') {
            $base = 'https://pp.gmpserver.com';
        }
        return $base . '/api/public/agt-users/photo/' . rawurlencode($fileName);
    }

    private function deletePhotoFile(string $fileName): void
    {
        $fileName = basename(trim($fileName));
        if ($fileName === '' || !preg_match('/^agt_user_[a-zA-Z0-9._-]+$/', $fileName)) {
            return;
        }
        $path = $this->photoDir() . DIRECTORY_SEPARATOR . $fileName;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }
        $s = strtolower(trim((string) $value));
        return in_array($s, ['1', 'true', 'yes', 'on'], true);
    }
}
