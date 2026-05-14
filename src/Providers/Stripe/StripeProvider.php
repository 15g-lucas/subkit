<?php

namespace SubKit\Providers\Stripe;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Stripe\StripeClient;
use SubKit\Contracts\PaymentProviderContract;

class StripeProvider implements PaymentProviderContract
{
    public function name(): string
    {
        return 'stripe';
    }

    public function createCheckoutSession(
        ?Model $user,
        string $priceId,
        string $successUrl,
        string $cancelUrl,
        ?int $trialDays = null,
        int $quantity = 1,
        array $options = [],
    ): string {
        $installationFee = (int) ($options['installation_fee'] ?? 0);
        $installationFeeLabel = (string) ($options['installation_fee_label'] ?? 'Installation fee');
        unset($options['installation_fee'], $options['installation_fee_label']);

        if (! $user) {
            $secret = (string) config('cashier.secret');
            if ($secret === '') {
                throw new RuntimeException('Missing Stripe secret key for guest checkout.');
            }

            $payload = $this->guestPayload(
                priceId: $priceId,
                successUrl: $successUrl,
                cancelUrl: $cancelUrl,
                trialDays: $trialDays,
                quantity: $quantity,
                installationFee: $installationFee,
                installationFeeLabel: $installationFeeLabel,
            );

            $session = (new StripeClient($secret))
                ->checkout
                ->sessions
                ->create(array_merge($payload, $options));

            return (string) $session->url;
        }

        $builder = $user->newSubscription('default', $priceId);

        if ($trialDays !== null && $trialDays > 0) {
            $builder->trialDays($trialDays);
        }

        if ($quantity > 1) {
            $builder->quantity($quantity);
        }

        $checkoutPayload = [
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ];

        if ($installationFee > 0) {
            $checkoutPayload['subscription_data'] = [
                'add_invoice_items' => [[
                    'price_data' => [
                        'currency' => strtolower((string) config('subkit.currency.code', 'USD')),
                        'unit_amount' => $installationFee,
                        'product_data' => [
                            'name' => $installationFeeLabel,
                        ],
                    ],
                ]],
            ];
        }

        return $builder->checkout(array_merge($checkoutPayload, $options))->url;
    }

    /**
     * @return array<string, mixed>
     */
    protected function guestPayload(
        string $priceId,
        string $successUrl,
        string $cancelUrl,
        ?int $trialDays,
        int $quantity,
        int $installationFee,
        string $installationFeeLabel,
    ): array {
        $payload = [
            'mode' => 'subscription',
            'line_items' => [[
                'price' => $priceId,
                'quantity' => max(1, $quantity),
            ]],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ];

        $subscriptionData = [];

        if ($trialDays !== null && $trialDays > 0) {
            $subscriptionData['trial_period_days'] = $trialDays;
        }

        if ($installationFee > 0) {
            $subscriptionData['add_invoice_items'] = [[
                'price_data' => [
                    'currency' => strtolower((string) config('subkit.currency.code', 'USD')),
                    'unit_amount' => $installationFee,
                    'product_data' => [
                        'name' => $installationFeeLabel,
                    ],
                ],
            ]];
        }

        if ($subscriptionData !== []) {
            $payload['subscription_data'] = $subscriptionData;
        }

        return $payload;
    }

    public function cancelSubscription(Model $user, bool $immediately = false): void
    {
        $immediately
            ? $user->subscription('default')->cancelNow()
            : $user->subscription('default')->cancel();
    }

    public function resumeSubscription(Model $user): void
    {
        $user->subscription('default')->resume();
    }

    public function createBillingPortalSession(Model $user, string $returnUrl): string
    {
        return $user->billingPortalUrl($returnUrl);
    }
}
