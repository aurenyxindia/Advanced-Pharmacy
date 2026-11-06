<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use PDO;

/** Previous retail bills for a patient + stock fulfillment (Retail retail_patient_purchase_history.php parity). */
final class PatientRetailHistoryService
{
    /** @return array{bills:list<array<string,mixed>>,bill_count:int} */
    public function getHistory(
        int $clientId,
        int $branchId,
        string $patientNo,
        string $contact,
        int $limit = 40,
    ): array {
        $patientNo = trim($patientNo);
        $contact = preg_replace('/\D/', '', $contact) ?? '';
        if (strlen($contact) >= 10) {
            $contact = substr($contact, -10);
        }

        $match = [];
        $params = ['c' => $clientId, 'b' => $branchId];
        if ($patientNo !== '' && ctype_digit($patientNo)) {
            $match[] = 's.customer_id = :cid';
            $params['cid'] = (int) $patientNo;
        }
        if (strlen($contact) >= 10) {
            $match[] = "REPLACE(REPLACE(REPLACE(REPLACE(s.contact_no,' ',''),'-',''),'+',''),'.','') LIKE :phone";
            $params['phone'] = '%' . $contact;
        }
        if ($match === []) {
            return ['bills' => [], 'bill_count' => 0];
        }

        $limit = max(1, min(80, $limit));
        $whereMatch = implode(' OR ', $match);
        $sql = "SELECT s.id, s.invoice_no AS order_no, s.invoice_date AS order_date,
                       s.customer_id AS patient_no, s.patient_name, s.contact_no, s.doctor_name,
                       s.net_amount AS net, s.discount_amount AS discount, s.gst_amount AS gst,
                       s.taxable_amount AS taxable
                FROM sales s
                WHERE s.client_id = :c AND s.branch_id = :b
                  AND (s.bill_mode = 'retail' OR s.bill_mode IS NULL OR s.bill_mode = '')
                  AND UPPER(COALESCE(s.sale_type, 'Regular')) NOT LIKE 'CASH%'
                  AND UPPER(COALESCE(s.patient_name, '')) NOT IN ('CASH SALE', 'WALK-IN')
                  AND ($whereMatch)
                ORDER BY s.id DESC
                LIMIT $limit";

        $stmt = Connection::ops()->prepare($sql);
        $stmt->execute($params);
        $bills = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $bill) {
            $saleId = (int) ($bill['id'] ?? 0);
            $products = [];
            if ($saleId > 0) {
                $items = Connection::ops()->prepare(
                    'SELECT si.*, pb.schedule_category AS p_cat, pb.pack_size, pb.units_per_pack
                     FROM sale_items si
                     LEFT JOIN product_bindings pb ON pb.id = si.product_binding_id
                     WHERE si.sale_id = :sid ORDER BY si.id ASC'
                );
                $items->execute(['sid' => $saleId]);
                foreach ($items->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $line = $this->mapSaleItemLine($row);
                    $this->enrichLineStock($clientId, $branchId, $line);
                    $products[] = $line;
                }
            }
            unset($bill['id']);
            $bill['products'] = $products;
            $bill['line_count'] = count($products);
            $bills[] = $bill;
        }

        return ['bills' => $bills, 'bill_count' => count($bills)];
    }

    /** @param array<string,mixed> $row */
    private function mapSaleItemLine(array $row): array
    {
        $upp = max(1, (int) ($row['units_per_pack'] ?? 1));
        $packMrp = (float) ($row['mrp'] ?? 0);
        $unitMrp = (float) ($row['rate'] ?? 0);
        if ($unitMrp <= 0 && $packMrp > 0) {
            $unitMrp = round($packMrp / $upp, 4);
        }

        return [
            'p_name' => (string) ($row['product_name'] ?? ''),
            'product_name' => (string) ($row['product_name'] ?? ''),
            'm_name' => (string) ($row['manufacturer_short'] ?? ''),
            'batch_no' => (string) ($row['batch_no'] ?? ''),
            'exp_date' => $row['expiry_date'] ?? '',
            'qty' => (float) ($row['quantity'] ?? 0),
            'disc_per' => (float) ($row['discount_pct'] ?? 0),
            'mrp' => $packMrp,
            'unit_mrp' => $unitMrp,
            'net' => (float) ($row['line_total'] ?? 0),
            'gst' => (float) ($row['gst_rate'] ?? 0),
            'hsn' => (string) ($row['hsn_code'] ?? ''),
            'shelf' => (string) ($row['shelf'] ?? ''),
            'p_cat' => (string) ($row['p_cat'] ?? $row['schedule_category'] ?? ''),
            'pack_size' => (string) ($row['pack'] ?? $row['pack_size'] ?? ''),
            'binding_id' => (int) ($row['product_binding_id'] ?? 0),
            'batch_id' => (int) ($row['batch_id'] ?? 0),
        ];
    }

    /** @param array<string,mixed> $line */
    private function historicalUnitMrp(array $line): float
    {
        $qty = (float) ($line['qty'] ?? 0);
        $net = (float) ($line['net'] ?? 0);
        $disc = (float) ($line['disc_per'] ?? 0);
        if ($qty > 0 && $net >= 0) {
            $factor = 1 - ($disc / 100);
            if ($factor <= 0) {
                $factor = 1;
            }
            $u = round($net / ($qty * $factor), 4);
            if ($u > 0) {
                return $u;
            }
        }
        $u = (float) ($line['unit_mrp'] ?? 0);
        return $u > 0 ? round($u, 4) : 0.0;
    }

    /** @param array<string,mixed> $line */
    private function enrichLineStock(int $clientId, int $branchId, array &$line): void
    {
        $soldQty = (float) ($line['qty'] ?? 0);
        $oldBatch = trim((string) ($line['batch_no'] ?? ''));
        $pName = (string) ($line['p_name'] ?? '');
        $historicalRate = $this->historicalUnitMrp($line);

        $line['historical_unit_mrp'] = $historicalRate;
        $line['fulfillment_segments'] = [];
        $line['available_qty'] = 0;
        $line['in_stock'] = false;
        $line['stock_sufficient'] = false;
        $line['qty_unfulfilled'] = $soldQty;
        $line['stock_status'] = 'none';

        if ($soldQty <= 0 || $pName === '' || $oldBatch === '') {
            return;
        }

        $oldStock = $this->fetchBatchStock($clientId, $branchId, $pName, $oldBatch);
        $oldAvail = $oldStock ? (float) $oldStock['available_qty'] : 0;
        $line['available_qty'] = $oldAvail;
        $others = $this->fetchOtherBatches($clientId, $branchId, $pName, $oldBatch);

        $segments = [];
        $remainder = $soldQty;

        if ($oldAvail > 0 && $oldStock) {
            $take = min($oldAvail, $remainder);
            $segments[] = $this->segmentFromStock($oldStock, $take, 'historical', $historicalRate, $line);
            $remainder -= $take;
        }

        foreach ($others as $alt) {
            if ($remainder <= 0) {
                break;
            }
            $altAvail = (float) ($alt['available_qty'] ?? 0);
            if ($altAvail <= 0) {
                continue;
            }
            $take = min($altAvail, $remainder);
            $segments[] = $this->segmentFromStock($alt, $take, 'current', (float) $alt['unit_mrp'], $line);
            $remainder -= $take;
        }

        $line['fulfillment_segments'] = $segments;
        $line['qty_unfulfilled'] = max(0, $remainder);
        $fulfilled = $soldQty - $line['qty_unfulfilled'];
        $line['in_stock'] = $fulfilled > 0;
        $line['stock_sufficient'] = $line['qty_unfulfilled'] <= 0;

        if ($oldAvail >= $soldQty && $soldQty > 0) {
            $line['stock_status'] = 'same_batch';
        } elseif ($oldAvail > 0 && $oldAvail < $soldQty && $fulfilled >= $soldQty) {
            $line['stock_status'] = 'split_batch';
        } elseif ($oldAvail > 0 && $fulfilled > 0 && $fulfilled < $soldQty) {
            $line['stock_status'] = 'split_batch_partial';
        } elseif ($oldAvail <= 0 && count($segments) > 0) {
            $line['stock_status'] = 'new_batch';
        } elseif ($fulfilled <= 0) {
            $line['stock_status'] = 'none';
        } else {
            $line['stock_status'] = 'partial';
        }
    }

    /** @return array<string,mixed>|null */
    private function fetchBatchStock(int $clientId, int $branchId, string $productName, string $batchNo): ?array
    {
        $sql = 'SELECT pb.id AS binding_id, pb.product_name, pb.pack_size, pb.schedule_category AS p_cat,
                       pb.units_per_pack, ib.id AS batch_id, ib.batch_no, ib.quantity AS available_qty,
                       ib.mrp, ib.expiry_date AS exp_date
                FROM inventory_batches ib
                INNER JOIN product_bindings pb ON pb.id = ib.product_binding_id
                WHERE pb.client_id = :c AND pb.branch_id = :b AND pb.product_name = :pn AND ib.batch_no = :bn
                  AND ib.quantity > 0 LIMIT 1';
        $stmt = Connection::ops()->prepare($sql);
        $stmt->execute(['c' => $clientId, 'b' => $branchId, 'pn' => $productName, 'bn' => $batchNo]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $upp = max(1, (int) $row['units_per_pack']);
        $packMrp = (float) $row['mrp'];
        $row['unit_mrp'] = $upp > 0 ? round($packMrp / $upp, 4) : $packMrp;
        $row['pack_mrp'] = $packMrp;
        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function fetchOtherBatches(int $clientId, int $branchId, string $productName, string $excludeBatch): array
    {
        $sql = 'SELECT pb.id AS binding_id, pb.pack_size, pb.schedule_category AS p_cat, pb.units_per_pack,
                       ib.id AS batch_id, ib.batch_no, ib.quantity AS available_qty, ib.mrp, ib.expiry_date AS exp_date
                FROM inventory_batches ib
                INNER JOIN product_bindings pb ON pb.id = ib.product_binding_id
                WHERE pb.client_id = :c AND pb.branch_id = :b AND pb.product_name = :pn
                  AND ib.batch_no <> :eb AND ib.quantity > 0
                  AND (ib.expiry_date IS NULL OR ib.expiry_date >= CURDATE())
                ORDER BY (ib.expiry_date IS NULL), ib.expiry_date ASC, ib.batch_no ASC';
        $stmt = Connection::ops()->prepare($sql);
        $stmt->execute(['c' => $clientId, 'b' => $branchId, 'pn' => $productName, 'eb' => $excludeBatch]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $upp = max(1, (int) $row['units_per_pack']);
            $packMrp = (float) $row['mrp'];
            $row['unit_mrp'] = $upp > 0 ? round($packMrp / $upp, 4) : $packMrp;
            $row['pack_mrp'] = $packMrp;
            $out[] = $row;
        }
        return $out;
    }

    /** @param array<string,mixed> $stock @param array<string,mixed> $line */
    private function segmentFromStock(array $stock, float $qty, string $rateSource, float $historicalRate, array $line): array
    {
        $upp = max(1, (int) ($stock['units_per_pack'] ?? 1));
        $unitMrp = $rateSource === 'historical' && $historicalRate > 0
            ? $historicalRate
            : (float) ($stock['unit_mrp'] ?? 0);

        return [
            'binding_id' => (int) ($stock['binding_id'] ?? 0),
            'batch_id' => (int) ($stock['batch_id'] ?? 0),
            'batch_no' => (string) ($stock['batch_no'] ?? ''),
            'qty' => $qty,
            'rate_source' => $rateSource,
            'unit_mrp' => $unitMrp,
            'pack_mrp' => (float) ($stock['pack_mrp'] ?? $stock['mrp'] ?? ($unitMrp * $upp)),
            'exp_date' => ($line['exp_date'] ?? '') !== '' ? $line['exp_date'] : ($stock['exp_date'] ?? ''),
            'available_qty' => (float) ($stock['available_qty'] ?? 0),
            'pack_size' => ($line['pack_size'] ?? '') !== '' ? $line['pack_size'] : ($stock['pack_size'] ?? ''),
            'p_cat' => ($line['p_cat'] ?? '') !== '' ? $line['p_cat'] : ($stock['p_cat'] ?? ''),
            'disc_per' => (float) ($line['disc_per'] ?? 0),
        ];
    }
}
