<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Model;
use App\Core\Request;
use App\Core\Response;
use App\Database\Connection;
use App\Models\Generic;

final class GenericController extends ResourceController
{
    protected bool $tenantScoped = false;
    protected array $fillable = ['name', 'therapeutic_class', 'schedule_category', 'is_active'];
    protected array $searchColumns = ['name', 'therapeutic_class', 'schedule_category'];
    protected string $defaultOrder = 'name ASC';

    protected function model(): Model
    {
        return new Generic();
    }

    protected function storeRules(): array
    {
        return [
            'name'              => 'required|string|min:2|max:180',
            'therapeutic_class' => 'string|max:120',
            'schedule_category' => 'required|in:OTC,H,H1,X,G,RESTRICTED',
            'is_active'         => 'integer|in:0,1',
        ];
    }

    public function index(Request $request): Response
    {
        $page    = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min(5000, (int) $request->query('per_page', 25)));
        $offset  = ($page - 1) * $perPage;
        $search  = trim((string) ($request->query('search') ?? ''));

        $clauses  = [];
        $bindings = [];

        $schedule = trim((string) ($request->query('schedule_category') ?? ''));
        if ($schedule !== '' && in_array($schedule, ['OTC', 'H', 'H1', 'X', 'G', 'RESTRICTED'], true)) {
            $clauses[] = 'schedule_category = :schedule';
            $bindings['schedule'] = $schedule;
        }

        $thera = trim((string) ($request->query('therapeutic_class') ?? ''));
        if ($thera === '__UNCATEGORISED__') {
            $clauses[] = "(therapeutic_class IS NULL OR TRIM(therapeutic_class) = '')";
        } elseif ($thera !== '') {
            $clauses[] = 'therapeutic_class = :thera';
            $bindings['thera'] = $thera;
        }

        if ($search !== '') {
            $likes = [];
            foreach ($this->searchColumns as $i => $column) {
                $param = "s_{$i}";
                $likes[] = "`{$column}` LIKE :{$param}";
                $bindings[$param] = '%' . $search . '%';
            }
            $clauses[] = '(' . implode(' OR ', $likes) . ')';
        }

        $where = $clauses !== [] ? 'WHERE ' . implode(' AND ', $clauses) : '';
        $db = Connection::master();

        $countStmt = $db->prepare("SELECT COUNT(*) FROM generics {$where}");
        $countStmt->execute($bindings);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT * FROM generics {$where} ORDER BY {$this->defaultOrder} LIMIT {$perPage} OFFSET {$offset}";
        $stmt = $db->prepare($sql);
        $stmt->execute($bindings);

        return $this->success([
            'data'     => $stmt->fetchAll(),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ], 'List fetched.');
    }

    /**
     * Schedule + therapeutic-class counters for Generic Master UI.
     */
    public function stats(Request $request): Response
    {
        $db = Connection::master();

        $total = (int) $db->query('SELECT COUNT(*) FROM generics')->fetchColumn();
        $active = (int) $db->query('SELECT COUNT(*) FROM generics WHERE is_active = 1')->fetchColumn();

        $scheduleRows = $db->query(
            "SELECT schedule_category AS code, COUNT(*) AS cnt
             FROM generics
             GROUP BY schedule_category
             ORDER BY FIELD(schedule_category, 'OTC','H','H1','X','G','RESTRICTED')"
        )->fetchAll();

        $scheduleCounts = [];
        foreach ($scheduleRows as $row) {
            $scheduleCounts[(string) $row['code']] = (int) $row['cnt'];
        }

        $categories = $db->query(
            "SELECT COALESCE(NULLIF(TRIM(therapeutic_class), ''), 'Uncategorised') AS name,
                    COUNT(*) AS cnt
             FROM generics
             GROUP BY COALESCE(NULLIF(TRIM(therapeutic_class), ''), 'Uncategorised')
             ORDER BY cnt DESC, name ASC"
        )->fetchAll();

        $categoryList = array_map(static fn (array $r) => [
            'name'  => (string) $r['name'],
            'count' => (int) $r['cnt'],
        ], $categories);

        $cards = [
            ['label' => 'Total Generics', 'value' => $total, 'sub' => $active . ' active', 'icon' => 'bi-droplet-half'],
            ['label' => 'Schedule H', 'value' => $scheduleCounts['H'] ?? 0, 'sub' => 'prescription', 'icon' => 'bi-prescription2'],
            ['label' => 'Schedule H1', 'value' => $scheduleCounts['H1'] ?? 0, 'sub' => 'strict control', 'icon' => 'bi-shield-exclamation'],
            ['label' => 'Schedule X / G', 'value' => ($scheduleCounts['X'] ?? 0) + ($scheduleCounts['G'] ?? 0), 'sub' => 'X: ' . ($scheduleCounts['X'] ?? 0) . ' · G: ' . ($scheduleCounts['G'] ?? 0), 'icon' => 'bi-exclamation-octagon'],
            ['label' => 'OTC', 'value' => $scheduleCounts['OTC'] ?? 0, 'sub' => 'over the counter', 'icon' => 'bi-bag-check'],
            ['label' => 'Categories', 'value' => count($categoryList), 'sub' => 'therapeutic classes', 'icon' => 'bi-tags'],
        ];

        return $this->success([
            'cards'           => $cards,
            'schedule_counts' => $scheduleCounts,
            'categories'      => $categoryList,
        ], 'Generic catalogue stats.');
    }
}
