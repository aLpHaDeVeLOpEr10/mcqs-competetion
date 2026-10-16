@props(['label', 'value', 'hint' => null])

<x-card padding="p-4" {{ $attributes }}>
    <p class="text-sm text-ink-400">{{ $label }}</p>
    <p class="mt-1 text-2xl font-bold text-white">{{ $value }}</p>
    @if ($hint)
        <p class="mt-1 text-xs text-ink-400">{{ $hint }}</p>
    @endif
</x-card>
