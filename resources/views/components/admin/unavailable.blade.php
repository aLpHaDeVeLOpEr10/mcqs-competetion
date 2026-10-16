{{-- Marks functionality that belongs to a later phase. Renders no action. --}}
@props(['feature'])

<div {{ $attributes->class('flex items-start gap-3 rounded-xl border border-dashed border-ink-600 px-4 py-3 text-sm text-ink-400') }}>
    <x-badge>Not yet available</x-badge>
    <p>{{ $feature }} {{ $slot }}</p>
</div>
