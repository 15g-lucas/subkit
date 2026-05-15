<?php

namespace SubKit\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class GuestCheckoutController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $redirect = $request->query('redirect');

        if ($redirect !== null) {
            if (! $request->hasValidSignature()) {
                abort(403);
            }

            // Reject dangerous URL schemes (e.g. javascript:) even though the
            // value is already HMAC-protected by the signed URL.
            if (! str_starts_with($redirect, '/') && ! preg_match('#^https?://#i', $redirect)) {
                abort(403);
            }

            return redirect()->to($redirect);
        }

        // Cashier syncs the subscription via its webhook handler.
        // Nothing to do here — redirect to the configured success URL.
        return redirect()->to(
            config('subkit.guest_checkout.success_url', '/')
        );
    }
}
