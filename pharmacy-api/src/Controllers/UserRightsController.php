<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\UserRightsService;

final class UserRightsController extends Controller
{
    private function assertAdmin(Request $request): void
    {
        $role = (string) ($request->user()['role'] ?? '');
        if (!UserRightsService::isAdminRole($role)) {
            throw HttpException::forbidden('Admin access required.');
        }
    }

    public function modules(Request $request): Response
    {
        return $this->success(
            (new UserRightsService())->listModules(),
            'Modules.'
        );
    }

    public function users(Request $request): Response
    {
        $this->assertAdmin($request);
        return $this->success(
            (new UserRightsService())->listUsers((int) $request->clientId()),
            'Users.'
        );
    }

    public function showUser(Request $request): Response
    {
        $this->assertAdmin($request);
        return $this->success(
            (new UserRightsService())->getUser(
                (int) $request->clientId(),
                (int) $request->attribute('id')
            ),
            'User.'
        );
    }

    public function saveUser(Request $request): Response
    {
        $this->assertAdmin($request);
        $body = $request->all();
        $body['branch_id'] = $body['branch_id'] ?? $request->branchId();
        $result = (new UserRightsService())->saveUser((int) $request->clientId(), $body);
        return $this->success($result, 'User saved.');
    }

    public function deleteUser(Request $request): Response
    {
        $this->assertAdmin($request);
        (new UserRightsService())->deleteUser(
            (int) $request->clientId(),
            (int) $request->attribute('id')
        );
        return $this->success(null, 'User deactivated.');
    }

    public function myRights(Request $request): Response
    {
        $user = $request->user();
        return $this->success(
            (new UserRightsService())->myRights(
                (int) $request->clientId(),
                (int) ($user['id'] ?? 0),
                (string) ($user['role'] ?? '')
            ),
            'My rights.'
        );
    }
}
