<?php

namespace SubKit\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use SubKit\Services\SubscriptionService;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $service,
    ) {}

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_code' => ['required', 'string'],
            'user_id' => ['required', 'string'],
            'success_url' => ['required', 'string'],
            'cancel_url' => ['required', 'string'],
            'provider' => ['sometimes', 'string'],
            'quantity' => ['sometimes', 'integer', 'min:1'],
        ]);

        $url = $this->service->checkout(
            planCode: $data['plan_code'],
            userId: $data['user_id'],
            successUrl: $data['success_url'],
            cancelUrl: $data['cancel_url'],
            provider: $data['provider'] ?? 'stripe',
            quantity: (int) ($data['quantity'] ?? 1),
        );

        return response()->json(['checkout_url' => $url]);
    }
}
