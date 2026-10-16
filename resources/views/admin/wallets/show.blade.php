<x-admin.layout :title="'Wallet #'.$wallet->id" :breadcrumbs="['Wallets' => route('admin.wallets.index'), '#'.$wallet->id => null]">
    <x-card>
        <dl class="grid gap-4 text-sm sm:grid-cols-4">
            <div><dt class="text-ink-400">Owner</dt><dd class="mt-1 text-white">
                @if ($wallet->user)
                    @can('users.view')<a href="{{ route('admin.users.show', $wallet->user) }}" class="text-accent-300 hover:text-accent-200">{{ $wallet->ownerLabel() }}</a>@else{{ $wallet->ownerLabel() }}@endcan
                    <p class="text-xs text-ink-400">Account: {{ $wallet->user->status->label() }}</p>
                @else
                    {{ $wallet->ownerLabel() }}
                @endif
            </dd></div>
            <div><dt class="text-ink-400">Balance</dt><dd class="mt-1 text-2xl font-bold text-white">{{ \App\Support\Money::format($wallet->balance_minor, $wallet->currency) }}</dd></div>
            <div><dt class="text-ink-400">Status</dt><dd class="mt-1 text-white">{{ ucfirst($wallet->status->value) }}</dd></div>
            <div><dt class="text-ink-400">Reconciliation</dt><dd class="mt-1">
                @if ($issues === [])
                    <x-badge variant="accent">Matches the ledger</x-badge>
                @else
                    <x-badge variant="danger">{{ count($issues) }} discrepancy(ies)</x-badge>
                @endif
            </dd></div>
        </dl>
        @if ($issues !== [])
            <ul class="mt-4 space-y-1 text-sm text-rose-300">
                @foreach ($issues as $issue)
                    <li>{{ $issue['check'] }} on {{ $issue['subject'] }}: expected {{ $issue['expected'] }}, found {{ $issue['actual'] }}</li>
                @endforeach
            </ul>
        @endif
    </x-card>

    <x-admin.table caption="Ledger entries for this wallet" :columns="['When', 'Reference', 'Type', 'Amount', 'Balance after', 'Status']" :empty="$entries->isEmpty()" empty-title="No ledger entries">
        @foreach ($entries as $entry)
            <tr>
                <td class="whitespace-nowrap text-ink-300"><x-datetime :value="$entry->created_at" format="j M Y, g:i:s A" /></td>
                <td><a href="{{ route('admin.ledger.show', $entry->ledger_transaction_id) }}" class="font-mono text-xs text-accent-300 hover:text-accent-200">{{ $entry->transaction->reference }}</a></td>
                <td class="text-ink-100">{{ $entry->transaction->type->label() }}<p class="max-w-xs truncate text-xs text-ink-400">{{ $entry->transaction->description }}</p></td>
                <td @class(['font-semibold whitespace-nowrap', 'text-emerald-300' => $entry->amount_minor > 0, 'text-rose-300' => $entry->amount_minor < 0])>{{ \App\Support\Money::format($entry->amount_minor, $wallet->currency) }}</td>
                <td class="whitespace-nowrap text-ink-300">{{ \App\Support\Money::format($entry->balance_after_minor, $wallet->currency) }}</td>
                <td>{{ ucfirst($entry->transaction->status()) }}</td>
            </tr>
        @endforeach
    </x-admin.table>
    <x-pagination :paginator="$entries" noun="entries" />

    @can('adjust', $wallet)
        <section aria-labelledby="adjust-heading" class="space-y-3">
            <h2 id="adjust-heading" class="text-lg font-semibold text-white">Adjustment</h2>
            @if (! $adjustmentsEnabled)
                <x-alert variant="info">Wallet adjustments are disabled on this platform (<code>WALLET_ADJUSTMENTS_ENABLED=false</code>).</x-alert>
            @else
                <p class="text-sm text-ink-400">Posts a new, permanent ledger transaction against the platform adjustments account. It cannot be edited or deleted; a mistake is corrected with another adjustment. Debits never take the balance below zero.</p>
                <x-admin.confirm-action title="Post an adjustment" :action="route('admin.wallets.adjust', $wallet)" bag="adjust" submit-label="Post adjustment" danger>
                    <input type="hidden" name="token" value="{{ $token }}">
                    <x-form.select name="direction" id="adjust-direction" label="Direction" required bag="adjust" :selected="old('direction')"
                        :options="['credit' => 'Credit (add to the player\'s balance)', 'debit' => 'Debit (take from the player\'s balance)']" />
                    <x-form.input name="amount" id="adjust-amount" label="Amount in rupees" inputmode="decimal" required bag="adjust" :value="old('amount')" placeholder="e.g. 250.00" />
                </x-admin.confirm-action>
            @endif
        </section>
    @endcan
</x-admin.layout>
