{{-- Period filter (Pakistan time) and, where allowed, a queued CSV export of the current report. --}}
@php($exportable = $export ?? null)
<x-card padding="p-4">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <form method="GET" class="flex flex-wrap items-end gap-3" aria-label="Report period">
            <label class="text-xs text-ink-400">Period
                <select name="period" class="mt-1 block rounded-lg border border-ink-600 bg-ink-900 px-3 py-2 text-sm text-ink-100">
                    @foreach (\App\Services\Reports\ReportPeriod::PRESETS as $value => $label)
                        <option value="{{ $value }}" @selected($period->preset === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-xs text-ink-400">From
                <input type="date" name="from" value="{{ $period->firstDay->format('Y-m-d') }}" class="mt-1 block rounded-lg border border-ink-600 bg-ink-900 px-3 py-2 text-sm text-ink-100">
            </label>
            <label class="text-xs text-ink-400">To
                <input type="date" name="to" value="{{ $period->lastDay->format('Y-m-d') }}" class="mt-1 block rounded-lg border border-ink-600 bg-ink-900 px-3 py-2 text-sm text-ink-100">
            </label>
            <x-button type="submit" variant="secondary" size="sm">Apply</x-button>
        </form>
        @if ($exportable && auth()->user()->can('reports.export') && \App\Services\Reports\ReportCatalog::allowed(auth()->user(), $exportable))
            <form method="POST" action="{{ route('admin.reports.export') }}" data-loading>
                @csrf
                <input type="hidden" name="report" value="{{ $exportable }}">
                @foreach ($period->toQuery() as $key => $value)
                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                @endforeach
                <x-button type="submit" size="sm" data-loading-text="Queuing…">Export CSV</x-button>
            </form>
        @endif
    </div>
    @error('to')<p class="mt-2 text-sm text-rose-300">{{ $message }}</p>@enderror
    @error('from')<p class="mt-2 text-sm text-rose-300">{{ $message }}</p>@enderror
    <p class="mt-3 text-xs text-ink-400">Showing {{ $period->label() }}, whole days in Pakistan time ({{ config('competition.display_timezone') }}). From/To apply when “Custom range” is selected.</p>
</x-card>
