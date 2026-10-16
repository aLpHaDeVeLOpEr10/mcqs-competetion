@props(['code', 'title', 'back' => false])

<x-layouts.app :title="$title">
    <div class="mx-auto max-w-xl px-4 py-24 text-center sm:px-6">
        <p class="text-sm font-semibold tracking-widest text-accent-300">{{ $code }}</p>
        <h1 class="mt-3 text-3xl font-bold text-white sm:text-4xl">{{ $title }}</h1>
        <p class="mt-4 text-ink-300">{{ $slot }}</p>
        <div class="mt-8 flex flex-wrap justify-center gap-3">
            @if ($back && url()->previous() !== url()->current())
                <x-button :href="url()->previous()">Go back</x-button>
            @endif
            <x-button :href="route('home')" :variant="$back ? 'secondary' : 'primary'">Go to home</x-button>
            <x-button :href="route('competitions.index')" variant="secondary">Browse competitions</x-button>
        </div>
    </div>
</x-layouts.app>
