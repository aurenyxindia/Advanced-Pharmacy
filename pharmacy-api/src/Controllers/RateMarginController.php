<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Model;
use App\Core\Request;
use App\Core\Response;
use App\Models\RateMargin;

/**
 * Rate Margin Master - category-wise wholesale pricing policy.
 *
 * PTR/PTS are derived from MRP (GST is added on top on the wholesale bill):
 *   PTR = MRP × (1 − ptr_margin/100)
 *   PTS = MRP × (1 − pts_margin/100)
 */
final class RateMarginController extends ResourceController
{
    private const DEFAULTS = [
        ['category' => 'Allopathy', 'ptr_margin' => 20, 'pts_margin' => 30],
        ['category' => 'Ayurvedic', 'ptr_margin' => 25, 'pts_margin' => 35],
        ['category' => 'Generic',   'ptr_margin' => 40, 'pts_margin' => 55],
        ['category' => 'Surgical',  'ptr_margin' => 15, 'pts_margin' => 22],
        ['category' => 'Cosmetic',  'ptr_margin' => 18, 'pts_margin' => 28],
    ];

    protected bool $tenantScoped = true;
    protected array $fillable = [
        'category', 'ptr_margin', 'pts_margin', 'max_discount', 'std_discount',
        'rate_by', 'credit_days', 'cash_discount', 'is_active',
    ];
    protected array $searchColumns = ['category'];
    protected string $defaultOrder = 'category ASC';

    protected function model(): Model
    {
        return new RateMargin();
    }

    protected function storeRules(): array
    {
        return [
            'category'      => 'required|string|max:30',
            'ptr_margin'    => 'required|numeric|min:0|max:100',
            'pts_margin'    => 'required|numeric|min:0|max:100',
            'max_discount'  => 'numeric|min:0|max:100',
            'std_discount'  => 'numeric|min:0|max:100',
            'rate_by'       => 'string|in:Rate By Purchase,Rate By Margin',
            'credit_days'   => 'integer|min:0|max:365',
            'cash_discount' => 'numeric|min:0|max:100',
            'is_active'     => 'integer|in:0,1',
        ];
    }

    public function index(Request $request): Response
    {
        $model = new RateMargin();
        $existing = $model->where(['client_id' => $request->clientId()]);
        if ($existing === []) {
            foreach (self::DEFAULTS as $d) {
                $model->insert(array_merge($d, [
                    'client_id'     => $request->clientId(),
                    'max_discount'  => 0,
                    'std_discount'  => 0,
                    'rate_by'       => 'Rate By Margin',
                    'credit_days'   => 0,
                    'cash_discount' => 0,
                    'is_active'     => 1,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]));
            }
        }
        return parent::index($request);
    }

    public function compute(Request $request): Response
    {
        $mrp = (float) $request->query('mrp', 0);
        $gst = (float) $request->query('gst', 0);
        $category = (string) $request->query('category', 'Allopathy');

        $margin = (new RateMargin())->forCategory((int) $request->clientId(), $category);
        $ptrMargin = (float) ($margin['ptr_margin'] ?? 20);
        $ptsMargin = (float) ($margin['pts_margin'] ?? 30);

        $ptr = $mrp * (1 - $ptrMargin / 100);
        $pts = $mrp * (1 - $ptsMargin / 100);
        $netMrp = $mrp / (1 + $gst / 100);

        return $this->success([
            'category'   => $category,
            'mrp'        => round($mrp, 2),
            'net_mrp'    => round($netMrp, 2),
            'ptr_margin' => $ptrMargin,
            'pts_margin' => $ptsMargin,
            'ptr'        => round($ptr, 2),
            'pts'        => round($pts, 2),
        ], 'Computed wholesale rates.');
    }
}
