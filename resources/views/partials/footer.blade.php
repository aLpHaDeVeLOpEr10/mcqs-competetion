<footer class="mt-20 border-t border-ink-800 bg-ink-900/60">
    <div class="mx-auto grid max-w-6xl gap-10 px-4 py-12 sm:px-6 md:grid-cols-4 lg:px-8">
        <div class="md:col-span-2">
            <p class="font-bold text-white">{{ config('app.name') }}</p>
            <p class="mt-2 max-w-sm text-sm text-ink-400">Live, timed multiple-choice competitions. Every timer, score and ranking is decided by our servers, not your device.</p>
            @unless (config('competition.paid_entries.enabled'))
                <p class="mt-4 inline-flex items-center gap-2 rounded-lg border border-ink-700 px-3 py-1.5 text-xs text-ink-300">
                    <span class="size-1.5 rounded-full bg-accent-400" aria-hidden="true"></span>
                    Only free-entry competitions are currently offered.
                </p>
            @endunless
        </div>
        <nav aria-label="Play">
            <p class="text-sm font-semibold text-ink-100">Play</p>
            <ul class="mt-3 space-y-2 text-sm text-ink-400">
                <li><a class="hover:text-white" href="{{ route('competitions.index') }}">Competitions</a></li>
                <li><a class="hover:text-white" href="{{ route('how-to-play') }}">How to play &amp; rules</a></li>
                <li><a class="hover:text-white" href="{{ route('leaderboard') }}">Leaderboard</a></li>
            </ul>
        </nav>
        <nav aria-label="Legal and support">
            <p class="text-sm font-semibold text-ink-100">Support</p>
            <ul class="mt-3 space-y-2 text-sm text-ink-400">
                <li><a class="hover:text-white" href="{{ route('contact') }}">Contact</a></li>
                <li><a class="hover:text-white" href="{{ route('responsible-play') }}">Responsible play</a></li>
                <li><a class="hover:text-white" href="{{ route('terms') }}">Terms</a></li>
                <li><a class="hover:text-white" href="{{ route('privacy') }}">Privacy</a></li>
            </ul>
        </nav>
    </div>
    <div class="border-t border-ink-800">
        <p class="mx-auto max-w-6xl px-4 py-5 text-xs text-ink-400 sm:px-6 lg:px-8">&copy; {{ now()->year }} {{ config('app.name') }}. Times are shown in Pakistan Standard Time ({{ \App\Support\DisplayTime::zoneLabel() }}).</p>
    </div>
</footer>
