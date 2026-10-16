<x-layouts.app title="How to play" description="How competitions work, how scoring and tie-breakers are decided, and the fair-play rules.">
    <x-page-header title="How to play" eyebrow="Rules">
        Everything you need to know before you take a seat.
    </x-page-header>

    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="prose-page">
            <h2 id="joining">1. Joining a competition</h2>
            <ul>
                <li>You need a player account to join. Each person may hold only one account.</li>
                <li>Every room lists its entry fee, maximum players, start time and prize structure before you join.</li>
                <li><strong>Scheduled rooms</strong> start at the published time if the minimum number of players has joined; otherwise they are cancelled.</li>
                <li><strong>Fill rooms</strong> start automatically shortly after the last seat is taken.</li>
                <li>Your seat is confirmed only when our server accepts it. A seat shown as confirmed on your screen always reflects the server.</li>
            </ul>

            <h2 id="playing">2. Answering questions</h2>
            <ul>
                <li>All players receive each question at the same moment.</li>
                <li>Each question has its own time limit, shown as a countdown. The countdown follows the server's clock; changing your device's clock has no effect.</li>
                <li>Choose one option and submit. <strong>Your first submitted answer is final.</strong></li>
                <li>Answers that reach the server after the question has closed are not accepted.</li>
                <li>Correct answers are not shown during the competition; they are revealed with the results.</li>
            </ul>

            <h2 id="scoring">3. Scoring and tie-breakers</h2>
            @include('partials.scoring-rules')

            <h2 id="results">4. Results</h2>
            <ul>
                <li>Results are calculated automatically when the last question closes.</li>
                <li>Rankings are published on the competition page and the leaderboard. Only your display name, rank and score are shown publicly.</li>
                <li>Every scoring decision is recorded so it can be reviewed if you raise a concern through the <a href="{{ route('contact') }}">contact page</a>.</li>
            </ul>

            <h2 id="fair-play">5. Fair play</h2>
            <ul>
                <li>No multiple accounts, shared accounts, bots, scripts or automated answering.</li>
                <li>No collusion with other players or sharing answers during a live competition.</li>
                <li>Players who break these rules may be disqualified and their results removed.</li>
            </ul>

            <h2 id="cancellations">6. Cancellations and refunds</h2>
            <ul>
                <li>A competition may be cancelled if too few players join or a technical fault prevents a fair game.</li>
                <li>When a competition is cancelled, every entry fee paid for it is refunded.</li>
            </ul>
        </div>

        <div class="mt-10 flex flex-wrap gap-3">
            <x-button :href="route('competitions.index')">Browse competitions</x-button>
            <x-button :href="route('responsible-play')" variant="secondary">Responsible play</x-button>
        </div>
    </div>
</x-layouts.app>
