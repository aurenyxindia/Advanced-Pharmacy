<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\Prescription;
use App\Support\UploadGuard;

/**
 * Handles prescription image/PDF uploads (file picker or camera capture)
 * required for Schedule H1/X/G/Restricted dispensing.
 */
final class PrescriptionController extends Controller
{
    public function upload(Request $request): Response
    {
        $file = $_FILES['file'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw HttpException::validation(['file' => ['A prescription file is required.']]);
        }

        $maxMb = (int) config('app.upload.max_mb', 50);
        $maxBytes = $maxMb * 1024 * 1024;
        if (($file['size'] ?? 0) > $maxBytes) {
            throw HttpException::validation(['file' => ["File exceeds the maximum allowed size of {$maxMb} MB."]]);
        }

        $allowed = config('app.upload.allowed_mimes', ['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);
        $mime = UploadGuard::detectMime($file['tmp_name'], $file['type'] ?? null);
        if (!in_array($mime, $allowed, true)) {
            throw HttpException::validation(['file' => ['Only JPG, PNG, WEBP or PDF files are allowed.']]);
        }

        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => 'bin',
        };

        $dir = storage_path('uploads/prescriptions/' . $request->clientId());
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $fileName = str_uuid() . '.' . $ext;
        $target = $dir . '/' . $fileName;
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            // Fallback for non-HTTP-upload contexts (e.g. tests).
            if (!@rename($file['tmp_name'], $target)) {
                throw new HttpException('Failed to store the uploaded file.', 500);
            }
        }

        $relativePath = 'uploads/prescriptions/' . $request->clientId() . '/' . $fileName;
        $id = (new Prescription())->insert([
            'client_id'   => $request->clientId(),
            'branch_id'   => $request->branchId(),
            'customer_id' => $request->input('customer_id') ?: null,
            'doctor_name' => $request->input('doctor_name') ?: null,
            'file_path'   => $relativePath,
            'source'      => $request->input('source') === 'camera' ? 'camera' : 'upload',
            'created_at'  => now(),
        ]);

        return $this->created([
            'id'        => $id,
            'file_path' => $relativePath,
        ], 'Prescription uploaded.');
    }
}
