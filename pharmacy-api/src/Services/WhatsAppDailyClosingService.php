<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Support\Env;
use App\Support\Logger;

/**
 * Retail WhatsApp template daily_closing on day-end save (Unique Digital Outreach).
 * Same provider / template / campaign as Retail dailyclosing.php.
 * Body {{1}}–{{7}}: Total Sales, Sales Cash, UPI, Sales Card, Customer Payment, Debit, Welcome(+Return/Net).
 * Header text: Daily Closing – DD-MM-YYYY
 *
 * Recipients: client phone, My Profile alternate_phone, then branch phone (unique).
 */
final class WhatsAppDailyClosingService
{
    /**
     * @param array<string,mixed> $metrics from DailyClosingMetricsService::compute
     * @return array{ok:bool,skipped?:string,to?:string,sent?:int,failed?:int,error?:string,http?:int}
     */
    public function send(int $clientId, string $closingDate, array $metrics, ?int $branchId = null): array
    {
        (new ClientProfileService())->ensureProfileColumns();

        $client = Connection::ho()->prepare(
            'SELECT business_name, owner_name, phone, alternate_phone FROM clients WHERE id = :id LIMIT 1'
        );
        $client->execute(['id' => $clientId]);
        $row = $client->fetch();
        if (!$row) {
            return ['ok' => false, 'skipped' => 'no_client'];
        }

        $candidates = [
            trim((string) ($row['phone'] ?? '')),
            trim((string) ($row['alternate_phone'] ?? '')),
        ];
        if ($branchId !== null && $branchId > 0) {
            $b = Connection::ho()->prepare('SELECT phone FROM branches WHERE id = :id AND client_id = :c LIMIT 1');
            $b->execute(['id' => $branchId, 'c' => $clientId]);
            $br = $b->fetch();
            $candidates[] = trim((string) ($br['phone'] ?? ''));
        }

        $recipients = [];
        foreach ($candidates as $raw) {
            $to = $this->normalizePhone($raw);
            if ($to !== '' && !in_array($to, $recipients, true)) {
                $recipients[] = $to;
            }
        }

        if ($recipients === []) {
            return ['ok' => false, 'skipped' => 'no_phone'];
        }

        $medical = trim((string) ($row['business_name'] ?? ''));
        if ($medical === '') {
            $medical = 'PaperLess Pharmacy';
        }
        $owner = trim((string) ($row['owner_name'] ?? ''));
        $welcome = $medical . ($owner !== '' ? ' – ' . $owner : '');

        $apiKey = trim((string) Env::get('WHATSAPP_API_KEY', ''));
        $from = trim((string) Env::get('WHATSAPP_FROM', '+15558342917'));
        $apiUrl = trim((string) Env::get(
            'WHATSAPP_API_URL',
            'https://api.uniquedigitaloutreach.com/v1/whatsapp'
        ));
        $campaign = trim((string) Env::get('WHATSAPP_DC_CAMPAIGN', 'daily-closing'));
        $template = trim((string) Env::get('WHATSAPP_DC_TEMPLATE', 'daily_closing'));

        if ($apiKey === '' || $from === '') {
            return ['ok' => false, 'skipped' => 'not_configured'];
        }

        $fmt = static fn ($n) => number_format((float) $n, 2, '.', '');
        $sales = $metrics['sales'] ?? [];
        $retailReturn = (float) ($sales['return'] ?? 0);
        $netSales = (float) ($sales['net'] ?? max(0, (float) ($sales['total'] ?? 0) - $retailReturn));
        $welcomeDetail = $welcome . ' | Return: ' . $fmt($retailReturn) . ' | Net Sales: ' . $fmt($netSales);

        $d = \DateTime::createFromFormat('Y-m-d', $closingDate);
        $dateLabel = $d ? $d->format('d-m-Y') : $closingDate;
        $headerText = 'Daily Closing – ' . $dateLabel;
        if (function_exists('mb_strlen') && mb_strlen($headerText, 'UTF-8') > 60) {
            $headerText = mb_substr($headerText, 0, 60, 'UTF-8');
        } elseif (strlen($headerText) > 60) {
            $headerText = substr($headerText, 0, 60);
        }

        $components = [
            'body' => [
                'params' => [
                    $fmt($sales['total'] ?? 0),
                    $fmt($sales['cash'] ?? 0),
                    $fmt($sales['upi'] ?? 0),
                    $fmt($sales['card'] ?? 0),
                    $fmt($metrics['customer_payment']['total'] ?? 0),
                    $fmt($metrics['debit_payment']['total'] ?? 0),
                    $welcomeDetail,
                ],
            ],
            'header' => [
                'type' => 'text',
                'text' => $headerText,
            ],
        ];

        $sentTo = [];
        $errors = [];
        $lastHttp = 0;
        foreach ($recipients as $to) {
            $payload = [
                'from' => $from,
                'campaignName' => $campaign !== '' ? $campaign : 'daily-closing',
                'to' => $to,
                'templateName' => $template !== '' ? $template : 'daily_closing',
                'components' => $components,
                'type' => 'template',
            ];

            $result = $this->postWhatsApp(
                $apiUrl !== '' ? $apiUrl : 'https://api.uniquedigitaloutreach.com/v1/whatsapp',
                $apiKey,
                $payload
            );
            $lastHttp = $result['http'];
            $this->appendDebugLog($clientId, $closingDate, $to, $result['http'], $result['body'], $result['curl_error']);

            if ($result['ok']) {
                $sentTo[] = $to;
            } else {
                $errors[] = $to . ': ' . $result['error'];
                Logger::warning('daily_closing_whatsapp_fail', [
                    'http' => $result['http'],
                    'to' => $to,
                    'body' => $result['body'],
                ]);
            }
        }

        if ($sentTo === []) {
            return [
                'ok' => false,
                'error' => $errors[0] ?? 'WhatsApp API error.',
                'http' => $lastHttp,
                'to' => implode(', ', $recipients),
                'sent' => 0,
                'failed' => count($errors),
            ];
        }

        return [
            'ok' => true,
            'to' => implode(', ', $sentTo),
            'http' => $lastHttp,
            'sent' => count($sentTo),
            'failed' => count($errors),
            'error' => $errors !== [] ? implode('; ', $errors) : null,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{ok:bool,http:int,body:string,curl_error:string,error:string}
     */
    private function postWhatsApp(string $apiUrl, string $apiKey, array $payload): array
    {
        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'apikey: ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT => 45,
        ]);
        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            Logger::warning('daily_closing_whatsapp_curl', ['error' => $err]);
            return [
                'ok' => false,
                'http' => $http,
                'body' => '',
                'curl_error' => $err,
                'error' => $err !== '' ? $err : 'WhatsApp API connection error.',
            ];
        }

        $decoded = json_decode((string) $body, true);
        if ($http >= 200 && $http < 300) {
            return [
                'ok' => true,
                'http' => $http,
                'body' => (string) $body,
                'curl_error' => '',
                'error' => '',
            ];
        }

        $msg = is_array($decoded) && !empty($decoded['message'])
            ? (string) $decoded['message']
            : substr((string) $body, 0, 200);
        if ($msg === '') {
            $msg = 'WhatsApp API error.';
        }

        return [
            'ok' => false,
            'http' => $http,
            'body' => (string) $body,
            'curl_error' => '',
            'error' => $msg,
        ];
    }

    private function appendDebugLog(
        int $clientId,
        string $closingDate,
        string $to,
        int $http,
        string $response,
        string $curlError
    ): void {
        $dir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = date('Y-m-d H:i:s')
            . " | client={$clientId} | date={$closingDate} | To: {$to} | HTTP: {$http} | Response: "
            . substr($response, 0, 500)
            . ($curlError !== '' ? " | cURL Error: {$curlError}" : '')
            . "\n";
        @file_put_contents($dir . '/whatsapp_daily_closing_debug.log', $line, FILE_APPEND);
    }

    private function normalizePhone(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return '';
        }
        if (strlen($digits) === 10) {
            $digits = '91' . $digits;
        } elseif (!str_starts_with($digits, '91') && strlen($digits) > 10) {
            $digits = '91' . substr($digits, -10);
        }
        return '+' . $digits;
    }
}
