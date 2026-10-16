<x-layouts.app :title="'Live: '.$room->name">
    @push('scripts')
        @vite('resources/js/play.js')
    @endpush

    {{--
        Presentation only: every deadline, state change and answer check is on the server.
        Correct answers are never sent to this page during a competition.
    --}}
    <div class="mx-auto max-w-3xl px-4 pt-6 pb-10 sm:px-6"
        data-play
        data-state-url="{{ route('play.state', $room) }}"
        data-answer-url="{{ route('play.answer', $room) }}"
        data-poll-connected="{{ config('competition.engine.poll_seconds_connected') }}"
        data-poll-disconnected="{{ config('competition.engine.poll_seconds_disconnected') }}"
        data-initial-state="{{ json_encode($initialState) }}">

        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-xs font-semibold tracking-wide text-accent-300 uppercase">Live competition</p>
                <h1 class="mt-1 line-clamp-2 text-xl font-bold break-words text-white sm:text-2xl">
                    <a href="{{ route('competitions.show', $room) }}" class="hover:text-accent-200">{{ $room->name }}</a>
                </h1>
            </div>
            <span data-connection class="inline-flex shrink-0 items-center gap-2 rounded-full border border-ink-700 bg-ink-900 px-3 py-1 text-xs font-medium text-ink-300">
                <span data-connection-dot class="size-2 rounded-full bg-ink-600" aria-hidden="true"></span>
                <span data-connection-label>Connecting…</span>
            </span>
        </div>

        <noscript>
            <x-alert variant="warning" class="mt-5">The live competition needs JavaScript to show questions and the countdown.</x-alert>
        </noscript>

        {{-- Shown only while the WebSocket is down; the page keeps working by polling. --}}
        <div data-reconnect class="mt-4 hidden rounded-xl border border-amber-400/30 bg-amber-400/10 px-4 py-3 text-sm text-amber-100">
            <p class="flex items-center gap-2 font-semibold">
                <svg class="size-4 shrink-0 motion-safe:animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12a9 9 0 1 1-6.2-8.6" /></svg>
                Reconnecting to live updates…
            </p>
            <p class="mt-1 text-amber-100/80">You can keep playing. The timer and your answers are kept on our server, and this page checks for updates every few seconds.</p>
        </div>

        {{-- Screen-reader announcements for important changes (question, time left, answer, connection). --}}
        <p data-status class="sr-only" aria-live="polite" aria-atomic="true"></p>

        {{-- Waiting: before the start, between questions, after the end --}}
        <x-card data-panel="waiting" class="mt-5 text-center" padding="px-6 py-10">
            <p data-waiting-title class="text-lg font-semibold text-white">Loading…</p>
            <p data-waiting-detail class="mt-2 text-sm text-ink-400"></p>
            <p data-waiting-label class="mt-6 hidden text-xs font-semibold tracking-wide text-ink-400 uppercase"></p>
            <p data-waiting-countdown class="mt-1 min-h-12 font-mono text-5xl font-bold text-accent-300 tabular-nums" aria-hidden="true"></p>
            <a data-results-link href="#" class="mt-6 hidden rounded-lg bg-accent-400 px-5 py-2.5 text-sm font-semibold text-ink-950 hover:bg-accent-300">View results</a>
        </x-card>

        {{-- Active question --}}
        <section data-panel="question" class="mt-5 hidden" aria-labelledby="question-heading">
            {{-- Sticky under the site header so the timer stays visible while scrolling long questions. --}}
            <div class="sticky top-16 z-10 -mx-4 border-b border-ink-800 bg-ink-950/95 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-t-2xl sm:border sm:border-ink-700 sm:bg-ink-900/95 sm:px-5">
                <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p data-q-progress class="text-sm font-semibold text-white"></p>
                        <ol data-q-steps class="mt-1.5 flex flex-wrap gap-1" aria-hidden="true"></ol>
                    </div>
                    <p data-q-timer-wrap class="inline-flex shrink-0 items-center gap-1.5 rounded-full border border-accent-400/30 bg-accent-400/10 px-3 py-1.5 text-accent-200">
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="13" r="8" /><path d="M12 9v4l2 2M9 2h6" /></svg>
                        <span class="sr-only">Time left:</span>
                        <span data-q-timer class="font-mono text-base font-bold tabular-nums">--</span>
                    </p>
                </div>
                <div class="mt-2.5 h-1 overflow-hidden rounded-full bg-ink-700" aria-hidden="true">
                    <div data-q-bar class="h-full rounded-full bg-accent-400 transition-[width] duration-200 ease-linear"></div>
                </div>
            </div>

            <div class="rounded-b-2xl border-ink-700 pt-5 sm:border sm:border-t-0 sm:bg-ink-900 sm:px-5 sm:pb-5">
                <h2 id="question-heading" data-q-body class="text-lg leading-snug font-semibold text-white sm:text-xl"></h2>
                <img data-q-image class="mt-4 hidden h-48 w-full rounded-lg border border-ink-700 bg-ink-950 object-contain sm:h-64" alt="" decoding="async">

                <fieldset class="mt-5">
                    <legend class="sr-only">Choose one answer. Your first answer is final.</legend>
                    <div data-q-options class="grid gap-3 sm:grid-cols-2"></div>
                </fieldset>

                <p data-q-feedback class="mt-4 flex min-h-6 items-center gap-2 text-sm text-ink-300"></p>
            </div>
        </section>

        <p class="mt-6 text-xs leading-relaxed text-ink-400">
            Every player has the same server deadline for each question; answers received after it are not accepted.
            Your first answer is final. Correct answers and scores are shown when results are published.
        </p>
    </div>
</x-layouts.app>
