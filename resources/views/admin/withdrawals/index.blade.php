<x-admin.layout title="Withdrawals" :breadcrumbs="['Withdrawals' => null]">
    <x-alert variant="info" title="{{ $pendingCount }} awaiting review">
        Funds are held from each player's wallet when they request. Rejections and failed payouts return them automatically, once.
        Money is sent only through the configured payout provider{{ config('competition.withdrawals.gateway') === 'disabled' ? ' — none is configured, so nothing can be paid out yet' : '' }}.
    </x-alert>

    <x-admin.filters :action="route('admin.withdrawals.index')" :filters="$filters">
        <x-form.input name="q" label="Search" :value="$filters->get('q')" placeholder="Reference, provider reference, player email" />
        <x-form.select name="status" label="Status" placeholder="Open first" :selected="$filters->get('status')"
            :options="collect(\App\Enums\WithdrawalStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()" />
    </x-admin.filters>

    <x-admin.table caption="Withdrawals" :columns="['Reference', 'Requested', 'Player', 'Amount', 'To', 'Status']" :empty="$withdrawals->isEmpty()" empty-title="No withdrawals">
        @foreach ($withdrawals as $withdrawal)
            <tr>
                <td><a href="{{ route('admin.withdrawals.show', $withdrawal) }}" class="font-mono text-xs text-accent-300 hover:text-accent-200">{{ $withdrawal->reference }}</a></td>
                <td class="whitespace-nowrap text-ink-300"><x-datetime :value="$withdrawal->created_at" format="j M Y, g:i A" /></td>
                <td class="text-white">{{ $withdrawal->user?->display_name }}<p class="text-xs text-ink-400">{{ $withdrawal->user?->email }}</p></td>
                <td class="font-semibold whitespace-nowrap text-white">{{ \App\Support\Money::format($withdrawal->amount_minor, $withdrawal->currency) }}</td>
                <td class="whitespace-nowrap text-ink-300">{{ $withdrawal->destinationLabel() }}</td>
                <td>@include('account.withdrawals.status-badge', ['status' => $withdrawal->status])</td>
            </tr>
        @endforeach
    </x-admin.table>
    <x-pagination :paginator="$withdrawals" noun="withdrawals" />
</x-admin.layout>
