<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Auth\RegistrationService;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;

final class RegistrationController extends Controller
{
    public function register(Request $request): Response
    {
        $data = $this->validate($request, [
            'business_name'   => 'required|string|min:3|max:150',
            'owner_name'      => 'required|string|min:3|max:120',
            'email'           => 'required|email|max:150',
            'phone'           => 'required|string|min:7|max:20',
            'drug_license_no' => 'required|string|min:3|max:60',
            'gstin'           => 'string|max:20',
            'address'         => 'string|max:255',
            'city'            => 'string|max:80',
            'state'           => 'string|max:80',
            'pincode'         => 'string|max:12',
            'password'        => 'required|string|min:8|confirmed',
        ]);

        (new RegistrationService())->register($data, $request->ip());

        // Auto-login after successful registration.
        $tokens = (new AuthService())->attempt($data['email'], $data['password'], $request->ip());

        return $this->created($tokens, 'Registration successful. Welcome aboard!');
    }
}
