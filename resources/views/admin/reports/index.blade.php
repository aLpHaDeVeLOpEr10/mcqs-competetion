@php($money = fn (int $minor) => \App\Support\Money::format($minor))
<x-admin.layout title="Reports" :breadcrumbs="['Reports' => null]">
    @include('admin.reports.partials.tabs')
    @include('admin.reports.partials.period')

    <section aria-labelledby="activity-heading">
        <h2 id="activity-heading" class="text-lg font-semibold text-white">Activity</h2>
        <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <x-admin.stat label="Registered users" :value="number_format($activity['registered_users'])" hint="Accounts created in the period" />
            <x-admin.stat label="Active users" :value="number_format($activity['active_users'])" hint="Took a seat or had wallet activity" />
            <x-admin.stat label="Rooms created" :value="number_format($activity['rooms_created'])" />
            <x-admin.stat label="Competitions completed" :value="number_format($activity['competitions_completed'])" hint="Results finalized in the period" />
            <x-admin.stat label="Competition entries" :value="number_format($activity['competition_entries'])" hint="Seats confirmed in the period" />
        </div>
    </section>

    @if ($finance)
        <section aria-labelledby="finance-heading">
            <h2 id="finance-heading" class="text-lg font-semibold text-white">Money movements</h2>
            <p class="mt-1 text-sm text-ink-400">Amounts moved through player wallets, from the ledger. These are volumes, not profit.</p>
            <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <x-admin.stat label="Gross entry fees" :value="$money($finance['gross_entry_fees'])" />
                <x-admin.stat label="Entry fee refunds" :value="$money($finance['entry_fee_refunds'])" :hint="'Net entry fees '.$money($finance['net_entry_fees'])" />
                <x-admin.stat label="Prize credits" :value="$money($finance['prize_credits'])" :hint="$finance['prizes_paid'].' prize(s) paid'" />
                <x-admin.stat label="Deposits" :value="$money($finance['deposits'])" :hint="$finance['deposits_count'].' successful payment(s)'" />
                <x-admin.stat label="Withdrawals paid" :value="$money($finance['withdrawals_paid'])" :hint="'Requested '.$money($finance['withdrawals_requested'])" />
                <x-admin.stat label="Failed transactions" :value="number_format($finance['failed_payments'] + $finance['failed_withdrawals'])"
                    :hint="$finance['failed_payments'].' payment(s), '.$finance['failed_withdrawals'].' payout(s); '.$finance['rejected_withdrawals'].' withdrawal(s) rejected'" />
                <x-admin.stat label="Administrative adjustments (net)" :value="$money($finance['adjustments'])" />
                <x-admin.stat label="Platform revenue" :value="$money($finance['platform_revenue'])" hint="Platform share of entry fees and withdrawal fees, as recorded in the ledger" />
            </div>
        </section>
    @endif
</x-admin.layout>
