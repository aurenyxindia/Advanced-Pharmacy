<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\RegistrationService;
use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

final class RegistrationController extends Controller
{
    public function register(Request $request): Response
    {
        $data = $this->validate($request, [
            'business_type'   => 'required|string|in:retail,wholesale,both',
            'business_name'   => 'required|string|min:3|max:150',
            'owner_name'      => 'required|string|min:3|max:120',
            'email'           => 'required|email|max:150',
            'phone'           => 'required|string|min:7|max:20',
            'drug_license_no' => 'required|string|min:3|max:60',
            'gstin'           => 'string|max:20',
            'pan'             => 'string|max:10',
            'fssai_no'        => 'string|max:14',
            'address'         => 'string|max:255',
            'city'            => 'string|max:80',
            'state'           => 'string|max:80',
            'pincode'         => 'string|max:12',
            'password'        => 'required|string|min:8|confirmed',
            'access_channel'  => 'string|in:web,android',
            'plan_code'       => 'string|max:32',
        ]);

        $data['_from_android'] = $request->isAndroidApp();
        if ($data['_from_android'] && trim((string) ($data['access_channel'] ?? '')) === '') {
            $data['access_channel'] = 'android';
        }
        if (trim((string) ($data['access_channel'] ?? '')) === '') {
            $data['access_channel'] = 'web';
        }

        $type = (string) ($data['business_type'] ?? 'both');
        if (in_array($type, ['wholesale', 'both'], true) && trim((string) ($data['gstin'] ?? '')) === '') {
            throw HttpException::validation([
                'gstin' => ['GSTIN is required for wholesale / PCD pharmacy.'],
            ]);
        }

        $result = (new RegistrationService())->register($data, $request->ip());

        $pending = !empty($result['pending_activation']);
        $channel = (string) ($result['web_android'] ?? 'web');
        $msg = $pending
            ? 'Registration received. Your login will be activated after admin approval.'
            : ($channel === 'android'
                ? 'Registration successful. Your 3-day free trial has started — you can sign in now.'
                : 'Registration successful.');

        return $this->created([
            'pending_activation' => $pending,
            'client_id' => $result['client_id'] ?? null,
            'email' => strtolower(trim((string) $data['email'])),
            'web_android' => $result['web_android'] ?? null,
            'android_expiry_date' => $result['android_expiry_date'] ?? null,
            'subscription_plan' => $result['subscription_plan'] ?? null,
            'subscription_status' => $result['subscription_status'] ?? null,
            'trial_ends_at' => $result['trial_ends_at'] ?? null,
        ], $msg);
    }
}
