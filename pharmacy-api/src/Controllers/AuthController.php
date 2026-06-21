<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;

final class AuthController extends Controller
{
    private AuthService $auth;

    public function __construct()
    {
        $this->auth = new AuthService();
    }

    public function login(Request $request): Response
    {
        $data = $this->validate($request, [
            'email'    => 'required|email',
            'password' => 'required|string|min:6',
        ]);

        $result = $this->auth->attempt($data['email'], $data['password'], $request->ip());

        return $this->success($result, 'Login successful.');
    }

    public function refresh(Request $request): Response
    {
        $data = $this->validate($request, [
            'refresh_token' => 'required|string',
        ]);

        $result = $this->auth->refresh($data['refresh_token']);

        return $this->success($result, 'Token refreshed.');
    }

    public function logout(Request $request): Response
    {
        $token = (string) $request->input('refresh_token', '');
        if ($token !== '') {
            $this->auth->logout($token);
        }
        return $this->success(null, 'Logged out.');
    }

    public function me(Request $request): Response
    {
        return $this->success($request->user(), 'Authenticated user.');
    }
}
