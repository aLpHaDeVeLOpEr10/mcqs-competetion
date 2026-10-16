@props(['paginator', 'noun' => 'results'])

@if ($paginator->total() > 0)
    <div {{ $attributes->class('mt-8 flex flex-col items-center gap-4 sm:flex-row sm:justify-between') }}>
        <p class="text-sm text-ink-400">
            Showing <span class="font-medium text-ink-100">{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }}</span>
            of <span class="font-medium text-ink-100">{{ number_format($paginator->total()) }}</span> {{ $noun }}
        </p>
        {{ $paginator->links() }}
    </div>
@endif
