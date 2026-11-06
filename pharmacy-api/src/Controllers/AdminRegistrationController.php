<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AdminRegistrationService;

final class AdminRegistrationController extends Controller
{
    private function gate(Request $request): AdminRegistrationService
    {
        $svc = new AdminRegistrationService();
        $user = $request->user() ?? [];
        $svc->assertPlatformAdmin(
            (int) $request->clientId(),
            (int) ($request->branchId() ?: ($user['branch_id'] ?? 0)),
            (string) ($user['role'] ?? '')
        );
        return $svc;
    }

    public function pending(Request $request): Response
    {
        $svc = $this->gate($request);
        $all = $svc->listAll();
        $pending = array_values(array_filter($all, static fn ($r) => (int) ($r['platform_active'] ?? 1) === 0));
        $active = array_values(array_filter($all, static fn ($r) => (int) ($r['platform_active'] ?? 1) === 1));
        return $this->success([
            'rows' => $all,
            'pending' => $pending,
            'recent' => array_slice($active, 0, 50),
        ], 'Pharmacy activations.');
    }

    public function activate(Request $request): Response
    {
        $svc = $this->gate($request);
        $clientId = (int) $request->attribute('clientId');
        if ($clientId <= 0) {
            throw HttpException::validation(['client_id' => ['client_id is required.']]);
        }
        $result = $svc->setPlatformActive(
            $clientId,
            true,
            (int) ($request->user()['id'] ?? 0),
            $request->ip()
        );
        return $this->success($result, 'Pharmacy activated. They can log in now.');
    }

    public function toggle(Request $request): Response
    {
        $svc = $this->gate($request);
        $clientId = (int) $request->attribute('clientId');
        if ($clientId <= 0) {
            throw HttpException::validation(['client_id' => ['client_id is required.']]);
        }
        $raw = $request->input('platform_active', $request->input('active', null));
        if ($raw === null) {
            throw HttpException::validation(['platform_active' => ['platform_active (0 or 1) is required.']]);
        }
        $active = (int) $raw === 1 || $raw === true || $raw === 'true';
        $result = $svc->setPlatformActive(
            $clientId,
            $active,
            (int) ($request->user()['id'] ?? 0),
            $request->ip()
        );
        $msg = $active
            ? 'Pharmacy activated. They can log in now.'
            : 'Pharmacy inactivated. Login is blocked.';
        return $this->success($result, $msg);
    }
}
