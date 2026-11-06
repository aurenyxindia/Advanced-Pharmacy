<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Model;
use App\Core\Request;
use App\Core\Response;
use App\Models\PaymentVoucher;
use App\Services\PaymentReceiptService;
use App\Services\PaymentSettlementService;

final class PaymentController extends ResourceController
{
    protected bool $tenantScoped = true;
    protected bool $branchScoped = true;
    protected array $fillable = ['voucher_no', 'voucher_type', 'party_type', 'party_name', 'amount', 'mode', 'ref_no', 'voucher_date', 'narration'];
    protected array $searchColumns = ['voucher_no', 'party_name', 'ref_no'];
    protected string $defaultOrder = 'voucher_date DESC, id DESC';

    protected function model(): Model
    {
        return new PaymentVoucher();
    }

    protected function storeRules(): array
    {
        return [
            'voucher_type' => 'required|in:receipt,payment',
            'party_name'   => 'required|string|max:150',
            'amount'       => 'required|numeric|min:0.01',
            'mode'         => 'string|max:20',
            'ref_no'       => 'string|max:60',
            'voucher_date' => 'required|string|max:20',
            'party_type'   => 'string|max:20',
            'narration'    => 'string|max:255',
        ];
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, $this->storeRules());
        $payload = $this->fillData($data, $request);
        $payload['voucher_no'] = ($payload['voucher_no'] ?? '')
            ?: ('PV-' . strtoupper(substr((string) $data['voucher_type'], 0, 1)) . '-' . time());
        $payload['created_at'] = now();
        $payload['updated_at'] = now();
        $id = $this->model()->insert($payload);
        return $this->created(['id' => $id], 'Voucher saved.');
    }

    public function distributorDues(Request $request): Response
    {
        $branchId = (int) ($request->branchId() ?: 0);
        $rows = (new PaymentSettlementService())->distributorDues((int) $request->clientId(), $branchId);
        return $this->success($rows, 'Distributor dues.');
    }

    public function customerDues(Request $request): Response
    {
        $branchId = (int) ($request->branchId() ?: 0);
        $rows = (new PaymentSettlementService())->customerDues((int) $request->clientId(), $branchId, 'retail');
        return $this->success($rows, 'Retail customer dues.');
    }

    public function wholesaleCustomerDues(Request $request): Response
    {
        $branchId = (int) ($request->branchId() ?: 0);
        $rows = (new PaymentSettlementService())->customerDues((int) $request->clientId(), $branchId, 'wholesale');
        return $this->success($rows, 'Wholesale customer dues.');
    }

    public function settleDistributor(Request $request): Response
    {
        $branchId = (int) ($request->branchId() ?: 0);
        $userId = isset($request->user()['id']) ? (int) $request->user()['id'] : null;
        $result = (new PaymentSettlementService())->settleDistributor(
            (int) $request->clientId(),
            $branchId,
            $userId,
            $request->all()
        );
        return $this->success($result, 'Distributor payment saved.');
    }

    public function settleCustomer(Request $request): Response
    {
        $branchId = (int) ($request->branchId() ?: 0);
        $userId = isset($request->user()['id']) ? (int) $request->user()['id'] : null;
        $result = (new PaymentSettlementService())->settleCustomer(
            (int) $request->clientId(),
            $branchId,
            $userId,
            $request->all(),
            'retail'
        );
        return $this->success($result, 'Customer payment saved.');
    }

    public function settleWholesaleCustomer(Request $request): Response
    {
        $branchId = (int) ($request->branchId() ?: 0);
        $userId = isset($request->user()['id']) ? (int) $request->user()['id'] : null;
        $result = (new PaymentSettlementService())->settleCustomer(
            (int) $request->clientId(),
            $branchId,
            $userId,
            $request->all(),
            'wholesale'
        );
        return $this->success($result, 'Wholesale customer payment saved.');
    }

    public function history(Request $request): Response
    {
        $branchId = (int) ($request->branchId() ?: 0);
        $partyType = trim((string) ($request->query('party_type') ?? 'distributor'));
        $party = trim((string) ($request->query('party') ?? ''));
        $phone = trim((string) ($request->query('phone') ?? ''));
        $rows = (new PaymentSettlementService())->history(
            (int) $request->clientId(),
            $branchId,
            $partyType,
            $party !== '' ? $party : null,
            $phone !== '' ? $phone : null
        );
        return $this->success($rows, 'Payment history.');
    }

    /** HTML payment receipt (Retail wholesale_customer_payment_print parity). */
    public function printReceipt(Request $request): Response
    {
        $branchId = (int) ($request->branchId() ?: 0);
        $id = (int) $request->attribute('id');
        $html = (new PaymentReceiptService())->html((int) $request->clientId(), $branchId, $id);
        return Response::raw($html, 'text/html; charset=utf-8');
    }
}
