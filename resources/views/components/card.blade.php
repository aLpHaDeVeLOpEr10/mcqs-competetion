@props(['as' => 'div', 'padding' => 'p-5 sm:p-6'])

<{{ $as }} {{ $attributes->class(['rounded-2xl border border-ink-700 bg-ink-850/80 shadow-lg shadow-black/20', $padding]) }}>
    {{ $slot }}
</{{ $as }}>
