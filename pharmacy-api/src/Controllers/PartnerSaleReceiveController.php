<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\PartnerSaleReceiveService;
use Throwable;

/**
 * Legacy-compatible Paperless receive endpoint (status/message JSON shape).
 * Also used by public/server/partner_sale_receive.php.
 */
final class PartnerSaleReceiveController extends Controller
{
    private PartnerSaleReceiveService $service;

    public function __construct()
    {
        $this->service = new PartnerSaleReceiveService();
    }

    public function handle(Request $request): Response
    {
        if (strtoupper($request->method()) === 'OPTIONS') {
            return Response::raw('', 'application/json; charset=utf-8', 204, [
                'Access-Control-Allow-Origin'  => '*',
                'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type, X-API-Key, X-Partner, Authorization',
            ]);
        }

        try {
            $type = strtolower(trim((string) $request->query('type', '')));
            if (strtoupper($request->method()) === 'GET' || $type === 'ping') {
                return $this->partnerJson($this->service->ping());
            }

            $apiKey = trim((string) $request->header('x-api-key', ''));
            $this->service->assertApiKey($apiKey !== '' ? $apiKey : null);

            if ($type === '' || $type === 'receivesale') {
                $body = $request->all();
                if (!is_array($body)) {
                    $body = [];
                }
                return $this->partnerJson($this->service->receiveSale($body));
            }

            return $this->partnerJson(['status' => 'error', 'message' => 'Unknown type.'], 400);
        } catch (HttpException $e) {
            $status = $e->statusCode();
            $payload = ['status' => 'error', 'message' => $e->getMessage()];
            if ($status === 401) {
                $payload['message'] = 'Unauthorized';
            }
            return $this->partnerJson($payload, $status);
        } catch (Throwable $e) {
            return $this->partnerJson([
                'status'  => 'error',
                'message' => 'Import failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /** Authenticated UI — list inward bills for current client. */
    public function index(Request $request): Response
    {
        $clientId = (int) $request->clientId();
        $status = trim((string) $request->query('status', 'pending'));
        return $this->success([
            'rows' => $this->service->listForClient($clientId, $status),
        ], 'Inward stock list.');
    }

    public function show(Request $request): Response
    {
        $clientId = (int) $request->clientId();
        $id = (int) $request->attribute('id');
        return $this->success($this->service->show($clientId, $id), 'Inward stock bill.');
    }

    public function approve(Request $request): Response
    {
        $clientId = (int) $request->clientId();
        $branchId = (int) ($request->branchId() ?? 0);
        $userId = isset($request->user()['id']) ? (int) $request->user()['id'] : null;
        $id = (int) $request->attribute('id');
        return $this->success(
            $this->service->approve($clientId, $branchId, $userId, $id),
            'Inward stock approved.'
        );
    }

    public function reject(Request $request): Response
    {
        $clientId = (int) $request->clientId();
        $userId = isset($request->user()['id']) ? (int) $request->user()['id'] : null;
        $id = (int) $request->attribute('id');
        $reason = trim((string) ($request->input('reason') ?? ''));
        return $this->success(
            $this->service->reject($clientId, $userId, $id, $reason),
            'Inward stock rejected.'
        );
    }

    /** @param array<string,mixed> $payload */
    private function partnerJson(array $payload, int $status = 200): Response
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return Response::raw(
            $body !== false ? $body : '{"status":"error","message":"Encode failed"}',
            'application/json; charset=utf-8',
            $status,
            ['Access-Control-Allow-Origin' => '*']
        );
    }
}
