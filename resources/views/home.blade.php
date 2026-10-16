<x-layouts.app>
    {{-- Hero --}}
    <section class="relative overflow-hidden border-b border-ink-800">
        <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,rgb(45_212_191/0.14),transparent_55%)]" aria-hidden="true"></div>
        <div class="relative mx-auto grid max-w-6xl items-center gap-12 px-4 py-16 sm:px-6 md:py-24 lg:grid-cols-2 lg:px-8">
            <div>
                <p class="inline-flex items-center gap-2 rounded-full border border-ink-700 bg-ink-900 px-3 py-1 text-xs font-medium text-ink-300">
                    <span class="size-1.5 rounded-full bg-accent-400" aria-hidden="true"></span>
                    Live multiplayer MCQ competitions
                </p>
                <h1 class="mt-6 text-4xl font-bold tracking-tight text-white sm:text-5xl">
                    Think fast. Answer right.<br class="hidden sm:block">
                    <span class="text-accent-300">Climb the ranks.</span>
                </h1>
                <p class="mt-5 max-w-xl text-lg text-ink-300">
                    Join a room, face the same timed questions as every other player at the same moment, and see exactly how the results were decided.
                </p>
                <div class="mt-8 flex flex-wrap gap-3">
                    <x-button :href="route('competitions.index')" size="lg">Browse competitions</x-button>
                    <x-button :href="route('how-to-play')" variant="secondary" size="lg">How it works</x-button>
                </div>
            </div>

            {{-- Illustrative example of the question screen; not a live question --}}
            <figure class="mx-auto w-full max-w-md" aria-label="Example of the question screen">
                <x-card padding="p-6" class="border-ink-600">
                    <div class="flex items-center justify-between text-xs text-ink-400">
                        <span>Example · Question 3 of 10</span>
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-ink-800 px-2.5 py-1 font-semibold text-accent-300">
                            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg>
                            0:12
                        </span>
                    </div>
                    <div class="mt-3 h-1 overflow-hidden rounded-full bg-ink-700" aria-hidden="true"><div class="h-full w-3/5 rounded-full bg-accent-400"></div></div>
                    <p class="mt-5 font-semibold text-white">Which data structure processes items in first-in, first-out order?</p>
                    <ul class="mt-4 space-y-2 text-sm">
                        @foreach (['Stack', 'Queue', 'Binary tree', 'Hash map'] as $i => $option)
                            <li @class([
                                'flex items-center gap-3 rounded-lg border px-3 py-2.5',
                                'border-accent-400 bg-accent-400/10 text-white' => $i === 1,
                                'border-ink-700 text-ink-300' => $i !== 1,
                            ])>
                                <span class="grid size-6 place-items-center rounded-md bg-ink-800 text-xs font-semibold">{{ chr(65 + $i) }}</span>
                                {{ $option }}
                            </li>
                        @endforeach
                    </ul>
                    <figcaption class="mt-4 text-xs text-ink-400">Illustration only. Answers lock the moment you submit.</figcaption>
                </x-card>
            </figure>
        </div>
    </section>

    {{-- How it works --}}
    <section class="mx-auto max-w-6xl px-4 py-16 sm:px-6 lg:px-8" aria-labelledby="how-heading">
        <h2 id="how-heading" class="text-2xl font-bold text-white sm:text-3xl">How it works</h2>
        <ol class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['Pick a room', 'Browse open and upcoming competitions. Each room shows its entry, seats, start time and prizes up front.'],
                ['Take your seat', 'Join before registration closes. Your seat counts only once our server confirms it.'],
                ['Answer against the clock', 'Everyone gets the same question at the same moment. The server keeps the time, not your device.'],
                ['See the results', 'Scores, tie-breakers and rankings are calculated automatically and published for each room.'],
            ] as $i => [$title, $text])
                <li>
                    <x-card class="h-full">
                        <span class="grid size-9 place-items-center rounded-lg bg-accent-400/10 text-sm font-bold text-accent-300">{{ $i + 1 }}</span>
                        <h3 class="mt-4 font-semibold text-white">{{ $title }}</h3>
                        <p class="mt-2 text-sm text-ink-400">{{ $text }}</p>
                    </x-card>
                </li>
            @endforeach
        </ol>
    </section>

    {{-- Featured competitions --}}
    <section class="border-y border-ink-800 bg-ink-900/40" aria-labelledby="featured-heading">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h2 id="featured-heading" class="text-2xl font-bold text-white sm:text-3xl">Featured competitions</h2>
                    <p class="mt-2 text-ink-400">Live, open and upcoming rooms.</p>
                </div>
                <x-button :href="route('competitions.index')" variant="secondary" size="sm">View all</x-button>
            </div>

            @if ($featured->isEmpty())
                <x-empty-state title="No competitions scheduled right now" class="mt-8">
                    New rooms are published regularly. Check back soon.
                </x-empty-state>
            @else
                <div class="mt-8 grid gap-5 md:grid-cols-2 lg:grid-cols-3">
                    @foreach ($featured as $room)
                        <x-competition-card :room="$room" :categories="$categories[$room->id] ?? []" :eligibility="$eligibility[$room->id]" />
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    {{-- Fair play --}}
    <section class="mx-auto max-w-6xl px-4 py-16 sm:px-6 lg:px-8" aria-labelledby="fair-heading">
        <h2 id="fair-heading" class="text-2xl font-bold text-white sm:text-3xl">Built to be fair</h2>
        <div class="mt-8 grid gap-4 md:grid-cols-3">
            @foreach ([
                ['Server-controlled timing', 'Question windows open and close on our servers. Answers arriving after the window are not accepted.'],
                ['One answer, locked in', 'Each player can submit one answer per question. It cannot be changed or resubmitted.'],
                ['Answers stay hidden', 'Correct answers are never sent to your device during the competition; they appear with the results.'],
            ] as [$title, $text])
                <x-card>
                    <h3 class="font-semibold text-white">{{ $title }}</h3>
                    <p class="mt-2 text-sm text-ink-400">{{ $text }}</p>
                </x-card>
            @endforeach
        </div>
    </section>

    {{-- FAQ --}}
    <section class="mx-auto max-w-3xl px-4 pb-4 sm:px-6 lg:px-8" aria-labelledby="faq-heading">
        <h2 id="faq-heading" class="text-2xl font-bold text-white sm:text-3xl">Frequently asked questions</h2>
        <div class="mt-8 divide-y divide-ink-800 rounded-2xl border border-ink-700 bg-ink-900/60">
            @foreach ([
                ['Do competitions cost money to enter?', 'Only free-entry competitions are offered at the moment. Paid entry is switched off until all legal and compliance requirements are confirmed.'],
                ['How is the timer kept fair?', 'The server decides when each question opens and closes. Your on-screen countdown is a display of that server time, so a slow or altered device clock cannot buy extra time.'],
                ['Can I change my answer?', 'No. Your first submitted answer for each question is final.'],
                ['How are winners decided?', 'Correct answers earn points. Ties are broken by the total time taken on correct answers. Full details are on the How to play page.'],
                ['When are results published?', 'Results appear on the leaderboard once a competition is completed and its results have been published.'],
                ['What happens if a competition is cancelled?', 'Cancelled competitions do not count toward any ranking, and any entry fees paid are refunded to the player.'],
            ] as [$question, $answer])
                <details class="group px-5 py-4">
                    <summary class="flex items-center justify-between gap-4 font-medium text-ink-100">
                        {{ $question }}
                        <svg class="size-5 shrink-0 text-ink-400 transition-transform group-open:rotate-45" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                    </summary>
                    <p class="mt-3 text-sm text-ink-400">{{ $answer }}</p>
                </details>
            @endforeach
        </div>
    </section>
</x-layouts.app>
