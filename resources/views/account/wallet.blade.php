<x-account-layout title="Wallet">
    @if (! $wallet)
        <x-empty-state title="No wallet yet">Your wallet is created with your account. Please contact support if it is missing.</x-empty-state>
    @else
        <x-card>
            <p class="text-sm text-ink-400">Available balance</p>
            <p class="mt-1 text-3xl font-bold text-white">{{ \App\Support\Money::format($wallet->balance_minor, $wallet->currency) }}</p>
            <p class="mt-2 text-sm text-ink-400">
                Wallet status: {{ ucfirst($wallet->status->value) }}
                @if ($wallet->status === \App\Enums\WalletStatus::Frozen)
                    · Payments from this wallet are paused. Refunds and prizes can still be received.
                @endif
            </p>
            <div class="mt-4 flex flex-wrap gap-3">
                {{-- Buttons only link where the operation is actually available. --}}
                @if ($depositsAvailable)
                    <x-button :href="route('account.deposits.index')" variant="secondary">Add money</x-button>
                @else
                    <x-button type="button" variant="secondary" disabled aria-describedby="wallet-unavailable">Add money</x-button>
                @endif
                @if ($withdrawalsAvailable)
                    <x-button :href="route('account.withdrawals.index')" variant="secondary">Withdraw</x-button>
                @else
                    <x-button type="button" variant="secondary" disabled aria-describedby="wallet-unavailable">Withdraw</x-button>
                @endif
            </div>
            @if (! $depositsAvailable || ! $withdrawalsAvailable)
                <p id="wallet-unavailable" class="mt-2 text-xs text-ink-400">
                    {{ ! $depositsAvailable && ! $withdrawalsAvailable ? 'Deposits and withdrawals are not available yet. They will open once a payment provider is connected.' : ($depositsAvailable ? 'Withdrawals are not available yet.' : 'Deposits are not available yet.') }}
                </p>
            @endif
        </x-card>

        @if ($breakdown)
            <section aria-labelledby="breakdown-heading">
                <h2 id="breakdown-heading" class="text-lg font-semibold text-white">Money in and out</h2>
                <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    @foreach (['deposits' => ['Deposits', 'deposit'], 'entry_fees' => ['Entry fees', 'entry_fee'], 'refunds' => ['Refunds', 'entry_refund'], 'prizes' => ['Prize credits', 'prize_payout'], 'withdrawals' => ['Withdrawals', 'withdrawal']] as $key => [$label, $type])
                        <x-card padding="p-4">
                            <dt class="text-xs text-ink-400">{{ $label }}</dt>
                            <dd @class(['mt-1 font-semibold', 'text-emerald-300' => $breakdown[$key] > 0, 'text-white' => $breakdown[$key] <= 0])>{{ \App\Support\Money::format($breakdown[$key], $wallet->currency) }}</dd>
                            <dd><a href="{{ route('account.transactions', ['type' => $type]) }}" class="text-xs text-accent-300 hover:text-accent-200">History</a></dd>
                        </x-card>
                    @endforeach
                </dl>
            </section>
        @endif

        <section aria-labelledby="withdrawals-heading">
            <h2 id="withdrawals-heading" class="text-lg font-semibold text-white">Withdrawals</h2>
            @if ($withdrawals->isEmpty())
                <x-empty-state :title="$withdrawalsAvailable ? 'No withdrawals yet' : 'Withdrawals are not available yet'" class="mt-3">
                    {{ $withdrawalsAvailable ? 'Withdrawals you request appear here with their status.' : 'Cashing out to a bank or mobile account is not open yet. Your withdrawal history will appear here.' }}
                </x-empty-state>
            @else
                <x-card class="mt-3" padding="p-0">
                    <ul class="divide-y divide-ink-800">
                        @foreach ($withdrawals as $withdrawal)
                            <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm">
                                <a href="{{ route('account.withdrawals.show', $withdrawal->reference) }}" class="font-mono text-xs text-accent-300 hover:text-accent-200">{{ $withdrawal->reference }}</a>
                                <span class="text-white">{{ \App\Support\Money::format($withdrawal->amount_minor, $withdrawal->currency) }}</span>
                                @include('account.withdrawals.status-badge', ['status' => $withdrawal->status])
                            </li>
                        @endforeach
                    </ul>
                </x-card>
            @endif
        </section>

        <section aria-labelledby="recent-tx-heading">
            <div class="flex items-center justify-between">
                <h2 id="recent-tx-heading" class="text-lg font-semibold text-white">Recent transactions</h2>
                <a href="{{ route('account.transactions') }}" class="text-sm text-accent-300 hover:text-accent-200">View all</a>
            </div>
            @if ($recent->isEmpty())
                <x-empty-state title="No transactions yet" class="mt-4">Entry fees, refunds and prizes will appear here.</x-empty-state>
            @else
                @include('account.partials.ledger-table', ['entries' => $recent])
            @endif
        </section>
        <p class="text-xs text-ink-400">Every movement is a permanent ledger record. Corrections appear as new transactions; past records are never changed.</p>
    @endif
</x-account-layout>
