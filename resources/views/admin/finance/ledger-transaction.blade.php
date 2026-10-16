<x-admin.layout :title="'Ledger transaction '.$transaction->reference" :breadcrumbs="['Ledger' => route('admin.ledger.index'), $transaction->reference => null]">
    <x-card>
        <dl class="grid gap-4 text-sm sm:grid-cols-2">
            <div><dt class="text-ink-400">Reference</dt><dd class="mt-1 font-mono text-xs break-all text-white">{{ $transaction->reference }}</dd></div>
            <div><dt class="text-ink-400">Type</dt><dd class="mt-1 text-white">{{ $transaction->type->label() }}</dd></div>
            <div><dt class="text-ink-400">Status</dt><dd class="mt-1 text-white">{{ ucfirst($transaction->status()) }}</dd></div>
            <div><dt class="text-ink-400">Recorded</dt><dd class="mt-1 text-white"><x-datetime :value="$transaction->created_at" format="j M Y, g:i:s A" /></dd></div>
            <div><dt class="text-ink-400">Currency</dt><dd class="mt-1 text-white">{{ $transaction->currency }}</dd></div>
            <div><dt class="text-ink-400">Created by</dt><dd class="mt-1 text-white">{{ $transaction->creator?->name ?? 'System' }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-ink-400">Idempotency key</dt><dd class="mt-1 font-mono text-xs break-all text-white">{{ $transaction->idempotency_key }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-ink-400">Description</dt><dd class="mt-1 text-white">{{ $transaction->description ?? '—' }}</dd></div>
            @if ($related)
                <div class="sm:col-span-2"><dt class="text-ink-400">Related to</dt><dd class="mt-1 text-white">
                    @if ($related['url'])<a class="text-accent-300 hover:text-accent-200" href="{{ $related['url'] }}">{{ $related['label'] }}</a>@else{{ $related['label'] }}@endif
                </dd></div>
            @endif
            @if ($transaction->reversedTransaction)
                <div><dt class="text-ink-400">Reverses</dt><dd class="mt-1"><a class="font-mono text-xs text-accent-300" href="{{ route('admin.ledger.show', $transaction->reversedTransaction) }}">{{ $transaction->reversedTransaction->reference }}</a></dd></div>
            @endif
            @if ($transaction->reversal)
                <div><dt class="text-ink-400">Reversed by</dt><dd class="mt-1"><a class="font-mono text-xs text-accent-300" href="{{ route('admin.ledger.show', $transaction->reversal) }}">{{ $transaction->reversal->reference }}</a></dd></div>
            @endif
            @if ($transaction->metadata)
                <div class="sm:col-span-2"><dt class="text-ink-400">Metadata (no secrets are ever stored)</dt>
                    <dd class="mt-1 overflow-x-auto rounded-lg bg-ink-900 p-3 font-mono text-xs text-ink-200">{{ json_encode($transaction->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</dd></div>
            @endif
        </dl>
    </x-card>

    <x-admin.table caption="Entries" :columns="['Wallet', 'Direction', 'Amount', 'Balance after']" :empty="$transaction->entries->isEmpty()" empty-title="No entries">
        @foreach ($transaction->entries as $entry)
            <tr>
                <td><a href="{{ route('admin.wallets.show', $entry->wallet) }}" class="text-accent-300 hover:text-accent-200">{{ $entry->wallet->ownerLabel() }}</a></td>
                <td class="text-ink-300">{{ ucfirst($entry->direction()) }}</td>
                <td @class(['font-semibold whitespace-nowrap', 'text-emerald-300' => $entry->amount_minor > 0, 'text-rose-300' => $entry->amount_minor < 0])>{{ \App\Support\Money::format($entry->amount_minor, $transaction->currency) }}</td>
                <td class="whitespace-nowrap text-ink-300">{{ \App\Support\Money::format($entry->balance_after_minor, $transaction->currency) }}</td>
            </tr>
        @endforeach
    </x-admin.table>
    <p class="text-sm text-ink-400">Net of all entries: {{ \App\Support\Money::format((int) $transaction->entries->sum('amount_minor'), $transaction->currency) }} (a balanced transaction nets to zero).</p>
</x-admin.layout>
