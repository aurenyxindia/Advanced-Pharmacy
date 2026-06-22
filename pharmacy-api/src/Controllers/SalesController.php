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

    /** Type-ahead over the branch's bound products with live stock + MRP.
     *  Supports search by product name OR by generic (substitute) name. */
    public function searchProducts(Request $request): Response
    {
        $q = '%' . (string) $request->query('q', '') . '%';
        $genericId = (int) $request->query('generic_id', 0);

        $sql =
            'SELECT pb.id AS binding_id, pb.product_name, pb.manufacturer, pb.manufacturer_short,
                    pb.schedule_category, pb.gst_rate, pb.master_product_id,
                    pb.units_per_pack, pb.allow_loose, pb.shelf_location, pb.pack_size, pb.generic_id,
                    g.name AS generic_name,
                    COALESCE(st.quantity, 0) AS available_qty,
                    COALESCE((SELECT NULLIF(ib.mrp,0) FROM inventory_batches ib
                       WHERE ib.product_binding_id = pb.id AND ib.quantity > 0
                       ORDER BY (ib.expiry_date IS NULL), ib.expiry_date ASC LIMIT 1), pb.mrp) AS mrp
             FROM product_bindings pb
             LEFT JOIN inventory_stock st ON st.product_binding_id = pb.id
             LEFT JOIN pharma_master.generics g ON g.id = pb.generic_id
             WHERE pb.client_id = :c AND pb.branch_id = :b AND pb.is_active = 1
               AND EXISTS (
                   SELECT 1 FROM inventory_batches ib2
                   WHERE ib2.product_binding_id = pb.id AND ib2.quantity > 0
               )';
        $bind = ['c' => $request->clientId(), 'b' => $request->branchId()];

        if ($genericId > 0) {
            $sql .= ' AND pb.generic_id = :gid';
            $bind['gid'] = $genericId;
        } else {
            $sql .= ' AND (pb.product_name LIKE :q OR g.name LIKE :q2)';
            $bind['q'] = $q;
            $bind['q2'] = $q;
        }
        $sql .= ' ORDER BY pb.product_name ASC LIMIT 25';

        $rows = Connection::ops()->prepare($sql);
        $rows->execute($bind);
        return $this->success($rows->fetchAll(), 'Products.');
    }

    /**
     * Resolve a scanned barcode (= inventory batch id) to a billing product
     * with the exact batch. Used by the sales form scanner.
     */
    public function scan(Request $request): Response
    {
        $code = preg_replace('/\D/', '', (string) $request->query('code', ''));
        $batchId = (int) ltrim($code, '0');
        if ($batchId <= 0) {
            throw HttpException::validation(['code' => ['Invalid barcode.']]);
        }

        $stmt = Connection::ops()->prepare(
            'SELECT pb.id AS binding_id, pb.product_name, pb.manufacturer, pb.manufacturer_short,
                    pb.schedule_category, pb.gst_rate, pb.master_product_id,
                    pb.units_per_pack, pb.allow_loose, pb.shelf_location, pb.pack_size, pb.generic_id,
                    ib.id AS batch_id, ib.batch_no, ib.expiry_date, ib.mrp AS unit_mrp, ib.ptr, ib.pts,
                    ib.purchase_rate, ib.quantity AS available_qty,
                    g.name AS generic_name
             FROM inventory_batches ib
             JOIN product_bindings pb ON pb.id = ib.product_binding_id
             LEFT JOIN pharma_master.generics g ON g.id = pb.generic_id
             WHERE ib.id = :id AND ib.client_id = :c AND ib.branch_id = :b LIMIT 1'
        );
        $stmt->execute(['id' => $batchId, 'c' => $request->clientId(), 'b' => $request->branchId()]);
        $row = $stmt->fetch();
        if (!$row) {
            throw HttpException::notFound('Barcode not recognised in this branch.');
        }
        $row['mrp'] = $row['unit_mrp'];
        return $this->success($row, 'Product resolved.');
    }

    /** Available batches (B.No, expiry, MRP, shelf, stock) for a bound product. */
    public function batches(Request $request): Response
    {
        $bindingId = (int) $request->attribute('id');
        $binding = Connection::ops()->prepare(
            'SELECT id, units_per_pack FROM product_bindings WHERE id = :id AND client_id = :c AND branch_id = :b LIMIT 1'
        );
        $binding->execute(['id' => $bindingId, 'c' => $request->clientId(), 'b' => $request->branchId()]);
        $b = $binding->fetch();
        if (!$b) {
            throw HttpException::notFound('Product not found in your branch.');
        }
        $upp = max(1, (int) $b['units_per_pack']);

        $stmt = Connection::ops()->prepare(
            'SELECT id AS batch_id, batch_no, expiry_date, mrp, ptr, pts, purchase_rate, quantity, shelf_location
             FROM inventory_batches
             WHERE product_binding_id = :p AND quantity > 0
             ORDER BY (expiry_date IS NULL), expiry_date ASC'
        );
        $stmt->execute(['p' => $bindingId]);
        $batches = $stmt->fetchAll();
        foreach ($batches as &$batch) {
            // MRP/cost stored per base (loose) unit; PTR/PTS per pack.
            $batch['unit_mrp'] = round((float) $batch['mrp'], 2);
            $batch['ptr'] = round((float) $batch['ptr'], 2);
            $batch['pts'] = round((float) $batch['pts'], 2);
            $batch['units_per_pack'] = $upp;
            $batch['packs'] = $upp > 0 ? intdiv((int) $batch['quantity'], $upp) : (int) $batch['quantity'];
        }
        unset($batch);

        return $this->success($batches, 'Batches.');
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
