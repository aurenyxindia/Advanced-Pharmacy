<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Model;
use App\Core\Request;
use App\Core\Response;
use App\Models\PaymentVoucher;

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
        $payload['voucher_no'] = $payload['voucher_no'] ?: ('PV-' . strtoupper(substr($data['voucher_type'], 0, 1)) . '-' . time());
        $payload['created_at'] = now();
        $payload['updated_at'] = now();
        $id = $this->model()->insert($payload);
        return $this->created(['id' => $id], 'Voucher saved.');
    }
}
