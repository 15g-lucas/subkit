<?php

namespace Tests\Unit;

use Tests\TestCase;

class GuestCheckoutSuccessRouteTest extends TestCase
{
    public function test_guest_checkout_success_route_is_registered(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Route::has('subkit.guest-checkout.success')
        );
    }

    public function test_guest_checkout_success_redirects_to_configured_url(): void
    {
        config(['subkit.guest_checkout.success_url' => '/thank-you']);

        $response = $this->get(route('subkit.guest-checkout.success'));

        $response->assertRedirect('/thank-you');
    }

    public function test_guest_checkout_success_redirects_to_default_when_not_configured(): void
    {
        config(['subkit.guest_checkout.success_url' => '/']);

        $response = $this->get(route('subkit.guest-checkout.success'));

        $response->assertRedirect('/');
    }
}
