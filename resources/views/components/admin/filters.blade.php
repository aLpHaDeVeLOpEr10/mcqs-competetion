{{-- GET filter bar. Put x-form.select / x-form.input fields in the slot. --}}
@props(['action', 'filters'])

<form method="GET" action="{{ $action }}" class="rounded-2xl border border-ink-700 bg-ink-900/60 p-4" aria-label="Filter results">
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {{ $slot }}
        <div class="flex items-end gap-2">
            <x-button type="submit">Apply</x-button>
            @if ($filters->any())
                <x-button :href="$action" variant="ghost">Reset</x-button>
            @endif
        </div>
    </div>
</form>

@if ($filters->ignored !== [])
    <x-alert variant="warning">Some filters were not recognised and were ignored: {{ implode(', ', $filters->ignored) }}.</x-alert>
@endif
