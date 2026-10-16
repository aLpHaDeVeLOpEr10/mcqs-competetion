<x-account-layout title="Withdrawal">
    <x-card>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-sm text-ink-400">Withdrawal to {{ $withdrawal->destinationLabel() }}</p>
                <p class="mt-1 text-3xl font-bold text-white">{{ \App\Support\Money::format($withdrawal->amount_minor, $withdrawal->currency) }}</p>
            </div>
            @include('account.withdrawals.status-badge', ['status' => $withdrawal->status])
        </div>
        <p class="mt-4 text-sm text-ink-300" role="status">
            @switch($withdrawal->status)
                @case(\App\Enums\WithdrawalStatus::Paid) This withdrawal was paid out. @break
                @case(\App\Enums\WithdrawalStatus::Rejected) Not approved: {{ $withdrawal->rejection_reason }}. The full amount is back in your wallet. @break
                @case(\App\Enums\WithdrawalStatus::Failed) The payout could not be completed. The full amount is back in your wallet. @break
                @case(\App\Enums\WithdrawalStatus::Cancelled) You cancelled this withdrawal. The full amount is back in your wallet. @break
                @case(\App\Enums\WithdrawalStatus::Reversed) The payout provider returned this money. The full amount is back in your wallet. @break
                @default The money is held in your wallet while this withdrawal is reviewed and paid.
            @endswitch
        </p>
        <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-2">
            <div><dt class="text-ink-400">Reference</dt><dd class="mt-1 font-mono text-xs break-all text-white">{{ $withdrawal->reference }}</dd></div>
            <div><dt class="text-ink-400">Requested</dt><dd class="mt-1 text-white"><x-datetime :value="$withdrawal->created_at" format="j M Y, g:i A" /></dd></div>
            <div><dt class="text-ink-400">Fee</dt><dd class="mt-1 text-white">{{ $withdrawal->fee_minor > 0 ? \App\Support\Money::format($withdrawal->fee_minor, $withdrawal->currency) : 'No fee' }}</dd></div>
            <div><dt class="text-ink-400">Account holder</dt><dd class="mt-1 text-white">{{ $withdrawal->destination_snapshot['account_title'] ?? '—' }}</dd></div>
            @if ($withdrawal->holdTransaction)
                <div><dt class="text-ink-400">Held</dt><dd class="mt-1"><a href="{{ route('account.transactions.show', $withdrawal->holdTransaction->reference) }}" class="font-mono text-xs text-accent-300 hover:text-accent-200">{{ $withdrawal->holdTransaction->reference }}</a></dd></div>
            @endif
            @if ($withdrawal->releaseTransaction)
                <div><dt class="text-ink-400">Returned</dt><dd class="mt-1"><a href="{{ route('account.transactions.show', $withdrawal->releaseTransaction->reference) }}" class="font-mono text-xs text-accent-300 hover:text-accent-200">{{ $withdrawal->releaseTransaction->reference }}</a></dd></div>
            @endif
        </dl>

        @if ($withdrawal->status === \App\Enums\WithdrawalStatus::Requested)
            <form method="POST" action="{{ route('account.withdrawals.cancel', $withdrawal->reference) }}" class="mt-5" data-confirm="Cancel this withdrawal? The money returns to your wallet.">
                @csrf
                <x-button type="submit" variant="secondary" size="sm">Cancel withdrawal</x-button>
            </form>
        @endif
    </x-card>
    <p class="text-sm"><a href="{{ route('account.withdrawals.index') }}" class="text-accent-300 hover:text-accent-200">← All withdrawals</a></p>
</x-account-layout>
