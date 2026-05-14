<?php

namespace SubKit\Listeners;

use Functional\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Cashier\Subscription as CashierSubscription;
use Stripe\StripeClient;
use SubKit\Events\SubscriptionActivated;
use SubKit\Events\SubscriptionCanceled;
use SubKit\Events\SubscriptionCancelScheduled;
use SubKit\Events\SubscriptionCreated;
use SubKit\Events\SubscriptionPastDue;
use SubKit\Events\SubscriptionPaused;
use SubKit\Events\SubscriptionResumed;
use SubKit\Events\SubscriptionTrialStarted;

class WebhookEventDispatcher
{
    public function handle(WebhookHandled $event): void
    {
        $payload = $event->payload;
        $type = $payload['type'] ?? '';

        $user = $this->resolveUser(
            payload: $payload,
            createIfMissing: in_array($type, ['checkout.session.completed', 'customer.subscription.created', 'customer.subscription.updated'], true),
        );
        if (! $user) {
            return;
        }

        $subscription = $this->resolveSubscription($user, $payload);

        match ($type) {
            'checkout.session.completed' => null,
            'customer.subscription.created' => $this->handleCreated($user, $subscription, $payload),
            'customer.subscription.updated' => $this->handleUpdated($user, $subscription, $payload),
            'customer.subscription.deleted' => SubscriptionCanceled::dispatch($user, $subscription, $payload),
            default => null,
        };
    }

    private function handleCreated(Model $user, ?CashierSubscription $subscription, array $payload): void
    {
        SubscriptionCreated::dispatch($user, $subscription, $payload);

        if (($payload['data']['object']['status'] ?? '') === 'trialing') {
            SubscriptionTrialStarted::dispatch($user, $subscription, $payload);
        }
    }

    private function handleUpdated(Model $user, ?CashierSubscription $subscription, array $payload): void
    {
        $current = $payload['data']['object'];
        $previous = $payload['data']['previous_attributes'] ?? [];

        // Status transition
        $newStatus = $current['status'] ?? null;
        $prevStatus = $previous['status'] ?? null;

        if ($prevStatus !== null && $prevStatus !== $newStatus) {
            match ($newStatus) {
                'active' => SubscriptionActivated::dispatch($user, $subscription, $payload),
                'past_due' => SubscriptionPastDue::dispatch($user, $subscription, $payload),
                'paused' => SubscriptionPaused::dispatch($user, $subscription, $payload),
                default => null,
            };
        }

        // cancel_at_period_end transition
        if (array_key_exists('cancel_at_period_end', $previous)) {
            $wasScheduled = (bool) $previous['cancel_at_period_end'];
            $isScheduled = (bool) ($current['cancel_at_period_end'] ?? false);

            if (! $wasScheduled && $isScheduled) {
                SubscriptionCancelScheduled::dispatch($user, $subscription, $payload);
            } elseif ($wasScheduled && ! $isScheduled) {
                SubscriptionResumed::dispatch($user, $subscription, $payload);
            }
        }
    }

    private function resolveUser(array $payload, bool $createIfMissing = false): ?Model
    {
        $stripeCustomerId = $payload['data']['object']['customer'] ?? null;
        if (! $stripeCustomerId) {
            return null;
        }

        $userModel = config('auth.providers.users.model', User::class);

        /** @var Model|null $user */
        $user = $userModel::where('stripe_id', $stripeCustomerId)->first();
        if ($user || ! $createIfMissing) {
            return $user;
        }

        return $this->resolveOrCreateUser($payload, $stripeCustomerId);
    }

    private function resolveSubscription(Model $user, array $payload): ?CashierSubscription
    {
        $type = $payload['type'] ?? '';
        $stripeSubscriptionId = $payload['data']['object']['id'] ?? null;
        if (! $stripeSubscriptionId) {
            return null;
        }

        /** @var CashierSubscription|null $subscription */
        $subscription = $user->subscriptions()->where('stripe_id', $stripeSubscriptionId)->first();
        if ($subscription) {
            return $this->syncSubscription($subscription, $payload);
        }

        if (! in_array($type, ['customer.subscription.created', 'customer.subscription.updated'], true)) {
            return null;
        }

        return $this->createSubscription($user, $payload);
    }

    private function resolveOrCreateUser(array $payload, string $stripeCustomerId): ?Model
    {
        $email = $this->extractBillingEmail($payload, $stripeCustomerId);
        if (! $email) {
            return null;
        }

        $userModel = config('auth.providers.users.model', User::class);

        /** @var Model|null $existingByEmail */
        $existingByEmail = $userModel::where('email', $email)->first();
        if ($existingByEmail) {
            if (empty($existingByEmail->getAttribute('stripe_id'))) {
                $existingByEmail->forceFill(['stripe_id' => $stripeCustomerId]);
                $existingByEmail->save();
            }

            return $existingByEmail;
        }

        /** @var Model $user */
        $user = new $userModel;
        $fullName = $this->extractBillingName($payload, $stripeCustomerId) ?? Str::before($email, '@');
        [$firstName, $lastName] = $this->splitName($fullName);

        $attributes = [
            'email' => $email,
            'stripe_id' => $stripeCustomerId,
        ];

        $table = $user->getTable();
        if (Schema::hasColumn($table, 'name')) {
            $attributes['name'] = $fullName;
        }
        if (Schema::hasColumn($table, 'first_name')) {
            $attributes['first_name'] = $firstName;
        }
        if (Schema::hasColumn($table, 'last_name')) {
            $attributes['last_name'] = $lastName;
        }
        if (Schema::hasColumn($table, 'password')) {
            $attributes['password'] = Hash::make(Str::random(40));
        }
        if (Schema::hasColumn($table, 'email_verified_at')) {
            $attributes['email_verified_at'] = now();
        }

        $user->forceFill($attributes);
        $user->save();

        return $user;
    }

    private function extractBillingEmail(array $payload, string $stripeCustomerId): ?string
    {
        $object = $payload['data']['object'] ?? [];

        $email = $object['customer_details']['email']
            ?? $object['customer_email']
            ?? $object['email']
            ?? null;

        if (is_string($email) && trim($email) !== '') {
            return strtolower(trim($email));
        }

        $value = $this->fetchStripeCustomerField($stripeCustomerId, 'email');

        return is_string($value) && trim($value) !== '' ? strtolower(trim($value)) : null;
    }

    private function extractBillingName(array $payload, string $stripeCustomerId): ?string
    {
        $object = $payload['data']['object'] ?? [];

        $name = $object['customer_details']['name']
            ?? $object['customer_name']
            ?? $object['name']
            ?? null;

        if (is_string($name) && trim($name) !== '') {
            return trim($name);
        }

        $value = $this->fetchStripeCustomerField($stripeCustomerId, 'name');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function fetchStripeCustomerField(string $stripeCustomerId, string $field): mixed
    {
        $secret = (string) config('cashier.secret');
        if ($secret === '') {
            return null;
        }

        try {
            $customer = (new StripeClient($secret))->customers->retrieve($stripeCustomerId, []);

            return $customer->{$field} ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = $parts[0] ?? $name;
        $last = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';

        return [$first, $last];
    }

    private function createSubscription(Model $user, array $payload): ?CashierSubscription
    {
        $object = $payload['data']['object'] ?? [];
        $stripeSubscriptionId = $object['id'] ?? null;
        if (! is_string($stripeSubscriptionId) || $stripeSubscriptionId === '') {
            return null;
        }

        $priceId = $object['items']['data'][0]['price']['id'] ?? '';
        $status = $object['status'] ?? 'incomplete';

        /** @var CashierSubscription $subscription */
        $subscription = $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => $stripeSubscriptionId,
            'stripe_status' => is_string($status) ? $status : 'incomplete',
            'stripe_price' => is_string($priceId) ? $priceId : '',
            'quantity' => (int) ($object['quantity'] ?? 1),
            'trial_ends_at' => $this->toDateTime($object['trial_end'] ?? null),
            'ends_at' => $this->toDateTime($object['cancel_at'] ?? null),
        ]);

        return $subscription;
    }

    private function syncSubscription(CashierSubscription $subscription, array $payload): CashierSubscription
    {
        $object = $payload['data']['object'] ?? [];
        $status = $object['status'] ?? null;
        $priceId = $object['items']['data'][0]['price']['id'] ?? null;

        $updates = [];
        if (is_string($status) && $status !== '') {
            $updates['stripe_status'] = $status;
        }
        if (is_string($priceId) && $priceId !== '') {
            $updates['stripe_price'] = $priceId;
        }

        if ($updates !== []) {
            $subscription->forceFill($updates);
            $subscription->save();
        }

        return $subscription;
    }

    private function toDateTime(mixed $value): ?\DateTimeInterface
    {
        if (! is_numeric($value)) {
            return null;
        }

        return now()->setTimestamp((int) $value);
    }
}
