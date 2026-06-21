<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Database\Connection;
use App\Models\Purchase;
use App\Services\BarcodeService;

final class BarcodeController extends Controller
{
    private const MAX_LABELS = 600;

    /** Strip/pack barcode labels for every line of a purchase (1 label per pack received). */
    public function purchaseLabels(Request $request): Response
    {
        $purchase = (new Purchase())->withItems((int) $request->attribute('id'));
        if (!$purchase || (int) $purchase['client_id'] !== $request->clientId()) {
            throw HttpException::notFound('Purchase not found.');
        }

        $labels = [];
        $batchStmt = Connection::ops()->prepare(
            'SELECT ib.id, ib.mrp, pb.manufacturer_short
             FROM inventory_batches ib JOIN product_bindings pb ON pb.id = ib.product_binding_id
             WHERE ib.product_binding_id = :p AND ib.branch_id = :b AND ib.batch_no = :batch LIMIT 1'
        );

        foreach ($purchase['items'] as $item) {
            $batchStmt->execute([
                'p' => $item['product_binding_id'],
                'b' => $purchase['branch_id'],
                'batch' => $item['batch_no'] ?: 'NA',
            ]);
            $batch = $batchStmt->fetch();
            if (!$batch) {
                continue;
            }

            $count = max(1, (int) $item['quantity']); // packs received
            for ($n = 0; $n < $count && count($labels) < self::MAX_LABELS; $n++) {
                $labels[] = [
                    'name'  => $item['product_name'],
                    'mfg'   => $batch['manufacturer_short'] ?? null,
                    'batch' => $item['batch_no'],
                    'exp'   => $item['expiry_date'] ? date('m/y', strtotime((string) $item['expiry_date'])) : null,
                    'mrp'   => (float) $item['mrp'],
                    'code'  => (string) $batch['id'],
                ];
            }
            if (count($labels) >= self::MAX_LABELS) {
                break;
            }
        }

        if ($labels === []) {
            throw HttpException::notFound('No batches found to label.');
        }

        $pdf = (new BarcodeService())->render($labels);
        return Response::raw($pdf, 'application/pdf', 200, [
            'Content-Disposition' => 'inline; filename="barcodes-' . ($purchase['invoice_no'] ?? 'purchase') . '.pdf"',
        ]);
    }
}
