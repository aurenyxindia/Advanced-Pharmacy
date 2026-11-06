<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Database\Connection;
use App\Services\DailyClosingMetricsService;
use App\Services\WhatsAppDailyClosingService;

/**
 * Daily Closing — Retail parity (drawer formula, day-end upsert, report, WhatsApp).
 */
final class DailyClosingController extends Controller
{
    public function summary(Request $request): Response
    {
        $date = $this->validDate($request->query('date')) ?? date('Y-m-d');
        $clientId = (int) $request->clientId();
        $branchId = (int) $request->branchId();

        $data = (new DailyClosingMetricsService())->compute($clientId, $branchId, $date);
        $data['date'] = $date;
        $data['closing'] = $this->existingClosing($clientId, $branchId, $date);

        return $this->success($data, 'Daily closing summary.');
    }

    public function dayEnd(Request $request): Response
    {
        $this->ensureExtraColumns();

        $date = $this->validDate($request->input('date')) ?? date('Y-m-d');
        $clientId = (int) $request->clientId();
        $branchId = (int) $request->branchId();
        $availableCash = round((float) $request->input('available_cash', 0), 2);
        $userId = $request->user()['id'] ?? null;

        $c = (new DailyClosingMetricsService())->compute($clientId, $branchId, $date);
        $difference = round($availableCash - (float) $c['expected_cash'], 2);

        $stmt = Connection::ops()->prepare(
            'INSERT INTO daily_closings
                (client_id, branch_id, closing_date, sales_cash, sales_upi, sales_card, sales_credit,
                 total_sales, retail_return, net_sales, customer_payment, debit_payment, today_customers, expected_cash,
                 available_cash, difference, closed_by, created_at, updated_at)
             VALUES
                (:cid, :bid, :d, :scash, :supi, :scard, :scredit,
                 :total, :ret, :net, :custpay, :debit, :cust, :expected,
                 :avail, :diff, :uid, :ca, :ua)
             ON DUPLICATE KEY UPDATE
                sales_cash = VALUES(sales_cash), sales_upi = VALUES(sales_upi),
                sales_card = VALUES(sales_card), sales_credit = VALUES(sales_credit),
                total_sales = VALUES(total_sales), retail_return = VALUES(retail_return),
                net_sales = VALUES(net_sales),
                customer_payment = VALUES(customer_payment),
                debit_payment = VALUES(debit_payment), today_customers = VALUES(today_customers),
                expected_cash = VALUES(expected_cash), available_cash = VALUES(available_cash),
                difference = VALUES(difference), closed_by = VALUES(closed_by), updated_at = VALUES(updated_at)'
        );
        $stmt->execute([
            'cid' => $clientId, 'bid' => $branchId, 'd' => $date,
            'scash' => $c['sales']['cash'], 'supi' => $c['sales']['upi'],
            'scard' => $c['sales']['card'], 'scredit' => $c['sales']['credit'],
            'total' => $c['sales']['total'],
            'ret' => $c['sales']['return'],
            'net' => $c['sales']['net'],
            'custpay' => $c['customer_payment']['total'],
            'debit' => $c['debit_payment']['total'], 'cust' => $c['sales']['customers'],
            'expected' => $c['expected_cash'], 'avail' => $availableCash, 'diff' => $difference,
            'uid' => $userId, 'ca' => now(), 'ua' => now(),
        ]);

        $whatsapp = (new WhatsAppDailyClosingService())->send($clientId, $date, $c, $branchId);

        $message = 'Day End value saved successfully!';
        if (!($whatsapp['ok'] ?? false)) {
            $skipped = (string) ($whatsapp['skipped'] ?? '');
            if ($skipped === 'no_phone') {
                $message .= ' WhatsApp not sent — add mobile or alternate number in My Profile.';
            } elseif ($skipped === 'no_client') {
                $message .= ' WhatsApp not sent — client record not found.';
            } elseif ($skipped === 'not_configured') {
                $message .= ' WhatsApp not sent — API not configured.';
            } else {
                $message .= ' WhatsApp not sent: ' . (string) ($whatsapp['error'] ?? 'API error');
            }
        } else {
            $message .= ' WhatsApp sent to ' . (string) ($whatsapp['to'] ?? 'registered number') . '.';
            if ((int) ($whatsapp['failed'] ?? 0) > 0 && !empty($whatsapp['error'])) {
                $message .= ' Partial failure: ' . (string) $whatsapp['error'];
            }
        }

        return $this->success([
            'date' => $date,
            'available_cash' => $availableCash,
            'expected_cash' => $c['expected_cash'],
            'difference' => $difference,
            'whatsapp' => $whatsapp,
        ], $message);
    }

    public function report(Request $request): Response
    {
        $this->ensureExtraColumns();

        $from = $this->validDate($request->query('from')) ?? date('Y-m-01');
        $to = $this->validDate($request->query('to')) ?? date('Y-m-d');
        $clientId = (int) $request->clientId();
        $branchId = (int) $request->branchId();

        $stmt = Connection::ops()->prepare(
            'SELECT closing_date, sales_cash, sales_upi, sales_card, sales_credit, total_sales,
                    retail_return, net_sales,
                    customer_payment, debit_payment, today_customers, expected_cash,
                    available_cash, difference
             FROM daily_closings
             WHERE client_id = :cid AND branch_id = :bid AND closing_date BETWEEN :f AND :t
             ORDER BY closing_date DESC'
        );
        $stmt->execute(['cid' => $clientId, 'bid' => $branchId, 'f' => $from, 't' => $to]);

        return $this->success(['rows' => $stmt->fetchAll(), 'from' => $from, 'to' => $to], 'Daily closing report.');
    }

    /** SpreadsheetML .xls — Retail exportDayEndReportExcel */
    public function exportReportExcel(Request $request): Response
    {
        $this->ensureExtraColumns();
        $from = $this->validDate($request->query('from')) ?? date('Y-m-01');
        $to = $this->validDate($request->query('to')) ?? date('Y-m-d');
        $clientId = (int) $request->clientId();
        $branchId = (int) $request->branchId();

        $stmt = Connection::ops()->prepare(
            'SELECT * FROM daily_closings
             WHERE client_id = :cid AND branch_id = :bid AND closing_date BETWEEN :f AND :t
             ORDER BY closing_date ASC'
        );
        $stmt->execute(['cid' => $clientId, 'bid' => $branchId, 'f' => $from, 't' => $to]);
        $rows = $stmt->fetchAll();

        $xml = $this->buildRangeExcelXml($rows, $from, $to);
        $filename = 'daily_closing_report_' . $from . '_to_' . $to . '.xls';

        return Response::raw($xml, 'application/vnd.ms-excel; charset=UTF-8', 200, [
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** SpreadsheetML .xls — Retail exportDayEndRowExcel */
    public function exportRowExcel(Request $request): Response
    {
        $date = $this->validDate($request->query('closing_date') ?? $request->query('date'));
        if ($date === null) {
            $date = date('Y-m-d');
        }
        $clientId = (int) $request->clientId();
        $branchId = (int) $request->branchId();

        $c = (new DailyClosingMetricsService())->compute($clientId, $branchId, $date);
        $closing = $this->existingClosing($clientId, $branchId, $date);
        $avail = (float) ($closing['available_cash'] ?? 0);
        $diff = $closing !== null
            ? (float) $closing['difference']
            : round($avail - (float) $c['expected_cash'], 2);

        $pairs = [
            'Closing Date' => $date,
            'Sales Cash' => $c['sales']['cash'],
            'UPI' => $c['sales']['upi'],
            'Sales Card' => $c['sales']['card'],
            'Sales Credit' => $c['sales']['credit'],
            'Total Sales' => $c['sales']['total'],
            'Return' => $c['sales']['return'],
            'Net Sales' => $c['sales']['net'],
            'Customer Payment' => $c['customer_payment']['total'],
            'Debit Payment' => $c['debit_payment']['total'],
            'Today Customers' => $c['sales']['customers'],
            'Total Cash' => $c['expected_cash'],
            'Available Cash' => $avail,
            'Difference' => $diff,
        ];

        $xml = $this->buildKvExcelXml($pairs, 'Daily Closing ' . $date);
        $filename = 'daily_closing_' . $date . '.xls';

        return Response::raw($xml, 'application/vnd.ms-excel; charset=UTF-8', 200, [
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function existingClosing(int $clientId, int $branchId, string $date): ?array
    {
        $this->ensureExtraColumns();
        $stmt = Connection::ops()->prepare(
            'SELECT available_cash, difference, closed_by, updated_at, retail_return, net_sales
             FROM daily_closings WHERE client_id = :c AND branch_id = :b AND closing_date = :d LIMIT 1'
        );
        $stmt->execute(['c' => $clientId, 'b' => $branchId, 'd' => $date]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private function ensureExtraColumns(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $db = Connection::ops();
        foreach (['retail_return' => 'DECIMAL(12,2) NOT NULL DEFAULT 0', 'net_sales' => 'DECIMAL(12,2) NOT NULL DEFAULT 0'] as $col => $def) {
            $check = $db->query("SHOW COLUMNS FROM daily_closings LIKE " . $db->quote($col));
            if ($check && $check->fetch()) {
                continue;
            }
            $after = $col === 'retail_return' ? 'total_sales' : 'retail_return';
            $db->exec("ALTER TABLE daily_closings ADD COLUMN `{$col}` {$def} AFTER `{$after}`");
        }
    }

    /** @param list<array<string,mixed>> $rows */
    private function buildRangeExcelXml(array $rows, string $from, string $to): string
    {
        $esc = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $headers = [
            'Date', 'Sales Cash', 'UPI', 'Card', 'Credit', 'Total', 'Return', 'Net',
            'Customer Payment', 'Debit', 'Customers', 'Total Cash', 'Available', 'Difference',
        ];
        $out = '<?xml version="1.0"?><?mso-application progid="Excel.Sheet"?>';
        $out .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" '
            . 'xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
        $out .= '<Worksheet ss:Name="Daily Closing"><Table>';
        $out .= '<Row><Cell ss:MergeAcross="' . (count($headers) - 1) . '"><Data ss:Type="String">'
            . $esc('Daily Closing Report ' . $from . ' to ' . $to) . '</Data></Cell></Row>';
        $out .= '<Row>';
        foreach ($headers as $h) {
            $out .= '<Cell><Data ss:Type="String">' . $esc($h) . '</Data></Cell>';
        }
        $out .= '</Row>';
        foreach ($rows as $r) {
            $cells = [
                $r['closing_date'] ?? '',
                $r['sales_cash'] ?? 0, $r['sales_upi'] ?? 0, $r['sales_card'] ?? 0, $r['sales_credit'] ?? 0,
                $r['total_sales'] ?? 0, $r['retail_return'] ?? 0, $r['net_sales'] ?? 0,
                $r['customer_payment'] ?? 0, $r['debit_payment'] ?? 0, $r['today_customers'] ?? 0,
                $r['expected_cash'] ?? 0, $r['available_cash'] ?? 0, $r['difference'] ?? 0,
            ];
            $out .= '<Row>';
            foreach ($cells as $i => $v) {
                if ($i === 0) {
                    $out .= '<Cell><Data ss:Type="String">' . $esc($v) . '</Data></Cell>';
                } else {
                    $out .= '<Cell><Data ss:Type="Number">' . $esc(number_format((float) $v, 2, '.', '')) . '</Data></Cell>';
                }
            }
            $out .= '</Row>';
        }
        $out .= '</Table></Worksheet></Workbook>';
        return $out;
    }

    /** @param array<string,scalar> $pairs */
    private function buildKvExcelXml(array $pairs, string $title): string
    {
        $esc = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $out = '<?xml version="1.0"?><?mso-application progid="Excel.Sheet"?>';
        $out .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" '
            . 'xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
        $out .= '<Worksheet ss:Name="Day End"><Table>';
        $out .= '<Row><Cell ss:MergeAcross="1"><Data ss:Type="String">' . $esc($title) . '</Data></Cell></Row>';
        $out .= '<Row><Cell><Data ss:Type="String">Metric</Data></Cell><Cell><Data ss:Type="String">Value</Data></Cell></Row>';
        foreach ($pairs as $k => $v) {
            $out .= '<Row><Cell><Data ss:Type="String">' . $esc($k) . '</Data></Cell>';
            if (is_numeric($v) && $k !== 'Closing Date' && $k !== 'Today Customers') {
                $out .= '<Cell><Data ss:Type="Number">' . $esc(number_format((float) $v, 2, '.', '')) . '</Data></Cell>';
            } else {
                $out .= '<Cell><Data ss:Type="String">' . $esc($v) . '</Data></Cell>';
            }
            $out .= '</Row>';
        }
        $out .= '</Table></Worksheet></Workbook>';
        return $out;
    }

    private function validDate(mixed $value): ?string
    {
        $v = trim((string) $value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }
}
