{{-- A player's own ledger entries. Never shows idempotency keys, metadata or other wallets. --}}
<x-card class="mt-4 overflow-x-auto" padding="p-0">
    <table class="table-stack w-full text-left text-sm">
        <caption class="sr-only">Wallet transactions</caption>
        <thead class="border-b border-ink-700 text-ink-400">
            <tr>
                <th scope="col" class="px-5 py-3 font-medium">Date</th>
                <th scope="col" class="px-5 py-3 font-medium">Type</th>
                <th scope="col" class="px-5 py-3 font-medium">Reference</th>
                <th scope="col" class="px-5 py-3 font-medium">Status</th>
                <th scope="col" class="px-5 py-3 text-right font-medium">Amount</th>
                <th scope="col" class="px-5 py-3 text-right font-medium">Balance</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-ink-800">
            @foreach ($entries as $entry)
                <tr>
                    <td data-label="Date" class="px-5 py-3 whitespace-nowrap text-ink-300"><x-datetime :value="$entry->created_at" format="j M Y, g:i A" /></td>
                    <td data-label="Type" data-primary class="px-5 py-3 text-ink-100">{{ $entry->transaction->type->label() }}</td>
                    <td data-label="Reference" class="px-5 py-3"><a href="{{ route('account.transactions.show', $entry->transaction->reference) }}" class="font-mono text-xs text-accent-300 hover:text-accent-200">{{ $entry->transaction->reference }}</a></td>
                    <td data-label="Status" class="px-5 py-3">
                        @if ($entry->transaction->status() === 'reversed')
                            <x-badge variant="warning">Reversed</x-badge>
                        @else
                            <x-badge variant="accent">Completed</x-badge>
                        @endif
                    </td>
                    <td data-label="Amount" class="px-5 py-3 text-right font-semibold whitespace-nowrap {{ $entry->amount_minor > 0 ? 'text-emerald-300' : 'text-ink-100' }}">
                        {{ $entry->amount_minor > 0 ? '+' : '' }}{{ \App\Support\Money::format($entry->amount_minor) }}
                    </td>
                    <td data-label="Balance" class="px-5 py-3 text-right whitespace-nowrap text-ink-300">{{ \App\Support\Money::format($entry->balance_after_minor) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</x-card>
