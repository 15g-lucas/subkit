<?php

namespace Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use SubKit\Providers\Stripe\StripeProvider;
use Tests\TestCase;

class StripeProviderAuthenticatedCheckoutPayloadTest extends TestCase
{
    public function test_authenticated_checkout_adds_only_installation_fee_as_one_time_line_item(): void
    {
        $capturedCheckoutPayload = null;

        $builder = new class($capturedCheckoutPayload)
        {
            public ?int $trialDaysSet = null;

            public ?int $quantitySet = null;

            /** @var array<string, mixed>|null */
            private ?array $capturedCheckoutPayload;

            public function __construct(?array &$capturedCheckoutPayload)
            {
                $this->capturedCheckoutPayload = &$capturedCheckoutPayload;
            }

            public function trialDays(int $days): self
            {
                $this->trialDaysSet = $days;

                return $this;
            }

            public function quantity(int $quantity): self
            {
                $this->quantitySet = $quantity;

                return $this;
            }

            public function checkout(array $payload): object
            {
                $this->capturedCheckoutPayload = $payload;

                return (object) ['url' => 'https://checkout.test/session'];
            }
        };

        $user = new class($builder) extends Model
        {
            public array $newSubscriptionArguments = [];

            public function __construct(private object $builder) {}

            public function newSubscription($type, $price): object
            {
                $this->newSubscriptionArguments = [$type, $price];

                return $this->builder;
            }
        };

        config(['subkit.currency.code' => 'EUR']);

        $provider = new StripeProvider;
        $url = $provider->createCheckoutSession(
            user: $user,
            priceId: 'price_test_123',
            successUrl: 'https://example.com/success',
            cancelUrl: 'https://example.com/cancel',
            trialDays: 14,
            quantity: 3,
            options: [
                'installation_fee' => 2500,
                'installation_fee_label' => 'Setup fee',
            ],
        );

        $this->assertSame('https://checkout.test/session', $url);
        $this->assertSame(['default', 'price_test_123'], $user->newSubscriptionArguments);
        $this->assertSame(14, $builder->trialDaysSet);
        $this->assertSame(3, $builder->quantitySet);
        $this->assertIsArray($capturedCheckoutPayload);
        $this->assertArrayHasKey('line_items', $capturedCheckoutPayload);
        $this->assertCount(1, $capturedCheckoutPayload['line_items']);
        $this->assertArrayNotHasKey('price', $capturedCheckoutPayload['line_items'][0]);
        $this->assertSame(2500, $capturedCheckoutPayload['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame('eur', $capturedCheckoutPayload['line_items'][0]['price_data']['currency']);
        $this->assertSame('Setup fee', $capturedCheckoutPayload['line_items'][0]['price_data']['product_data']['name']);
    }

    public function test_authenticated_checkout_does_not_add_line_items_when_installation_fee_is_zero(): void
    {
        $capturedCheckoutPayload = null;

        $builder = new class($capturedCheckoutPayload)
        {
            /** @var array<string, mixed>|null */
            private ?array $capturedCheckoutPayload;

            public function __construct(?array &$capturedCheckoutPayload)
            {
                $this->capturedCheckoutPayload = &$capturedCheckoutPayload;
            }

            public function trialDays(int $days): self
            {
                return $this;
            }

            public function quantity(int $quantity): self
            {
                return $this;
            }

            public function checkout(array $payload): object
            {
                $this->capturedCheckoutPayload = $payload;

                return (object) ['url' => 'https://checkout.test/session'];
            }
        };

        $user = new class($builder) extends Model
        {
            public function __construct(private object $builder) {}

            public function newSubscription($type, $price): object
            {
                return $this->builder;
            }
        };

        $provider = new StripeProvider;
        $provider->createCheckoutSession(
            user: $user,
            priceId: 'price_test_123',
            successUrl: 'https://example.com/success',
            cancelUrl: 'https://example.com/cancel',
            trialDays: null,
            quantity: 1,
            options: [
                'installation_fee' => 0,
            ],
        );

        $this->assertIsArray($capturedCheckoutPayload);
        $this->assertArrayNotHasKey('line_items', $capturedCheckoutPayload);
    }
}
