<x-admin.layout title="Exports" :breadcrumbs="['Exports' => null]">
    <x-alert variant="info">
        Exports are built in the background and kept for {{ \App\Models\ReportExport::EXPIRES_DAYS }} days. Only you can download your exports, and only while you still hold the report's permission. Spreadsheet formulas in exported text are neutralized.
    </x-alert>

    <x-admin.table caption="Your exports" :columns="['Requested', 'Report', 'Period', 'Status', 'Rows', '']" :empty="$exports->isEmpty()" empty-title="No exports yet" empty-text="Use “Export CSV” on a report.">
        @foreach ($exports as $export)
            <tr>
                <td class="whitespace-nowrap text-ink-300"><x-datetime :value="$export->created_at" format="j M Y, g:i A" /></td>
                <td class="text-white">{{ \App\Services\Reports\ReportCatalog::REPORTS[$export->report]['title'] ?? $export->report }}</td>
                <td class="text-ink-300">{{ \App\Services\Reports\ReportPeriod::fromArray($export->filters)->label() }}</td>
                <td>
                    <x-badge :variant="match ($export->status) { 'done' => 'accent', 'failed' => 'danger', default => 'info' }">{{ $export->isExpired() ? 'Expired' : ucfirst($export->status) }}</x-badge>
                    @if ($export->error)<p class="mt-1 text-xs text-rose-300">{{ $export->error }}</p>@endif
                </td>
                <td class="text-ink-300">{{ $export->status === 'done' ? number_format($export->rows) : '—' }}</td>
                <td>@if ($export->status === 'done' && ! $export->isExpired())<a href="{{ route('admin.exports.download', $export) }}" class="text-sm text-accent-300 hover:text-accent-200">Download</a>@endif</td>
            </tr>
        @endforeach
    </x-admin.table>
    <x-pagination :paginator="$exports" noun="exports" />
</x-admin.layout>
