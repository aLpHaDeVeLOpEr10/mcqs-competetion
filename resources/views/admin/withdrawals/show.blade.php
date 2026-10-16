@php($money = fn (int $minor) => \App\Support\Money::format($minor, $withdrawal->currency))
@php($status = $withdrawal->status)
@php($own = $withdrawal->user_id === auth()->id())
<x-admin.layout :title="'Withdrawal '.$withdrawal->reference" :breadcrumbs="['Withdrawals' => route('admin.withdrawals.index'), $withdrawal->reference => null]">
    <x-card>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-sm text-ink-400">{{ $user->display_name }} · {{ $user->email }} · account {{ strtolower($user->status->label()) }}</p>
                <p class="mt-1 text-3xl font-bold text-white">{{ $money($withdrawal->amount_minor) }}</p>
                <p class="text-sm text-ink-400">Fee {{ $money($withdrawal->fee_minor) }} · held {{ $money($withdrawal->totalMinor()) }}</p>
            </div>
            @include('account.withdrawals.status-badge', ['status' => $status])
        </div>
        <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-3">
            <div><dt class="text-ink-400">Destination (masked)</dt><dd class="mt-1 text-white">{{ $withdrawal->destinationLabel() }}<span class="block text-xs text-ink-400">{{ $withdrawal->destination_snapshot['account_title'] ?? '' }}</span></dd></div>
            <div><dt class="text-ink-400">Wallet balance now</dt><dd class="mt-1 text-white">{{ $wallet ? $money($wallet->balance_minor) : '—' }}</dd></div>
            <div><dt class="text-ink-400">Requested</dt><dd class="mt-1 text-white"><x-datetime :value="$withdrawal->created_at" format="j M Y, g:i A" /></dd></div>
            <div><dt class="text-ink-400">Hold / release</dt><dd class="mt-1 font-mono text-xs">
                @if ($withdrawal->holdTransaction)<a href="{{ route('admin.ledger.show', $withdrawal->hold_ledger_transaction_id) }}" class="text-accent-300">{{ $withdrawal->holdTransaction->reference }}</a>@endif
                @if ($withdrawal->releaseTransaction)<br><a href="{{ route('admin.ledger.show', $withdrawal->release_ledger_transaction_id) }}" class="text-accent-300">{{ $withdrawal->releaseTransaction->reference }}</a> (released)@endif
            </dd></div>
            <div><dt class="text-ink-400">Provider</dt><dd class="mt-1 text-white">{{ $withdrawal->gateway ?? '—' }}<span class="block font-mono text-xs text-ink-400">{{ $withdrawal->provider_reference ?? '' }} {{ $withdrawal->provider_status ? '('.$withdrawal->provider_status.')' : '' }}</span></dd></div>
            <div><dt class="text-ink-400">Reviewer / approver</dt><dd class="mt-1 text-white">{{ $withdrawal->reviewer?->name ?? '—' }} / {{ $withdrawal->approver?->name ?? '—' }}</dd></div>
            @if ($withdrawal->rejection_reason || $withdrawal->failure_reason)
                <div class="sm:col-span-3"><dt class="text-ink-400">Reason</dt><dd class="mt-1 text-white">{{ $withdrawal->rejection_reason ?? $withdrawal->failure_reason }}</dd></div>
            @endif
        </dl>
    </x-card>

    @if ($own)
        <x-alert variant="warning">This is your own withdrawal. Another finance team member must review it.</x-alert>
    @else
        <section aria-labelledby="actions-heading" class="space-y-3">
            <h2 id="actions-heading" class="text-lg font-semibold text-white">Actions</h2>
            @if ($status === \App\Enums\WithdrawalStatus::Requested)
                <form method="POST" action="{{ route('admin.withdrawals.review', $withdrawal) }}">@csrf<x-button type="submit" variant="secondary" size="sm">Start review</x-button></form>
            @endif
            @can('withdrawals.approve')
                @if ($status === \App\Enums\WithdrawalStatus::UnderReview)
                    <x-admin.confirm-action title="Approve" :action="route('admin.withdrawals.approve', $withdrawal)" bag="approve" submit-label="Approve withdrawal" />
                @endif
                @if ($status->canTransitionTo(\App\Enums\WithdrawalStatus::Rejected))
                    <x-admin.confirm-action title="Reject (funds return to the player)" :action="route('admin.withdrawals.reject', $withdrawal)" bag="reject" submit-label="Reject withdrawal" danger />
                @endif
                @if ($status === \App\Enums\WithdrawalStatus::Approved)
                    @if ($payoutsAvailable)
                        <form method="POST" action="{{ route('admin.withdrawals.initiate', $withdrawal) }}" class="space-y-2 rounded-xl border border-ink-700 p-4">
                            @csrf
                            <x-form.checkbox name="confirm">Send {{ $money($withdrawal->amount_minor) }} to {{ $withdrawal->destinationLabel() }} through {{ config('competition.withdrawals.gateway') }}.</x-form.checkbox>
                            <x-button type="submit" size="sm">Send payout</x-button>
                        </form>
                    @else
                        <x-alert variant="info">No payout provider is configured, so this approved withdrawal cannot be paid yet. Nothing has been sent.</x-alert>
                    @endif
                @endif
            @endcan
            @if (in_array($status, [\App\Enums\WithdrawalStatus::Processing, \App\Enums\WithdrawalStatus::Paid], true) && $withdrawal->gateway)
                <form method="POST" action="{{ route('admin.withdrawals.refresh', $withdrawal) }}">@csrf<x-button type="submit" variant="ghost" size="sm">Check status with the provider</x-button></form>
            @endif
        </section>
    @endif

    <div class="grid gap-6 xl:grid-cols-2">
        <x-admin.table caption="Recent wallet movements" :columns="['When', 'Type', 'Amount', 'Balance']" :empty="$entries->isEmpty()" empty-title="No wallet movements">
            @foreach ($entries as $entry)
                <tr>
                    <td class="whitespace-nowrap text-ink-300"><x-datetime :value="$entry->created_at" format="j M, g:i A" /></td>
                    <td class="text-ink-100">{{ $entry->transaction->type->label() }}</td>
                    <td @class(['whitespace-nowrap', 'text-emerald-300' => $entry->amount_minor > 0, 'text-ink-100' => $entry->amount_minor < 0])>{{ $money($entry->amount_minor) }}</td>
                    <td class="whitespace-nowrap text-ink-300">{{ $money($entry->balance_after_minor) }}</td>
                </tr>
            @endforeach
        </x-admin.table>
        <x-admin.table caption="Other withdrawals by this player" :columns="['Reference', 'Amount', 'Status']" :empty="$others->isEmpty()" empty-title="None">
            @foreach ($others as $other)
                <tr>
                    <td><a href="{{ route('admin.withdrawals.show', $other) }}" class="font-mono text-xs text-accent-300">{{ $other->reference }}</a></td>
                    <td class="whitespace-nowrap text-ink-100">{{ $money($other->amount_minor) }}</td>
                    <td>@include('account.withdrawals.status-badge', ['status' => $other->status])</td>
                </tr>
            @endforeach
        </x-admin.table>
    </div>

    <x-admin.table caption="History (audit)" :columns="['When', 'Change', 'By', 'Reason']" :empty="$history->isEmpty()" empty-title="No history">
        @foreach ($history as $log)
            <tr>
                <td class="whitespace-nowrap text-ink-300"><x-datetime :value="$log->created_at" format="j M Y, g:i:s A" /></td>
                <td class="text-ink-100">{{ ($log->old_values['status'] ?? '—') }} → {{ $log->new_values['status'] ?? $log->action }}</td>
                <td class="text-ink-300">{{ $log->actor?->name ?? 'System / provider' }}</td>
                <td class="text-ink-300">{{ $log->metadata['reason'] ?? '' }}</td>
            </tr>
        @endforeach
    </x-admin.table>

    @if ($callbacks->isNotEmpty())
        <x-admin.table caption="Provider notifications" :columns="['When', 'Outcome', 'Reason', 'Signature']" :empty="false">
            @foreach ($callbacks as $callback)
                <tr>
                    <td class="whitespace-nowrap text-ink-300"><x-datetime :value="$callback->created_at" format="j M, g:i:s A" /></td>
                    <td class="text-ink-100">{{ ucfirst($callback->outcome) }}</td>
                    <td class="text-ink-300">{{ $callback->reason }}</td>
                    <td class="text-ink-300">{{ $callback->signature_valid ? 'Valid' : 'Invalid' }}</td>
                </tr>
            @endforeach
        </x-admin.table>
    @endif
</x-admin.layout>
