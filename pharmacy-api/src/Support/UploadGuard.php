<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\HttpException;

/**
 * Shared upload size / error checks for import & file endpoints.
 */
final class UploadGuard
{
    public static function maxMb(): int
    {
        return max(1, (int) config('app.upload.max_mb', 50));
    }

    public static function maxBytes(): int
    {
        return self::maxMb() * 1024 * 1024;
    }

    /** Raise runtime limits for heavy import/AI work (best-effort). */
    public static function boostRuntime(): void
    {
        @ini_set('memory_limit', '1024M');
        @ini_set('max_execution_time', '300');
        @set_time_limit(300);
        @ignore_user_abort(false);
    }

    /**
     * Validate $_FILES entry; throws HttpException on failure.
     *
     * @param array<string, mixed>|null $file
     * @return array<string, mixed>
     */
    public static function requireUploadedFile(?array $file, string $field = 'file'): array
    {
        if (!$file || !isset($file['error'])) {
            $contentLen = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
            if ($contentLen > 0) {
                $postMax = self::iniBytes('post_max_size');
                if ($postMax > 0 && $contentLen > $postMax) {
                    throw HttpException::validation([$field => [
                        sprintf(
                            'File is too large for the server (max %s). Please use a smaller image or PDF.',
                            self::formatIniLimit('post_max_size'),
                        ),
                    ]]);
                }
                throw HttpException::validation([$field => [
                    'Upload could not be read. Choose the file again or use a smaller image/PDF.',
                ]]);
            }
            throw HttpException::validation([$field => ['Please upload a file.']]);
        }

        $error = (int) $file['error'];
        if ($error !== UPLOAD_ERR_OK) {
            throw HttpException::validation([$field => [self::uploadErrorMessage($error)]]);
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            throw HttpException::validation([$field => ['Uploaded file is empty.']]);
        }

        $max = self::maxBytes();
        if ($size > $max) {
            throw HttpException::validation([
                $field => [sprintf('File exceeds the maximum allowed size of %d MB.', self::maxMb())],
            ]);
        }

        return $file;
    }

    public static function assertContentLength(?int $bytes, string $field = 'file'): void
    {
        if ($bytes === null || $bytes <= 0) {
            return;
        }
        if ($bytes > self::maxBytes()) {
            throw HttpException::validation([
                $field => [sprintf('Content exceeds the maximum allowed size of %d MB.', self::maxMb())],
            ]);
        }
    }

    /** Detect MIME from disk; falls back to client-provided type. */
    public static function detectMime(string $path, ?string $clientMime = null): string
    {
        if (function_exists('finfo_open')) {
            $finfo = \finfo_open(\FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = \finfo_file($finfo, $path) ?: '';
                \finfo_close($finfo);
                if ($mime !== '') {
                    return strtolower($mime);
                }
            }
        }

        if (function_exists('mime_content_type')) {
            $mime = \mime_content_type($path);
            if (is_string($mime) && $mime !== '') {
                return strtolower($mime);
            }
        }

        return strtolower((string) ($clientMime ?: 'application/octet-stream'));
    }

    private static function uploadErrorMessage(int $error): string
    {
        $max = self::maxMb();
        return match ($error) {
            UPLOAD_ERR_INI_SIZE => sprintf(
                'File is too large. Server limit is %s.',
                self::formatIniLimit('upload_max_filesize'),
            ),
            UPLOAD_ERR_FORM_SIZE => "File is too large. Maximum allowed size is {$max} MB.",
            UPLOAD_ERR_PARTIAL   => 'Upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE   => 'Please upload a file.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temporary folder is missing.',
            UPLOAD_ERR_CANT_WRITE => 'Server could not save the uploaded file.',
            UPLOAD_ERR_EXTENSION => 'Upload blocked by a PHP extension.',
            default              => 'Upload failed. Please try again.',
        };
    }

    private static function iniBytes(string $key): int
    {
        $raw = (string) ini_get($key);
        if ($raw === '') {
            return 0;
        }
        $raw = trim($raw);
        $unit = strtolower(substr($raw, -1));
        $num = (float) $raw;
        return (int) match ($unit) {
            'g'     => $num * 1024 * 1024 * 1024,
            'm'     => $num * 1024 * 1024,
            'k'     => $num * 1024,
            default => $num,
        };
    }

    private static function formatIniLimit(string $key): string
    {
        $bytes = self::iniBytes($key);
        if ($bytes <= 0) {
            return (string) ini_get($key);
        }
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024)) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024) . ' KB';
        }
        return $bytes . ' B';
    }
}
