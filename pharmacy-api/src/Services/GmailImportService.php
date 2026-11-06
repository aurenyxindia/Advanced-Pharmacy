<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Support\Env;

/**
 * Gmail OAuth + attachment list/download for purchase CSV/Excel import.
 * Refresh token stored per client under storage/gmail/ (no DB schema change).
 */
final class GmailImportService
{
    private const SCOPES = 'https://www.googleapis.com/auth/gmail.readonly';

    public function isConfigured(): bool
    {
        $id = (string) Env::get('GOOGLE_CLIENT_ID', '');
        $secret = (string) Env::get('GOOGLE_CLIENT_SECRET', '');
        return $id !== '' && $secret !== ''
            && !str_contains($id, 'YOUR_GOOGLE')
            && !str_contains($secret, 'YOUR_GOOGLE');
    }

    /** @return array{configured:bool, linked:bool, email:?string, message:?string} */
    public function status(int $clientId): array
    {
        if (!$this->isConfigured()) {
            return [
                'configured' => false,
                'linked'     => false,
                'email'      => null,
                'message'    => 'Set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in pharmacy-api/config/.env, then reconnect.',
            ];
        }
        $linked = $this->getRefreshToken($clientId) !== null;
        $email = null;
        if ($linked) {
            try {
                $access = $this->accessToken($clientId);
                $profile = $this->httpGetJson(
                    'https://gmail.googleapis.com/gmail/v1/users/me/profile',
                    ['Authorization: Bearer ' . $access]
                );
                $email = isset($profile['emailAddress']) ? (string) $profile['emailAddress'] : null;
            } catch (\Throwable) {
                $email = null;
            }
        }
        return [
            'configured' => true,
            'linked'     => $linked,
            'email'      => $email,
            'message'    => null,
        ];
    }

    public function authUrl(int $clientId): string
    {
        if (!$this->isConfigured()) {
            throw new HttpException('Gmail OAuth is not configured. Add GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET to .env.', 501);
        }
        $state = bin2hex(random_bytes(16));
        $this->writeState($clientId, $state);
        $params = http_build_query([
            'client_id'     => (string) Env::get('GOOGLE_CLIENT_ID'),
            'redirect_uri'  => $this->redirectUri(),
            'response_type' => 'code',
            'scope'         => self::SCOPES,
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'include_granted_scopes' => 'true',
            'state'         => $clientId . ':' . $state,
        ]);
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . $params;
    }

    /** Exchange code; returns HTML for popup close. */
    public function handleCallback(string $code, string $state): string
    {
        if (!$this->isConfigured()) {
            return $this->htmlPage('Gmail OAuth is not configured.');
        }
        [$clientId, $nonce] = array_pad(explode(':', $state, 2), 2, '');
        $clientId = (int) $clientId;
        if ($clientId <= 0 || $nonce === '' || $this->readState($clientId) !== $nonce) {
            // Soft-fail: still try if code present (retail pattern)
            if ($clientId <= 0) {
                return $this->htmlPage('Invalid OAuth state.');
            }
        }

        $tokenResponse = $this->httpPostForm('https://oauth2.googleapis.com/token', [
            'code'          => $code,
            'client_id'     => (string) Env::get('GOOGLE_CLIENT_ID'),
            'client_secret' => (string) Env::get('GOOGLE_CLIENT_SECRET'),
            'redirect_uri'  => $this->redirectUri(),
            'grant_type'    => 'authorization_code',
        ]);

        if (empty($tokenResponse['access_token'])) {
            return $this->htmlPage('OAuth token exchange failed.');
        }
        if (!empty($tokenResponse['refresh_token'])) {
            $this->setRefreshToken($clientId, (string) $tokenResponse['refresh_token']);
        } elseif ($this->getRefreshToken($clientId) === null) {
            return $this->htmlPage('No refresh token returned. Revoke app access in Google Account and try again with consent.');
        }

        return $this->htmlPage('Gmail connected. You can close this window.', true);
    }

    public function disconnect(int $clientId): void
    {
        $path = $this->tokenPath($clientId);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * List recent CSV/Excel attachments (last 30 days). TXT files are excluded.
     * Uses a broad Gmail search then filters by extension (filename:csv alone often misses real bills).
     *
     * @return list<array<string, mixed>>
     */
    public function listAttachments(int $clientId): array
    {
        $access = $this->accessToken($clientId);
        // Broad search: any attachment in 30d, then keep csv/xlsx/xls/tsv in collectParts.
        // Also try filename operators as a second pass for older-style queries.
        $queries = [
            'has:attachment newer_than:30d',
            'has:attachment (filename:csv OR filename:xlsx OR filename:xls OR filename:tsv) newer_than:30d',
        ];
        $seenMsg = [];
        $attachments = [];

        foreach ($queries as $query) {
            foreach ($this->gmailListMessages($access, $query) as $msg) {
                $messageId = (string) ($msg['id'] ?? '');
                if ($messageId === '' || isset($seenMsg[$messageId])) {
                    continue;
                }
                $seenMsg[$messageId] = true;
                $message = $this->gmailGetMessage($access, $messageId);
                if (!$message) {
                    continue;
                }
                $subject = '';
                $from = '';
                $date = '';
                foreach ($message['payload']['headers'] ?? [] as $h) {
                    $name = $h['name'] ?? '';
                    if ($name === 'Subject') {
                        $subject = (string) ($h['value'] ?? '');
                    } elseif ($name === 'From') {
                        $from = (string) ($h['value'] ?? '');
                    } elseif ($name === 'Date') {
                        $date = (string) ($h['value'] ?? '');
                    }
                }
                $parts = $message['payload']['parts'] ?? [$message['payload']];
                $this->collectParts($parts, $messageId, $subject, $from, $date, $attachments);
            }
        }

        return $attachments;
    }

    /** Download attachment bytes. */
    public function downloadAttachment(int $clientId, string $messageId, string $attachmentId): string
    {
        $access = $this->accessToken($clientId);
        $attachment = $this->httpGetJson(
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/' . rawurlencode($messageId)
            . '/attachments/' . rawurlencode($attachmentId),
            ['Authorization: Bearer ' . $access]
        );
        if (empty($attachment['data'])) {
            throw new HttpException('Attachment not found.', 404);
        }
        return $this->base64UrlDecode((string) $attachment['data']);
    }

    private function redirectUri(): string
    {
        $configured = trim((string) Env::get('GOOGLE_REDIRECT_URI', ''));
        if ($configured !== '') {
            return $configured;
        }
        $base = rtrim((string) Env::get('APP_URL', 'http://localhost:8080'), '/');
        return $base . '/api/import/gmail/callback';
    }

    private function accessToken(int $clientId): string
    {
        if (!$this->isConfigured()) {
            throw new HttpException('Gmail OAuth is not configured.', 501);
        }
        $refresh = $this->getRefreshToken($clientId);
        if ($refresh === null) {
            throw new HttpException('Gmail not linked. Connect Gmail first.', 401);
        }
        $tokenResponse = $this->httpPostForm('https://oauth2.googleapis.com/token', [
            'client_id'     => (string) Env::get('GOOGLE_CLIENT_ID'),
            'client_secret' => (string) Env::get('GOOGLE_CLIENT_SECRET'),
            'refresh_token' => $refresh,
            'grant_type'    => 'refresh_token',
        ]);
        if (empty($tokenResponse['access_token'])) {
            throw new HttpException('Failed to refresh Gmail access token. Reconnect Gmail.', 500);
        }
        return (string) $tokenResponse['access_token'];
    }

    /** @param list<array<string,mixed>> $parts @param list<array<string,mixed>> $attachments */
    private function collectParts(array $parts, string $messageId, string $subject, string $from, string $date, array &$attachments): void
    {
        foreach ($parts as $part) {
            if (!empty($part['parts']) && is_array($part['parts'])) {
                $this->collectParts($part['parts'], $messageId, $subject, $from, $date, $attachments);
            }
            $filename = trim((string) ($part['filename'] ?? ''));
            if ($filename === '') {
                // Some senders put the name only in Content-Disposition / Content-Type name=
                foreach ($part['headers'] ?? [] as $h) {
                    $hn = strtolower((string) ($h['name'] ?? ''));
                    $hv = (string) ($h['value'] ?? '');
                    if ($hn === 'content-disposition' || $hn === 'content-type') {
                        if (preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', $hv, $m)
                            || preg_match('/name="?([^";]+)"?/i', $hv, $m)) {
                            $filename = trim(rawurldecode(str_replace(["\r", "\n"], '', $m[1])));
                            break;
                        }
                    }
                }
            }
            if ($filename === '' || !preg_match('/\.(csv|tsv|xlsx|xls)$/i', $filename)) {
                continue;
            }
            $attachmentId = (string) ($part['body']['attachmentId'] ?? '');
            if ($attachmentId === '') {
                continue;
            }
            $attachments[] = [
                'messageId'    => $messageId,
                'attachmentId' => $attachmentId,
                'subject'      => $subject,
                'from'         => $from,
                'date'         => $date,
                'filename'     => $filename,
                'size'         => (int) ($part['body']['size'] ?? 0),
            ];
        }
    }

    /** @return list<array{id:string}> */
    private function gmailListMessages(string $accessToken, string $query): array
    {
        $all = [];
        $pageToken = null;
        for ($page = 0; $page < 3; $page++) {
            $url = 'https://gmail.googleapis.com/gmail/v1/users/me/messages?q='
                . rawurlencode($query) . '&maxResults=100';
            if ($pageToken) {
                $url .= '&pageToken=' . rawurlencode($pageToken);
            }
            $res = $this->httpGetJson($url, ['Authorization: Bearer ' . $accessToken]);
            $batch = is_array($res['messages'] ?? null) ? $res['messages'] : [];
            foreach ($batch as $m) {
                $all[] = $m;
            }
            $pageToken = isset($res['nextPageToken']) ? (string) $res['nextPageToken'] : null;
            if ($pageToken === null || $pageToken === '') {
                break;
            }
        }
        return $all;
    }

    /** @return array<string,mixed>|null */
    private function gmailGetMessage(string $accessToken, string $messageId): ?array
    {
        $url = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/' . rawurlencode($messageId) . '?format=full';
        return $this->httpGetJson($url, ['Authorization: Bearer ' . $accessToken]);
    }

    /** @param array<string,string> $data @return array<string,mixed>|null */
    private function httpPostForm(string $url, array $data): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS     => http_build_query($data),
        ]);
        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response === false || $code < 200 || $code >= 300) {
            return null;
        }
        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @param list<string> $headers @return array<string,mixed>|null */
    private function httpGetJson(string $url, array $headers = []): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response === false) {
            return null;
        }
        $decoded = json_decode($response, true);
        if ($code < 200 || $code >= 300) {
            $msg = is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : '';
            if ($code === 403 && (str_contains($msg, 'Gmail API') || str_contains($msg, 'gmail.googleapis.com'))) {
                throw new HttpException(
                    'Gmail API is not enabled for this Google Cloud project. Open Google Cloud Console → APIs & Services → Library → enable “Gmail API”, wait a few minutes, then click Refresh.',
                    503
                );
            }
            if ($code === 401 || $code === 403) {
                throw new HttpException(
                    'Gmail access was denied. Disconnect and Connect Gmail again, and ensure this Google account is a Test user (or the app is verified).',
                    401
                );
            }
            return null;
        }
        return is_array($decoded) ? $decoded : null;
    }

    private function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }

    private function tokenDir(): string
    {
        $dir = storage_path('gmail');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    private function tokenPath(int $clientId): string
    {
        return $this->tokenDir() . '/client_' . $clientId . '.json';
    }

    private function getRefreshToken(int $clientId): ?string
    {
        $path = $this->tokenPath($clientId);
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        $token = is_array($data) ? ($data['refresh_token'] ?? null) : null;
        return is_string($token) && $token !== '' ? $token : null;
    }

    private function setRefreshToken(int $clientId, string $token): void
    {
        file_put_contents($this->tokenPath($clientId), json_encode([
            'refresh_token' => $token,
            'updated_at'    => date('c'),
        ], JSON_PRETTY_PRINT));
    }

    private function writeState(int $clientId, string $state): void
    {
        file_put_contents($this->tokenDir() . '/state_' . $clientId . '.txt', $state);
    }

    private function readState(int $clientId): ?string
    {
        $path = $this->tokenDir() . '/state_' . $clientId . '.txt';
        if (!is_file($path)) {
            return null;
        }
        $v = trim((string) file_get_contents($path));
        return $v !== '' ? $v : null;
    }

    private function htmlPage(string $message, bool $success = false): string
    {
        $payload = json_encode(['type' => 'gmail_oauth_done', 'success' => $success]);
        return '<!doctype html><html><head><meta charset="utf-8"><title>Gmail</title></head><body>'
            . '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<script>try{if(window.opener){window.opener.postMessage(' . $payload . ', "*");window.close();}}catch(e){}</script>'
            . '</body></html>';
    }
}
