<?php

namespace Tests\Unit;

use SubKit\Providers\Stripe\StripeProvider;
use Tests\TestCase;

class StripeProviderGuestPayloadTest extends TestCase
{
    public function test_guest_payload_uses_requested_quantity(): void
    {
        $provider = new class extends StripeProvider
        {
            /** @return array<string, mixed> */
            public function exposeGuestPayload(
                string $priceId,
                string $successUrl,
                string $cancelUrl,
                ?int $trialDays,
                int $quantity,
                int $installationFee,
                string $installationFeeLabel,
            ): array {
                return $this->guestPayload(
                    $priceId,
                    $successUrl,
                    $cancelUrl,
                    $trialDays,
                    $quantity,
                    $installationFee,
                    $installationFeeLabel,
                );
            }
        };

        $payload = $provider->exposeGuestPayload(
            priceId: 'price_test_123',
            successUrl: 'https://example.com/success',
            cancelUrl: 'https://example.com/cancel',
            trialDays: null,
            quantity: 5,
            installationFee: 0,
            installationFeeLabel: 'Installation fee',
        );

        $this->assertSame(5, $payload['line_items'][0]['quantity']);
    }

    public function test_guest_payload_adds_installation_fee_as_invoice_item(): void
    {
        config(['subkit.currency.code' => 'EUR']);

        $provider = new class extends StripeProvider
        {
            /** @return array<string, mixed> */
            public function exposeGuestPayload(
                string $priceId,
                string $successUrl,
                string $cancelUrl,
                ?int $trialDays,
                int $quantity,
                int $installationFee,
                string $installationFeeLabel,
            ): array {
                return $this->guestPayload(
                    $priceId,
                    $successUrl,
                    $cancelUrl,
                    $trialDays,
                    $quantity,
                    $installationFee,
                    $installationFeeLabel,
                );
            }
        };

        $payload = $provider->exposeGuestPayload(
            priceId: 'price_test_123',
            successUrl: 'https://example.com/success',
            cancelUrl: 'https://example.com/cancel',
            trialDays: 14,
            quantity: 2,
            installationFee: 4500,
            installationFeeLabel: 'Setup fee',
        );

        $this->assertSame(14, $payload['subscription_data']['trial_period_days']);
        $this->assertSame(
            4500,
            $payload['subscription_data']['add_invoice_items'][0]['price_data']['unit_amount']
        );
        $this->assertSame(
            'eur',
            $payload['subscription_data']['add_invoice_items'][0]['price_data']['currency']
        );
        $this->assertSame(
            'Setup fee',
            $payload['subscription_data']['add_invoice_items'][0]['price_data']['product_data']['name']
        );
    }

    public function test_guest_payload_requires_customer_details_fields(): void
    {
        $provider = new class extends StripeProvider
        {
            /** @return array<string, mixed> */
            public function exposeGuestPayload(
                string $priceId,
                string $successUrl,
                string $cancelUrl,
                ?int $trialDays,
                int $quantity,
                int $installationFee,
                string $installationFeeLabel,
            ): array {
                return $this->guestPayload(
                    $priceId,
                    $successUrl,
                    $cancelUrl,
                    $trialDays,
                    $quantity,
                    $installationFee,
                    $installationFeeLabel,
                );
            }
        };

        $payload = $provider->exposeGuestPayload(
            priceId: 'price_test_123',
            successUrl: 'https://example.com/success',
            cancelUrl: 'https://example.com/cancel',
            trialDays: null,
            quantity: 1,
            installationFee: 0,
            installationFeeLabel: 'Installation fee',
        );

        $this->assertSame('required', $payload['billing_address_collection']);
        $this->assertTrue($payload['phone_number_collection']['enabled']);
        $this->assertCount(3, $payload['custom_fields']);
        $this->assertSame('first_name', $payload['custom_fields'][0]['key']);
        $this->assertSame('last_name', $payload['custom_fields'][1]['key']);
        $this->assertSame('company_name', $payload['custom_fields'][2]['key']);
        $this->assertFalse($payload['custom_fields'][0]['optional']);
        $this->assertFalse($payload['custom_fields'][1]['optional']);
        $this->assertFalse($payload['custom_fields'][2]['optional']);
    }
}
