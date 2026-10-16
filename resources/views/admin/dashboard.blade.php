<x-admin.layout title="Dashboard">
    @if ($alerts !== [])
        <section aria-labelledby="alerts-heading" class="space-y-2">
            <h2 id="alerts-heading" class="text-lg font-semibold text-white">System alerts</h2>
            @foreach ($alerts as $alert)
                <x-alert :variant="$alert['level']">{{ $alert['message'] }}</x-alert>
            @endforeach
        </section>
    @endif

    @if ($figuresAsOf)
        <p class="text-xs text-ink-400">Totals as of <x-datetime :value="\Illuminate\Support\Carbon::parse($figuresAsOf)" format="g:i:s A" />, refreshed every {{ config('competition.cache.dashboard_seconds') }} seconds. Alerts and recent actions are live.</p>
    @endif

    <section aria-labelledby="users-heading">
        <h2 id="users-heading" class="text-lg font-semibold text-white">Users</h2>
        <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <x-admin.stat label="Total users" :value="number_format($users['total'])" />
            <x-admin.stat label="Active" :value="number_format($users['active'])" />
            <x-admin.stat label="Restricted" :value="number_format($users['restricted'])" />
            <x-admin.stat label="Suspended" :value="number_format($users['suspended'])" />
            <x-admin.stat label="New in last 7 days" :value="number_format($users['new_7_days'])" :hint="number_format($users['verified']).' verified emails in total'" />
        </div>
    </section>

    <section aria-labelledby="rooms-heading">
        <h2 id="rooms-heading" class="text-lg font-semibold text-white">Competitions</h2>
        <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-admin.stat label="Open for registration" :value="number_format($rooms['open'] + $rooms['full'])" :hint="$rooms['full'].' of them full'" />
            <x-admin.stat label="Scheduled" :value="number_format($rooms['scheduled'])" :hint="$rooms['draft'].' drafts not yet published'" />
            <x-admin.stat label="Live now" :value="number_format($rooms['in_progress'])" />
            <x-admin.stat label="Completed" :value="number_format($rooms['completed'])" :hint="($rooms['cancelled'] + $rooms['refunded']).' cancelled or refunded'" />
            <x-admin.stat label="Seats taken (all time)" :value="number_format($participation['seats'])" />
            <x-admin.stat label="Distinct players" :value="number_format($participation['players'])" />
            <x-admin.stat label="Sessions completed" :value="number_format($participation['sessions_completed'])" />
        </div>
    </section>

    @if ($finance !== null)
        <section aria-labelledby="finance-heading">
            <h2 id="finance-heading" class="text-lg font-semibold text-white">Money movements</h2>
            <p class="mt-1 text-sm text-ink-400">Separate figures from recorded payments and the wallet ledger. None of these is profit.</p>
            <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <x-admin.stat label="Gross deposits received" :value="\App\Support\Money::format($finance['gross_deposits'])" hint="Successful gateway deposits. Player money, not revenue." />
                <x-admin.stat label="Withdrawals paid out" :value="\App\Support\Money::format($finance['withdrawals_paid'])" />
                <x-admin.stat label="Gateway refunds" :value="\App\Support\Money::format($finance['gateway_refunds'])" hint="Deposits returned to the payment source." />
                <x-admin.stat label="Entry fees charged" :value="\App\Support\Money::format($finance['entry_fees_charged'])" hint="Debited from player wallets." />
                <x-admin.stat label="Entry fees refunded" :value="\App\Support\Money::format($finance['entry_fees_refunded'])" hint="Credited back after cancellations or withdrawals." />
                <x-admin.stat label="Prizes paid" :value="\App\Support\Money::format($finance['prizes_paid'])" hint="Credited to winners' wallets." />
                <x-admin.stat label="Platform revenue wallet" :value="\App\Support\Money::format($finance['platform_revenue_balance'])" hint="Ledger balance before costs and taxes." />
                <x-admin.stat label="Outstanding prize payouts" :value="\App\Support\Money::format($finance['outstanding_payouts_amount'])" :hint="$finance['outstanding_payouts_count'].' unpaid record(s)'" />
            </div>
        </section>
    @endif

    @if ($recentActions !== null)
        <section aria-labelledby="actions-heading">
            <div class="flex items-center justify-between">
                <h2 id="actions-heading" class="text-lg font-semibold text-white">Recent administrative actions</h2>
                <a href="{{ route('admin.audit.index') }}" class="text-sm text-accent-300 hover:text-accent-200">Full audit log</a>
            </div>
            <x-admin.table class="mt-3" caption="Recent audit records" :columns="['When', 'Action', 'Actor', 'Subject']" :empty="$recentActions->isEmpty()" empty-title="No actions recorded yet">
                @foreach ($recentActions as $log)
                    @include('admin.audit.row', ['log' => $log])
                @endforeach
            </x-admin.table>
        </section>
    @endif
</x-admin.layout>
