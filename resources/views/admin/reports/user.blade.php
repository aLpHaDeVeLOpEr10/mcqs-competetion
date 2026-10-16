@php($money = fn ($minor) => \App\Support\Money::format((int) $minor))
<x-admin.layout :title="'User report: '.$user->publicName()" :breadcrumbs="['Users' => route('admin.users.index'), $user->publicName() => route('admin.users.show', $user), 'Report' => null]">
    <x-card>
        <dl class="grid gap-4 text-sm sm:grid-cols-4">
            <div><dt class="text-ink-400">Account</dt><dd class="mt-1 text-white">{{ $user->status->label() }} · {{ $user->role->label() }}</dd></div>
            <div><dt class="text-ink-400">Joined</dt><dd class="mt-1 text-white"><x-datetime :value="$user->created_at" format="j M Y" /></dd></div>
            <div><dt class="text-ink-400">Email verified</dt><dd class="mt-1 text-white">{{ $user->email_verified_at ? 'Yes' : 'No' }}</dd></div>
            <div><dt class="text-ink-400">Wins (published)</dt><dd class="mt-1 text-white">{{ $wins }}</dd></div>
        </dl>
    </x-card>

    <x-admin.table caption="Competition participation" :columns="['Competition', 'Seat', 'Entry', 'Result', 'Prize']" :empty="$participations->isEmpty()" empty-title="No competitions">
        @foreach ($participations as $p)
            @php($published = $p->room?->session?->results_published_at !== null)
            <tr>
                <td class="text-white"><a href="{{ route('admin.rooms.show', $p->room_id) }}" class="hover:text-accent-300">{{ $p->room?->name }}</a>
                    <p class="text-xs text-ink-400"><x-datetime :value="$p->joined_at" format="j M Y" /> · {{ $p->room?->status->label() }}</p></td>
                <td class="text-ink-300">{{ $p->status->label() }}</td>
                <td class="whitespace-nowrap text-ink-300">{{ \App\Support\Money::fee($p->entry_fee_minor) }}</td>
                <td class="text-ink-300">{{ $published && $p->result ? ($p->result->rank ? '#'.$p->result->rank : 'Not ranked').' · '.$p->result->correct_count.' correct' : '—' }}</td>
                <td class="whitespace-nowrap text-ink-300">{{ $published && $p->prizePayout && $p->prizePayout->status !== \App\Enums\PayoutStatus::Cancelled ? $money($p->prizePayout->net_amount_minor ?? $p->prizePayout->amount_minor).' ('.$p->prizePayout->status->label().')' : '—' }}</td>
            </tr>
        @endforeach
    </x-admin.table>
    <x-pagination :paginator="$participations" noun="competitions" />

    @if ($breakdown !== null)
        <section aria-labelledby="money-heading" class="space-y-3">
            <h2 id="money-heading" class="text-lg font-semibold text-white">Financial activity</h2>
            <div class="grid gap-4 sm:grid-cols-3 lg:grid-cols-6">
                <x-admin.stat label="Balance" :value="$money($user->wallet?->balance_minor ?? 0)" />
                @foreach (['deposits' => 'Deposits', 'entry_fees' => 'Entry fees', 'refunds' => 'Refunds', 'prizes' => 'Prize credits', 'withdrawals' => 'Withdrawals'] as $key => $label)
                    <x-admin.stat :label="$label" :value="$money($breakdown[$key] ?? 0)" />
                @endforeach
            </div>
            @if ($user->wallet)
                <p class="text-sm"><a href="{{ route('admin.wallets.show', $user->wallet) }}" class="text-accent-300 hover:text-accent-200">Full wallet ledger</a></p>
            @endif
            <div class="grid gap-6 xl:grid-cols-2">
                <x-admin.table caption="Recent deposits" :columns="['Reference', 'Amount', 'Status']" :empty="$deposits->isEmpty()" empty-title="No deposits">
                    @foreach ($deposits as $d)
                        <tr><td><a href="{{ route('admin.payments.show', $d) }}" class="font-mono text-xs text-accent-300">{{ $d->reference }}</a></td><td class="text-ink-100">{{ $money($d->amount_minor) }}</td><td class="text-ink-300">{{ $d->status->label() }}</td></tr>
                    @endforeach
                </x-admin.table>
                <x-admin.table caption="Recent withdrawals" :columns="['Reference', 'Amount', 'Status']" :empty="$withdrawals->isEmpty()" empty-title="No withdrawals">
                    @foreach ($withdrawals as $w)
                        <tr><td class="font-mono text-xs text-ink-300">@can('withdrawals.review')<a href="{{ route('admin.withdrawals.show', $w) }}" class="text-accent-300">{{ $w->reference }}</a>@else{{ $w->reference }}@endcan</td><td class="text-ink-100">{{ $money($w->amount_minor) }}</td><td class="text-ink-300">{{ $w->status->label() }}</td></tr>
                    @endforeach
                </x-admin.table>
            </div>
        </section>
    @else
        <x-alert variant="info">Financial activity is visible only to finance staff.</x-alert>
    @endif

    @if ($history !== null)
        <x-admin.table caption="Audit history (actions on or by this user)" :columns="['When', 'Action', 'Actor', 'Subject']" :empty="$history->isEmpty()" empty-title="No recorded changes">
            @foreach ($history as $log)
                @include('admin.audit.row', ['log' => $log])
            @endforeach
        </x-admin.table>
    @endif
</x-admin.layout>
