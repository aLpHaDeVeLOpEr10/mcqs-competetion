<x-admin.layout title="Prize payouts" :breadcrumbs="['Prize payouts' => null]">
    <x-alert variant="info" title="Internal wallet credits">
        Prizes are credited to winners' wallets from the prize pool through the ledger. Paid payouts cannot be edited; a mistake is corrected by an audited reversal.
        @unless (config('competition.prizes.payouts_enabled'))
            <strong class="text-white">Payouts are currently disabled (PRIZE_PAYOUTS_ENABLED=false), so every entitlement is on hold.</strong>
        @endunless
    </x-alert>

    <x-admin.filters :action="route('admin.payouts.index')" :filters="$filters">
        <x-form.input name="q" label="Search" :value="$filters->get('q')" placeholder="Competition, player email or ledger reference" />
        <x-form.select name="status" label="State" placeholder="Any state" :selected="$filters->get('status')"
            :options="collect(\App\Enums\PayoutStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()" />
    </x-admin.filters>

    <x-admin.table caption="Prize payouts" :columns="['Competition', 'Winner', 'Rank', 'Prize', 'Credited', 'State', 'Ledger', 'Updated']" :empty="$payouts->isEmpty()" empty-title="No prize payouts yet" empty-text="Prize entitlements appear here when a competition with prizes is finalized.">
        @foreach ($payouts as $payout)
            @php($room = $payout->participant->room)
            <tr>
                <td><a href="{{ route('admin.payouts.show', $payout->distribution->competition_session_id) }}" class="text-accent-300 hover:text-accent-200">{{ $room->name }}</a></td>
                <td class="text-white">{{ $payout->participant->user?->display_name }}<p class="text-xs text-ink-400">{{ $payout->participant->user?->email }}</p></td>
                <td class="text-ink-300">#{{ $payout->rank }}</td>
                <td class="font-semibold whitespace-nowrap text-white">{{ \App\Support\Money::format($payout->amount_minor, $room->currency) }}</td>
                <td class="whitespace-nowrap text-ink-300">{{ $payout->net_amount_minor !== null ? \App\Support\Money::format($payout->net_amount_minor, $room->currency) : '—' }}</td>
                <td>@include('admin.payouts.status-badge', ['status' => $payout->status])
                    @if ($payout->status_reason)<p class="mt-1 max-w-xs text-xs text-ink-400">{{ $payout->status_reason }}</p>@endif
                </td>
                <td>@if ($payout->ledgerTransaction)<a href="{{ route('admin.ledger.show', $payout->ledger_transaction_id) }}" class="font-mono text-xs text-accent-300 hover:text-accent-200">{{ $payout->ledgerTransaction->reference }}</a>@else — @endif</td>
                <td class="whitespace-nowrap text-ink-400"><x-datetime :value="$payout->updated_at" format="j M Y, g:i A" /></td>
            </tr>
        @endforeach
    </x-admin.table>
    <x-pagination :paginator="$payouts" noun="payouts" />
</x-admin.layout>
