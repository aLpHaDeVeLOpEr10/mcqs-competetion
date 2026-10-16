@props(['title', 'subtitle' => null])

<x-layouts.app :title="$title">
    <div class="mx-auto max-w-md px-4 py-12 sm:px-6 sm:py-16">
        <x-card padding="p-6 sm:p-8">
            <h1 class="text-2xl font-bold text-white">{{ $title }}</h1>
            @if ($subtitle)
                <p class="mt-2 text-sm text-ink-400">{{ $subtitle }}</p>
            @endif

            @if (session('status'))
                <x-alert variant="success" class="mt-6">{{ session('status') }}</x-alert>
            @endif

            <div class="mt-6">{{ $slot }}</div>
        </x-card>
        @isset($footer)
            <p class="mt-6 text-center text-sm text-ink-400">{{ $footer }}</p>
        @endisset
    </div>
</x-layouts.app>
