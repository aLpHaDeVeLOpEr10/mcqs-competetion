@props(['title', 'action' => null, 'actionLabel' => null])

<div {{ $attributes->class('rounded-2xl border border-dashed border-ink-600 bg-ink-900/60 px-6 py-12 text-center') }}>
    <svg class="mx-auto size-10 text-ink-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
        <path d="M9 12h6m-3-3v6M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v11a2.5 2.5 0 0 1-2.5 2.5h-11A2.5 2.5 0 0 1 4 17.5v-11Z" />
    </svg>
    <h3 class="mt-4 text-base font-semibold text-ink-100">{{ $title }}</h3>
    <div class="mx-auto mt-2 max-w-md text-sm text-ink-400">{{ $slot }}</div>
    @if ($action)
        <x-button :href="$action" variant="secondary" size="sm" class="mt-5">{{ $actionLabel }}</x-button>
    @endif
</div>
