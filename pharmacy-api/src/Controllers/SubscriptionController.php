<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\ClientSubscriptionService;
use App\Services\SubscriptionPaymentService;
use App\Services\SubscriptionPlanService;

final class SubscriptionController extends Controller
{
    public function status(Request $request): Response
    {
        $clientId = (int) $request->clientId();
        $user = $request->user() ?? [];
        $snap = (new ClientSubscriptionService())->snapshotForClient($clientId, $user);
        return $this->success(['subscription' => $snap], 'Subscription status.');
    }

    /**
     * Create Razorpay Checkout order — amount locked from plan (server-side).
     */
    public function checkout(Request $request): Response
    {
        $data = $this->validate($request, [
            'plan_code' => 'required|string|max:32',
        ]);

        $clientId = (int) $request->clientId();
        if ($clientId <= 0) {
            throw HttpException::forbidden('No pharmacy on this login.');
        }

        $result = (new SubscriptionPaymentService())->createCheckout(
            $clientId,
            (string) $data['plan_code'],
            $request->user() ?? [],
            $request->ip()
        );

        return $this->success($result, 'Payment order created.');
    }

    /**
     * Verify Razorpay Checkout success signature and activate subscription.
     */
    public function verify(Request $request): Response
    {
        $data = $this->validate($request, [
            'razorpay_order_id' => 'required|string|max:64',
            'razorpay_payment_id' => 'required|string|max:64',
            'razorpay_signature' => 'required|string|max:255',
        ]);

        $clientId = (int) $request->clientId();
        if ($clientId <= 0) {
            throw HttpException::forbidden('No pharmacy on this login.');
        }

        $result = (new SubscriptionPaymentService())->verifyAndActivate(
            $clientId,
            (string) $data['razorpay_order_id'],
            (string) $data['razorpay_payment_id'],
            (string) $data['razorpay_signature'],
            (int) ($request->user()['id'] ?? 0),
            $request->ip()
        );

        return $this->success($result, 'Payment verified. Subscription activated.');
    }

    /**
     * Legacy manual UPI-ref activate — disabled. Use Razorpay Checkout.
     */
    public function activate(Request $request): Response
    {
        throw new HttpException(
            'Manual payment activation is disabled. Please pay securely via Razorpay Checkout.',
            410
        );
    }

    /** Public Razorpay webhook (no JWT). */
    public function webhook(Request $request): Response
    {
        $raw = $request->rawBody();
        if ($raw === '') {
            // Fallback if content-type was not JSON
            $raw = file_get_contents('php://input') ?: '';
        }
        $sig = $request->header('x-razorpay-signature');
        $result = (new SubscriptionPaymentService())->handleWebhook($raw, $sig);
        return $this->success($result, 'Webhook processed.');
    }

    /** Public preview of payable for a plan (Android paywall). */
    public function quote(Request $request): Response
    {
        $code = strtolower(trim((string) $request->query('plan_code', $request->input('plan_code', ''))));
        if ($code === '') {
            throw HttpException::validation(['plan_code' => ['Plan code is required.']]);
        }
        $plan = (new SubscriptionPlanService())->findByCode($code);
        if (!$plan || empty($plan['is_active'])) {
            throw HttpException::notFound('Plan not found.');
        }
        return $this->success(['plan' => $plan], 'Plan quote.');
    }
}
