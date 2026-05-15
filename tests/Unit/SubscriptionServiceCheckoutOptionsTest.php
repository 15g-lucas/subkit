<?php

namespace Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use SubKit\Contracts\PaymentProviderContract;
use SubKit\Models\Plan;
use SubKit\Models\PlanProviderPrice;
use SubKit\Services\ProviderRegistry;
use SubKit\Services\SubscriptionService;
use Tests\TestCase;

class SubscriptionServiceCheckoutOptionsTest extends TestCase
{
    /**
     * @return array{url: string, quantity: int, options: array<string, mixed>, success_url: string}|null
     */
    private function checkoutForPlan(Plan $plan, int $requestedQuantity, string $successUrl = 'https://example.com/success'): ?array
    {
        $provider = new class implements PaymentProviderContract
        {
            /** @var array{url: string, quantity: int, options: array<string, mixed>, success_url: string}|null */
            public ?array $captured = null;

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
                $this->captured = [
                    'url' => 'https://checkout.test/session',
                    'quantity' => $quantity,
                    'options' => $options,
                    'success_url' => $successUrl,
                ];

                return $this->captured['url'];
            }

            public function cancelSubscription(Model $user, bool $immediately = false): void {}

            public function resumeSubscription(Model $user): void {}

            public function createBillingPortalSession(Model $user, string $returnUrl): string
            {
                return 'https://billing.test/portal';
            }
        };

        $registry = new ProviderRegistry;
        $registry->register($provider);

        PlanProviderPrice::create([
            'plan_id' => $plan->id,
            'provider' => 'stripe',
            'provider_price_id' => 'price_test_123',
        ]);

        $service = new SubscriptionService($registry);
        $service->checkout(
            planCode: $plan->code,
            userId: null,
            successUrl: $successUrl,
            cancelUrl: 'https://example.com/cancel',
            quantity: $requestedQuantity,
        );

        return $provider->captured;
    }

    public function test_checkout_for_non_quantity_plan_forces_quantity_to_one(): void
    {
        $plan = Plan::create([
            'code' => 'basic',
            'name' => 'Basic',
            'interval' => 'monthly',
            'price' => 1200,
            'has_quantity' => false,
            'is_active' => true,
            'version' => 1,
        ]);

        $captured = $this->checkoutForPlan($plan, requestedQuantity: 7);

        $this->assertNotNull($captured);
        $this->assertSame(1, $captured['quantity']);
    }

    public function test_checkout_for_quantity_plan_preserves_requested_quantity(): void
    {
        $plan = Plan::create([
            'code' => 'team',
            'name' => 'Team',
            'interval' => 'monthly',
            'price' => 3000,
            'has_quantity' => true,
            'is_active' => true,
            'version' => 1,
        ]);

        $captured = $this->checkoutForPlan($plan, requestedQuantity: 7);

        $this->assertNotNull($captured);
        $this->assertSame(7, $captured['quantity']);
    }

    public function test_checkout_adds_installation_fee_to_provider_options(): void
    {
        $plan = Plan::create([
            'code' => 'pro',
            'name' => 'Pro',
            'interval' => 'monthly',
            'price' => 9900,
            'installation_fee' => 2500,
            'has_quantity' => true,
            'is_active' => true,
            'version' => 1,
        ]);

        $captured = $this->checkoutForPlan($plan, requestedQuantity: 2);

        $this->assertNotNull($captured);
        $this->assertSame(2500, $captured['options']['installation_fee']);
        $this->assertArrayHasKey('installation_fee_label', $captured['options']);
    }

    public function test_checkout_signs_local_success_urls(): void
    {
        config(['app.key' => 'subkit-unit-test-signing-key']);

        $plan = Plan::create([
            'code' => 'growth',
            'name' => 'Growth',
            'interval' => 'monthly',
            'price' => 4900,
            'has_quantity' => false,
            'is_active' => true,
            'version' => 1,
        ]);

        $captured = $this->checkoutForPlan($plan, requestedQuantity: 1, successUrl: url('/success'));

        $this->assertNotNull($captured);
        $this->assertStringContainsString('signature=', $captured['success_url']);
        $this->assertTrue(URL::hasValidSignature(Request::create($captured['success_url'])));
    }

    public function test_checkout_does_not_sign_success_urls_with_stripe_placeholders(): void
    {
        $plan = Plan::create([
            'code' => 'startup',
            'name' => 'Startup',
            'interval' => 'monthly',
            'price' => 1900,
            'has_quantity' => false,
            'is_active' => true,
            'version' => 1,
        ]);

        $successUrl = url('/success?session_id={CHECKOUT_SESSION_ID}');
        $captured = $this->checkoutForPlan($plan, requestedQuantity: 1, successUrl: $successUrl);

        $this->assertNotNull($captured);
        $this->assertSame($successUrl, $captured['success_url']);
    }

    public function test_checkout_does_not_sign_external_success_urls(): void
    {
        $plan = Plan::create([
            'code' => 'scale',
            'name' => 'Scale',
            'interval' => 'monthly',
            'price' => 7900,
            'has_quantity' => false,
            'is_active' => true,
            'version' => 1,
        ]);

        $successUrl = 'https://external.example.com/success';
        $captured = $this->checkoutForPlan($plan, requestedQuantity: 1, successUrl: $successUrl);

        $this->assertNotNull($captured);
        $this->assertSame($successUrl, $captured['success_url']);
    }
}
