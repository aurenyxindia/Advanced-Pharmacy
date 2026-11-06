<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\ClientProfileService;

/**
 * Resolve pharmacy profile logo / stamp / scanner images for TCPDF invoices.
 * Prefers Profile → Documents uploads; resizes large images so TCPDF does not crash.
 */
final class InvoiceProfileAssets
{
    /**
     * @param array<string,mixed> $client HO clients row (may include *_file columns)
     */
    public static function imgHtml(array $client, string $kind, int $widthPx, ?int $heightPx = null): string
    {
        $kind = strtolower(trim($kind));
        if (!in_array($kind, ['logo', 'stamp', 'scanner'], true)) {
            return '&nbsp;';
        }

        $path = self::resolvePath($client, $kind);
        if ($path === null) {
            return '&nbsp;';
        }

        $src = self::tcpdfImageSrc($path, $widthPx, $heightPx);
        if ($src === null) {
            return '&nbsp;';
        }

        $style = 'width:' . $widthPx . 'px;';
        $style .= $heightPx !== null ? ('height:' . $heightPx . 'px;') : 'height:auto;';

        // TCPDF: '@' means raw binary data — use absolute filesystem path for files.
        return '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" style="' . $style . '" />';
    }

    /**
     * @param array<string,mixed> $client
     */
    public static function resolvePath(array $client, string $kind): ?string
    {
        $kind = strtolower(trim($kind));
        $clientId = (int) ($client['id'] ?? 0);

        if ($clientId > 0) {
            try {
                (new ClientProfileService())->ensureProfileColumns();
            } catch (\Throwable) {
                // ignore
            } 

            $col = match ($kind) {
                'logo'    => 'logo_file',
                'stamp'   => 'stamp_file',
                'scanner' => 'scanner_file',
                default   => null,
            };
            $filename = $col ? trim((string) ($client[$col] ?? '')) : '';
            if ($filename !== '') {
                $profilePath = storage_path('uploads/profile/' . $clientId . '/' . basename($filename));
                if (is_file($profilePath)) {
                    $real = realpath($profilePath);
                    return $real !== false ? $real : $profilePath;
                }
            }

            $fromDb = (new ClientProfileService())->assetPath($clientId, $kind);
            if ($fromDb !== null) {
                $real = realpath($fromDb);
                return $real !== false ? $real : $fromDb;
            }
        }

        return self::fallbackPath($kind);
    }

    /**
     * Build a TCPDF-safe local image src (@path). Large originals are resized to JPEG.
     */
    private static function tcpdfImageSrc(string $path, int $printW, ?int $printH): ?string
    {
        if (!is_file($path) || !@getimagesize($path)) {
            return null;
        }

        // Print-quality pixel budget (3–4× CSS px).
        $maxW = max(160, $printW * 4);
        $maxH = max(120, ($printH ?? $printW) * 4);
        $size = (int) filesize($path);

        // Small enough and already modest dimensions → use file directly.
        $info = @getimagesize($path);
        $iw = (int) ($info[0] ?? 0);
        $ih = (int) ($info[1] ?? 0);
        if ($size > 0 && $size <= 1_500_000 && $iw > 0 && $iw <= $maxW * 2 && $ih <= $maxH * 2) {
            return $path;
        }

        $resized = self::resizedJpegPath($path, $maxW, $maxH);
        return $resized;
    }

    private static function resizedJpegPath(string $path, int $maxW, int $maxH): ?string
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
            // No GD — only embed if reasonably small.
            return ((int) filesize($path) <= 2_000_000) ? $path : null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $src = @imagecreatefromstring($raw);
        if ($src === false) {
            return null;
        }

        $ow = imagesx($src);
        $oh = imagesy($src);
        if ($ow < 1 || $oh < 1) {
            imagedestroy($src);
            return null;
        }

        $scale = min($maxW / $ow, $maxH / $oh, 1.0);
        $nw = max(1, (int) round($ow * $scale));
        $nh = max(1, (int) round($oh * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        if ($dst === false) {
            imagedestroy($src);
            return null;
        }
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $nw, $nh, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $ow, $oh);
        imagedestroy($src);

        $dir = storage_path('uploads/profile/_pdf_cache');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $out = $dir . DIRECTORY_SEPARATOR . md5($path . '|' . $maxW . 'x' . $maxH . '|' . filemtime($path)) . '.jpg';
        if (!is_file($out)) {
            $ok = @imagejpeg($dst, $out, 82);
            imagedestroy($dst);
            if (!$ok || !is_file($out)) {
                return null;
            }
        } else {
            imagedestroy($dst);
        }

        $real = realpath($out);
        return $real !== false ? $real : $out;
    }

    private static function fallbackPath(string $kind): ?string
    {
        $envKey = match ($kind) {
            'logo'    => 'INVOICE_LOGO_PATH',
            'stamp'   => 'INVOICE_STAMP_PATH',
            'scanner' => 'INVOICE_SCANNER_PATH',
            default   => '',
        };
        $candidates = [];
        if ($envKey !== '') {
            $envPath = trim((string) Env::get($envKey, ''));
            if ($envPath !== '') {
                $candidates[] = $envPath;
            }
        }
        if ($kind === 'logo') {
            $candidates = array_merge($candidates, [
                dirname(base_path()) . '/assets/logo.png',
                dirname(base_path()) . '/assets/paperless-logo.png',
                storage_path('logo.png'),
            ]);
        } else {
            $candidates = array_merge($candidates, [
                storage_path('invoices/' . $kind . '.png'),
                storage_path('invoices/' . $kind . '.jpg'),
                storage_path($kind . '.png'),
            ]);
        }

        foreach ($candidates as $path) {
            if (is_string($path) && $path !== '' && is_file($path)) {
                $real = realpath($path);
                return $real !== false ? $real : $path;
            }
        }

        return null;
    }
}
