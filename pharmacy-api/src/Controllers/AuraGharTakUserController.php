<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuraGharTakUserService;

/** AuraGharTak Android app — customer register / login / profile. */
final class AuraGharTakUserController extends Controller
{
    public function register(Request $request): Response
    {
        $data = $request->all();
        $photo = is_string($data['photo_base64'] ?? null) ? (string) $data['photo_base64'] : '';
        $result = (new AuraGharTakUserService())->register($data, $photo);
        return $this->created($result, 'Account created.');
    }

    public function login(Request $request): Response
    {
        $data = $this->validate($request, [
            'phone'    => 'required|string|max:20',
            'password' => 'required|string|max:128',
        ]);
        $result = (new AuraGharTakUserService())->login(
            (string) $data['phone'],
            (string) $data['password']
        );
        return $this->success($result, 'Welcome back.');
    }

    public function logout(Request $request): Response
    {
        (new AuraGharTakUserService())->logout($request->bearerToken());
        return $this->success(null, 'Logged out.');
    }

    public function me(Request $request): Response
    {
        $token = $request->bearerToken();
        $user = (new AuraGharTakUserService())->userByToken($token);
        if (!$user) {
            throw HttpException::unauthorized('Please log in.');
        }
        return $this->success(['user' => $user], 'Profile.');
    }

    public function updateProfile(Request $request): Response
    {
        $token = $request->bearerToken();
        if ($token === null || $token === '') {
            throw HttpException::unauthorized('Please log in.');
        }
        $data = $request->all();
        $password = (string) ($data['password'] ?? '');
        if ($password === '') {
            throw HttpException::validation(['password' => ['Enter your password to save changes.']]);
        }
        $photo = is_string($data['photo_base64'] ?? null) ? (string) $data['photo_base64'] : '';
        $user = (new AuraGharTakUserService())->updateProfile($token, $password, $data, $photo);
        return $this->success(['user' => $user], 'Profile updated.');
    }

    public function photo(Request $request): Response
    {
        $file = (string) $request->attribute('file');
        $path = (new AuraGharTakUserService())->photoFilePath($file);
        if ($path === null) {
            throw HttpException::notFound('Photo not found.');
        }
        $lower = strtolower($path);
        $mime = match (true) {
            str_ends_with($lower, '.png') => 'image/png',
            str_ends_with($lower, '.webp') => 'image/webp',
            default => 'image/jpeg',
        };
        return Response::raw((string) file_get_contents($path), $mime);
    }
}
