@php
    $links = [
        ['route' => 'competitions.index', 'active' => 'competitions.*', 'label' => 'Competitions'],
        ['route' => 'how-to-play', 'active' => 'how-to-play', 'label' => 'How to play'],
        ['route' => 'leaderboard', 'active' => 'leaderboard', 'label' => 'Leaderboard'],
    ];
@endphp

<header class="sticky top-0 z-40 border-b border-ink-800 bg-ink-950/90 backdrop-blur">
    <div class="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
        <a href="{{ route('home') }}" class="flex items-center gap-2.5 font-bold text-white">
            <span class="grid size-8 place-items-center rounded-lg bg-accent-400 text-ink-950" aria-hidden="true">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 9a3 3 0 1 1 4 2.8c-.6.3-1 .9-1 1.6V14m0 3.5h.01" /></svg>
            </span>
            <span>{{ config('app.name') }}</span>
        </a>

        <nav aria-label="Main" class="hidden md:block">
            <ul class="flex items-center gap-1 text-sm font-medium">
                @foreach ($links as $link)
                    @php($active = request()->routeIs($link['active']))
                    <li>
                        <a href="{{ route($link['route']) }}" @if ($active) aria-current="page" @endif
                            @class(['rounded-lg px-3 py-2 transition-colors', 'bg-ink-800 text-white' => $active, 'text-ink-300 hover:text-white' => ! $active])>{{ $link['label'] }}</a>
                    </li>
                @endforeach
            </ul>
        </nav>

        <div class="flex items-center gap-1">
        @auth
            @include('partials.notification-bell')
        @endauth
        <div class="hidden items-center gap-2 md:flex">
            @auth
                <details class="relative" data-mobile-menu>
                    <summary class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-ink-100 hover:bg-ink-800">
                        <span class="grid size-7 place-items-center rounded-full bg-ink-700 text-xs font-bold text-accent-300" aria-hidden="true">{{ strtoupper(substr(auth()->user()->publicName(), 0, 1)) }}</span>
                        {{ auth()->user()->publicName() }}
                        <svg class="size-4 text-ink-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
                    </summary>
                    <div class="absolute right-0 mt-2 w-56 rounded-xl border border-ink-700 bg-ink-900 p-2 shadow-2xl">
                        @include('partials.account-menu')
                    </div>
                </details>
            @else
                <x-button :href="route('login')" variant="ghost" size="sm">Log in</x-button>
                <x-button :href="route('register')" size="sm">Create account</x-button>
            @endauth
        </div>

        {{-- Mobile menu: native <details>, works without JavaScript --}}
        <details class="relative md:hidden" data-mobile-menu>
            <summary class="rounded-lg p-2 text-ink-300 hover:bg-ink-800 hover:text-white" aria-label="Open menu">
                <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" /></svg>
            </summary>
            <nav aria-label="Mobile" class="absolute right-0 mt-2 w-64 rounded-xl border border-ink-700 bg-ink-900 p-2 shadow-2xl">
                <ul class="space-y-1 text-sm font-medium">
                    @foreach ([['route' => 'home', 'active' => 'home', 'label' => 'Home'], ...$links] as $link)
                        @php($active = request()->routeIs($link['active']))
                        <li>
                            <a href="{{ route($link['route']) }}" @if ($active) aria-current="page" @endif
                                @class(['block rounded-lg px-3 py-3 md:py-2', 'bg-ink-800 text-white' => $active, 'text-ink-300 hover:bg-ink-800 hover:text-white' => ! $active])>{{ $link['label'] }}</a>
                        </li>
                    @endforeach
                </ul>
                <div class="mt-2 border-t border-ink-700 pt-3">
                    @auth
                        @include('partials.account-menu')
                    @else
                        <div class="grid gap-2">
                            <x-button :href="route('login')" variant="secondary" size="sm">Log in</x-button>
                            <x-button :href="route('register')" size="sm">Create account</x-button>
                        </div>
                    @endauth
                </div>
            </nav>
        </details>
        </div>
    </div>
</header>
