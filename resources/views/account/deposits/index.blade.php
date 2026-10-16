<x-account-layout title="Deposits">
    @if (! $available)
        <x-alert variant="info" title="Deposits are not available">
            Adding money is not available right now. Nothing on this page takes payments until a payment provider is connected and enabled.
        </x-alert>
    @else
        <x-card as="section" aria-labelledby="deposit-heading">
            <h2 id="deposit-heading" class="text-lg font-semibold text-white">Add money</h2>
            <p class="mt-1 text-sm text-ink-400">
                Between {{ \App\Support\Money::format($limits['min_minor']) }} and {{ \App\Support\Money::format($limits['max_minor']) }} per deposit,
                up to {{ \App\Support\Money::format($limits['daily_max_minor']) }} in 24 hours. You will be sent to the payment provider to pay;
                your wallet is credited only after the provider confirms the payment.
            </p>
            <form method="POST" action="{{ route('account.deposits.store') }}" class="mt-4 flex flex-wrap items-end gap-3" data-loading novalidate>
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <div class="w-48">
                    <x-form.input name="amount" label="Amount in rupees" inputmode="decimal" required :value="old('amount')" placeholder="e.g. 500" />
                </div>
                <x-button type="submit" data-loading-text="Starting…">Continue to payment</x-button>
            </form>
        </x-card>
    @endif

    <section aria-labelledby="deposit-history-heading">
        <h2 id="deposit-history-heading" class="text-lg font-semibold text-white">Deposit history</h2>
        @if ($deposits->isEmpty())
            <x-empty-state title="No deposits yet" class="mt-4">Your deposits and their status will appear here.</x-empty-state>
        @else
            <x-card class="mt-4 overflow-x-auto" padding="p-0">
                <table class="table-stack w-full text-left text-sm">
                    <caption class="sr-only">Deposits</caption>
                    <thead class="border-b border-ink-700 text-ink-400">
                        <tr>
                            <th scope="col" class="px-5 py-3 font-medium">Date</th>
                            <th scope="col" class="px-5 py-3 font-medium">Reference</th>
                            <th scope="col" class="px-5 py-3 font-medium">Status</th>
                            <th scope="col" class="px-5 py-3 text-right font-medium">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-800">
                        @foreach ($deposits as $deposit)
                            <tr>
                                <td data-label="Date" class="px-5 py-3 whitespace-nowrap text-ink-300"><x-datetime :value="$deposit->created_at" format="j M Y, g:i A" /></td>
                                <td data-label="Reference" data-primary class="px-5 py-3"><a href="{{ route('account.deposits.show', $deposit->reference) }}" class="font-mono text-xs text-accent-300 hover:text-accent-200">{{ $deposit->reference }}</a></td>
                                <td data-label="Status" class="px-5 py-3">@include('account.deposits.status-badge', ['status' => $deposit->status])</td>
                                <td data-label="Amount" class="px-5 py-3 text-right font-semibold whitespace-nowrap text-white">{{ \App\Support\Money::format($deposit->amount_minor, $deposit->currency) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-card>
            <x-pagination :paginator="$deposits" noun="deposits" />
        @endif
    </section>
</x-account-layout>
