@props(['current', 'max'])

@php
    $percent = $max > 0 ? min(100, intdiv($current * 100, $max)) : 0;
@endphp

{{-- data-seat-* hooks are updated live by resources/js/room.js (display only). --}}
<div {{ $attributes->class('space-y-1.5') }} data-seat-meter>
    <p class="text-sm text-ink-100"><span class="font-semibold" data-seat-count>{{ number_format($current) }}</span><span class="text-ink-400" data-seat-max> / {{ number_format($max) }} players</span></p>
    <div class="h-1.5 overflow-hidden rounded-full bg-ink-700" role="img" aria-label="{{ $current }} of {{ $max }} seats taken">
        <div class="h-full rounded-full {{ $percent >= 100 ? 'bg-amber-400' : 'bg-accent-400' }}" style="width: {{ $percent }}%" data-seat-bar></div>
    </div>
</div>
