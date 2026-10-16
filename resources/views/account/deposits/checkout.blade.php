{{-- Hands the player to a provider that expects a POSTed form (hosted checkout). --}}
<x-layouts.app title="Redirecting to payment">
    <div class="mx-auto max-w-md px-4 py-16 text-center">
        <p class="text-ink-300">Taking you to the payment provider to pay {{ \App\Support\Money::format($payment->amount_minor, $payment->currency) }}…</p>
        <form id="checkout-form" method="POST" action="{{ $checkout['url'] }}" class="mt-6">
            @foreach ($checkout['fields'] as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            <x-button type="submit">Continue to payment</x-button>
        </form>
    </div>
    @push('scripts')
        <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">document.addEventListener('DOMContentLoaded', () => document.getElementById('checkout-form').submit());</script>
    @endpush
</x-layouts.app>
