<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Database\Connection;
use App\Models\ProductBinding;
use App\Services\InventoryService;
use App\Services\ProductBindingService;

/**
 * Opening stock entry and expired-stock write-off. Both post to the stock
 * ledger so the stock book and FDA tally stay accurate.
 */
final class StockController extends Controller
{
    public function openingStore(Request $request): Response
    {
        if ($request->branchId() === null) {
            throw HttpException::forbidden('A branch context is required.');
        }
        $data = $this->validate($request, [
            'master_product_id' => 'required|integer',
            'batch_no'          => 'required|string|max:60',
            'quantity'          => 'required|integer|min:1',
            'mrp'               => 'numeric|min:0',
            'purchase_rate'     => 'numeric|min:0',
            'opening_date'      => 'string|max:20',
        ]);

        $clientId = (int) $request->clientId();
        $branchId = (int) $request->branchId();

        $bind = (new ProductBindingService())->bind($clientId, $branchId, (int) $data['master_product_id']);
        $bindingId = (int) $bind['id'];

        $expiry = $this->normalizeExpiry($request->input('expiry_date'));
        (new InventoryService())->addStock(
            $clientId,
            $branchId,
            $bindingId,
            (string) $data['batch_no'],
            $expiry,
            (float) ($data['mrp'] ?? 0),
            (float) ($data['purchase_rate'] ?? 0),
            (int) $data['quantity'],
            $request->input('shelf_location'),
            0,
            0,
            'opening',
            $data['opening_date'] ?? date('Y-m-d'),
            'opening',
            null
        );

        return $this->created(['binding_id' => $bindingId], 'Opening stock added.');
    }

    public function openingList(Request $request): Response
    {
        $branchClause = '';
        $bind = ['c' => $request->clientId()];
        if ($request->branchId() !== null) {
            $branchClause = ' AND l.branch_id = :b';
            $bind['b'] = $request->branchId();
        }
        $stmt = Connection::ops()->prepare(
            "SELECT l.txn_date, pb.product_name, pb.manufacturer_short, l.qty_in AS quantity, l.note
             FROM stock_ledger l JOIN product_bindings pb ON pb.id = l.product_binding_id
             WHERE l.client_id = :c {$branchClause} AND l.txn_type = 'opening'
             ORDER BY l.id DESC LIMIT 200"
        );
        $stmt->execute($bind);
        return $this->success($stmt->fetchAll(), 'Opening stock entries.');
    }

    /** Write off expired batches (expiry < today) -> ledger 'expiry' OUT, qty 0. */
    public function writeOffExpired(Request $request): Response
    {
        $branchId = (int) $request->branchId();
        $clientId = (int) $request->clientId();
        $branchClause = $branchId > 0 ? ' AND branch_id = :b' : '';
        $bind = ['c' => $clientId];
        if ($branchId > 0) {
            $bind['b'] = $branchId;
        }

        $batches = Connection::ops()->prepare(
            "SELECT id, quantity FROM inventory_batches
             WHERE client_id = :c {$branchClause} AND quantity > 0 AND expiry_date IS NOT NULL AND expiry_date < CURDATE()"
        );
        $batches->execute($bind);
        $rows = $batches->fetchAll();

        $inv = new InventoryService();
        $count = 0;
        $qty = 0;
        foreach ($rows as $b) {
            $inv->reduceBatch((int) $b['id'], (int) $b['quantity'], 'expiry', date('Y-m-d'), 'expiry', null);
            // keep aggregate in sync
            $stmt = Connection::ops()->prepare(
                'UPDATE inventory_stock st JOIN inventory_batches ib ON ib.product_binding_id = st.product_binding_id AND ib.branch_id = st.branch_id
                 SET st.quantity = GREATEST(st.quantity - :q, 0) WHERE ib.id = :id'
            );
            $stmt->execute(['q' => (int) $b['quantity'], 'id' => (int) $b['id']]);
            $count++;
            $qty += (int) $b['quantity'];
        }

        return $this->success(['batches' => $count, 'quantity' => $qty], "Wrote off {$count} expired batch(es).");
    }

    private function normalizeExpiry(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('#^(\d{1,2})[/-](\d{2,4})$#', $raw, $m)) {
            $month = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $year = strlen($m[2]) === 2 ? '20' . $m[2] : $m[2];
            return date('Y-m-t', strtotime("$year-$month-01"));
        }
        $ts = strtotime($raw);
        return $ts ? date('Y-m-d', $ts) : null;
    }
}
