<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Database\Connection;
use App\Models\Sale;
use App\Services\InvoiceService;
use App\Services\SalesService;

final class SalesController extends Controller
{
    public function index(Request $request): Response
    {
        $result = (new Sale())->paginate(
            ['client_id' => $request->clientId(), 'branch_id' => $request->branchId()],
            $request->query('search'),
            ['invoice_no', 'customer_name'],
            (int) $request->query('page', 1),
            (int) $request->query('per_page', 25),
            'invoice_date DESC, id DESC'
        );
        return $this->success($result, 'Sales fetched.');
    }

    public function show(Request $request): Response
    {
        $sale = $this->findSale($request);
        return $this->success($sale, 'Sale fetched.');
    }

    public function store(Request $request): Response
    {
        if ($request->branchId() === null) {
            throw HttpException::forbidden('A branch context is required.');
        }

        $result = (new SalesService())->create(
            $request->clientId(),
            $request->branchId(),
            $request->user()['id'] ?? null,
            $request->all()
        );

        return $this->created($result, 'Sale completed.');
    }

    /** Type-ahead over the branch's bound products with live stock + MRP. */
    public function searchProducts(Request $request): Response
    {
        $q = '%' . (string) $request->query('q', '') . '%';
        $rows = Connection::ops()->prepare(
            'SELECT pb.id AS binding_id, pb.product_name, pb.schedule_category, pb.gst_rate, pb.master_product_id,
                    COALESCE(st.quantity, 0) AS available_qty,
                    (SELECT ib.mrp FROM inventory_batches ib
                       WHERE ib.product_binding_id = pb.id AND ib.quantity > 0
                       ORDER BY (ib.expiry_date IS NULL), ib.expiry_date ASC LIMIT 1) AS mrp
             FROM product_bindings pb
             LEFT JOIN inventory_stock st ON st.product_binding_id = pb.id
             WHERE pb.client_id = :c AND pb.branch_id = :b AND pb.is_active = 1 AND pb.product_name LIKE :q
             ORDER BY pb.product_name ASC LIMIT 15'
        );
        $rows->execute(['c' => $request->clientId(), 'b' => $request->branchId(), 'q' => $q]);
        return $this->success($rows->fetchAll(), 'Products.');
    }

    /** Substitutes (same generic) available, flagged with branch stock. */
    public function substitutes(Request $request): Response
    {
        $bindingId = (int) $request->attribute('id');
        $binding = Connection::ops()->prepare(
            'SELECT master_product_id FROM product_bindings WHERE id = :id AND client_id = :c LIMIT 1'
        );
        $binding->execute(['id' => $bindingId, 'c' => $request->clientId()]);
        $row = $binding->fetch();
        if (!$row) {
            throw HttpException::notFound('Product not found.');
        }

        $subs = (new \App\Models\Product())->substitutes((int) $row['master_product_id']);

        // Annotate with this branch's binding + stock where available.
        foreach ($subs as &$sub) {
            $b = Connection::ops()->prepare(
                'SELECT pb.id AS binding_id, COALESCE(st.quantity,0) AS available_qty
                 FROM product_bindings pb
                 LEFT JOIN inventory_stock st ON st.product_binding_id = pb.id
                 WHERE pb.client_id = :c AND pb.branch_id = :b AND pb.master_product_id = :mp LIMIT 1'
            );
            $b->execute(['c' => $request->clientId(), 'b' => $request->branchId(), 'mp' => $sub['id']]);
            $bound = $b->fetch();
            $sub['binding_id'] = $bound['binding_id'] ?? null;
            $sub['available_qty'] = (int) ($bound['available_qty'] ?? 0);
        }
        unset($sub);

        return $this->success($subs, 'Substitutes.');
    }

    public function invoice(Request $request): Response
    {
        $sale = $this->findSale($request);
        $pdf = (new InvoiceService())->forSale($sale);

        return Response::raw($pdf, 'application/pdf', 200, [
            'Content-Disposition' => 'inline; filename="' . ($sale['invoice_no'] ?? 'invoice') . '.pdf"',
        ]);
    }

    private function findSale(Request $request): array
    {
        $sale = (new Sale())->withItems((int) $request->attribute('id'));
        if (!$sale || (int) $sale['client_id'] !== $request->clientId()) {
            throw HttpException::notFound('Sale not found.');
        }
        return $sale;
    }
}
