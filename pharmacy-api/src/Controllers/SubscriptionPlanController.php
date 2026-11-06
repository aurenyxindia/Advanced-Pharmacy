<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AdminRegistrationService;
use App\Services\SubscriptionPlanService;

final class SubscriptionPlanController extends Controller
{
    public function publicIndex(Request $request): Response
    {
        $plans = (new SubscriptionPlanService())->list(true);
        return $this->success(['plans' => $plans], 'Subscription plans.');
    }

    public function index(Request $request): Response
    {
        $this->gate($request);
        $plans = (new SubscriptionPlanService())->list(false);
        return $this->success(['plans' => $plans], 'Subscription plans.');
    }

    public function store(Request $request): Response
    {
        $this->gate($request);
        $data = $this->validate($request, [
            'code' => 'required|string|max:32',
            'name' => 'required|string|max:80',
            'months' => 'required|integer|min:0|max:1200',
            'monthly_rate' => 'numeric',
            'discount_pct' => 'numeric',
            'gst_pct' => 'numeric',
            'lifetime_base' => 'numeric',
            'sort_order' => 'integer',
        ]);
        $plan = (new SubscriptionPlanService())->create(array_merge($request->all(), $data));
        return $this->created($plan, 'Plan created.');
    }

    public function update(Request $request): Response
    {
        $this->gate($request);
        $code = strtolower(trim((string) $request->attribute('code')));
        if ($code === '') {
            throw HttpException::validation(['code' => ['Plan code is required.']]);
        }
        $plan = (new SubscriptionPlanService())->update($code, $request->all());
        return $this->success($plan, 'Plan updated.');
    }

    private function gate(Request $request): void
    {
        $svc = new AdminRegistrationService();
        $user = $request->user() ?? [];
        $svc->assertPlatformAdmin(
            (int) $request->clientId(),
            (int) ($request->branchId() ?: ($user['branch_id'] ?? 0)),
            (string) ($user['role'] ?? '')
        );
    }
}
