@props([
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
    'type' => 'button',
    'disabled' => false,
])

@php
    $classes = implode(' ', [
        'inline-flex items-center justify-center gap-2 rounded-lg font-semibold whitespace-nowrap transition-colors',
        'disabled:cursor-not-allowed disabled:opacity-60',
        match ($variant) {
            'primary' => 'bg-accent-400 text-ink-950 hover:bg-accent-300',
            'secondary' => 'border border-ink-600 bg-ink-800 text-ink-100 hover:border-ink-400 hover:bg-ink-700',
            'ghost' => 'text-ink-300 hover:bg-ink-800 hover:text-ink-100',
            'danger' => 'bg-rose-500 text-white hover:bg-rose-400',
        },
        match ($size) {
            'sm' => 'px-3 py-1.5 text-sm',
            'md' => 'px-4 py-2.5 text-sm',
            'lg' => 'px-6 py-3 text-base',
        },
    ]);
@endphp

@if ($href && ! $disabled)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" @disabled($disabled) {{ $attributes->class($classes) }}>
        {{-- Shown by resources/js/app.js while a [data-loading] form submits --}}
        <svg data-spinner class="hidden size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity=".25" stroke-width="4" />
            <path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="4" stroke-linecap="round" />
        </svg>
        <span data-label>{{ $slot }}</span>
    </button>
@endif
