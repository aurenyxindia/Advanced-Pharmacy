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

    /** A4 sheet barcodes (office printer) — 1 label per pack received. */
    public function purchaseLabels(Request $request): Response
    {
        $purchase = $this->loadPurchase($request);
        $labels = $this->buildLabels($purchase);

        $pdf = (new BarcodeService())->render($labels);
        return Response::raw($pdf, 'application/pdf', 200, [
            'Content-Disposition' => 'inline; filename="barcodes-' . ($purchase['invoice_no'] ?? 'purchase') . '.pdf"',
        ]);
    }

    /**
     * TSC TE 210 / TE 244 thermal roll — 40 mm × 15 mm, one label per page (vertical feed).
     * Same barcode data as A4; different page geometry for the label printer.
     */
    public function purchaseThermalLabels(Request $request): Response
    {
        $purchase = $this->loadPurchase($request);
        $labels = $this->buildLabels($purchase);

        $pdf = (new BarcodeService())->renderThermalRoll($labels);
        $safeInv = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($purchase['invoice_no'] ?? 'purchase')) ?: 'purchase';

        return Response::raw($pdf, 'application/pdf', 200, [
            'Content-Disposition' => 'inline; filename="label-barcodes-' . $safeInv . '.pdf"',
        ]);
    }

    /** @return array<string,mixed> */
    private function loadPurchase(Request $request): array
    {
        $purchase = (new Purchase())->withItems((int) $request->attribute('id'));
        if (!$purchase || (int) $purchase['client_id'] !== $request->clientId()) {
            throw HttpException::notFound('Purchase not found.');
        }
        return $purchase;
    }

    /**
     * @param array<string,mixed> $purchase
     * @return list<array{name:string, medical:?string, mfg:?string, batch:?string, exp:?string, mrp:float, code:string}>
     */
    private function buildLabels(array $purchase): array
    {
        $medical = $this->resolveMedicalName((int) ($purchase['client_id'] ?? 0));

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

            // Total packs (purchase qty + free) — one sticker per pack, Retail t_qty parity.
            $count = max(1, (int) $item['quantity'] + (int) ($item['free_quantity'] ?? 0));
            for ($n = 0; $n < $count && count($labels) < self::MAX_LABELS; $n++) {
                $labels[] = [
                    'name'    => (string) $item['product_name'],
                    'medical' => $medical,
                    'mfg'     => $batch['manufacturer_short'] ?? null,
                    'batch'   => $item['batch_no'],
                    'exp'     => $item['expiry_date'] ? date('m/y', strtotime((string) $item['expiry_date'])) : null,
                    'mrp'     => (float) $item['mrp'],
                    'code'    => (string) $batch['id'],
                ];
            }
            if (count($labels) >= self::MAX_LABELS) {
                break;
            }
        }

        if ($labels === []) {
            throw HttpException::notFound('No batches found to label.');
        }

        return $labels;
    }

    /** Pharmacy / medical shop name for sticker header (clients.business_name). */
    private function resolveMedicalName(int $clientId): ?string
    {
        if ($clientId <= 0) {
            return null;
        }
        $st = Connection::ho()->prepare(
            'SELECT business_name FROM clients WHERE id = :id LIMIT 1'
        );
        $st->execute(['id' => $clientId]);
        $name = trim((string) ($st->fetchColumn() ?: ''));
        return $name !== '' ? $name : null;
    }
}
