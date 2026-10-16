<x-admin.layout title="Wallets" :breadcrumbs="['Wallets' => null]">
    <x-alert variant="info" title="Balances come from the ledger">
        Balances cannot be edited. Each balance is a cache of its ledger entries; the "Ledger total" column is computed from the entries themselves and must always match.
    </x-alert>

    <x-admin.filters :action="route('admin.wallets.index')" :filters="$filters">
        <x-form.input name="q" label="Search" :value="$filters->get('q')" placeholder="Email, display name or system code" />
        <x-form.select name="type" label="Type" placeholder="Any type" :selected="$filters->get('type')"
            :options="['user' => 'Player', 'system' => 'Platform']" />
        <x-form.select name="status" label="Status" placeholder="Any status" :selected="$filters->get('status')"
            :options="collect(\App\Enums\WalletStatus::cases())->mapWithKeys(fn ($s) => [$s->value => ucfirst($s->value)])->all()" />
    </x-admin.filters>

    <x-admin.table caption="Wallets" :columns="['#', 'Owner', 'Balance', 'Ledger total', 'Entries', 'Status']" :empty="$wallets->isEmpty()" empty-title="No wallets found">
        @foreach ($wallets as $wallet)
            <tr>
                <td><a href="{{ route('admin.wallets.show', $wallet) }}" class="text-accent-300 hover:text-accent-200">{{ $wallet->id }}</a></td>
                <td class="text-white">{{ $wallet->ownerLabel() }}
                    @if ($wallet->user)<p class="text-xs text-ink-400">{{ $wallet->user->email }}</p>@endif
                </td>
                <td class="font-semibold whitespace-nowrap text-white">{{ \App\Support\Money::format($wallet->balance_minor, $wallet->currency) }}</td>
                <td class="whitespace-nowrap">
                    @if ((int) $wallet->ledger_sum_minor === $wallet->balance_minor)
                        <span class="text-ink-300">{{ \App\Support\Money::format((int) $wallet->ledger_sum_minor, $wallet->currency) }}</span>
                    @else
                        <x-badge variant="danger">Mismatch: {{ \App\Support\Money::format((int) $wallet->ledger_sum_minor, $wallet->currency) }}</x-badge>
                    @endif
                </td>
                <td class="text-ink-300">{{ $wallet->entries_count }}</td>
                <td><x-badge :variant="$wallet->status === \App\Enums\WalletStatus::Active ? 'neutral' : 'warning'">{{ ucfirst($wallet->status->value) }}</x-badge></td>
            </tr>
        @endforeach
    </x-admin.table>
    <x-pagination :paginator="$wallets" noun="wallets" />
</x-admin.layout>
