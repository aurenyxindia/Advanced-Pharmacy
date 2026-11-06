<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuraGharTakUserService;
use App\Support\Env;

/**
 * AuraGharTak app → Retail Paperless Pharmacy (emr_stock_api.php).
 * Keeps RETAIL_API_KEY on the server; responses are Retail's JSON passed through unchanged.
 */
final class RetailProxyController extends Controller
{
    private const CLIENTS_CACHE_SECONDS = 300;

    public function clients(Request $request): Response
    {
        $cache = dirname(__DIR__, 2) . '/storage/cache/retail_clients.json';
        if (is_file($cache) && (time() - (int) filemtime($cache)) < self::CLIENTS_CACHE_SECONDS) {
            $cached = (string) @file_get_contents($cache);
            if ($cached !== '') {
                return $this->passThrough($cached, 200);
            }
        }
        [$status, $body] = $this->call('GET', ['type' => 'listClients']);
        if ($status === 200 && str_contains($body, '"success"')) {
            $dir = dirname($cache);
            if (is_dir($dir) || @mkdir($dir, 0775, true)) {
                @file_put_contents($cache, $body, LOCK_EX);
            }
        }
        return $this->passThrough($body, $status);
    }

    public function stock(Request $request): Response
    {
        $clientNo = trim((string) $request->query('client_no', ''));
        if ($clientNo === '') {
            throw HttpException::validation(['client_no' => ['Pharmacy code required.']]);
        }
        $params = ['type' => 'getStock', 'client_no' => $clientNo];
        foreach (['q', 'only_positive', 'paginate', 'limit', 'page'] as $key) {
            $value = $request->query($key);
            if ($value !== null && $value !== '') {
                $params[$key] = (string) $value;
            }
        }
        $params['limit'] = (string) min(200, max(1, (int) ($params['limit'] ?? 120)));
        [$status, $body] = $this->call('GET', $params);
        return $this->passThrough($body, $status);
    }

    public function placeOrder(Request $request): Response
    {
        if ((new AuraGharTakUserService())->phoneForToken($request->bearerToken()) === null) {
            throw HttpException::unauthorized('Log in to the AuraGharTak app to place an order.');
        }
        $payload = $request->all();
        if (trim((string) ($payload['client_no'] ?? '')) === '' || empty($payload['items'])) {
            throw HttpException::validation(['items' => ['Pharmacy and items are required.']]);
        }
        [$status, $body] = $this->call('POST', ['type' => 'placeRetailOrder'], $payload);
        return $this->passThrough($body, $status);
    }

    public function ordersByPhone(Request $request): Response
    {
        $digits = preg_replace('/\D+/', '', (string) $request->query('phone', '')) ?? '';
        if (strlen($digits) < 10) {
            throw HttpException::validation(['phone' => ['Enter a valid 10-digit mobile number.']]);
        }
        $suffix = substr($digits, -10);
        $viewer = (new AuraGharTakUserService())->phoneForToken($request->bearerToken());
        if ($viewer === null || $viewer !== $suffix) {
            throw HttpException::unauthorized('Log in to the AuraGharTak app with this phone number to see its orders.');
        }
        [$status, $body] = $this->call('GET', ['type' => 'listOrdersByPhone', 'phone' => $suffix]);
        return $this->passThrough($body, $status);
    }

    /**
     * @param array<string,string> $query
     * @param array<string,mixed>|null $json
     * @return array{0:int,1:string}
     */
    private function call(string $method, array $query, ?array $json = null): array
    {
        $key = trim((string) Env::get('RETAIL_API_KEY', ''));
        if ($key === '') {
            throw new HttpException('Retail pharmacies are not configured on this server.', 503);
        }
        $base = rtrim((string) Env::get('RETAIL_API_BASE', 'https://paperlesspharmacy.in/serverdev'), '/');
        $url = $base . '/emr_stock_api.php?' . http_build_query($query);

        $ch = curl_init($url);
        $headers = ['Accept: application/json', 'X-API-Key: ' . $key];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
        ]);
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json ?? [], JSON_UNESCAPED_UNICODE));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $body === '') {
            error_log('Retail proxy ' . ($query['type'] ?? '') . ' failed: ' . $error);
            throw new HttpException('Retail pharmacy server is not reachable right now.', 502);
        }
        return [$status > 0 ? $status : 502, (string) $body];
    }

    private function passThrough(string $body, int $status): Response
    {
        return Response::raw($body, 'application/json; charset=utf-8', $status >= 200 && $status < 600 ? $status : 502);
    }
}
