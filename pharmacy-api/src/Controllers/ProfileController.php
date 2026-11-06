<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\ClientProfileService;
use App\Support\UploadGuard;

final class ProfileController extends Controller
{
    public function show(Request $request): Response
    {
        $data = (new ClientProfileService())->getProfile(
            (int) $request->clientId(),
            (int) $request->branchId()
        );
        return $this->success($data, 'Profile loaded.');
    }

    public function update(Request $request): Response
    {
        $data = $this->validate($request, [
            'email'            => 'email|max:150',
            'bank_name'        => 'string|max:120',
            'account_no'       => 'string|max:30',
            'ifsc_code'        => 'string|max:11',
            'dob'              => 'string|max:20',
            'alternate_phone'  => 'string|max:20',
        ]);

        $role = (string) ($request->user()['role'] ?? '');
        $isAdmin = in_array($role, ['ho_admin', 'branch_admin'], true);
        // Hide Master Catalogue flags — admins only (client-wide policy).
        if ($isAdmin) {
            $all = $request->all();
            if (array_key_exists('hide_master_products', $all)) {
                $data['hide_master_products'] = $this->asBool($all['hide_master_products']);
            }
            if (array_key_exists('hide_master_distributors', $all)) {
                $data['hide_master_distributors'] = $this->asBool($all['hide_master_distributors']);
            }
        }

        (new ClientProfileService())->updateProfile(
            (int) $request->clientId(),
            $data,
            (int) ($request->user()['id'] ?? 0)
        );
        return $this->success(null, 'Profile saved.');
    }

    public function upload(Request $request): Response
    {
        $type = strtolower(trim((string) $request->attribute('type')));
        if (!in_array($type, ['stamp', 'logo', 'scanner', 'photo', 'dl'], true)) {
            throw HttpException::validation(['type' => ['Invalid upload type.']]);
        }

        $file = UploadGuard::requireUploadedFile($_FILES['file'] ?? null, 'file');
        $mime = UploadGuard::detectMime($file['tmp_name'], $file['type'] ?? null);

        $allowed = [
            'image/jpeg', 'image/jpg', 'image/pjpeg', 'image/png', 'image/x-png',
            'image/webp', 'image/gif', 'application/pdf',
        ];
        if (!in_array(strtolower($mime), $allowed, true)) {
            throw HttpException::validation(['file' => ['Only image or PDF files are allowed.']]);
        }

        $ext = match (strtolower($mime)) {
            'image/jpeg', 'image/jpg', 'image/pjpeg' => 'jpg',
            'image/png', 'image/x-png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'application/pdf' => 'pdf',
            default => strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION) ?: 'jpg'),
        };

        $clientId = (int) $request->clientId();
        $dir = storage_path('uploads/profile/' . $clientId);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw HttpException::validation(['file' => ['Could not create upload directory.']]);
        }

        $name = $type . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $target = $dir . DIRECTORY_SEPARATOR . $name;

        if (!move_uploaded_file($file['tmp_name'], $target)) {
            throw HttpException::validation(['file' => ['Upload failed. Could not save file on server.']]);
        }

        (new ClientProfileService())->saveUpload($clientId, $type, $name);

        return $this->success([
            'type' => $type,
            'url'  => '/api/profile/assets/' . $clientId . '/' . $type,
        ], ucfirst($type) . ' uploaded.');
    }

    public function asset(Request $request): Response
    {
        $clientId = (int) $request->attribute('clientId');
        if ($clientId !== (int) $request->clientId()) {
            throw HttpException::forbidden('Access denied.');
        }

        $type = strtolower(trim((string) $request->attribute('type')));
        $path = (new ClientProfileService())->assetPath($clientId, $type);
        if (!$path) {
            throw HttpException::notFound('File not found.');
        }

        $mime = UploadGuard::detectMime($path);
        return Response::raw((string) file_get_contents($path), $mime);
    }

    private function asBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }
        return !empty($value);
    }
}
