<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\AgtDeliveryService;
use App\Services\AuraGharTakUserService;
use App\Services\UserRightsService;

/** AuraGharTak live delivery — staff assign riders, riders push GPS, customers watch the map. */
final class AgtDeliveryController extends Controller
{
    private const STAFF_MODULE = 'AURA_GHAR_TAK';
    private const RIDER_MODULE = 'AGT_RIDER';

    private function requireModule(Request $request, string $module, bool $edit): void
    {
        $user = $request->user() ?? [];
        (new UserRightsService())->requireModule(
            (int) $request->clientId(),
            (int) ($user['id'] ?? 0),
            (string) ($user['role'] ?? ''),
            $module,
            $edit,
        );
    }

    private function riderId(Request $request): int
    {
        return (int) (($request->user() ?? [])['id'] ?? 0);
    }

    public function riders(Request $request): Response
    {
        $this->requireModule($request, self::STAFF_MODULE, false);
        $rows = (new AgtDeliveryService())->listRiders((int) $request->clientId(), (int) $request->branchId());
        return $this->success(['riders' => $rows], 'Delivery staff.');
    }

    public function assignRider(Request $request): Response
    {
        $this->requireModule($request, self::STAFF_MODULE, true);
        $data = $this->validate($request, [
            'order_id' => 'required|integer',
            'rider_user_id' => 'integer',
        ]);
        $result = (new AgtDeliveryService())->assignRider(
            (int) $request->clientId(),
            (int) $request->branchId(),
            (int) $data['order_id'],
            (int) ($data['rider_user_id'] ?? 0),
        );
        return $this->success($result, $result['message']);
    }

    public function riderOrders(Request $request): Response
    {
        $this->requireModule($request, self::RIDER_MODULE, false);
        $user = $request->user() ?? [];
        $rows = (new AgtDeliveryService())->riderOrders((int) $request->clientId(), $this->riderId($request));
        return $this->success([
            'rider' => ['id' => $this->riderId($request), 'name' => (string) ($user['name'] ?? '')],
            'orders' => $rows,
        ], 'My deliveries.');
    }

    public function riderStart(Request $request): Response
    {
        $this->requireModule($request, self::RIDER_MODULE, true);
        $data = $this->validate($request, ['order_id' => 'required|integer']);
        $result = (new AgtDeliveryService())->riderStart(
            (int) $request->clientId(),
            $this->riderId($request),
            (int) $data['order_id'],
        );
        return $this->success($result, $result['message']);
    }

    public function riderDelivered(Request $request): Response
    {
        $this->requireModule($request, self::RIDER_MODULE, true);
        $data = $this->validate($request, ['order_id' => 'required|integer']);
        $result = (new AgtDeliveryService())->riderDelivered(
            (int) $request->clientId(),
            $this->riderId($request),
            (int) $data['order_id'],
        );
        return $this->success($result, $result['message']);
    }

    public function riderLocation(Request $request): Response
    {
        $this->requireModule($request, self::RIDER_MODULE, true);
        $data = $this->validate($request, [
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
        ]);
        $result = (new AgtDeliveryService())->riderPing(
            (int) $request->clientId(),
            $this->riderId($request),
            array_merge($request->all(), $data),
        );
        return $this->success($result, 'Location saved.');
    }

    // ---- Public (no auth) ----

    public function publicLive(Request $request): Response
    {
        $lookup = trim((string) ($request->query('order_no', '') ?: $request->query('token', '')));
        $viewerPhone = (new AuraGharTakUserService())->phoneForToken($request->bearerToken());
        $result = (new AgtDeliveryService())->publicLive($lookup, $viewerPhone);
        return $this->success($result, 'Live delivery.');
    }
}
