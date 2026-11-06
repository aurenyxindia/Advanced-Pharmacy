<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Env;

/**
 * AuraGharTak WhatsApp — Retail parity (Unique Digital Outreach):
 * stock link: template auraghartak; delivery bill: medicin_bill; delivered: tablet_reminder.
 */
final class AuraGharTakWhatsAppService
{
    public function sendStockCatalogueLink(
        string $phone,
        string $patientName,
        string $pharmacyName,
        string $shopUrl,
        string $pharmacyContact
    ): array {
        $to = $this->normalizePhone($phone);
        if ($to === '') {
            return ['ok' => false, 'error' => 'invalid_phone'];
        }
        $patientName = trim($patientName) !== '' ? trim($patientName) : 'Customer';
        $pharmacyName = trim($pharmacyName) !== '' ? trim($pharmacyName) : 'Pharmacy';
        $pharmacyContact = trim($pharmacyContact) !== '' ? trim($pharmacyContact) : 'your pharmacy';

        $payload = [
            'from' => (string) Env::get('WHATSAPP_FROM', '+15558342917'),
            'campaignName' => (string) Env::get('WHATSAPP_AGT_CAMPAIGN', 'auraghartak'),
            'to' => $to,
            'templateName' => (string) Env::get('WHATSAPP_AGT_TEMPLATE', 'auraghartak'),
            'components' => [
                'body' => [
                    'params' => [
                        $patientName,
                        $pharmacyName,
                        $shopUrl,
                        $pharmacyContact,
                        $pharmacyName,
                    ],
                ],
            ],
            'type' => 'template',
        ];

        return $this->post($payload);
    }

    /** @return array{ok:bool,error?:string} */
    public function sendDeliveryBill(int $saleId, int $clientId, string $orderLabel, ?string $patientPhone = null): array
    {
        try {
            (new WhatsAppBillService())->sendSaleBill($saleId, $clientId, $patientPhone);
            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function sendDeliveredNotice(
        string $phone,
        string $patientName,
        string $pharmacyName,
        string $message
    ): array {
        $to = $this->normalizePhone($phone);
        if ($to === '') {
            return ['ok' => false, 'error' => 'invalid_phone'];
        }
        $headerName = trim($patientName) !== '' ? trim($patientName) : 'Customer';
        if (strlen($headerName) > 60) {
            $headerName = substr($headerName, 0, 60);
        }
        $pharmacyName = trim($pharmacyName) !== '' ? trim($pharmacyName) : 'PaperLess Pharmacy';
        $message = trim($message) !== '' ? trim($message) : 'Your order has been delivered. Thank you.';

        $payload = [
            'from' => (string) Env::get('WHATSAPP_FROM', '+15558342917'),
            'campaignName' => (string) Env::get('WHATSAPP_AGT_DELIVERED_CAMPAIGN', 'aura_ghar_tak_delivered'),
            'to' => $to,
            'templateName' => (string) Env::get('WHATSAPP_AGT_DELIVERED_TEMPLATE', 'tablet_reminder'),
            'components' => [
                'body' => ['params' => [$pharmacyName, $message]],
                'header' => ['type' => 'text', 'text' => $headerName],
            ],
            'type' => 'template',
        ];

        return $this->post($payload);
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if (strlen($digits) === 10) {
            $digits = '91' . $digits;
        } elseif (!str_starts_with($digits, '91') && strlen($digits) > 10) {
            $digits = '91' . substr($digits, -10);
        }
        if (strlen($digits) < 12) {
            return '';
        }
        return '+' . $digits;
    }

    /** @param array<string,mixed> $payload */
    private function post(array $payload): array
    {
        $apiKey = (string) Env::get('WHATSAPP_API_KEY', '');
        if ($apiKey === '') {
            return ['ok' => false, 'error' => 'WhatsApp not configured', 'skipped' => true];
        }
        $apiUrl = (string) Env::get('WHATSAPP_API_URL', 'https://api.uniquedigitaloutreach.com/v1/whatsapp');

        $ch = curl_init($apiUrl);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'curl_init failed'];
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'apikey: ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 45,
        ]);
        $body = curl_exec($ch);
        $curlError = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlError !== '') {
            return ['ok' => false, 'error' => 'WhatsApp API connection error.', 'http' => $http];
        }
        if ($body === false) {
            return ['ok' => false, 'error' => 'WhatsApp API connection error.', 'http' => $http];
        }
        $decoded = json_decode((string) $body, true);
        if (is_array($decoded) && !empty($decoded['error']) && $decoded['error'] !== false) {
            $msg = !empty($decoded['message']) ? (string) $decoded['message'] : 'WhatsApp API error.';
            return ['ok' => false, 'error' => $msg, 'http' => $http];
        }
        if ($http >= 200 && $http < 300) {
            return ['ok' => true, 'response' => $decoded];
        }
        $msg = is_array($decoded) && !empty($decoded['message'])
            ? (string) $decoded['message']
            : 'WhatsApp API error.';
        return ['ok' => false, 'error' => $msg, 'http' => $http];
    }
}
