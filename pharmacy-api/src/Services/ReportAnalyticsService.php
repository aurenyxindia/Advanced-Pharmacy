<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use PDO;

/**
 * Retail-parity analytical reports against Advanced ops schema.
 * Controllers pass scoped WHERE fragments (alias-aware) + binds.
 */
final class ReportAnalyticsService
{
    /** @param array<string,mixed> $bind */
    public function rows(string $sql, array $bind): array
    {
        $stmt = Connection::ops()->prepare($sql);
        $stmt->execute($bind);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array{where:string,bind:array<string,mixed>,branchSql:string} $scope
     * @return array{rows:list<array<string,mixed>>,totals:array<string,float|int>}
     */
    public function dateWisePurchase(array $scope): array
    {
        $rows = $this->rows(
            "SELECT p.invoice_no, DATE(p.invoice_date) AS invoice_date, p.supplier_name,
                    p.net_amount AS invoice_amt, p.balance AS due_amt, p.cash_credit
             FROM purchases p
             WHERE {$scope['where']}
             ORDER BY p.invoice_date DESC, p.id DESC",
            $scope['bind']
        );
        return [
            'rows' => $rows,
            'totals' => [
                'count' => count($rows),
                'net' => $this->sum($rows, 'invoice_amt'),
                'due' => $this->sum($rows, 'due_amt'),
            ],
        ];
    }

    /** @param array{where:string,bind:array<string,mixed>} $scope */
    public function allPurchase(array $scope): array
    {
        $rows = $this->rows(
            "SELECT DATE(p.invoice_date) AS invoice_date, p.invoice_no, p.po_no, p.supplier_name,
                    pi.product_name, pi.batch_no, pi.expiry_date, pi.quantity, pi.free_quantity,
                    pi.purchase_rate, pi.mrp, pi.discount_pct, pi.gst_rate, pi.line_total,
                    ROUND(pi.quantity * pi.purchase_rate, 2) AS gross_amt
             FROM purchase_items pi
             JOIN purchases p ON p.id = pi.purchase_id
             WHERE {$scope['where']}
             ORDER BY p.invoice_date DESC, p.id DESC, pi.id ASC
             LIMIT 5000",
            $scope['bind']
        );
        return [
            'rows' => $rows,
            'totals' => [
                'count' => count($rows),
                'qty' => (int) $this->sum($rows, 'quantity'),
                'net' => $this->sum($rows, 'line_total'),
            ],
        ];
    }

    /** @param array{where:string,bind:array<string,mixed>,distributor_name?:string} $scope */
    public function distributorWisePurchase(array $scope): array
    {
        $name = trim((string) ($scope['distributor_name'] ?? ''));
        if ($name !== '') {
            $scope['where'] .= ' AND TRIM(p.supplier_name) = :distributor_name';
            $scope['bind']['distributor_name'] = $name;
            $rows = $this->rows(
                "SELECT p.invoice_no, DATE(p.invoice_date) AS invoice_date, p.supplier_name,
                        pi.product_name, pi.batch_no, pi.quantity, pi.purchase_rate AS rate,
                        ROUND(pi.line_total, 2) AS amount
                 FROM purchase_items pi
                 JOIN purchases p ON p.id = pi.purchase_id
                 WHERE {$scope['where']}
                 ORDER BY p.invoice_date, pi.product_name, pi.batch_no
                 LIMIT 5000",
                $scope['bind']
            );
            return [
                'rows' => $rows,
                'mode' => 'detail',
                'totals' => [
                    'count' => count($rows),
                    'qty' => (int) $this->sum($rows, 'quantity'),
                    'net' => $this->sum($rows, 'amount'),
                ],
            ];
        }

        $rows = $this->rows(
            "SELECT COALESCE(NULLIF(p.supplier_name,''),'(Unknown)') AS supplier_name,
                    COUNT(DISTINCT p.id) AS bills,
                    COALESCE(SUM(p.net_amount),0) AS net_amount,
                    COALESCE(SUM(p.gst_amount),0) AS gst_amount,
                    COALESCE(SUM(p.balance),0) AS balance
             FROM purchases p
             WHERE {$scope['where']}
             GROUP BY COALESCE(NULLIF(p.supplier_name,''),'(Unknown)')
             ORDER BY net_amount DESC",
            $scope['bind']
        );
        return [
            'rows' => $rows,
            'mode' => 'summary',
            'totals' => [
                'count' => count($rows),
                'net' => $this->sum($rows, 'net_amount'),
                'gst' => $this->sum($rows, 'gst_amount'),
            ],
        ];
    }

    /** @param array{where:string,bind:array<string,mixed>,product_name?:string,branchSql?:string} $scope */
    public function productWisePurchase(array $scope): array
    {
        $name = trim((string) ($scope['product_name'] ?? ''));
        if ($name !== '') {
            $scope['where'] .= ' AND (
                TRIM(pi.product_name) = :product_name
                OR EXISTS (
                    SELECT 1 FROM product_bindings pbx
                    WHERE pbx.id = pi.product_binding_id AND TRIM(pbx.product_name) = :product_name2
                )
            )';
            $scope['bind']['product_name'] = $name;
            $scope['bind']['product_name2'] = $name;

            $purchaseRows = $this->rows(
                "SELECT 'Purchase' AS entry_type,
                        p.invoice_no, DATE(p.invoice_date) AS invoice_date, p.supplier_name,
                        pi.product_name, pi.batch_no,
                        DATE_FORMAT(pi.expiry_date, '%Y-%m') AS expiry,
                        pi.quantity, pi.purchase_rate AS rate, pi.mrp,
                        ROUND(pi.line_total, 2) AS amount
                 FROM purchase_items pi
                 JOIN purchases p ON p.id = pi.purchase_id
                 WHERE {$scope['where']}
                 ORDER BY p.invoice_date, pi.batch_no
                 LIMIT 5000",
                $scope['bind']
            );

            // Opening stock lines for the same product within the report date range.
            $openingBind = [
                'c' => $scope['bind']['c'],
                'product_name' => $name,
                'product_name2' => $name,
            ];
            $openingDate = '';
            if (isset($scope['bind']['from'])) {
                $openingDate .= ' AND DATE(l.txn_date) >= :from';
                $openingBind['from'] = $scope['bind']['from'];
            }
            if (isset($scope['bind']['to'])) {
                $openingDate .= ' AND DATE(l.txn_date) <= :to';
                $openingBind['to'] = $scope['bind']['to'];
            }
            $openingBranch = '';
            if (isset($scope['bind']['b'])) {
                $openingBranch = ' AND l.branch_id = :b';
                $openingBind['b'] = $scope['bind']['b'];
            }

            $openingRows = $this->rows(
                "SELECT 'Opening Stock' AS entry_type,
                        COALESCE(NULLIF(TRIM(l.note),''), 'Opening') AS invoice_no,
                        DATE(l.txn_date) AS invoice_date,
                        'Opening Stock' AS supplier_name,
                        pb.product_name,
                        COALESCE(ib.batch_no, '') AS batch_no,
                        DATE_FORMAT(ib.expiry_date, '%Y-%m') AS expiry,
                        l.qty_in AS quantity,
                        COALESCE(ib.purchase_rate, 0) AS rate,
                        COALESCE(ib.mrp, 0) AS mrp,
                        ROUND(l.qty_in * COALESCE(ib.purchase_rate, 0), 2) AS amount
                 FROM stock_ledger l
                 JOIN product_bindings pb ON pb.id = l.product_binding_id
                 LEFT JOIN inventory_batches ib ON ib.id = l.batch_id
                 WHERE l.client_id = :c AND l.txn_type = 'opening'
                   AND (
                     TRIM(pb.product_name) = :product_name
                     OR TRIM(pb.product_name) = :product_name2
                   )
                   {$openingBranch}
                   {$openingDate}
                 ORDER BY l.txn_date, ib.batch_no
                 LIMIT 2000",
                $openingBind
            );

            $rows = array_merge($openingRows, $purchaseRows);
            usort($rows, static function ($a, $b) {
                $da = (string) ($a['invoice_date'] ?? '');
                $db = (string) ($b['invoice_date'] ?? '');
                if ($da === $db) {
                    return strcmp((string) ($a['entry_type'] ?? ''), (string) ($b['entry_type'] ?? ''));
                }
                return strcmp($da, $db);
            });

            return [
                'rows' => $rows,
                'mode' => 'detail',
                'totals' => [
                    'count' => count($rows),
                    'qty' => (int) $this->sum($rows, 'quantity'),
                    'net' => $this->sum($rows, 'amount'),
                ],
            ];
        }

        $rows = $this->rows(
            "SELECT pi.product_name,
                    COALESCE(SUM(pi.quantity),0) AS quantity,
                    COALESCE(SUM(pi.free_quantity),0) AS free_quantity,
                    COALESCE(SUM(pi.line_total),0) AS net_amount,
                    COUNT(DISTINCT p.id) AS bills
             FROM purchase_items pi
             JOIN purchases p ON p.id = pi.purchase_id
             WHERE {$scope['where']}
             GROUP BY pi.product_name
             ORDER BY net_amount DESC
             LIMIT 2000",
            $scope['bind']
        );
        return [
            'rows' => $rows,
            'mode' => 'summary',
            'totals' => [
                'count' => count($rows),
                'qty' => (int) $this->sum($rows, 'quantity'),
                'net' => $this->sum($rows, 'net_amount'),
            ],
        ];
    }

    /** @param array{where:string,bind:array<string,mixed>,manufacturer?:string} $scope */
    public function manufacturerWisePurchase(array $scope): array
    {
        $name = trim((string) ($scope['manufacturer'] ?? ''));
        if ($name !== '') {
            $scope['where'] .= ' AND (TRIM(COALESCE(pb.manufacturer_short,\'\')) = :manufacturer
                OR TRIM(COALESCE(pb.manufacturer,\'\')) = :manufacturer2)';
            $scope['bind']['manufacturer'] = $name;
            $scope['bind']['manufacturer2'] = $name;
            $rows = $this->rows(
                "SELECT p.invoice_no, DATE(p.invoice_date) AS invoice_date, p.supplier_name,
                        pi.product_name, pi.batch_no, pi.quantity, pi.purchase_rate AS rate,
                        COALESCE(NULLIF(pb.manufacturer_short,''), pb.manufacturer) AS manufacturer,
                        ROUND(pi.line_total, 2) AS amount
                 FROM purchase_items pi
                 JOIN purchases p ON p.id = pi.purchase_id
                 LEFT JOIN product_bindings pb ON pb.id = pi.product_binding_id
                 WHERE {$scope['where']}
                 ORDER BY p.invoice_date, pi.product_name, pi.batch_no
                 LIMIT 5000",
                $scope['bind']
            );
            return [
                'rows' => $rows,
                'mode' => 'detail',
                'totals' => [
                    'count' => count($rows),
                    'qty' => (int) $this->sum($rows, 'quantity'),
                    'net' => $this->sum($rows, 'amount'),
                ],
            ];
        }

        $rows = $this->rows(
            "SELECT COALESCE(NULLIF(pb.manufacturer_short,''), COALESCE(NULLIF(pb.manufacturer,''),'(Unknown)')) AS manufacturer,
                    COALESCE(SUM(pi.quantity),0) AS quantity,
                    COALESCE(SUM(pi.line_total),0) AS net_amount,
                    COUNT(DISTINCT p.id) AS bills
             FROM purchase_items pi
             JOIN purchases p ON p.id = pi.purchase_id
             LEFT JOIN product_bindings pb ON pb.id = pi.product_binding_id
             WHERE {$scope['where']}
             GROUP BY manufacturer
             ORDER BY net_amount DESC",
            $scope['bind']
        );
        return [
            'rows' => $rows,
            'mode' => 'summary',
            'totals' => [
                'count' => count($rows),
                'qty' => (int) $this->sum($rows, 'quantity'),
                'net' => $this->sum($rows, 'net_amount'),
            ],
        ];
    }

    /** @param array{where:string,bind:array<string,mixed>,category?:string} $scope */
    public function categoryWisePurchase(array $scope): array
    {
        $name = trim((string) ($scope['category'] ?? ''));
        if ($name !== '') {
            $scope['where'] .= ' AND COALESCE(NULLIF(TRIM(pb.product_category),\'\'),\'Allopathy\') = :category';
            $scope['bind']['category'] = $name;
            $rows = $this->rows(
                "SELECT p.invoice_no, DATE(p.invoice_date) AS invoice_date, p.supplier_name,
                        pi.product_name, pi.batch_no, pi.quantity, pi.purchase_rate AS rate,
                        COALESCE(NULLIF(pb.product_category,''),'Allopathy') AS category,
                        ROUND(pi.line_total, 2) AS amount
                 FROM purchase_items pi
                 JOIN purchases p ON p.id = pi.purchase_id
                 LEFT JOIN product_bindings pb ON pb.id = pi.product_binding_id
                 WHERE {$scope['where']}
                 ORDER BY p.invoice_date, pi.product_name, pi.batch_no
                 LIMIT 5000",
                $scope['bind']
            );
            return [
                'rows' => $rows,
                'mode' => 'detail',
                'totals' => [
                    'count' => count($rows),
                    'qty' => (int) $this->sum($rows, 'quantity'),
                    'net' => $this->sum($rows, 'amount'),
                ],
            ];
        }

        $rows = $this->rows(
            "SELECT COALESCE(NULLIF(pb.product_category,''),'Allopathy') AS category,
                    COALESCE(SUM(pi.quantity),0) AS quantity,
                    COALESCE(SUM(pi.line_total),0) AS net_amount,
                    COUNT(DISTINCT p.id) AS bills
             FROM purchase_items pi
             JOIN purchases p ON p.id = pi.purchase_id
             LEFT JOIN product_bindings pb ON pb.id = pi.product_binding_id
             WHERE {$scope['where']}
             GROUP BY category
             ORDER BY net_amount DESC",
            $scope['bind']
        );
        return [
            'rows' => $rows,
            'mode' => 'summary',
            'totals' => [
                'count' => count($rows),
                'net' => $this->sum($rows, 'net_amount'),
            ],
        ];
    }

    /** @param array{where:string,bind:array<string,mixed>} $scope */
    public function dateWiseSale(array $scope): array
    {
        $rows = $this->rows(
            "SELECT DATE(s.invoice_date) AS invoice_date,
                    COUNT(*) AS bills,
                    COALESCE(SUM(s.net_amount),0) AS net_amount,
                    COALESCE(SUM(s.gst_amount),0) AS gst_amount,
                    COALESCE(SUM(s.profit_amount),0) AS profit_amount
             FROM sales s
             WHERE {$scope['where']}
             GROUP BY DATE(s.invoice_date)
             ORDER BY invoice_date DESC",
            $scope['bind']
        );
        return [
            'rows' => $rows,
            'totals' => [
                'count' => (int) $this->sum($rows, 'bills'),
                'net' => $this->sum($rows, 'net_amount'),
                'profit' => $this->sum($rows, 'profit_amount'),
            ],
        ];
    }

    /** @param array{where:string,bind:array<string,mixed>} $scope */
    public function allProductWiseSale(array $scope): array
    {
        $rows = $this->rows(
            "SELECT DATE(s.invoice_date) AS invoice_date, s.invoice_no,
                    COALESCE(NULLIF(s.patient_name,''), s.customer_name) AS customer_name,
                    si.product_name, si.batch_no, si.quantity, si.rate, si.mrp,
                    si.discount_pct, si.gst_rate, si.taxable, si.line_total, si.profit
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             WHERE {$scope['where']}
             ORDER BY s.invoice_date DESC, s.id DESC, si.id ASC
             LIMIT 5000",
            $scope['bind']
        );
        return [
            'rows' => $this->scrubNames($rows),
            'totals' => [
                'count' => count($rows),
                'qty' => (int) $this->sum($rows, 'quantity'),
                'net' => $this->sum($rows, 'line_total'),
                'profit' => $this->sum($rows, 'profit'),
            ],
        ];
    }

    /** @param array{where:string,bind:array<string,mixed>,product_name?:string} $scope */
    public function productWiseSale(array $scope): array
    {
        $name = trim((string) ($scope['product_name'] ?? ''));
        if ($name !== '') {
            $scope['where'] .= ' AND (
                TRIM(si.product_name) = :product_name
                OR EXISTS (
                    SELECT 1 FROM product_bindings pbx
                    WHERE pbx.id = si.product_binding_id AND TRIM(pbx.product_name) = :product_name2
                )
            )';
            $scope['bind']['product_name'] = $name;
            $scope['bind']['product_name2'] = $name;
            $rows = $this->rows(
                "SELECT DATE(s.invoice_date) AS sale_date,
                        COALESCE(NULLIF(s.patient_name,''), s.customer_name) AS customer_name,
                        s.invoice_no AS bill_no,
                        si.product_name, si.batch_no, si.quantity,
                        ROUND(si.line_total, 2) AS net_amount
                 FROM sale_items si
                 JOIN sales s ON s.id = si.sale_id
                 WHERE {$scope['where']}
                 ORDER BY s.invoice_date, si.batch_no
                 LIMIT 5000",
                $scope['bind']
            );
            return [
                'rows' => $this->scrubNames($rows),
                'mode' => 'detail',
                'totals' => [
                    'count' => count($rows),
                    'qty' => (int) $this->sum($rows, 'quantity'),
                    'net' => $this->sum($rows, 'net_amount'),
                ],
            ];
        }

        $rows = $this->rows(
            "SELECT si.product_name,
                    COALESCE(SUM(si.quantity),0) AS quantity,
                    COALESCE(SUM(si.line_total),0) AS net_amount,
                    COALESCE(SUM(si.profit),0) AS profit,
                    COUNT(DISTINCT s.id) AS bills
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             WHERE {$scope['where']}
             GROUP BY si.product_name
             ORDER BY net_amount DESC
             LIMIT 2000",
            $scope['bind']
        );
        return [
            'rows' => $this->scrubNames($rows),
            'mode' => 'summary',
            'totals' => [
                'count' => count($rows),
                'qty' => (int) $this->sum($rows, 'quantity'),
                'net' => $this->sum($rows, 'net_amount'),
                'profit' => $this->sum($rows, 'profit'),
            ],
        ];
    }

    /** @param array{where:string,bind:array<string,mixed>,manufacturer?:string} $scope */
    public function manufacturerWiseSale(array $scope): array
    {
        $name = trim((string) ($scope['manufacturer'] ?? ''));
        if ($name !== '') {
            $scope['where'] .= ' AND (TRIM(COALESCE(si.manufacturer_short,\'\')) = :manufacturer
                OR TRIM(COALESCE(pb.manufacturer,\'\')) = :manufacturer2)';
            $scope['bind']['manufacturer'] = $name;
            $scope['bind']['manufacturer2'] = $name;
            $rows = $this->rows(
                "SELECT DATE(s.invoice_date) AS sale_date,
                        COALESCE(NULLIF(s.patient_name,''), s.customer_name) AS customer_name,
                        s.invoice_no AS bill_no,
                        si.product_name, si.batch_no, si.quantity,
                        ROUND(si.line_total, 2) AS net_amount
                 FROM sale_items si
                 JOIN sales s ON s.id = si.sale_id
                 LEFT JOIN product_bindings pb ON pb.id = si.product_binding_id
                 WHERE {$scope['where']}
                 ORDER BY s.invoice_date, si.product_name, si.batch_no
                 LIMIT 5000",
                $scope['bind']
            );
            return [
                'rows' => $this->scrubNames($rows),
                'mode' => 'detail',
                'totals' => [
                    'count' => count($rows),
                    'qty' => (int) $this->sum($rows, 'quantity'),
                    'net' => $this->sum($rows, 'net_amount'),
                ],
            ];
        }

        $rows = $this->rows(
            "SELECT COALESCE(NULLIF(si.manufacturer_short,''),'(Unknown)') AS manufacturer,
                    COALESCE(SUM(si.quantity),0) AS quantity,
                    COALESCE(SUM(si.line_total),0) AS net_amount,
                    COALESCE(SUM(si.profit),0) AS profit
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             WHERE {$scope['where']}
             GROUP BY manufacturer
             ORDER BY net_amount DESC",
            $scope['bind']
        );
        return [
            'rows' => $rows,
            'mode' => 'summary',
            'totals' => [
                'count' => count($rows),
                'net' => $this->sum($rows, 'net_amount'),
                'profit' => $this->sum($rows, 'profit'),
            ],
        ];
    }

    /** @param array{where:string,bind:array<string,mixed>,category?:string} $scope */
    public function categoryWiseSale(array $scope): array
    {
        $name = trim((string) ($scope['category'] ?? ''));
        if ($name !== '') {
            $scope['where'] .= ' AND COALESCE(NULLIF(TRIM(pb.product_category),\'\'),\'Allopathy\') = :category';
            $scope['bind']['category'] = $name;
            $rows = $this->rows(
                "SELECT DATE(s.invoice_date) AS sale_date,
                        COALESCE(NULLIF(s.patient_name,''), s.customer_name) AS customer_name,
                        s.invoice_no AS bill_no,
                        si.product_name, si.batch_no, si.quantity,
                        ROUND(si.line_total, 2) AS net_amount
                 FROM sale_items si
                 JOIN sales s ON s.id = si.sale_id
                 LEFT JOIN product_bindings pb ON pb.id = si.product_binding_id
                 WHERE {$scope['where']}
                 ORDER BY s.invoice_date, si.product_name, si.batch_no
                 LIMIT 5000",
                $scope['bind']
            );
            return [
                'rows' => $this->scrubNames($rows),
                'mode' => 'detail',
                'totals' => [
                    'count' => count($rows),
                    'qty' => (int) $this->sum($rows, 'quantity'),
                    'net' => $this->sum($rows, 'net_amount'),
                ],
            ];
        }

        $rows = $this->rows(
            "SELECT COALESCE(NULLIF(pb.product_category,''),'Allopathy') AS category,
                    COALESCE(SUM(si.quantity),0) AS quantity,
                    COALESCE(SUM(si.line_total),0) AS net_amount,
                    COALESCE(SUM(si.profit),0) AS profit
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             LEFT JOIN product_bindings pb ON pb.id = si.product_binding_id
             WHERE {$scope['where']}
             GROUP BY category
             ORDER BY net_amount DESC",
            $scope['bind']
        );
        return [
            'rows' => $rows,
            'mode' => 'summary',
            'totals' => [
                'count' => count($rows),
                'net' => $this->sum($rows, 'net_amount'),
            ],
        ];
    }

    /** @param array{where:string,bind:array<string,mixed>,customer?:string} $scope */
    public function customerWiseSale(array $scope): array
    {
        $customer = trim((string) ($scope['customer'] ?? ''));
        if ($customer !== '') {
            if (ctype_digit($customer)) {
                $scope['where'] .= ' AND s.customer_id = :customer_id';
                $scope['bind']['customer_id'] = (int) $customer;
            } else {
                $scope['where'] .= ' AND (TRIM(s.customer_name) = :customer_name OR TRIM(s.patient_name) = :customer_name2)';
                $scope['bind']['customer_name'] = $customer;
                $scope['bind']['customer_name2'] = $customer;
            }
            $rows = $this->rows(
                "SELECT s.invoice_no AS bill_no, DATE(s.invoice_date) AS bill_date,
                        si.product_name, si.batch_no, si.quantity, si.mrp,
                        ROUND(si.line_total, 2) AS net_amount
                 FROM sale_items si
                 JOIN sales s ON s.id = si.sale_id
                 WHERE {$scope['where']}
                 ORDER BY s.invoice_date, s.invoice_no, si.product_name
                 LIMIT 5000",
                $scope['bind']
            );
            return [
                'rows' => $this->scrubNames($rows),
                'mode' => 'detail',
                'totals' => [
                    'count' => count($rows),
                    'qty' => (int) $this->sum($rows, 'quantity'),
                    'net' => $this->sum($rows, 'net_amount'),
                ],
            ];
        }

        $rows = $this->rows(
            "SELECT COALESCE(NULLIF(s.customer_name,''),'(Walk-in)') AS customer_name,
                    COUNT(*) AS bills,
                    COALESCE(SUM(s.net_amount),0) AS net_amount,
                    COALESCE(SUM(s.balance),0) AS balance,
                    COALESCE(SUM(s.profit_amount),0) AS profit
             FROM sales s
             WHERE {$scope['where']}
             GROUP BY COALESCE(NULLIF(s.customer_name,''),'(Walk-in)')
             ORDER BY net_amount DESC
             LIMIT 2000",
            $scope['bind']
        );
        return [
            'rows' => $rows,
            'mode' => 'summary',
            'totals' => [
                'count' => count($rows),
                'net' => $this->sum($rows, 'net_amount'),
            ],
        ];
    }

    /** @param array{where:string,bind:array<string,mixed>} $scope */
    public function doctorWiseDetail(array $scope): array
    {
        $rows = $this->rows(
            "SELECT s.invoice_no AS bill_no, DATE(s.invoice_date) AS bill_date,
                    COALESCE(NULLIF(s.patient_name,''), s.customer_name) AS patient_name,
                    si.product_name, si.quantity,
                    ROUND(si.line_total, 2) AS amount
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             WHERE {$scope['where']}
             ORDER BY s.invoice_date, s.invoice_no, si.product_name
             LIMIT 5000",
            $scope['bind']
        );
        return [
            'rows' => $this->scrubNames($rows),
            'mode' => 'detail',
            'totals' => [
                'count' => count($rows),
                'qty' => (int) $this->sum($rows, 'quantity'),
                'net' => $this->sum($rows, 'amount'),
            ],
        ];
    }

    /** Free qty from purchases (Advanced sale lines do not store free qty). */
    /** @param array{where:string,bind:array<string,mixed>} $scope */
    public function freeQty(array $scope): array
    {
        $rows = $this->rows(
            "SELECT pi.product_name, pi.batch_no,
                    COALESCE(SUM(pi.free_quantity),0) AS free_quantity,
                    COALESCE(SUM(pi.quantity),0) AS quantity
             FROM purchase_items pi
             JOIN purchases p ON p.id = pi.purchase_id
             WHERE {$scope['where']} AND pi.free_quantity > 0
             GROUP BY pi.product_name, pi.batch_no
             ORDER BY free_quantity DESC
             LIMIT 2000",
            $scope['bind']
        );
        return [
            'rows' => $rows,
            'totals' => [
                'count' => count($rows),
                'free' => (int) $this->sum($rows, 'free_quantity'),
            ],
        ];
    }

    /** @param array{where:string,bind:array<string,mixed>} $scope */
    public function purchaseGst(array $scope): array
    {
        $rows = $this->rows(
            "SELECT DATE(p.invoice_date) AS invoice_date, p.invoice_no, p.po_no, p.supplier_name,
                    p.net_amount, p.gst_amount,
                    ROUND(p.gst_amount / 2, 2) AS cgst,
                    ROUND(p.gst_amount / 2, 2) AS sgst,
                    0 AS igst
             FROM purchases p
             WHERE {$scope['where']}
             ORDER BY p.invoice_date DESC, p.id DESC",
            $scope['bind']
        );
        return [
            'rows' => $rows,
            'totals' => [
                'count' => count($rows),
                'net' => $this->sum($rows, 'net_amount'),
                'gst' => $this->sum($rows, 'gst_amount'),
            ],
        ];
    }

    /** @param array{where:string,bind:array<string,mixed>} $scope */
    public function salesGst(array $scope): array
    {
        $rows = $this->rows(
            "SELECT DATE(s.invoice_date) AS invoice_date, s.invoice_no, s.customer_name,
                    s.taxable_amount, s.cgst, s.sgst, s.igst, s.gst_amount, s.net_amount
             FROM sales s
             WHERE {$scope['where']}
             ORDER BY s.invoice_date DESC, s.id DESC",
            $scope['bind']
        );
        return [
            'rows' => $rows,
            'totals' => [
                'count' => count($rows),
                'taxable' => $this->sum($rows, 'taxable_amount'),
                'gst' => $this->sum($rows, 'gst_amount'),
                'net' => $this->sum($rows, 'net_amount'),
            ],
        ];
    }

    /** @param array{where:string,bind:array<string,mixed>} $scope */
    public function saleGstSlabWise(array $scope): array
    {
        $rows = $this->rows(
            "SELECT si.gst_rate,
                    COALESCE(SUM(si.taxable),0) AS taxable,
                    COALESCE(SUM(si.taxable * si.gst_rate / 100),0) AS gst,
                    COALESCE(SUM(si.line_total),0) AS net_amount
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             WHERE {$scope['where']}
             GROUP BY si.gst_rate
             ORDER BY si.gst_rate ASC",
            $scope['bind']
        );
        foreach ($rows as &$r) {
            $g = (float) $r['gst'];
            $r['cgst'] = round($g / 2, 2);
            $r['sgst'] = round($g / 2, 2);
            $r['igst'] = 0;
            $r['gst'] = round($g, 2);
            $r['taxable'] = round((float) $r['taxable'], 2);
            $r['net_amount'] = round((float) $r['net_amount'], 2);
        }
        unset($r);
        return [
            'rows' => $rows,
            'totals' => [
                'count' => count($rows),
                'taxable' => $this->sum($rows, 'taxable'),
                'gst' => $this->sum($rows, 'gst'),
            ],
        ];
    }

    /** @param array{where:string,bind:array<string,mixed>} $scope */
    public function purchaseGstSlabWise(array $scope): array
    {
        $rows = $this->rows(
            "SELECT pi.gst_rate,
                    COALESCE(SUM(pi.quantity * pi.purchase_rate * (1 - pi.discount_pct/100)),0) AS taxable,
                    COALESCE(SUM(pi.line_total - (pi.quantity * pi.purchase_rate * (1 - pi.discount_pct/100))),0) AS gst_approx,
                    COALESCE(SUM(pi.line_total),0) AS net_amount
             FROM purchase_items pi
             JOIN purchases p ON p.id = pi.purchase_id
             WHERE {$scope['where']}
             GROUP BY pi.gst_rate
             ORDER BY pi.gst_rate ASC",
            $scope['bind']
        );
        foreach ($rows as &$r) {
            $taxable = (float) $r['taxable'];
            $rate = (float) $r['gst_rate'];
            $gst = round($taxable * $rate / 100, 2);
            $r['taxable'] = round($taxable, 2);
            $r['gst'] = $gst;
            $r['cgst'] = round($gst / 2, 2);
            $r['sgst'] = round($gst / 2, 2);
            $r['igst'] = 0;
            $r['net_amount'] = round((float) $r['net_amount'], 2);
            unset($r['gst_approx']);
        }
        unset($r);
        return [
            'rows' => $rows,
            'totals' => [
                'count' => count($rows),
                'taxable' => $this->sum($rows, 'taxable'),
                'gst' => $this->sum($rows, 'gst'),
            ],
        ];
    }

    /** @param array{where:string,bind:array<string,mixed>} $scope */
    public function allProductSaleGst(array $scope): array
    {
        $rows = $this->rows(
            "SELECT DATE(s.invoice_date) AS invoice_date, s.invoice_no, s.customer_name,
                    si.product_name, si.hsn_code, si.batch_no, si.quantity, si.gst_rate,
                    si.taxable,
                    ROUND(si.taxable * si.gst_rate / 100, 2) AS gst,
                    ROUND(si.taxable * si.gst_rate / 200, 2) AS cgst,
                    ROUND(si.taxable * si.gst_rate / 200, 2) AS sgst,
                    si.line_total AS net_amount
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             WHERE {$scope['where']}
             ORDER BY s.invoice_date DESC
             LIMIT 5000",
            $scope['bind']
        );
        return [
            'rows' => $rows,
            'totals' => [
                'count' => count($rows),
                'taxable' => $this->sum($rows, 'taxable'),
                'gst' => $this->sum($rows, 'gst'),
            ],
        ];
    }

    /** @param array{where:string,bind:array<string,mixed>} $scope */
    public function allProductPurchaseGst(array $scope): array
    {
        $rows = $this->rows(
            "SELECT DATE(p.invoice_date) AS invoice_date, p.invoice_no, p.supplier_name,
                    pi.product_name, pi.hsn_code, pi.batch_no, pi.quantity, pi.gst_rate,
                    ROUND(pi.quantity * pi.purchase_rate * (1 - pi.discount_pct/100), 2) AS taxable,
                    ROUND(pi.quantity * pi.purchase_rate * (1 - pi.discount_pct/100) * pi.gst_rate / 100, 2) AS gst,
                    ROUND(pi.quantity * pi.purchase_rate * (1 - pi.discount_pct/100) * pi.gst_rate / 200, 2) AS cgst,
                    ROUND(pi.quantity * pi.purchase_rate * (1 - pi.discount_pct/100) * pi.gst_rate / 200, 2) AS sgst,
                    pi.line_total AS net_amount
             FROM purchase_items pi
             JOIN purchases p ON p.id = pi.purchase_id
             WHERE {$scope['where']}
             ORDER BY p.invoice_date DESC
             LIMIT 5000",
            $scope['bind']
        );
        return [
            'rows' => $rows,
            'totals' => [
                'count' => count($rows),
                'taxable' => $this->sum($rows, 'taxable'),
                'gst' => $this->sum($rows, 'gst'),
            ],
        ];
    }

    /** @param array{where:string,bind:array<string,mixed>} $scope */
    public function distributorPayments(array $scope): array
    {
        $rows = $this->rows(
            "SELECT DATE(p.invoice_date) AS invoice_date, p.invoice_no, p.po_no, p.supplier_name,
                    p.net_amount AS bill_amount, p.paid, p.balance, p.cash_credit, p.payment_status
             FROM purchases p
             WHERE {$scope['where']}
             ORDER BY p.invoice_date DESC",
            $scope['bind']
        );
        // Retail parity: nearest-rupee for bill / paid / balance (credit dues).
        foreach ($rows as &$r) {
            $r['bill_amount'] = round((float) ($r['bill_amount'] ?? 0), 0);
            $r['paid'] = round((float) ($r['paid'] ?? 0), 0);
            $r['balance'] = round((float) ($r['balance'] ?? 0), 0);
        }
        unset($r);
        return [
            'rows' => $rows,
            'totals' => [
                'count' => count($rows),
                'bill' => round($this->sum($rows, 'bill_amount'), 0),
                'paid' => round($this->sum($rows, 'paid'), 0),
                'balance' => round($this->sum($rows, 'balance'), 0),
            ],
        ];
    }

    /** @param array{where:string,bind:array<string,mixed>} $scope */
    public function customerPayments(array $scope): array
    {
        $rows = $this->rows(
            "SELECT DATE(s.invoice_date) AS invoice_date, s.invoice_no, s.customer_name,
                    s.net_amount AS bill_amount, s.paid, s.balance, s.payment_mode
             FROM sales s
             WHERE {$scope['where']}
             ORDER BY s.invoice_date DESC",
            $scope['bind']
        );
        return [
            'rows' => $rows,
            'totals' => [
                'count' => count($rows),
                'bill' => $this->sum($rows, 'bill_amount'),
                'paid' => $this->sum($rows, 'paid'),
                'balance' => $this->sum($rows, 'balance'),
            ],
        ];
    }

    /** Product P/L (Retail-style: sale line revenue − line cost). cost_price is already line cost. */
    /** @param array{where:string,bind:array<string,mixed>} $scope */
    public function profitLoss(array $scope): array
    {
        $rows = $this->rows(
            "SELECT si.product_name,
                    COALESCE(SUM(si.quantity),0) AS quantity,
                    ROUND(AVG(si.rate),2) AS sale_rate,
                    ROUND(AVG(CASE WHEN si.quantity > 0 THEN si.cost_price / si.quantity ELSE 0 END),2) AS purchase_rate,
                    COALESCE(SUM(si.line_total),0) AS sale_value,
                    COALESCE(SUM(si.cost_price),0) AS purchase_value,
                    COALESCE(SUM(si.profit),0) AS profit
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             WHERE {$scope['where']}
             GROUP BY si.product_name
             ORDER BY profit DESC
             LIMIT 2000",
            $scope['bind']
        );
        return [
            'rows' => $rows,
            'totals' => [
                'count' => count($rows),
                'sale' => $this->sum($rows, 'sale_value'),
                'purchase' => $this->sum($rows, 'purchase_value'),
                'profit' => $this->sum($rows, 'profit'),
            ],
        ];
    }

    /** Distributor P/L = purchase net vs sale of products bought from that supplier (approx via purchase lines). */
    /** @param array{whereSale:string,bindSale:array<string,mixed>,wherePur:string,bindPur:array<string,mixed>} $scopes */
    public function distributorProfitLoss(array $scopes): array
    {
        $purchase = $this->rows(
            "SELECT COALESCE(NULLIF(p.supplier_name,''),'(Unknown)') AS supplier_name,
                    COALESCE(SUM(p.net_amount),0) AS purchase_value
             FROM purchases p
             WHERE {$scopes['wherePur']}
             GROUP BY supplier_name",
            $scopes['bindPur']
        );
        $sale = $this->rows(
            "SELECT COALESCE(NULLIF(p.supplier_name,''),'(Unknown)') AS supplier_name,
                    COALESCE(SUM(si.line_total),0) AS sale_value,
                    COALESCE(SUM(si.profit),0) AS profit
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             JOIN purchase_items pi ON pi.product_binding_id = si.product_binding_id
                  AND pi.batch_no = si.batch_no
             JOIN purchases p ON p.id = pi.purchase_id
             WHERE {$scopes['whereSale']}
             GROUP BY supplier_name",
            $scopes['bindSale']
        );
        $map = [];
        foreach ($purchase as $r) {
            $k = (string) $r['supplier_name'];
            $map[$k] = [
                'supplier_name' => $k,
                'purchase_value' => (float) $r['purchase_value'],
                'sale_value' => 0.0,
                'profit' => 0.0,
            ];
        }
        foreach ($sale as $r) {
            $k = (string) $r['supplier_name'];
            if (!isset($map[$k])) {
                $map[$k] = [
                    'supplier_name' => $k,
                    'purchase_value' => 0.0,
                    'sale_value' => 0.0,
                    'profit' => 0.0,
                ];
            }
            $map[$k]['sale_value'] = (float) $r['sale_value'];
            $map[$k]['profit'] = (float) $r['profit'];
        }
        $rows = array_values($map);
        usort($rows, static fn ($a, $b) => $b['profit'] <=> $a['profit']);
        return [
            'rows' => $rows,
            'totals' => [
                'count' => count($rows),
                'purchase' => $this->sum($rows, 'purchase_value'),
                'sale' => $this->sum($rows, 'sale_value'),
                'profit' => $this->sum($rows, 'profit'),
            ],
        ];
    }

    /**
     * Batch-wise stock report.
     *
     * Qty unit by client business_type:
     *   retail / both → tablets (base units)
     *   wholesale     → strips/packs
     *
     * Purchase qty = purchase bill pack qty (+ free) only (not opening/transfer).
     * Sale qty     = sold qty (sale ledger / sale_items; not transfers).
     * Return qty   = retail sale return only.
     * DB note qty  = debit note / purchase_return.
     *
     * @param array{branchSqlIb:string,branchSqlL:string,branchSqlP?:string,bind:array<string,mixed>,manufacturer?:string,qty_mode?:string,business_type?:string} $scope
     * @return array{rows:list<array<string,mixed>>,total_value:float,total_items:int,totals:array<string,float>,qty_unit:string,business_type:string}
     */
    public function stockReport(array $scope): array
    {
        $bind = $scope['bind'];
        $businessType = strtolower(trim((string) ($scope['business_type'] ?? 'both')));
        if (!in_array($businessType, ['retail', 'wholesale', 'both'], true)) {
            $businessType = 'both';
        }
        // wholesale → strips; retail and both → tablets
        if (isset($scope['qty_mode'])) {
            $packMode = $scope['qty_mode'] === 'pack';
        } else {
            $packMode = $businessType === 'wholesale';
        }
        $qtyUnit = $packMode ? 'strip' : 'tablet';

        $mfrFilter = '';
        if (!empty($scope['manufacturer'])) {
            $mfrFilter = ' AND (pb.manufacturer_short = :mfr OR pb.manufacturer = :mfr2)';
            $bind['mfr'] = $scope['manufacturer'];
            $bind['mfr2'] = $scope['manufacturer'];
        }

        $branchSqlP = (string) ($scope['branchSqlP'] ?? '');
        if ($branchSqlP === '' && preg_match('/ib\.branch_id\s*=\s*(\d+)/', (string) $scope['branchSqlIb'], $m)) {
            $branchSqlP = ' AND p.branch_id = ' . (int) $m[1];
        }
        $branchSqlSi = '';
        if (preg_match('/ib\.branch_id\s*=\s*(\d+)/', (string) $scope['branchSqlIb'], $m)) {
            $branchSqlSi = ' AND s.branch_id = ' . (int) $m[1];
        }

        $rows = $this->rows(
            "SELECT ib.id AS batch_id,
                    COALESCE(pb.hsn_code, '') AS hsn,
                    pb.product_name,
                    ib.batch_no,
                    COALESCE(pb.pack_size, '') AS pack_size,
                    COALESCE(NULLIF(pb.units_per_pack, 0), 1) AS units_per_pack,
                    ib.purchase_rate AS rate,
                    ib.mrp,
                    DATE_FORMAT(ib.expiry_date, '%Y-%m') AS expiry,
                    ib.quantity AS available_base_qty
             FROM inventory_batches ib
             JOIN product_bindings pb ON pb.id = ib.product_binding_id
             WHERE ib.client_id = :c {$scope['branchSqlIb']}
               AND ib.quantity > 0
               {$mfrFilter}
             ORDER BY pb.product_name, ib.batch_no",
            $bind
        );

        if ($rows === []) {
            return [
                'rows' => [],
                'total_value' => 0.0,
                'total_items' => 0,
                'qty_unit' => $qtyUnit,
                'business_type' => $businessType,
                'totals' => ['rate' => 0.0, 'mrp' => 0.0, 'stock_value' => 0.0],
            ];
        }

        // Purchase bill qty only (packs on bill + free packs) — exclude opening / transfer_in.
        $purRows = $this->rows(
            "SELECT ib.id AS batch_id,
                    SUM(pi.quantity + COALESCE(pi.free_quantity, 0)) AS purchase_packs,
                    SUM((pi.quantity + COALESCE(pi.free_quantity, 0))
                        * COALESCE(NULLIF(pi.units_per_pack, 0), 1)) AS purchase_base
             FROM purchase_items pi
             JOIN purchases p ON p.id = pi.purchase_id
             JOIN inventory_batches ib
               ON ib.client_id = p.client_id
              AND ib.branch_id = p.branch_id
              AND ib.product_binding_id = pi.product_binding_id
              AND ib.batch_no = pi.batch_no
             WHERE p.client_id = :c {$branchSqlP}
             GROUP BY ib.id",
            $scope['bind']
        );
        $purMap = [];
        foreach ($purRows as $pr) {
            $purMap[(int) $pr['batch_id']] = $pr;
        }

        // Sold qty from sale_items (always base/loose units in Advanced).
        $saleRows = $this->rows(
            "SELECT si.batch_id,
                    SUM(si.quantity) AS sale_qty,
                    SUM(COALESCE(si.free_quantity, 0)) AS free_qty
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             WHERE s.client_id = :c {$branchSqlSi}
               AND si.batch_id IS NOT NULL
             GROUP BY si.batch_id",
            $scope['bind']
        );
        $saleMap = [];
        foreach ($saleRows as $sr) {
            $saleMap[(int) $sr['batch_id']] = $sr;
        }

        // Ledger columns — exclude system stock moves that reuse the same txn_type:
        //   purchase_return + ref purchase  = purchase edit / purchase reverse (NOT debit note)
        //   adjustment + ref sale/purchase  = sale edit restore / free-pack fix (NOT physical adj)
        $mvRows = $this->rows(
            "SELECT l.batch_id,
                    SUM(CASE WHEN l.txn_type = 'purchase_return'
                              AND l.ref_type = 'debit_note'
                             THEN l.qty_out ELSE 0 END) AS dbnote_qty,
                    SUM(CASE WHEN l.txn_type = 'sale_return'
                              AND (l.ref_type = 'retail_sale_return'
                                   OR COALESCE(l.note,'') LIKE 'Retail sale return%')
                             THEN l.qty_in ELSE 0 END) AS return_qty,
                    SUM(CASE WHEN l.txn_type = 'adjustment'
                              AND l.ref_type = 'stock_adjustment'
                             THEN l.qty_in ELSE 0 END) AS adj_in_qty,
                    SUM(CASE WHEN l.txn_type = 'adjustment'
                              AND l.ref_type = 'stock_adjustment'
                             THEN l.qty_out ELSE 0 END) AS adj_out_qty
             FROM stock_ledger l
             WHERE l.client_id = :c {$scope['branchSqlL']}
               AND l.batch_id IS NOT NULL
             GROUP BY l.batch_id",
            $scope['bind']
        );
        $mvMap = [];
        foreach ($mvRows as $mv) {
            $mvMap[(int) $mv['batch_id']] = $mv;
        }

        $totalValue = 0.0;
        $sumRate = 0.0;
        $sumMrp = 0.0;
        foreach ($rows as &$r) {
            $upp = max(1, (int) ($r['units_per_pack'] ?? 1));
            if ($upp <= 1 && trim((string) ($r['pack_size'] ?? '')) !== '') {
                $parsed = \App\Support\PackSize::parse((string) $r['pack_size']);
                $upp = max(1, (int) ($parsed['units_per_pack'] ?? 1));
            }

            $bid = (int) $r['batch_id'];
            $pur = $purMap[$bid] ?? [];
            $sale = $saleMap[$bid] ?? [];
            $mv = $mvMap[$bid] ?? [];

            $purchasePacks = (float) ($pur['purchase_packs'] ?? 0);
            $purchaseBase = (float) ($pur['purchase_base'] ?? 0);
            // Fallback if purchase_items upp missing: packs × current upp
            if ($purchaseBase <= 0 && $purchasePacks > 0) {
                $purchaseBase = $purchasePacks * $upp;
            }

            $saleBase = (float) ($sale['sale_qty'] ?? 0);
            $freeBase = (float) ($sale['free_qty'] ?? 0);
            $dbnoteBase = (float) ($mv['dbnote_qty'] ?? 0);
            $returnBase = (float) ($mv['return_qty'] ?? 0);
            $adjBase = (float) ($mv['adj_in_qty'] ?? 0) - (float) ($mv['adj_out_qty'] ?? 0);
            $availBase = (float) $r['available_base_qty'];

            $show = static function (float $base, float $packsHint = -1.0) use ($packMode, $upp): float {
                if ($packMode) {
                    if ($packsHint >= 0) {
                        return round($packsHint, 3);
                    }
                    return round($base / max(1, $upp), 3);
                }
                return round($base, 3);
            };

            $r['purchase_qty'] = $show($purchaseBase, $purchasePacks);
            $r['dbnote_qty'] = $show($dbnoteBase);
            $r['sale_qty'] = $show($saleBase);
            $r['free_qty'] = $show($freeBase);
            $r['sale_free_qty'] = round($r['sale_qty'] + $r['free_qty'], 3);
            $r['return_qty'] = $show($returnBase);
            $r['adj_qty'] = $show($adjBase);
            $r['available_qty'] = $show($availBase);

            $unitRate = (float) $r['rate'];
            $unitMrp = (float) $r['mrp'];
            // Strip mode: pack rate/MRP; tablet mode: per-unit rate/MRP.
            if ($packMode) {
                $r['rate'] = round($unitRate * $upp, 4);
                $r['mrp'] = round($unitMrp * $upp, 4);
            } else {
                $r['rate'] = round($unitRate, 4);
                $r['mrp'] = round($unitMrp, 4);
            }
            $r['stock_value'] = round($availBase * $unitRate, 2);
            $r['qty_unit'] = $qtyUnit;
            $totalValue += $r['stock_value'];
            $sumRate += (float) $r['rate'];
            $sumMrp += (float) $r['mrp'];
            unset($r['batch_id'], $r['available_base_qty'], $r['units_per_pack']);
        }
        unset($r);

        return [
            'rows' => $this->scrubNames($rows),
            'total_value' => round($totalValue, 2),
            'total_items' => count($rows),
            'qty_unit' => $qtyUnit,
            'business_type' => $businessType,
            'totals' => [
                'rate' => round($sumRate, 2),
                'mrp' => round($sumMrp, 2),
                'stock_value' => round($totalValue, 2),
            ],
        ];
    }

    /** @param array{branchSqlIb:string,branchSqlL:string,bind:array<string,mixed>,manufacturer?:string} $scope */
    public function manufacturerWiseStock(array $scope): array
    {
        return $this->stockReport($scope);
    }

    /** @param array{branchSql:string,bind:array<string,mixed>} $scope */
    public function expiredStock(array $scope): array
    {
        $rows = $this->rows(
            "SELECT pb.product_name, pb.pack_size, ib.batch_no,
                    DATE_FORMAT(ib.expiry_date, '%Y-%m') AS expiry_date,
                    pb.schedule_category AS category,
                    ib.quantity AS available_qty, ib.mrp,
                    ROUND(ib.quantity * ib.purchase_rate, 2) AS stock_value
             FROM inventory_batches ib
             JOIN product_bindings pb ON pb.id = ib.product_binding_id
             WHERE ib.client_id = :c {$scope['branchSql']}
               AND ib.quantity > 0
               AND ib.expiry_date IS NOT NULL
               AND ib.expiry_date < CURDATE()
             ORDER BY ib.expiry_date ASC
             LIMIT 2000",
            $scope['bind']
        );
        return [
            'rows' => $rows,
            'totals' => [
                'count' => count($rows),
                'qty' => (int) $this->sum($rows, 'available_qty'),
                'value' => $this->sum($rows, 'stock_value'),
            ],
        ];
    }

    /** @param array{branchSql:string,bind:array<string,mixed>,months:int} $scope */
    public function nearExpiry(array $scope): array
    {
        $months = max(1, min(24, (int) $scope['months']));
        $branchSqlL = str_replace('ib.', 'l.', $scope['branchSql']);
        // Native prepared statements need a unique placeholder per occurrence of :c.
        $bind = $scope['bind'];
        $bind['c2'] = $bind['c'];
        $rows = $this->rows(
            "SELECT pb.product_name, ib.batch_no,
                    COALESCE(mv.purchase_qty, 0) AS purchase_qty,
                    ib.quantity AS available_qty,
                    DATE_FORMAT(ib.expiry_date, '%Y-%m-%d') AS expiry_date,
                    DATEDIFF(ib.expiry_date, CURDATE()) AS days_to_expiry,
                    ROUND(ib.quantity * ib.purchase_rate, 2) AS stock_value
             FROM inventory_batches ib
             JOIN product_bindings pb ON pb.id = ib.product_binding_id
             LEFT JOIN (
                 SELECT l.batch_id,
                        SUM(CASE WHEN l.txn_type IN ('purchase','opening','transfer_in') THEN l.qty_in ELSE 0 END) AS purchase_qty
                 FROM stock_ledger l
                 WHERE l.client_id = :c2 {$branchSqlL}
                 GROUP BY l.batch_id
             ) mv ON mv.batch_id = ib.id
             WHERE ib.client_id = :c {$scope['branchSql']}
               AND ib.quantity > 0
               AND ib.expiry_date IS NOT NULL
               AND ib.expiry_date >= CURDATE()
               AND ib.expiry_date <= (CURDATE() + INTERVAL {$months} MONTH)
             ORDER BY ib.expiry_date ASC
             LIMIT 2000",
            $bind
        );
        return [
            'rows' => $rows,
            'totals' => [
                'count' => count($rows),
                'qty' => (int) $this->sum($rows, 'available_qty'),
                'value' => $this->sum($rows, 'stock_value'),
            ],
            'months' => $months,
        ];
    }

    /** Product transactions = purchase in + sale out. */
    /** @param array{wherePur:string,bindPur:array<string,mixed>,whereSale:string,bindSale:array<string,mixed>} $scopes */
    public function productTransaction(array $scopes): array
    {
        $bind = array_merge($scopes['bindPur'], $scopes['bindSale']);
        $openingUnion = '';
        // When a product is selected, also show Opening Stock ledger rows for that product.
        if (isset($scopes['bindPur']['p_product_name'])) {
            $dateFilter = '';
            if (isset($scopes['bindPur']['p_from'])) {
                $dateFilter .= ' AND DATE(l.txn_date) >= :p_from';
            }
            if (isset($scopes['bindPur']['p_to'])) {
                $dateFilter .= ' AND DATE(l.txn_date) <= :p_to';
            }
            $openingUnion = "
             UNION ALL
             (SELECT DATE(l.txn_date) AS txn_date,
                     'Opening Stock' AS party,
                     COALESCE(NULLIF(TRIM(l.note),''), 'Opening') AS voucher_no,
                     'Opening Stock' AS voucher_type,
                     pb.product_name, COALESCE(ib.batch_no,'') AS batch_no,
                     DATE_FORMAT(ib.expiry_date, '%Y-%m') AS expiry,
                     l.qty_in AS qty_in, 0 AS qty_out,
                     COALESCE(ib.purchase_rate, 0) AS rate,
                     COALESCE(ib.mrp, 0) AS mrp,
                     ROUND(l.qty_in * COALESCE(ib.purchase_rate, 0), 2) AS amount
              FROM stock_ledger l
              JOIN product_bindings pb ON pb.id = l.product_binding_id
              LEFT JOIN inventory_batches ib ON ib.id = l.batch_id
              WHERE l.client_id = :p_c AND l.txn_type = 'opening'
                AND (
                  TRIM(pb.product_name) = :p_product_name
                  OR TRIM(pb.product_name) = :p_product_name2
                )
                {$dateFilter})";
        }

        $rows = $this->rows(
            "(SELECT DATE(p.invoice_date) AS txn_date, p.supplier_name AS party,
                     p.invoice_no AS voucher_no, 'Purchase' AS voucher_type,
                     pi.product_name, pi.batch_no,
                     DATE_FORMAT(pi.expiry_date, '%Y-%m') AS expiry,
                     pi.quantity AS qty_in, 0 AS qty_out,
                     pi.purchase_rate AS rate, pi.mrp,
                     ROUND(pi.line_total, 2) AS amount
              FROM purchase_items pi
              JOIN purchases p ON p.id = pi.purchase_id
              WHERE {$scopes['wherePur']})
             UNION ALL
             (SELECT DATE(s.invoice_date) AS txn_date,
                     COALESCE(NULLIF(s.customer_name,''),'(Walk-in)') AS party,
                     s.invoice_no AS voucher_no, 'Sale' AS voucher_type,
                     si.product_name, si.batch_no,
                     DATE_FORMAT(si.expiry_date, '%Y-%m') AS expiry,
                     0 AS qty_in, si.quantity AS qty_out,
                     si.rate AS rate, si.mrp,
                     ROUND(si.line_total, 2) AS amount
              FROM sale_items si
              JOIN sales s ON s.id = si.sale_id
              WHERE {$scopes['whereSale']})
             {$openingUnion}
             ORDER BY txn_date DESC, voucher_no DESC
             LIMIT 5000",
            $bind
        );
        return [
            'rows' => $rows,
            'totals' => [
                'count' => count($rows),
                'in' => (int) $this->sum($rows, 'qty_in'),
                'out' => (int) $this->sum($rows, 'qty_out'),
            ],
        ];
    }

    /** @param array{where:string,bind:array<string,mixed>} $scope */
    public function saleGstSlabBillWise(array $scope): array
    {
        $rows = $this->rows(
            "SELECT s.invoice_no AS bill_no, si.gst_rate,
                    COALESCE(SUM(si.taxable),0) AS taxable,
                    COALESCE(SUM(si.taxable * si.gst_rate / 100),0) AS gst,
                    COALESCE(SUM(si.line_total),0) AS net_amount
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             WHERE {$scope['where']}
             GROUP BY s.invoice_no, si.gst_rate
             ORDER BY s.invoice_no, si.gst_rate",
            $scope['bind']
        );
        foreach ($rows as &$r) {
            $g = (float) $r['gst'];
            $r['cgst'] = round($g / 2, 2);
            $r['sgst'] = round($g / 2, 2);
            $r['igst'] = 0.0;
            $r['gst'] = round($g, 2);
            $r['taxable'] = round((float) $r['taxable'], 2);
            $r['net_amount'] = round((float) $r['net_amount'], 2);
        }
        unset($r);
        return ['rows' => $rows, 'totals' => ['count' => count($rows), 'taxable' => $this->sum($rows, 'taxable'), 'gst' => $this->sum($rows, 'gst')]];
    }

    /** @param array{where:string,bind:array<string,mixed>} $scope */
    public function purchaseGstSlabBillWise(array $scope): array
    {
        $rows = $this->rows(
            "SELECT p.invoice_no, pi.gst_rate,
                    COALESCE(SUM(pi.quantity * pi.purchase_rate * (1 - pi.discount_pct/100)),0) AS taxable,
                    COALESCE(SUM(pi.quantity * pi.purchase_rate * (1 - pi.discount_pct/100) * pi.gst_rate / 100),0) AS gst,
                    COALESCE(SUM(pi.line_total),0) AS net_amount
             FROM purchase_items pi
             JOIN purchases p ON p.id = pi.purchase_id
             WHERE {$scope['where']}
             GROUP BY p.invoice_no, pi.gst_rate
             ORDER BY p.invoice_no, pi.gst_rate",
            $scope['bind']
        );
        foreach ($rows as &$r) {
            $g = (float) $r['gst'];
            $r['cgst'] = round($g / 2, 2);
            $r['sgst'] = round($g / 2, 2);
            $r['igst'] = 0.0;
            $r['gst'] = round($g, 2);
            $r['taxable'] = round((float) $r['taxable'], 2);
            $r['net_amount'] = round((float) $r['net_amount'], 2);
        }
        unset($r);
        return ['rows' => $rows, 'totals' => ['count' => count($rows), 'taxable' => $this->sum($rows, 'taxable'), 'gst' => $this->sum($rows, 'gst')]];
    }

    /**
     * HSN B2C / B2B GST portal summary for a calendar month.
     * @param array{where:string,bind:array<string,mixed>} $scope
     * @param 'b2c'|'b2b' $mode
     */
    public function hsnGstPortal(array $scope, string $mode): array
    {
        $gstinClause = $mode === 'b2b'
            ? "AND TRIM(COALESCE(c.gstin,'')) <> ''"
            : "AND (c.id IS NULL OR TRIM(COALESCE(c.gstin,'')) = '')";

        $rows = $this->rows(
            "SELECT TRIM(COALESCE(si.hsn_code,'')) AS hsn,
                    'Goods' AS description,
                    " . ($mode === 'b2b' ? "'NOS-NUMBER'" : "'PCS-PIECES'") . " AS uqc,
                    COALESCE(SUM(si.quantity),0) AS total_quantity,
                    COALESCE(SUM(si.taxable + (si.taxable * si.gst_rate / 100)),0) AS total_value,
                    COALESCE(SUM(si.taxable),0) AS taxable_value,
                    0 AS integrated_tax,
                    COALESCE(SUM(si.taxable * si.gst_rate / 200),0) AS central_tax,
                    COALESCE(SUM(si.taxable * si.gst_rate / 200),0) AS state_ut_tax,
                    0 AS cess_amount,
                    ROUND(si.gst_rate, 2) AS rate
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             LEFT JOIN customers c ON c.id = s.customer_id
             WHERE {$scope['where']}
               AND TRIM(COALESCE(si.hsn_code,'')) <> ''
               {$gstinClause}
             GROUP BY TRIM(COALESCE(si.hsn_code,'')), ROUND(si.gst_rate, 2)
             ORDER BY hsn, rate",
            $scope['bind']
        );
        foreach ($rows as &$r) {
            $r['total_quantity'] = round((float) $r['total_quantity'], 3);
            $r['total_value'] = round((float) $r['total_value'], 2);
            $r['taxable_value'] = round((float) $r['taxable_value'], 2);
            $r['central_tax'] = round((float) $r['central_tax'], 2);
            $r['state_ut_tax'] = round((float) $r['state_ut_tax'], 2);
            $r['rate'] = (float) $r['rate'];
        }
        unset($r);
        return ['rows' => $rows, 'totals' => ['count' => count($rows), 'taxable' => $this->sum($rows, 'taxable_value')]];
    }

    /** @param array{where:string,bind:array<string,mixed>} $scope */
    public function b2bInvoiceGst(array $scope): array
    {
        $rows = $this->rows(
            "SELECT UPPER(TRIM(COALESCE(MAX(NULLIF(TRIM(c.gstin), '')), ''))) AS recipient_gstin,
                    TRIM(COALESCE(MAX(s.customer_name), '')) AS receiver_name,
                    s.invoice_no AS invoice_number,
                    MAX(DATE(s.invoice_date)) AS invoice_date,
                    SUM(COALESCE(si.line_total, 0)) AS invoice_value,
                    ROUND(si.gst_rate, 2) AS rate,
                    SUM(COALESCE(si.taxable, 0)) AS taxable_value
             FROM sales s
             JOIN sale_items si ON si.sale_id = s.id
             INNER JOIN customers c ON c.id = s.customer_id AND TRIM(COALESCE(c.gstin,'')) <> ''
             WHERE {$scope['where']}
             GROUP BY s.id, s.invoice_no, ROUND(si.gst_rate, 2)
             ORDER BY invoice_date, s.invoice_no, rate",
            $scope['bind']
        );
        foreach ($rows as &$r) {
            $gstin = trim((string) ($r['recipient_gstin'] ?? ''));
            $r['place_of_supply'] = $this->placeOfSupplyFromGstin($gstin);
            $r['reverse_charge'] = 'N';
            $r['invoice_type'] = 'Regular B2B';
            $r['ecommerce_gstin'] = '';
            $r['cess_amount'] = 0.0;
            $r['invoice_value'] = round((float) $r['invoice_value'], 2);
            $r['taxable_value'] = round((float) $r['taxable_value'], 2);
            $r['rate'] = (float) $r['rate'];
        }
        unset($r);
        return ['rows' => $rows, 'totals' => ['count' => count($rows), 'taxable' => $this->sum($rows, 'taxable_value')]];
    }

    /**
     * GSTR-1 Table 7 — B2C (Small).
     * @param array{where:string,bind:array<string,mixed>} $scope
     */
    public function b2cSmallGst(array $scope, string $placeOfSupply = ''): array
    {
        $pos = $placeOfSupply !== '' ? $placeOfSupply : '27-Maharashtra';
        $rows = $this->rows(
            "SELECT 'OE' AS supply_type,
                    :pos AS place_of_supply,
                    ROUND(si.gst_rate, 2) AS rate,
                    COALESCE(SUM(si.taxable),0) AS taxable_value,
                    0 AS cess_amount,
                    '0' AS ecommerce_gstin
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             LEFT JOIN customers c ON c.id = s.customer_id
             WHERE {$scope['where']}
               AND (c.id IS NULL OR TRIM(COALESCE(c.gstin,'')) = '')
             GROUP BY ROUND(si.gst_rate, 2)
             ORDER BY rate",
            array_merge($scope['bind'], ['pos' => $pos])
        );
        foreach ($rows as &$r) {
            $r['taxable_value'] = round((float) $r['taxable_value'], 2);
            $r['rate'] = (float) $r['rate'];
        }
        unset($r);
        return ['rows' => $rows, 'totals' => ['count' => count($rows), 'taxable' => $this->sum($rows, 'taxable_value')]];
    }

    /**
     * GSTR-1 Document Issued summary for a month.
     * @param array{client_id:int,branch_id:?int,from:string,to:string} $p
     */
    public function documentIssuedGst(array $p): array
    {
        $bind = ['c' => $p['client_id'], 'from' => $p['from'], 'to' => $p['to']];
        $branchSql = '';
        if (!empty($p['branch_id'])) {
            $branchSql = ' AND branch_id = :b';
            $bind['b'] = $p['branch_id'];
        }

        $inv = $this->rows(
            "SELECT COUNT(*) AS total_number,
                    MIN(invoice_no) AS sr_no_from,
                    MAX(invoice_no) AS sr_no_to
             FROM sales
             WHERE client_id = :c {$branchSql}
               AND DATE(invoice_date) >= :from AND DATE(invoice_date) <= :to",
            $bind
        )[0] ?? [];

        $cnBind = $bind;
        $cn = $this->rows(
            "SELECT COUNT(*) AS total_number,
                    MIN(COALESCE(NULLIF(note_no,''), CAST(id AS CHAR))) AS sr_no_from,
                    MAX(COALESCE(NULLIF(note_no,''), CAST(id AS CHAR))) AS sr_no_to
             FROM credit_notes
             WHERE client_id = :c {$branchSql}
               AND DATE(note_date) >= :from AND DATE(note_date) <= :to",
            $cnBind
        )[0] ?? [];

        $rows = [
            [
                'nature_of_document' => 'Invoices for outward supply',
                'sr_no_from' => (string) ($inv['sr_no_from'] ?? ''),
                'sr_no_to' => (string) ($inv['sr_no_to'] ?? ''),
                'total_number' => (int) ($inv['total_number'] ?? 0),
                'cancelled' => 0,
            ],
            [
                'nature_of_document' => 'Credit Note',
                'sr_no_from' => (string) ($cn['sr_no_from'] ?? ''),
                'sr_no_to' => (string) ($cn['sr_no_to'] ?? ''),
                'total_number' => (int) ($cn['total_number'] ?? 0),
                'cancelled' => 0,
            ],
        ];
        return ['rows' => $rows, 'totals' => ['count' => 2]];
    }

    /** @param array{where:string,bind:array<string,mixed>,distributor_name?:string} $scope */
    public function distributorWiseSale(array $scope): array
    {
        $name = trim((string) ($scope['distributor_name'] ?? ''));
        $distFilter = '';
        if ($name !== '') {
            $distFilter = ' AND TRIM(COALESCE(d.supplier_name,\'\')) = :distributor_name';
            $scope['bind']['distributor_name'] = $name;
        }
        $rows = $this->rows(
            "SELECT COALESCE(NULLIF(d.supplier_name,''),'(Unknown)') AS supplier_name,
                    DATE(s.invoice_date) AS sale_date,
                    s.customer_name, s.invoice_no,
                    si.product_name, si.batch_no, si.quantity,
                    ROUND(si.line_total, 2) AS net_amount
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             LEFT JOIN (
                 SELECT pi.product_binding_id, pi.batch_no, p.client_id,
                        MIN(p.supplier_name) AS supplier_name
                 FROM purchase_items pi
                 JOIN purchases p ON p.id = pi.purchase_id
                 GROUP BY pi.product_binding_id, pi.batch_no, p.client_id
             ) d ON d.product_binding_id = si.product_binding_id
                AND d.batch_no <=> si.batch_no
                AND d.client_id = s.client_id
             WHERE {$scope['where']} {$distFilter}
             ORDER BY supplier_name, s.invoice_date, si.product_name
             LIMIT 5000",
            $scope['bind']
        );
        return [
            'rows' => $this->scrubNames($rows),
            'mode' => $name !== '' ? 'detail' : 'summary',
            'totals' => ['count' => count($rows), 'net' => $this->sum($rows, 'net_amount')],
        ];
    }

    /** @param array{branchSql:string,bind:array<string,mixed>,distributor_name?:string} $p */
    public function distributorWiseStock(array $p): array
    {
        $name = trim((string) ($p['distributor_name'] ?? ''));
        $distFilter = '';
        if ($name !== '') {
            $distFilter = ' AND TRIM(COALESCE(d.supplier_name,\'\')) = :distributor_name';
            $p['bind']['distributor_name'] = $name;
        }
        $rows = $this->rows(
            "SELECT pb.product_name, COALESCE(pb.pack_size, '') AS pack_size,
                    COALESCE(NULLIF(d.supplier_name,''),'(Unknown)') AS distributor_name,
                    d.invoice_no, d.invoice_date,
                    ib.batch_no, ib.purchase_rate AS rate, ib.mrp,
                    COALESCE(d.purchase_qty, 0) AS purchase_qty,
                    ib.quantity AS available_qty
             FROM inventory_batches ib
             JOIN product_bindings pb ON pb.id = ib.product_binding_id
             LEFT JOIN (
                 SELECT pi.product_binding_id, pi.batch_no, p.client_id,
                        MIN(p.supplier_name) AS supplier_name,
                        MIN(p.invoice_no) AS invoice_no,
                        MIN(DATE(p.invoice_date)) AS invoice_date,
                        SUM(pi.quantity) AS purchase_qty
                 FROM purchase_items pi
                 JOIN purchases p ON p.id = pi.purchase_id
                 WHERE TRIM(COALESCE(p.supplier_name,'')) <> ''
                 GROUP BY pi.product_binding_id, pi.batch_no, p.client_id
             ) d ON d.product_binding_id = ib.product_binding_id
                AND d.batch_no <=> ib.batch_no
                AND d.client_id = ib.client_id
             WHERE ib.client_id = :c {$p['branchSql']} AND ib.quantity > 0
               {$distFilter}
             ORDER BY distributor_name, pb.product_name, ib.batch_no",
            $p['bind']
        );
        return [
            'rows' => $this->scrubNames($rows),
            'totals' => [
                'count' => count($rows),
                'qty' => (int) $this->sum($rows, 'available_qty'),
            ],
        ];
    }

    /** @param array{where:string,bind:array<string,mixed>} $scope */
    public function saleStock(array $scope): array
    {
        $rows = $this->rows(
            "SELECT si.product_name, si.batch_no,
                    COALESCE(SUM(si.quantity),0) AS sale_qty,
                    COALESCE(MAX(ib.quantity),0) AS available_qty,
                    ROUND(COALESCE(MAX(ib.quantity),0) * COALESCE(MAX(ib.purchase_rate),0), 2) AS stock_value,
                    ROUND(SUM(si.line_total), 2) AS sale_value
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             LEFT JOIN inventory_batches ib ON ib.id = si.batch_id
             WHERE {$scope['where']}
             GROUP BY si.product_name, si.batch_no
             ORDER BY si.product_name, si.batch_no",
            $scope['bind']
        );
        return [
            'rows' => $this->scrubNames($rows),
            'totals' => [
                'count' => count($rows),
                'net' => $this->sum($rows, 'sale_value'),
            ],
        ];
    }

    /** @param array{where:string,bind:array<string,mixed>} $scope */
    public function doctorMargin(array $scope, float $marginPct = 0): array
    {
        $rows = $this->rows(
            "SELECT COALESCE(NULLIF(s.doctor_name,''),'(No Doctor)') AS doctor_name,
                    si.product_name, si.batch_no, si.quantity,
                    ROUND(si.profit, 2) AS profit,
                    ROUND(si.line_total, 2) AS net_amount
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             WHERE {$scope['where']}
             ORDER BY doctor_name, si.product_name",
            $scope['bind']
        );
        foreach ($rows as &$r) {
            $profit = (float) $r['profit'];
            $r['margin_pct'] = $marginPct;
            $r['margin_amount'] = round($profit * $marginPct / 100, 2);
        }
        unset($r);
        return [
            'rows' => $this->scrubNames($rows),
            'totals' => [
                'count' => count($rows),
                'profit' => $this->sum($rows, 'profit'),
                'net' => $this->sum($rows, 'margin_amount'),
            ],
        ];
    }

    /** @param array{where:string,bind:array<string,mixed>} $scope */
    public function areaWisePayment(array $scope): array
    {
        $rows = $this->rows(
            "SELECT COALESCE(NULLIF(c.area,''),'(No Area)') AS area,
                    COALESCE(NULLIF(c.city,''),'-') AS city,
                    COALESCE(NULLIF(c.state,''),'-') AS state,
                    c.name AS patient_name, c.phone AS contact_no, c.email,
                    COALESCE(SUM(s.net_amount),0) AS bill_amount,
                    COALESCE(SUM(s.paid),0) AS paid_amount,
                    COALESCE(SUM(s.balance),0) AS balance
             FROM sales s
             LEFT JOIN customers c ON c.id = s.customer_id
             WHERE {$scope['where']}
             GROUP BY c.id, c.area, c.city, c.state, c.name, c.phone, c.email
             HAVING balance > 0 OR bill_amount > 0
             ORDER BY area, patient_name",
            $scope['bind']
        );
        return [
            'rows' => $rows,
            'totals' => [
                'count' => count($rows),
                'net' => $this->sum($rows, 'bill_amount'),
                'gst' => $this->sum($rows, 'paid_amount'),
                'profit' => $this->sum($rows, 'balance'),
            ],
        ];
    }

    /** Updated / accurate P/L — product-level with cost and sale. */
    public function accurateProfitLoss(array $scope): array
    {
        $rows = $this->rows(
            "SELECT si.product_name, si.batch_no,
                    COALESCE(SUM(si.quantity),0) AS quantity,
                    ROUND(AVG(si.rate), 2) AS sale_rate,
                    ROUND(AVG(CASE WHEN si.quantity > 0 THEN si.cost_price / si.quantity ELSE 0 END), 2) AS cost_rate,
                    ROUND(SUM(si.line_total), 2) AS sale_value,
                    ROUND(SUM(si.cost_price), 2) AS cost_value,
                    ROUND(SUM(si.profit), 2) AS profit
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             WHERE {$scope['where']}
             GROUP BY si.product_name, si.batch_no
             ORDER BY profit DESC",
            $scope['bind']
        );
        return [
            'rows' => $this->scrubNames($rows),
            'totals' => [
                'count' => count($rows),
                'net' => $this->sum($rows, 'sale_value'),
                'gst' => $this->sum($rows, 'cost_value'),
                'profit' => $this->sum($rows, 'profit'),
            ],
        ];
    }

    private function placeOfSupplyFromGstin(string $gstin): string
    {
        $code = strtoupper(substr(preg_replace('/\s+/', '', $gstin) ?? '', 0, 2));
        $map = [
            '01' => '01-Jammu & Kashmir', '02' => '02-Himachal Pradesh', '03' => '03-Punjab',
            '04' => '04-Chandigarh', '05' => '05-Uttarakhand', '06' => '06-Haryana',
            '07' => '07-Delhi', '08' => '08-Rajasthan', '09' => '09-Uttar Pradesh',
            '10' => '10-Bihar', '11' => '11-Sikkim', '12' => '12-Arunachal Pradesh',
            '13' => '13-Nagaland', '14' => '14-Manipur', '15' => '15-Mizoram',
            '16' => '16-Tripura', '17' => '17-Meghalaya', '18' => '18-Assam',
            '19' => '19-West Bengal', '20' => '20-Jharkhand', '21' => '21-Odisha',
            '22' => '22-Chhattisgarh', '23' => '23-Madhya Pradesh', '24' => '24-Gujarat',
            '27' => '27-Maharashtra', '29' => '29-Karnataka', '32' => '32-Kerala',
            '33' => '33-Tamil Nadu', '36' => '36-Telangana', '37' => '37-Andhra Pradesh',
        ];
        return $map[$code] ?? ($code !== '' ? $code : '');
    }

    /** @param list<array<string,mixed>> $rows */
    private function sum(array $rows, string $key): float
    {
        $t = 0.0;
        foreach ($rows as $r) {
            $t += (float) ($r[$key] ?? 0);
        }
        return round($t, 2);
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function scrubNames(array $rows): array
    {
        foreach ($rows as &$r) {
            if (isset($r['product_name'])) {
                $r['product_name'] = \App\Support\ProductName::clean((string) $r['product_name']);
            }
        }
        unset($r);
        return $rows;
    }
}
