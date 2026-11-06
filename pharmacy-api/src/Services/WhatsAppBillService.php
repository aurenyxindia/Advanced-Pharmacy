<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Database\Connection;
use App\Models\Sale;
use App\Support\Env;
use App\Support\Logger;

/**
 * Send sales bill on WhatsApp via Unique Digital Outreach — same as Retail:
 * template medicin_bill, campaign medicin-bill, header = invoice PDF document.
 */
final class WhatsAppBillService
{
    public function sendSaleBill(int $saleId, int $clientId, ?string $overridePhone = null): array
    {
        $sale = (new Sale())->withItems($saleId);
        if (!$sale || (int) $sale['client_id'] !== $clientId) {
            throw HttpException::notFound('Sale not found.');
        }

        $contact = trim((string) ($sale['contact_no'] ?? ''));
        if ($overridePhone !== null && trim($overridePhone) !== '') {
            $contact = trim($overridePhone);
        }
        $to = $this->normalizePhone($contact);
        if ($to === '') {
            throw HttpException::validation([
                'contact_no' => ['Customer WhatsApp/mobile number is not available on this bill.'],
            ]);
        }

        $branch = Connection::ho()->query('SELECT * FROM branches WHERE id = ' . (int) $sale['branch_id'])->fetch() ?: [];
        $client = Connection::ho()->query('SELECT * FROM clients WHERE id = ' . (int) $sale['client_id'])->fetch() ?: [];

        $pharmacyName = trim((string) ($client['business_name'] ?? $branch['name'] ?? 'Pharmacy'));
        if ($pharmacyName === '') {
            $pharmacyName = 'Pharmacy';
        }
        $pharmacyPhone = trim((string) ($branch['phone'] ?? $client['phone'] ?? ''));
        $customerName = trim((string) ($sale['patient_name'] ?: ($sale['customer_name'] ?? 'Customer')));
        if ($customerName === '') {
            $customerName = 'Customer';
        }

        $invoiceNo = (string) ($sale['invoice_no'] ?? ('sale-' . $saleId));
        $fileBase = preg_replace('/[^A-Za-z0-9_-]/', '_', $invoiceNo) ?: ('sale_' . $saleId);

        $pdfBinary = (new InvoiceService())->forSale($sale);
        $pdfPath = $this->writePublicPdf($fileBase, $pdfBinary);
        $pdfUrl = $this->publicPdfUrl($fileBase);

        $apiKey = (string) Env::get('WHATSAPP_API_KEY', '');
        $from = (string) Env::get('WHATSAPP_FROM', '+15558342917');
        $apiUrl = (string) Env::get('WHATSAPP_API_URL', 'https://api.uniquedigitaloutreach.com/v1/whatsapp');
        $campaign = (string) Env::get('WHATSAPP_CAMPAIGN', 'medicin-bill');
        $template = (string) Env::get('WHATSAPP_TEMPLATE', 'medicin_bill');

        if ($apiKey === '') {
            throw HttpException::validation([
                'whatsapp' => ['WhatsApp is not configured (WHATSAPP_API_KEY missing).'],
            ]);
        }

        $payload = [
            'from' => $from,
            'campaignName' => $campaign,
            'to' => $to,
            'templateName' => $template,
            'components' => [
                'body' => [
                    'params' => [$customerName, $pharmacyName, $pharmacyPhone !== '' ? $pharmacyPhone : $pharmacyName],
                ],
                'header' => [
                    'type' => 'document',
                    'document' => [
                        'link' => $pdfUrl,
                        'filename' => 'Bill_' . $fileBase . '.pdf',
                    ],
                ],
            ],
            'type' => 'template',
        ];

        $result = $this->postApi($apiUrl, $apiKey, $payload, $invoiceNo);

        if (!$result['ok']) {
            throw new HttpException(
                $result['error'] ?? 'Failed to send WhatsApp bill. Please try again or check the customer mobile number.',
                502
            );
        }

        return [
            'to' => $to,
            'invoice_no' => $invoiceNo,
            'pdf_url' => $pdfUrl,
            'pdf_path' => $pdfPath,
            'message' => 'WhatsApp bill sent to customer number ' . $contact . '.',
        ];
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if (strlen($digits) === 10) {
            $digits = '91' . $digits;
        } elseif (str_starts_with($digits, '91') === false && strlen($digits) > 10) {
            $digits = '91' . substr($digits, -10);
        }
        if (strlen($digits) < 12) {
            return '';
        }
        return '+' . $digits;
    }

    private function pdfDir(): string
    {
        $configured = trim((string) Env::get('WHATSAPP_PDF_DIR', ''));
        if ($configured !== '') {
            $dir = $configured;
        } else {
            $publicBase = (string) Env::get('WHATSAPP_PDF_PUBLIC_BASE', '');
            if (str_contains($publicBase, '/storage/invoices')) {
                $dir = storage_path('invoices');
            } else {
                $sibling = dirname(base_path()) . DIRECTORY_SEPARATOR . 'invoices';
                $dir = is_dir(dirname($sibling)) ? $sibling : storage_path('invoices');
            }
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    private function writePublicPdf(string $fileBase, string $binary): string
    {
        $path = rtrim($this->pdfDir(), '/\\') . DIRECTORY_SEPARATOR . $fileBase . '.pdf';
        if (file_put_contents($path, $binary) === false) {
            throw new HttpException('Could not write invoice PDF for WhatsApp.', 500);
        }
        return $path;
    }

    private function publicPdfUrl(string $fileBase): string
    {
        $base = rtrim((string) Env::get('WHATSAPP_PDF_PUBLIC_BASE', ''), '/');
        if ($base === '') {
            $app = rtrim((string) Env::get('APP_URL', ''), '/');
            if ($app === '' && !empty($_SERVER['HTTP_HOST'])) {
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $app = $scheme . '://' . $_SERVER['HTTP_HOST'];
            }
            $base = ($app !== '' ? $app : 'https://demo.paperlesspharmacy.in') . '/invoices';
        }
        return $base . '/' . rawurlencode($fileBase) . '.pdf?v=' . time();
    }

    /** @return array{ok:bool, error?:string, http?:int} */
    private function postApi(string $url, string $apiKey, array $payload, string $invoiceNo): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'apikey: ' . $apiKey,
            'Content-Type: application/json',
        ]);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
 
        Logger::info('whatsapp_bill', [
            'invoice' => $invoiceNo,
            'http' => $httpCode,
            'to' => $payload['to'] ?? null,
            'curl_error' => $curlError ?: null,
            'response' => is_string($response) ? substr($response, 0, 400) : null,
        ]);

        if ($curlError) {
            return ['ok' => false, 'error' => 'WhatsApp API connection error.', 'http' => $httpCode];
        }

        $decoded = json_decode((string) $response, true);
        if (is_array($decoded) && !empty($decoded['error']) && $decoded['error'] !== false) {
            $msg = !empty($decoded['message']) ? (string) $decoded['message'] : 'WhatsApp API error.';
            return ['ok' => false, 'error' => $msg, 'http' => $httpCode];
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return ['ok' => true, 'http' => $httpCode];
        }

        $msg = is_array($decoded) && !empty($decoded['message'])
            ? (string) $decoded['message']
            : 'Failed to send WhatsApp bill.';
        return ['ok' => false, 'error' => $msg, 'http' => $httpCode];
    }
}
