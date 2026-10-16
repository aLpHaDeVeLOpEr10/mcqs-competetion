<x-account-layout title="Deposit">
    @if ($payment->status->isOpen())
        {{-- Re-read our own record every few seconds; the provider's redirect decides nothing. --}}
        @push('scripts')
            <meta http-equiv="refresh" content="5">
        @endpush
    @endif

    <x-card>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-sm text-ink-400">Deposit</p>
                <p class="mt-1 text-3xl font-bold text-white">{{ \App\Support\Money::format($payment->amount_minor, $payment->currency) }}</p>
            </div>
            @include('account.deposits.status-badge', ['status' => $payment->status])
        </div>

        <div class="mt-4 text-sm text-ink-300" role="status">
            @switch($payment->status)
                @case(\App\Enums\PaymentStatus::Succeeded)
                    The payment was confirmed by the provider and your wallet has been credited.
                    @break
                @case(\App\Enums\PaymentStatus::Failed)
                    The payment was not completed. No money was added to your wallet. If you were charged, contact support with the reference below.
                    @break
                @case(\App\Enums\PaymentStatus::Expired)
                    This deposit expired before the provider confirmed a payment. If you paid, it will still be credited as soon as the provider confirms it.
                    @break
                @default
                    Waiting for the payment provider to confirm this payment. This page updates by itself; your wallet is credited only after confirmation.
            @endswitch
        </div>

        <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-2">
            <div><dt class="text-ink-400">Reference</dt><dd class="mt-1 font-mono text-xs break-all text-white">{{ $payment->reference }}</dd></div>
            <div><dt class="text-ink-400">Started</dt><dd class="mt-1 text-white"><x-datetime :value="$payment->created_at" format="j M Y, g:i:s A" /></dd></div>
            @if ($payment->verified_at)
                <div><dt class="text-ink-400">Confirmed by provider</dt><dd class="mt-1 text-white"><x-datetime :value="$payment->verified_at" format="j M Y, g:i:s A" /></dd></div>
            @endif
            @if ($payment->ledgerTransaction)
                <div><dt class="text-ink-400">Wallet transaction</dt><dd class="mt-1"><a href="{{ route('account.transactions.show', $payment->ledgerTransaction->reference) }}" class="font-mono text-xs text-accent-300 hover:text-accent-200">{{ $payment->ledgerTransaction->reference }}</a></dd></div>
            @endif
        </dl>

        @if ($payment->status === \App\Enums\PaymentStatus::Pending && $payment->checkout && $payment->expires_at?->isFuture())
            <div class="mt-5">
                @if (($payment->checkout['method'] ?? null) === 'redirect')
                    <x-button :href="$payment->checkout['url']" variant="secondary">Continue to the payment page</x-button>
                @endif
            </div>
        @endif
    </x-card>

    <p class="text-sm"><a href="{{ route('account.deposits.index') }}" class="text-accent-300 hover:text-accent-200">← All deposits</a></p>
</x-account-layout>
