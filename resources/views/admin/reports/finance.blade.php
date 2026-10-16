@php($money = fn ($minor) => \App\Support\Money::format((int) $minor))
@php($title = $report === 'reconciliation' ? 'Reconciliation' : \App\Services\Reports\ReportCatalog::REPORTS[$report]['title'])
<x-admin.layout :title="'Financial report: '.$title" :breadcrumbs="['Reports' => route('admin.reports.index'), $title => null]">
    @include('admin.reports.partials.tabs')
    @include('admin.reports.partials.period', ['export' => $report === 'reconciliation' ? null : $report])

    @if ($reconciliation)
        <x-card>
            <h2 class="text-lg font-semibold text-white">Player wallets</h2>
            <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                <div class="flex justify-between gap-3"><dt class="text-ink-400">Opening balance (start of period)</dt><dd class="font-semibold text-white">{{ $money($reconciliation['opening']) }}</dd></div>
                @foreach ($reconciliation['movements'] as $type => $amount)
                    <div class="flex justify-between gap-3"><dt class="text-ink-400">{{ \App\Enums\LedgerTransactionType::from($type)->label() }}</dt><dd class="text-ink-100">{{ $money($amount) }}</dd></div>
                @endforeach
                <div class="flex justify-between gap-3"><dt class="text-ink-400">Closing balance (end of period)</dt><dd class="font-semibold text-white">{{ $money($reconciliation['closing']) }}</dd></div>
            </dl>
            <p class="mt-3 text-sm">
                @if ($reconciliation['balanced'])
                    <x-badge variant="accent">Opening + movements = closing</x-badge>
                @else
                    <x-badge variant="danger">Opening + movements ≠ closing</x-badge>
                @endif
                @if ($reconciliation['ledger_issues'] === 0)
                    <x-badge variant="accent">All-time ledger reconciled</x-badge>
                @else
                    <x-badge variant="danger">{{ $reconciliation['ledger_issues'] }} ledger discrepancy(ies) — run wallets:reconcile</x-badge>
                @endif
            </p>
        </x-card>

        <x-admin.table caption="Records vs ledger" :columns="['Check', 'Business records', 'Ledger', 'Difference']" :empty="false">
            @foreach ($reconciliation['checks'] as $check)
                <tr>
                    <td class="text-ink-100">{{ $check['label'] }}</td>
                    <td class="whitespace-nowrap text-ink-300">{{ $money($check['records']) }}</td>
                    <td class="whitespace-nowrap text-ink-300">{{ $money($check['ledger']) }}</td>
                    <td class="whitespace-nowrap">@if ($check['difference'] === 0)<x-badge variant="accent">0</x-badge>@else<x-badge variant="danger">{{ $money($check['difference']) }}</x-badge>@endif</td>
                </tr>
            @endforeach
        </x-admin.table>

        <x-admin.table caption="Platform accounts (current balance)" :columns="['Account', 'Balance']" :empty="empty($reconciliation['system_wallets'])">
            @foreach ($reconciliation['system_wallets'] as $code => $balance)
                <tr><td class="text-ink-100">{{ str_replace('_', ' ', $code) }}</td><td class="whitespace-nowrap text-white">{{ $money($balance) }}</td></tr>
            @endforeach
        </x-admin.table>
        <p class="text-xs text-ink-400">Platform accounts are the counterparties of player movements (e.g. the prize pool, gateway clearing). Their balances are not profit; platform revenue is reported separately on the overview.</p>
    @else
        <div class="grid gap-4 sm:grid-cols-4">
            @foreach ($totals as $key => $value)
                <x-admin.stat :label="ucfirst(str_replace('_', ' ', $key))" :value="str_contains($key, 'amount') || $key === 'fees' ? $money($value) : number_format($value)" />
            @endforeach
        </div>

        @php($ledgerReport = isset(\App\Services\Reports\ReportCatalog::LEDGER_REPORTS[$report]))
        <x-admin.table :caption="$title" :columns="$ledgerReport ? ['When', 'Reference', 'Player', 'Amount', 'Description'] : ($report === 'deposits' ? ['When', 'Reference', 'Player', 'Amount', 'Status', 'Provider'] : ['When', 'Reference', 'Player', 'Amount', 'Fee', 'Status', 'Paid'])" :empty="$rows->isEmpty()" empty-title="Nothing in this period">
            @foreach ($rows as $row)
                <tr>
                    <td class="whitespace-nowrap text-ink-300"><x-datetime :value="\Carbon\CarbonImmutable::parse($row->created_at, 'UTC')" format="j M Y, g:i A" /></td>
                    <td class="font-mono text-xs text-ink-300">{{ $row->reference }}</td>
                    <td class="text-ink-100">{{ $row->email }}</td>
                    <td class="whitespace-nowrap font-semibold text-white">{{ $money($row->amount_minor) }}</td>
                    @if ($ledgerReport)
                        <td class="max-w-xs truncate text-ink-400">{{ $row->description }}</td>
                    @elseif ($report === 'deposits')
                        <td class="text-ink-300">{{ $row->status }}</td>
                        <td class="font-mono text-xs text-ink-400">{{ $row->gateway }} {{ $row->gateway_reference }}</td>
                    @else
                        <td class="whitespace-nowrap text-ink-300">{{ $money($row->fee_minor) }}</td>
                        <td class="text-ink-300">{{ $row->status }}</td>
                        <td class="whitespace-nowrap text-ink-400">@if ($row->paid_at)<x-datetime :value="\Carbon\CarbonImmutable::parse($row->paid_at, 'UTC')" format="j M, g:i A" />@else — @endif</td>
                    @endif
                </tr>
            @endforeach
        </x-admin.table>
        <x-pagination :paginator="$rows" noun="records" />
    @endif
</x-admin.layout>
