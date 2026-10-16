<x-account-layout title="Withdrawals">
    @if (! $enabled)
        <x-alert variant="info" title="Withdrawals are not available yet">
            Cashing out is not open on this platform yet. Your balance stays safely in your wallet.
        </x-alert>
    @else
        <x-card as="section" aria-labelledby="withdraw-heading">
            <h2 id="withdraw-heading" class="text-lg font-semibold text-white">Withdraw money</h2>
            <p class="mt-1 text-sm text-ink-400">
                Available: <span class="font-semibold text-white">{{ \App\Support\Money::format($balance) }}</span>.
                Between {{ \App\Support\Money::format($limits['min_minor']) }} and {{ \App\Support\Money::format($limits['max_minor']) }} per withdrawal,
                up to {{ \App\Support\Money::format($limits['daily_max_minor']) }} in 24 hours. Every withdrawal is reviewed before it is paid.
            </p>
            @if ($destinations->isEmpty())
                <x-empty-state title="Add a payout account first" :action="route('account.payout-destinations')" action-label="Add payout account" class="mt-4">
                    Withdrawals are paid only to your saved bank account or mobile wallet.
                </x-empty-state>
            @else
                <form method="POST" action="{{ route('account.withdrawals.review') }}" class="mt-4 grid gap-4 sm:grid-cols-[1fr_12rem_auto] sm:items-end" data-loading novalidate>
                    @csrf
                    <x-form.select name="destination_id" label="Pay to" required :selected="old('destination_id')"
                        :options="$destinations->mapWithKeys(fn ($d) => [$d->id => $d->maskedLabel().($d->isUsable() ? '' : ' (not usable yet)')])->all()" />
                    <x-form.input name="amount" label="Amount (Rs)" inputmode="decimal" required :value="old('amount')" />
                    <x-button type="submit" data-loading-text="Checking…">Review</x-button>
                </form>
                <p class="mt-2 text-xs text-ink-400"><a href="{{ route('account.payout-destinations') }}" class="text-accent-300 hover:text-accent-200">Manage payout accounts</a></p>
            @endif
        </x-card>
    @endif

    <section aria-labelledby="history-heading">
        <h2 id="history-heading" class="text-lg font-semibold text-white">Withdrawal history</h2>
        @if ($withdrawals->isEmpty())
            <x-empty-state title="No withdrawals yet" class="mt-3">Your withdrawal requests and their status appear here.</x-empty-state>
        @else
            <x-card class="mt-3 overflow-x-auto" padding="p-0">
                <table class="table-stack w-full text-left text-sm">
                    <caption class="sr-only">Withdrawals</caption>
                    <thead class="border-b border-ink-700 whitespace-nowrap text-ink-400">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">Requested</th>
                            <th scope="col" class="px-4 py-3 font-medium">Reference</th>
                            <th scope="col" class="px-4 py-3 font-medium">To</th>
                            <th scope="col" class="px-4 py-3 font-medium">Status</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-800">
                        @foreach ($withdrawals as $withdrawal)
                            <tr>
                                <td data-label="Requested" class="px-4 py-3 whitespace-nowrap text-ink-300"><x-datetime :value="$withdrawal->created_at" format="j M Y, g:i A" /></td>
                                <td data-label="Reference" data-primary class="px-4 py-3"><a href="{{ route('account.withdrawals.show', $withdrawal->reference) }}" class="font-mono text-xs text-accent-300 hover:text-accent-200">{{ $withdrawal->reference }}</a></td>
                                <td data-label="To" class="px-4 py-3 whitespace-nowrap text-ink-300">{{ $withdrawal->destinationLabel() }}</td>
                                <td data-label="Status" class="px-4 py-3">@include('account.withdrawals.status-badge', ['status' => $withdrawal->status])</td>
                                <td data-label="Amount" class="px-4 py-3 text-right font-semibold whitespace-nowrap text-white">{{ \App\Support\Money::format($withdrawal->amount_minor, $withdrawal->currency) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-card>
            <x-pagination :paginator="$withdrawals" noun="withdrawals" />
        @endif
    </section>
</x-account-layout>
