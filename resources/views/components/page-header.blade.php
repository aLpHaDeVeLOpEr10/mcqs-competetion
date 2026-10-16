@props(['title', 'eyebrow' => null])

<header {{ $attributes->class('border-b border-ink-800 bg-gradient-to-b from-ink-900 to-ink-950') }}>
    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6 sm:py-14 lg:px-8">
        @if ($eyebrow)
            <p class="text-sm font-semibold tracking-wide text-accent-300 uppercase">{{ $eyebrow }}</p>
        @endif
        <h1 class="mt-2 text-3xl font-bold tracking-tight text-white sm:text-4xl">{{ $title }}</h1>
        @if ($slot->isNotEmpty())
            <div class="mt-3 max-w-2xl text-base text-ink-300">{{ $slot }}</div>
        @endif
    </div>
</header>
