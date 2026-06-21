<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\Purchase;
use App\Services\PurchaseService;

final class PurchaseController extends Controller
{
    public function index(Request $request): Response
    {
        $result = (new Purchase())->paginate(
            ['client_id' => $request->clientId(), 'branch_id' => $request->branchId()],
            $request->query('search'),
            ['invoice_no', 'supplier_name'],
            (int) $request->query('page', 1),
            (int) $request->query('per_page', 25),
            'invoice_date DESC, id DESC'
        );
        return $this->success($result, 'Purchases fetched.');
    }

    public function show(Request $request): Response
    {
        $purchase = (new Purchase())->withItems((int) $request->attribute('id'));
        if (!$purchase || (int) $purchase['client_id'] !== $request->clientId()) {
            throw HttpException::notFound('Purchase not found.');
        }
        return $this->success($purchase, 'Purchase fetched.');
    }

    public function store(Request $request): Response
    {
        if ($request->branchId() === null) {
            throw HttpException::forbidden('A branch context is required.');
        }

        $this->validate($request, [
            'invoice_no'   => 'required|string|max:60',
            'invoice_date' => 'required|string|max:20',
        ]);

        $result = (new PurchaseService())->create(
            $request->clientId(),
            $request->branchId(),
            $request->user()['id'] ?? null,
            $request->all()
        );

        return $this->created($result, 'Purchase saved and stock updated.');
    }
}
