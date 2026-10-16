@php($money = fn (?int $minor) => $minor === null ? '—' : \App\Support\Money::format($minor, $distribution->currency))
<x-admin.layout :title="'Prizes: '.$room->name" :breadcrumbs="['Prize payouts' => route('admin.payouts.index'), $room->name => null]">
    <x-card>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-sm text-ink-400">Distribution</p>
                <p class="mt-1 text-lg font-semibold text-white">{{ ucfirst($distribution->status->value) }}
                    @if ($distribution->hold_reason)<span class="text-sm font-normal text-amber-300">— {{ $distribution->hold_reason }}</span>@endif
                </p>
                <p class="mt-1 text-sm text-ink-400">Results version {{ $distribution->calculation['result_version'] ?? '—' }} ·
                    <a href="{{ route('admin.results.show', $session) }}" class="text-accent-300 hover:text-accent-200">results</a></p>
            </div>
            <div class="text-sm">
                @if ($blocker)
                    <x-badge variant="warning">Blocked: {{ $blocker }}</x-badge>
                @else
                    <x-badge variant="accent">Payout conditions met</x-badge>
                @endif
            </div>
        </div>

        <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-4">
            <div><dt class="text-ink-400">Prize structure</dt><dd class="mt-1 text-white">{{ ucfirst($distribution->prize_mode) }}{{ ($distribution->calculation['rules']['pool_basis'] ?? null) ? ' · '.str_replace('_', ' ', $distribution->calculation['rules']['pool_basis']) : '' }}</dd></div>
            <div><dt class="text-ink-400">Gross entry fees</dt><dd class="mt-1 text-white">{{ $money($distribution->gross_entry_fees_minor) }}</dd></div>
            <div><dt class="text-ink-400">Refunds</dt><dd class="mt-1 text-white">{{ $money($distribution->refunds_minor) }}</dd></div>
            <div><dt class="text-ink-400">Net entry fees</dt><dd class="mt-1 text-white">{{ $money($distribution->net_entry_fees_minor) }}</dd></div>
            <div><dt class="text-ink-400">Platform amount</dt><dd class="mt-1 text-white">{{ $money($distribution->platform_amount_minor) }}</dd></div>
            <div><dt class="text-ink-400">Prize pool</dt><dd class="mt-1 font-semibold text-white">{{ $money($distribution->prize_pool_minor) }}</dd></div>
            <div><dt class="text-ink-400">Awarded</dt><dd class="mt-1 text-white">{{ $money($distribution->total_amount_minor) }}</dd></div>
            <div><dt class="text-ink-400">Unallocated (rounding, ties, unfilled)</dt><dd class="mt-1 text-white">{{ $money($distribution->unallocated_minor) }}</dd></div>
        </dl>
        <p class="mt-4 text-xs text-ink-400">{{ $distribution->calculation['rounding'] ?? '' }}
            Tax withholding rate: {{ number_format(config('competition.prizes.tax_withholding_bp') / 100, 2) }}%.
            @if ($distribution->approved_at) Approved <x-datetime :value="$distribution->approved_at" />. @endif
        </p>
    </x-card>

    <x-admin.table caption="Payouts" :columns="['Winner', 'Rank', 'Score / time', 'Rule', 'Prize', 'Tax', 'Credited', 'State', 'Ledger', 'Times']" :empty="$distribution->payouts->isEmpty()" empty-title="No prize winners">
        @foreach ($distribution->payouts as $payout)
            <tr>
                <td class="text-white">{{ $payout->participant->user?->display_name }}<p class="text-xs text-ink-400">{{ $payout->participant->user?->email }}</p></td>
                <td class="text-ink-300">#{{ $payout->rank }}</td>
                <td class="whitespace-nowrap text-ink-300">{{ $payout->correct_count }} correct<p class="text-xs text-ink-400">{{ number_format(($payout->timing_ms ?? 0) / 1000, 2) }} s</p></td>
                <td class="text-xs text-ink-400">
                    @if ($payout->rule)
                        Places {{ $payout->rule['positions'][0] }}–{{ $payout->rule['positions'][1] }}@if ($payout->rule['tied_players'] > 1), shared by {{ $payout->rule['tied_players'] }} tied players @endif
                        <br>Pool {{ $money($payout->rule['group_pool_minor']) }}, remainder {{ $money($payout->rule['group_remainder_minor']) }}
                    @endif
                </td>
                <td class="font-semibold whitespace-nowrap text-white">{{ $money($payout->amount_minor) }}</td>
                <td class="whitespace-nowrap text-ink-300">{{ $money($payout->tax_withheld_minor) }}</td>
                <td class="whitespace-nowrap text-ink-300">{{ $money($payout->net_amount_minor) }}</td>
                <td>@include('admin.payouts.status-badge', ['status' => $payout->status])
                    @if ($payout->status_reason)<p class="mt-1 max-w-xs text-xs text-ink-400">{{ $payout->status_reason }}</p>@endif
                </td>
                <td class="font-mono text-xs">
                    @if ($payout->ledgerTransaction)<a href="{{ route('admin.ledger.show', $payout->ledger_transaction_id) }}" class="text-accent-300 hover:text-accent-200">{{ $payout->ledgerTransaction->reference }}</a>@else — @endif
                    @if ($payout->reversalLedgerTransaction)<p>reversed: <a href="{{ route('admin.ledger.show', $payout->reversal_ledger_transaction_id) }}" class="text-accent-300 hover:text-accent-200">{{ $payout->reversalLedgerTransaction->reference }}</a></p>@endif
                </td>
                <td class="text-xs whitespace-nowrap text-ink-400">
                    Created <x-datetime :value="$payout->created_at" format="j M, g:i A" />
                    @if ($payout->approved_at)<br>Approved <x-datetime :value="$payout->approved_at" format="j M, g:i A" />@endif
                    @if ($payout->paid_at)<br>Paid <x-datetime :value="$payout->paid_at" format="j M, g:i A" />@endif
                    @if ($payout->reversed_at)<br>Reversed <x-datetime :value="$payout->reversed_at" format="j M, g:i A" />@endif
                </td>
            </tr>
        @endforeach
    </x-admin.table>

    @can('prizes.manage')
        <section aria-labelledby="payout-actions-heading" class="space-y-3">
            <h2 id="payout-actions-heading" class="text-lg font-semibold text-white">Actions</h2>
            <p class="text-sm text-ink-400">Every action needs a reason and is recorded in the audit log. Amounts can never be edited.</p>

            @if (($distribution->calculation['rules']['review_required'] ?? false) && ! $distribution->approved_at)
                <x-admin.confirm-action title="Approve these prizes (finance review)" :action="route('admin.payouts.approve', $session)" bag="approve" submit-label="Approve and pay" />
            @endif

            <form method="POST" action="{{ route('admin.payouts.distribute', $session) }}">
                @csrf
                <x-button type="submit" variant="secondary" size="sm">Check conditions and pay outstanding prizes</x-button>
            </form>

            @foreach ($distribution->payouts as $payout)
                @php($bag = 'payout-'.$payout->id)
                @php($name = ($payout->participant->user?->display_name ?? 'player').' (#'.$payout->rank.')')
                @if (in_array($payout->status, [\App\Enums\PayoutStatus::Pending, \App\Enums\PayoutStatus::Approved, \App\Enums\PayoutStatus::Failed], true))
                    <x-admin.confirm-action :title="'Hold the prize of '.$name" :action="route('admin.payouts.hold', $payout)" :bag="$bag" submit-label="Put on hold" />
                @endif
                @if ($payout->status === \App\Enums\PayoutStatus::Held)
                    <x-admin.confirm-action :title="'Release the prize of '.$name" :action="route('admin.payouts.release', $payout)" :bag="$bag" submit-label="Release and pay" />
                @endif
                @if ($payout->status === \App\Enums\PayoutStatus::Failed)
                    <x-admin.confirm-action :title="'Retry the prize of '.$name" :action="route('admin.payouts.retry', $payout)" :bag="$bag" submit-label="Retry" />
                @endif
                @if ($payout->status === \App\Enums\PayoutStatus::Paid)
                    <x-admin.confirm-action :title="'Reverse the prize of '.$name" :action="route('admin.payouts.reverse', $payout)" :bag="$bag" submit-label="Reverse prize" danger />
                @endif
            @endforeach
        </section>
    @endcan
</x-admin.layout>
