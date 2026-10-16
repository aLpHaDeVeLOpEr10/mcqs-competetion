@php($money = fn ($minor) => \App\Support\Money::format((int) $minor))
<x-admin.layout title="Competition report" :breadcrumbs="['Reports' => route('admin.reports.index'), 'Competitions' => null]">
    @include('admin.reports.partials.tabs')
    @include('admin.reports.partials.period', ['export' => 'competitions'])

    <div class="grid gap-4 sm:grid-cols-3">
        <x-admin.stat label="Competitions" :value="number_format($totals['competitions'])" hint="Started (or scheduled / created) in the period" />
        <x-admin.stat label="Gross collected" :value="$money($totals['gross_collected'])" hint="Entry fees debited from players" />
        <x-admin.stat label="Refunded" :value="$money($totals['refunds'])" />
    </div>

    <x-admin.table caption="Competitions" :columns="['Room', 'Status', 'Entry fee', 'Participants', 'Gross', 'Refunds', 'Prize pool', 'Winners', 'Start', 'End']" :empty="$rows->isEmpty()" empty-title="No competitions in this period">
        @foreach ($rows as $row)
            <tr>
                <td class="text-white"><a href="{{ route('admin.rooms.show', $row->id) }}" class="hover:text-accent-300">{{ $row->name }}</a>
                    <p class="font-mono text-xs text-ink-400">Room #{{ $row->id }}@if ($row->session_id) · session #{{ $row->session_id }}@endif</p></td>
                <td class="text-ink-300">{{ \App\Enums\RoomStatus::from($row->status)->label() }}</td>
                <td class="whitespace-nowrap text-ink-300">{{ \App\Support\Money::fee((int) $row->entry_fee_minor) }}</td>
                <td class="text-ink-300">{{ $row->participants }} / {{ $row->max_participants }}</td>
                <td class="whitespace-nowrap text-white">{{ $money($row->gross_collected) }}</td>
                <td class="whitespace-nowrap text-ink-300">{{ $money($row->refunds) }}</td>
                <td class="whitespace-nowrap text-ink-300">{{ $row->prize_pool_minor === null ? '—' : $money($row->prize_pool_minor) }}</td>
                <td class="text-ink-300">{{ $row->winners }}</td>
                <td class="whitespace-nowrap text-ink-400">@if ($row->started_at)<x-datetime :value="\Carbon\CarbonImmutable::parse($row->started_at, 'UTC')" format="j M, g:i A" />@else — @endif</td>
                <td class="whitespace-nowrap text-ink-400">@if ($row->ended_at)<x-datetime :value="\Carbon\CarbonImmutable::parse($row->ended_at, 'UTC')" format="j M, g:i A" />@else — @endif</td>
            </tr>
        @endforeach
    </x-admin.table>
    <x-pagination :paginator="$rows" noun="competitions" />
</x-admin.layout>
