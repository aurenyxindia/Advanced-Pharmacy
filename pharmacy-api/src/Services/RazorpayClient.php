<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Support\Env;

/**
 * Minimal Razorpay Orders API client (secret stays server-side).
 */
final class RazorpayClient
{
    public function keyId(): string
    {
        return trim((string) Env::get('RAZORPAY_KEY_ID', ''));
    }

    public function keySecret(): string
    {
        return trim((string) Env::get('RAZORPAY_KEY_SECRET', ''));
    }

    public function webhookSecret(): string
    {
        return trim((string) Env::get('RAZORPAY_WEBHOOK_SECRET', ''));
    }

    public function isConfigured(): bool
    {
        return $this->keyId() !== '' && $this->keySecret() !== '';
    }

    public function assertConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new HttpException(
                'Payment gateway is not configured. Please contact support.',
                503
            );
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function createOrder(array $payload): array
    {
        return $this->request('POST', 'https://api.razorpay.com/v1/orders', $payload);
    }

    /** @return array<string,mixed> */
    public function fetchPayment(string $paymentId): array
    {
        $paymentId = trim($paymentId);
        if ($paymentId === '') {
            throw HttpException::validation(['payment_id' => ['Payment id is required.']]);
        }
        return $this->request('GET', 'https://api.razorpay.com/v1/payments/' . rawurlencode($paymentId));
    }

    public function verifyPaymentSignature(string $orderId, string $paymentId, string $signature): bool
    {
        $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, $this->keySecret());
        return hash_equals($expected, $signature);
    }

    public function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        $secret = $this->webhookSecret();
        if ($secret === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, $signature);
    }

    /**
     * @param array<string,mixed>|null $payload
     * @return array<string,mixed>
     */
    private function request(string $method, string $url, ?array $payload = null): array
    {
        $this->assertConfigured();

        $ch = curl_init($url);
        if ($ch === false) {
            throw new HttpException('Unable to reach payment gateway.', 502);
        }

        $headers = ['Content-Type: application/json'];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERPWD => $this->keyId() . ':' . $this->keySecret(),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        }

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlError) {
            throw new HttpException('Payment gateway connection error.', 502);
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            throw new HttpException('Invalid response from payment gateway.', 502);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = (string) ($decoded['error']['description'] ?? $decoded['error']['code'] ?? 'Payment gateway error.');
            throw new HttpException($msg, 400);
        }

        return $decoded;
    }
}
