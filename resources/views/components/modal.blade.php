{{--
    Native <dialog>. Open it with an element carrying data-modal-open="{id}";
    make that element a real link so it still works without JavaScript.
--}}
@props(['id', 'title'])

<dialog id="{{ $id }}" aria-labelledby="{{ $id }}-title"
    {{ $attributes->class('m-auto max-h-[calc(100dvh-2rem)] w-[min(36rem,calc(100%-2rem))] flex-col overflow-hidden rounded-2xl border border-ink-700 bg-ink-900 p-0 text-ink-100 shadow-2xl open:flex') }}>
    <div class="flex items-start justify-between gap-4 border-b border-ink-700 px-5 py-4">
        <h2 id="{{ $id }}-title" class="text-lg font-semibold">{{ $title }}</h2>
        <form method="dialog">
            <button type="submit" class="rounded-md p-1 text-ink-400 hover:bg-ink-800 hover:text-ink-100" aria-label="Close dialog">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" /></svg>
            </button>
        </form>
    </div>
    <div class="space-y-3 overflow-y-auto overscroll-contain px-5 py-4 text-sm leading-relaxed text-ink-300">
        {{ $slot }}
    </div>
</dialog>
