@props(['variant' => 'neutral', 'dot' => false])

@php
    $tone = match ($variant) {
        'accent' => 'border-accent-400/30 bg-accent-400/10 text-accent-300',
        'info' => 'border-sky-400/30 bg-sky-400/10 text-sky-300',
        'warning' => 'border-amber-400/30 bg-amber-400/10 text-amber-300',
        'danger' => 'border-rose-400/30 bg-rose-400/10 text-rose-300',
        'live' => 'border-fuchsia-400/30 bg-fuchsia-400/10 text-fuchsia-300',
        default => 'border-ink-600 bg-ink-800 text-ink-300',
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap', $tone]) }}>
    @if ($dot)
        <span class="size-1.5 rounded-full bg-current" aria-hidden="true"></span>
    @endif
    {{ $slot }}
</span>
