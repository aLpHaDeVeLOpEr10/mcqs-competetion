<x-admin.layout title="Wallet ledger" :breadcrumbs="['Ledger' => null]">
    <x-alert variant="info" title="Append-only ledger">
        Ledger records cannot be edited or deleted, by anyone. Corrections are recorded as new reversal or adjustment transactions.
    </x-alert>

    <x-admin.filters :action="route('admin.ledger.index')" :filters="$filters">
        <x-form.input name="q" label="Search" :value="$filters->get('q')" placeholder="Reference, idempotency key or description" />
        <x-form.select name="type" label="Type" placeholder="Any type" :selected="$filters->get('type')"
            :options="collect(\App\Enums\LedgerTransactionType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])->all()" />
    </x-admin.filters>

    <x-admin.table caption="Ledger transactions" :columns="['Reference', 'When', 'Type', 'Amount', 'Status', 'Entries', 'Key']" :empty="$transactions->isEmpty()" empty-title="No ledger transactions yet" empty-text="Entry fees, refunds, prizes and adjustments are recorded here once money movement is enabled.">
        @foreach ($transactions as $transaction)
            <tr>
                <td><a href="{{ route('admin.ledger.show', $transaction) }}" class="font-mono text-xs text-accent-300 hover:text-accent-200">{{ $transaction->reference }}</a></td>
                <td class="whitespace-nowrap text-ink-300"><x-datetime :value="$transaction->created_at" format="j M Y, g:i:s A" /></td>
                <td class="text-ink-100">{{ $transaction->type->label() }}</td>
                <td class="font-semibold whitespace-nowrap text-white">{{ \App\Support\Money::format((int) $transaction->credits_minor, $transaction->currency) }}</td>
                <td>{{ ucfirst($transaction->status()) }}</td>
                <td class="text-ink-300">{{ $transaction->entries_count }}</td>
                <td class="max-w-xs truncate font-mono text-xs text-ink-400">{{ $transaction->idempotency_key }}</td>
            </tr>
        @endforeach
    </x-admin.table>
    <x-pagination :paginator="$transactions" noun="transactions" />
</x-admin.layout>
