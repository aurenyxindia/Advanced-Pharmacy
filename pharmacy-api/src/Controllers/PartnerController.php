<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\PartnerStockService;

/**
 * Partner integration API for EyeFlow EMR (and similar).
 * Auth: PartnerApiMiddleware (API key → fixed client_id).
 */
final class PartnerController extends Controller
{
    private PartnerStockService $service;

    public function __construct()
    {
        $this->service = new PartnerStockService();
    }

    /** Verify key + return mapped pharmacy client. */
    public function me(Request $request): Response
    {
        return $this->success($this->service->clientInfo($this->partner($request)), 'Partner session.');
    }

    /** Active branches for the mapped client. */
    public function branches(Request $request): Response
    {
        return $this->success($this->service->branches($this->partner($request)), 'Branches.');
    }

    /**
     * Batch-level stock (product + batch + expiry + qty).
     * Query: branch_id?, q?, in_stock_only=1, include_expired=0, limit=500, api_key?
     */
    public function stock(Request $request): Response
    {
        $data = $this->service->stockBatches($this->partner($request), [
            'branch_id'       => $request->query('branch_id', 0),
            'q'               => $request->query('q', ''),
            'in_stock_only'   => $request->query('in_stock_only', '1'),
            'include_expired' => $request->query('include_expired', '0'),
            'limit'           => $request->query('limit', 500),
        ]);

        return $this->success($data, 'Stock (batches).');
    }

    /**
     * Full client stock as browser-openable JSON (all branches, all in-stock batches).
     * Open: /api/partner/v1/stock/all?api_key=YOUR_KEY
     */
    public function stockAll(Request $request): Response
    {
        $partner = $this->partner($request);
        $info = $this->service->clientInfo($partner);
        $batches = $this->service->stockBatches($partner, [
            'branch_id'       => $request->query('branch_id', 0),
            'q'               => $request->query('q', ''),
            'in_stock_only'   => $request->query('in_stock_only', '1'),
            'include_expired' => $request->query('include_expired', '0'),
            'limit'           => $request->query('limit', 20000),
        ]);

        $payload = [
            'success' => true,
            'message' => 'Full stock for mapped pharmacy client.',
            'data'    => [
                'partner'       => $info['partner'],
                'client_id'     => $info['client_id'],
                'business_name' => $info['business_name'],
                'generated_at'  => date('c'),
                'qty_unit'      => $batches['qty_unit'],
                'count'         => $batches['count'],
                'stock'         => $batches['rows'],
            ],
        ];

        // Pretty JSON so it is readable when opened directly in a browser.
        $body = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return Response::raw(
            $body !== false ? $body : '{}',
            'application/json; charset=utf-8'
        );
    }

    /**
     * Product-level sellable stock (aggregated).
     * Query: branch_id?, q?, in_stock_only=1, limit=500
     */
    public function products(Request $request): Response
    {
        $data = $this->service->stockProducts($this->partner($request), [
            'branch_id'     => $request->query('branch_id', 0),
            'q'             => $request->query('q', ''),
            'in_stock_only' => $request->query('in_stock_only', '1'),
            'limit'         => $request->query('limit', 500),
        ]);

        return $this->success($data, 'Stock (products).');
    }

    /**
     * EMR prescription search — in-stock products by name / generic.
     * Query: q (required), branch_id?, limit=40
     */
    public function search(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $data = $this->service->search(
            $this->partner($request),
            $q,
            (int) $request->query('branch_id', 0),
            (int) $request->query('limit', 40)
        );

        return $this->success($data, 'Stock search.');
    }

    /**
     * @return array{client_id:int,partner:string,branch_ids:list<int>}
     */
    private function partner(Request $request): array
    {
        $partner = $request->attribute('partner');
        if (!is_array($partner) || empty($partner['client_id'])) {
            throw HttpException::unauthorized('Partner context missing.');
        }

        return $partner;
    }
}
