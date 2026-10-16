@props(['variant' => 'info', 'title' => null])

@php
    $tone = match ($variant) {
        'success' => 'border-emerald-400/30 bg-emerald-400/10 text-emerald-200',
        'error' => 'border-rose-400/40 bg-rose-500/10 text-rose-200',
        'warning' => 'border-amber-400/30 bg-amber-400/10 text-amber-200',
        default => 'border-sky-400/30 bg-sky-400/10 text-sky-200',
    };
    $icon = match ($variant) {
        'success' => 'M5 13l4 4L19 7',
        'error' => 'M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z',
        'warning' => 'M12 9v4m0 4h.01M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Z',
        default => 'M12 16v-4m0-4h.01M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Z',
    };
@endphp

<div role="{{ $variant === 'error' ? 'alert' : 'status' }}" {{ $attributes->class(['flex gap-3 rounded-xl border px-4 py-3 text-sm', $tone]) }}>
    <svg class="mt-0.5 size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="{{ $icon }}" />
    </svg>
    <div class="space-y-1">
        @if ($title)
            <p class="font-semibold">{{ $title }}</p>
        @endif
        <div>{{ $slot }}</div>
    </div>
</div>
