<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Model;
use App\Core\Request;
use App\Core\Response;
use App\Database\Connection;
use App\Models\Customer;
use PDO;

final class CustomerController extends ResourceController
{
    protected bool $tenantScoped = true;
    protected array $fillable = [
        'name', 'phone', 'email', 'address', 'dob', 'gstin', 'is_chronic', 'customer_type',
        'gender', 'gst_registered', 'pan_no', 'chronic_val', 'others_val',
        'dl_no', 'medical_type', 'fssai_no', 'credit_days', 'state', 'city', 'area', 'pp_client_no',
    ];
    protected array $searchColumns = ['name', 'phone', 'email', 'gstin', 'dl_no', 'city', 'area', 'pan_no'];
    protected string $defaultOrder = 'name ASC';

    protected function model(): Model
    {
        return new Customer();
    }

    protected function storeRules(): array
    {
        return [
            'name'           => 'required|string|min:2|max:150',
            'phone'          => 'required|string|regex:/^[6-9]\d{9}$/',
            'email'          => 'email|max:150',
            'address'        => 'string|max:255',
            'gstin'          => 'string|regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/',
            'is_chronic'     => 'integer|in:0,1',
            'customer_type' => 'required|in:retail,wholesale,ipd',
            'gender'         => 'string|in:Male,Female',
            'gst_registered' => 'integer|in:0,1',
            'pan_no'         => 'string|regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/',
            'chronic_val'    => 'string|max:40',
            'others_val'     => 'string|max:100',
            'dl_no'          => 'string|max:60',
            'medical_type'   => 'string|in:Retail,Wholesale',
            'fssai_no'       => 'string|regex:/^\d{14}$/',
            'credit_days'    => 'integer|min:0|max:365',
            'state'          => 'string|max:80',
            'city'           => 'string|max:80',
            'area'           => 'string|max:120',
            'pp_client_no'   => 'string|max:32',
        ];
    }

    protected function fillData(array $data, Request $request, bool $withTenant = true): array
    {
        $payload = parent::fillData($data, $request, $withTenant);
        $type = (string) ($payload['customer_type'] ?? 'retail');

        foreach (['name', 'address', 'dl_no', 'city', 'area', 'state', 'others_val'] as $field) {
            if (isset($payload[$field]) && is_string($payload[$field])) {
                $payload[$field] = strtoupper(trim($payload[$field]));
            }
        }
        if (isset($payload['gstin']) && is_string($payload['gstin'])) {
            $payload['gstin'] = strtoupper(trim($payload['gstin']));
        }
        if (isset($payload['pan_no']) && is_string($payload['pan_no'])) {
            $payload['pan_no'] = strtoupper(trim($payload['pan_no']));
            if ($payload['pan_no'] === '') {
                $payload['pan_no'] = null;
            }
        }
        if (isset($payload['fssai_no']) && is_string($payload['fssai_no'])) {
            $payload['fssai_no'] = preg_replace('/\D/', '', $payload['fssai_no']) ?? '';
            if ($payload['fssai_no'] === '') {
                $payload['fssai_no'] = null;
            }
        }
        if (isset($payload['pp_client_no']) && is_string($payload['pp_client_no'])) {
            $payload['pp_client_no'] = trim($payload['pp_client_no']);
            if ($payload['pp_client_no'] === '') {
                $payload['pp_client_no'] = null;
            }
        }
        if (array_key_exists('credit_days', $payload) && $payload['credit_days'] !== null && $payload['credit_days'] !== '') {
            $payload['credit_days'] = (int) $payload['credit_days'];
        }
        if (array_key_exists('gst_registered', $payload)) {
            $payload['gst_registered'] = (int) $payload['gst_registered'];
        } else {
            $payload['gst_registered'] = !empty($payload['gstin']) ? 1 : 0;
        }
        if (array_key_exists('is_chronic', $payload)) {
            $payload['is_chronic'] = (int) $payload['is_chronic'];
        }

        if ($type !== 'wholesale') {
            $payload['dl_no'] = null;
            $payload['medical_type'] = null;
            $payload['fssai_no'] = null;
            $payload['credit_days'] = null;
            $payload['state'] = null;
            $payload['city'] = null;
            $payload['area'] = null;
            $payload['pp_client_no'] = null;
            $payload['pan_no'] = null;
        }
        if ($type !== 'retail') {
            $payload['gender'] = null;
            $payload['is_chronic'] = 0;
            $payload['chronic_val'] = null;
            $payload['others_val'] = null;
        } else {
            if ((int) ($payload['is_chronic'] ?? 0) !== 1) {
                $payload['chronic_val'] = null;
                $payload['others_val'] = null;
            } elseif (($payload['chronic_val'] ?? '') !== 'Others') {
                $payload['others_val'] = null;
            }
        }
        if ((int) ($payload['gst_registered'] ?? 0) !== 1) {
            $payload['gstin'] = null;
        }

        return $payload;
    }

    /**
     * Phone lookup for billing.
     * Returns one row per distinct patient name on that mobile:
     * - master customers (duplicate imports collapsed to the best record)
     * - plus distinct patient names seen on past sales for the same number
     */
    public function lookup(Request $request): Response
    {
        $phone = $this->normalizePhone((string) $request->query('phone', ''));
        if ($phone === '') {
            return $this->success([], 'No phone supplied.');
        }

        $clientId = $request->clientId();
        $db = Connection::ops();
        $phoneSql = 'RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone, \'\'), \' \', \'\'), \'-\', \'\'), \'+\', \'\'), \'.\', \'\'), 10)';

        $stmt = $db->prepare(
            "SELECT * FROM customers
             WHERE client_id = :cid
               AND (phone = :phone OR {$phoneSql} = :phone_norm)
             ORDER BY name ASC, id ASC"
        );
        $stmt->execute([
            'cid'        => $clientId,
            'phone'      => $phone,
            'phone_norm' => $phone,
        ]);
        $masterRows = $this->collapseDuplicateCustomers($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

        $contactSql = 'RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(contact_no, \'\'), \' \', \'\'), \'-\', \'\'), \'+\', \'\'), \'.\', \'\'), 10)';
        $histStmt = $db->prepare(
            "SELECT s.patient_name, s.address
             FROM sales s
             INNER JOIN (
                 SELECT UPPER(TRIM(patient_name)) AS pkey, MAX(id) AS max_id
                 FROM sales
                 WHERE client_id = :cid
                   AND (contact_no = :phone OR {$contactSql} = :phone_norm)
                   AND patient_name IS NOT NULL
                   AND TRIM(patient_name) <> ''
                 GROUP BY UPPER(TRIM(patient_name))
             ) latest ON s.id = latest.max_id
             ORDER BY s.patient_name ASC"
        );
        $histStmt->execute([
            'cid'        => $clientId,
            'phone'      => $phone,
            'phone_norm' => $phone,
        ]);
        $historyRows = $histStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $masterNames = [];
        $out = [];
        foreach ($masterRows as $row) {
            $nameKey = $this->normalizeNameKey((string) ($row['name'] ?? ''));
            if ($nameKey === '') {
                continue;
            }
            $masterNames[$nameKey] = true;
            $row['lookup_source'] = 'master';
            $out[] = $row;
        }

        foreach ($historyRows as $row) {
            $name = trim((string) ($row['patient_name'] ?? ''));
            $nameKey = $this->normalizeNameKey($name);
            if ($nameKey === '' || isset($masterNames[$nameKey]) || $this->isWalkInName($nameKey)) {
                continue;
            }
            $masterNames[$nameKey] = true;
            $out[] = [
                'id'             => null,
                'name'           => strtoupper($name),
                'phone'          => $phone,
                'email'          => null,
                'address'        => trim((string) ($row['address'] ?? '')) ?: null,
                'is_chronic'     => 0,
                'customer_type'  => 'retail',
                'lookup_source'  => 'sales_history',
            ];
        }

        usort($out, static fn (array $a, array $b): int => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

        return $this->success($out, $out ? 'Customers found.' : 'New customer.');
    }

    /** Same name + phone (+ type) may exist twice after imports — keep the richer master row. */
    private function collapseDuplicateCustomers(array $rows): array
    {
        $best = [];
        foreach ($rows as $row) {
            $key = $this->normalizeNameKey((string) ($row['name'] ?? ''))
                . '|' . $this->normalizePhone((string) ($row['phone'] ?? ''))
                . '|' . strtolower(trim((string) ($row['customer_type'] ?? 'retail')));
            if ($key === '||retail') {
                continue;
            }
            if (!isset($best[$key]) || $this->customerRowScore($row) > $this->customerRowScore($best[$key])) {
                $best[$key] = $row;
            }
        }

        return array_values($best);
    }

    private function customerRowScore(array $row): int
    {
        $score = 0;
        if (trim((string) ($row['email'] ?? '')) !== '') {
            $score += 100;
        }
        if (trim((string) ($row['address'] ?? '')) !== '') {
            $score += 50;
        }
        if (trim((string) ($row['gstin'] ?? '')) !== '') {
            $score += 10;
        }
        $score += max(0, 1000 - (int) ($row['id'] ?? 0));

        return $score;
    }

    private function normalizeNameKey(string $name): string
    {
        return strtoupper(trim(preg_replace('/\s+/', ' ', $name) ?? ''));
    }

    private function isWalkInName(string $nameKey): bool
    {
        return in_array($nameKey, ['WALK-IN', 'WALK IN', 'COUNTER', 'CASH', '-'], true);
    }

    private function normalizePhone(string $raw): string
    {
        $digits = preg_replace('/\D/', '', trim($raw)) ?? '';
        if (strlen($digits) >= 10) {
            return substr($digits, -10);
        }
        return $digits;
    }
}
