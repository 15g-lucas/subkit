<div
    @if ($plan->has_quantity) x-data="{ qty: 1 }" @endif
    class="relative flex flex-col rounded-2xl border shadow-sm
    {{ $highlighted ? 'border-indigo-400 ring-2 ring-indigo-400 bg-gray-800' : 'border-gray-700 bg-gray-800' }}
    p-6">

    @if ($highlighted)
        <div class="absolute -top-3 left-1/2 -translate-x-1/2">
            <span class="inline-flex items-center rounded-full bg-indigo-500 px-3 py-0.5 text-xs font-medium text-white">
                Recommended
            </span>
        </div>
    @endif

    {{-- Plan name --}}
    <h3 class="text-lg font-semibold text-white">{{ $plan->name }}</h3>

    {{-- Price --}}
    <p class="mt-2 text-3xl font-bold text-white">
        @if ($plan->has_quantity)
            <span x-text="qty > 0 ? '{{ config('subkit.currency.symbol', '$') }}' + (qty * {{ $plan->price ?? 0 }} / 100).toFixed(2) : '{{ $plan->formatted_price }}'">{{ $plan->formatted_price }}</span>
        @else
            {{ $plan->formatted_price }}
        @endif
        @if ($plan->price)
            <span class="text-sm font-normal text-gray-400">/ {{ $plan->interval->value }}</span>
        @endif
    </p>

    {{-- Description --}}
    @if ($plan->description)
        <p class="mt-3 text-sm text-gray-400">{{ $plan->description }}</p>
    @endif

    {{-- Trial badge --}}
    @if ($plan->trial_days)
        <p class="mt-2 text-xs font-medium text-indigo-400">
            {{ $plan->trial_days }}-day free trial
        </p>
    @endif

    {{-- Feature list from plan relationship --}}
    @if ($plan->features->isNotEmpty())
        <ul class="mt-4 space-y-2 flex-1">
            @foreach ($plan->features as $feature)
                <li
                    class="flex items-center gap-2 text-sm {{ $feature->pivot->is_highlighted ? 'font-semibold text-indigo-400' : 'text-gray-300' }} relative"
                    @if ($feature->description) x-data="{ open: false }" @endif
                >
                    <span class="flex items-center gap-2">
                    <svg class="h-4 w-4 shrink-0 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                    {{ $feature->name }}

                    @if ($feature->description)
                        <span
                            class="ml-auto shrink-0 cursor-pointer text-gray-500 hover:text-indigo-300"
                            @mouseenter="open = true"
                            @mouseleave="open = false"
                        >
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </span>
                        <div
                            x-show="open"
                            x-cloak
                            class="absolute bottom-full right-0 mb-1 z-10 w-52 rounded-lg bg-gray-700 px-3 py-2 text-xs font-normal text-gray-100 shadow-lg"
                        >
                            {{ $feature->description }}
                            <div class="absolute right-2 top-full h-0 w-0 border-x-4 border-t-4 border-x-transparent border-t-gray-700"></div>
                        </div>
                    @endif
                    </span>
                    @if ($feature->pivot->value)
                        <span class="ml-auto text-xs {{ $feature->pivot->is_highlighted ? 'text-indigo-400' : 'text-gray-500' }}">{{ $feature->pivot->value }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @else
        <div class="flex-1"></div>
    @endif

    @auth
        @if (!$plan->price && !$plan->providerPrice($provider))
            {{-- Free plan --}}
            <div class="mt-6">
                @if (!empty($freeUrl) && $freeUrl !== '#')
                    <a href="{{ $freeUrl }}"
                       class="block w-full rounded-lg px-4 py-2.5 text-center text-sm font-semibold text-white transition
                           {{ $highlighted ? 'bg-indigo-500 hover:bg-indigo-400' : 'bg-gray-700 hover:bg-gray-600' }}"
                    >
                        {{ $labels['free'] ?? 'Get Started Free' }}
                    </a>
                @else
                    <span class="block w-full cursor-default rounded-lg px-4 py-2.5 text-center text-sm font-semibold text-gray-500">
                        {{ $labels['free'] ?? 'Get Started Free' }}
                    </span>
                @endif
            </div>
        @else
            {{-- Paid plan → Stripe Checkout --}}
            <form action="{{ route('subkit.checkout.redirect') }}" method="POST" class="mt-6">
                @csrf
                <input type="hidden" name="plan_code"   value="{{ $plan->code }}">
                <input type="hidden" name="provider"     value="{{ $provider }}">
                <input type="hidden" name="success_url"  value="{{ $successUrl }}">
                <input type="hidden" name="cancel_url"   value="{{ $cancelUrl }}">
                @if ($companyId)
                    <input type="hidden" name="company_id" value="{{ $companyId }}">
                @endif
                @if ($plan->has_quantity)
                    <div class="mb-4 flex items-center gap-3">
                        <label for="qty_{{ $plan->code }}" class="text-sm font-medium text-gray-300">
                            {{ __('subkit::messages.pricing.quantity') }}
                        </label>
                        <input
                            id="qty_{{ $plan->code }}"
                            type="number"
                            name="quantity"
                            x-model.number="qty"
                            min="1"
                            value="1"
                            class="w-20 rounded-lg border border-gray-600 bg-gray-700 px-3 py-1.5 text-center text-sm font-semibold text-white shadow-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                        >
                    </div>
                @endif
                <button
                    type="{{ $successUrl === '#' ? 'button' : 'submit' }}"
                    class="w-full rounded-lg px-4 py-2.5 text-sm font-semibold text-white transition
                        {{ $highlighted ? 'bg-indigo-500 hover:bg-indigo-400' : 'bg-gray-700 hover:bg-gray-600' }}"
                >
                    {{ $labels['subscribe'] ?? 'Get Started' }}
                </button>
            </form>
        @endif
    @else
        {{-- Guest --}}
        <div class="mt-6">
            <a href="{{ $guestUrl }}"
               class="block w-full rounded-lg px-4 py-2.5 text-center text-sm font-semibold text-white transition
                   {{ $highlighted ? 'bg-indigo-500 hover:bg-indigo-400' : 'bg-gray-700 hover:bg-gray-600' }}"
            >
                {{ $labels['guest'] ?? 'Create Account to Subscribe' }}
            </a>
        </div>
    @endauth
</div>
