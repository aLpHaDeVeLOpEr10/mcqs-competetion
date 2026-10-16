{{-- SIMULATED provider checkout (local/testing only). Not part of the real product. --}}
<x-layouts.app title="Simulated payment provider">
    <div class="mx-auto max-w-md px-4 py-12">
        <x-alert variant="warning" title="Test payment provider">
            This page simulates a payment provider for local development. No real money moves.
        </x-alert>
        <x-card class="mt-6">
            <p class="text-sm text-ink-400">Pay merchant {{ config('competition.payments.fake.merchant_id') }}</p>
            <p class="mt-1 text-3xl font-bold text-white">{{ $amount }}</p>
            <p class="mt-2 font-mono text-xs text-ink-400">Order {{ $state['reference'] }} · Provider ref {{ $providerReference }}</p>
            @if ($state['status'] === 'pending')
                <form method="POST" action="{{ route('fake-gateway.complete', $providerReference) }}" class="mt-6 flex gap-3">
                    @csrf
                    <x-button type="submit" name="outcome" value="succeeded">Approve payment</x-button>
                    <x-button type="submit" name="outcome" value="failed" variant="danger">Decline</x-button>
                </form>
            @else
                <p class="mt-6 text-sm text-ink-300">This simulated payment is already {{ $state['status'] }}.</p>
            @endif
        </x-card>
    </div>
</x-layouts.app>
