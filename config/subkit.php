<?php

use SubKit\Providers\Stripe\StripeProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | Display currency for the pricing table. Prices are stored in cents.
    | Example: code=USD, symbol=$  →  999 cents = "$9.99"
    |
    */

    'currency' => [
        'code' => env('EASY_SUB_CURRENCY_CODE', 'USD'),
        'symbol' => env('EASY_SUB_CURRENCY_SYMBOL', '$'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Providers
    |--------------------------------------------------------------------------
    |
    | Map provider names to their adapter classes.
    | Each adapter MUST implement PaymentProviderContract.
    |
    */

    'providers' => [
        'stripe' => StripeProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Guest Checkout
    |--------------------------------------------------------------------------
    |
    | Configuration for checkout sessions initiated by unauthenticated users.
    | When a guest completes payment on Stripe, they are redirected to the
    | 'subkit.guest-checkout.success' route, which then forwards them here.
    |
    | Set 'success_url' to a named route or absolute URL (e.g. '/thank-you').
    |
    */

    'guest_checkout' => [
        'success_url' => env('SUBKIT_GUEST_CHECKOUT_SUCCESS_URL', '/'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Web Middleware (pricing table checkout redirect)
    |--------------------------------------------------------------------------
    */

    'web' => [
        'middleware' => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | API Configuration
    |--------------------------------------------------------------------------
    |
    | Middleware applied to the package REST API routes.
    | Add 'auth:sanctum' or your own guard to protect these endpoints.
    |
    */

    'api' => [
        'middleware' => ['api'],
        'prefix' => 'api/subkit',
    ],

];
