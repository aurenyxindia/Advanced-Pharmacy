<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Core\Controller;
use App\Core\HttpException;
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
            'email'    => 'required|string|max:150',
            'password' => 'required|string|min:6',
        ]);

        $login = trim((string) $data['email']);
        if (str_contains($login, '@')) {
            if (!filter_var($login, FILTER_VALIDATE_EMAIL)) {
                throw HttpException::validation(['email' => ['Enter a valid email address.']]);
            }
        } else {
            $digits = preg_replace('/\D+/', '', $login) ?? '';
            if (!preg_match('/^[6-9]\d{9}$/', $digits)) {
                throw HttpException::validation(['email' => ['Enter a valid 10-digit mobile number or email address.']]);
            }
        }

        $result = $this->auth->attempt($login, $data['password'], $request->ip(), $request->isAndroidApp());

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

    /** Send 4-digit OTP to registered email (Retail forgotPassMain parity). */
    public function forgotPasswordSendOtp(Request $request): Response
    {
        $data = $this->validate($request, [
            'username' => 'required|string|max:150',
        ]);

        $result = (new \App\Services\PasswordResetService())->sendOtp($data['username']);

        return $this->success([
            'email'  => $result['email'],
            'notice' => $result['notice'] ?? null,
        ], 'OTP sent successfully. Please check email: ' . $result['email']);
    }

    /** Reset password using OTP (Retail login.php forgotPassword parity). */
    public function forgotPasswordReset(Request $request): Response
    {
        $data = $this->validate($request, [
            'username'      => 'required|string|max:150',
            'security_code' => 'required|string|max:16',
            'password'      => 'required|string|min:6|max:128',
        ]);

        (new \App\Services\PasswordResetService())->resetPassword(
            $data['username'],
            $data['security_code'],
            $data['password'],
        );

        return $this->success(null, 'Password changed successfully.');
    }

    public function me(Request $request): Response
    {
        $user = $request->user() ?? [];
        $subscription = (new \App\Services\ClientSubscriptionService())->snapshotForClient(
            (int) ($request->clientId() ?: ($user['client_id'] ?? 0)),
            $user
        );
        // Keep user fields at top level for existing clients; add subscription alongside.
        return $this->success(array_merge($user, [
            'subscription' => $subscription,
        ]), 'Authenticated user.');
    }
}
