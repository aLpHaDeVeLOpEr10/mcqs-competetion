<x-layouts.app :title="$room->name">
    @if (! $room->status->isTerminal())
        @push('scripts')
            @vite('resources/js/room.js')
        @endpush
    @endif
    <div hidden data-room-live data-room-id="{{ $room->id }}" data-room-status="{{ $room->status->value }}"></div>
    <div class="border-b border-ink-800 bg-gradient-to-b from-ink-900 to-ink-950">
        <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6 lg:px-8">
            <nav aria-label="Breadcrumb" class="text-sm text-ink-400">
                <ol class="flex flex-wrap items-center gap-2">
                    <li><a href="{{ route('competitions.index') }}" class="hover:text-white">Competitions</a></li>
                    <li aria-hidden="true">/</li>
                    <li aria-current="page" class="text-ink-300">{{ $room->name }}</li>
                </ol>
            </nav>
            <div class="mt-5 flex flex-wrap items-center gap-3">
                <x-status-badge :status="$room->status" />
                <x-badge>{{ $room->type->label() }}</x-badge>
                @foreach ($categories as $category)
                    <x-badge>{{ $category }}</x-badge>
                @endforeach
            </div>
            <h1 class="mt-4 text-3xl font-bold tracking-tight text-white sm:text-4xl">{{ $room->name }}</h1>
            @if ($room->description)
                <p class="mt-3 max-w-2xl text-ink-300">{{ $room->description }}</p>
            @endif
        </div>
    </div>

    <div class="mx-auto grid max-w-6xl gap-8 px-4 py-10 sm:px-6 lg:grid-cols-[1fr_22rem] lg:px-8">
        {{-- Join panel first in source order so mobile users see it immediately --}}
        <aside class="lg:order-2" aria-label="Entry">
            <x-card class="lg:sticky lg:top-24">
                <dl class="space-y-4 text-sm">
                    <div class="flex items-baseline justify-between gap-4">
                        <dt class="text-ink-400">Entry fee</dt>
                        <dd class="text-xl font-bold text-white">{{ \App\Support\Money::fee($room->entry_fee_minor, $room->currency) }}</dd>
                    </div>
                    <div>
                        <dt class="sr-only">Players</dt>
                        <dd><x-seat-meter :current="$room->participants_count" :max="$room->max_participants" /></dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-4">
                        <dt class="text-ink-400">Starts</dt>
                        <dd class="text-right text-ink-100">
                            @if ($room->scheduled_start_at)
                                <x-datetime :value="$room->scheduled_start_at" />
                            @else
                                When full
                            @endif
                        </dd>
                    </div>
                </dl>
                <p class="mt-4 text-xs text-amber-200" role="status" hidden data-room-changed>This room's status just changed. <a href="{{ route('competitions.show', $room) }}" class="underline">Refresh</a> to see whether you can still join.</p>
                <x-join-action :room="$room" :eligibility="$eligibility" :seat="$seat" :can-withdraw="$canWithdraw" size="lg" class="mt-6" />
                @if ($seat && $seat->status !== \App\Enums\ParticipantStatus::Reserved && $seat->confirmed_at)
                    @include('competitions.partials.my-entry', ['seat' => $seat, 'room' => $room])
                @endif
                @if ($seat && in_array($seat->status, \App\Broadcasting\CompetitionChannel::SEATED, true)
                    && in_array($room->status, [\App\Enums\RoomStatus::Starting, \App\Enums\RoomStatus::InProgress], true))
                    <x-button :href="route('play.show', $room)" size="lg" class="mt-3 w-full">Enter the live competition</x-button>
                @endif
            </x-card>
        </aside>

        <div class="space-y-8 lg:order-1">
            <section aria-labelledby="overview-heading">
                <h2 id="overview-heading" class="text-xl font-semibold text-white">Overview</h2>
                <x-card class="mt-4" padding="p-0">
                    <dl class="grid divide-ink-700 text-sm sm:grid-cols-2 sm:divide-x [&>div]:border-ink-700 [&>div]:p-5">
                        <div class="border-b">
                            <dt class="text-ink-400">Maximum players</dt>
                            <dd class="mt-1 font-semibold text-white">{{ number_format($room->max_participants) }}</dd>
                        </div>
                        <div class="border-b">
                            <dt class="text-ink-400">Minimum to start</dt>
                            <dd class="mt-1 font-semibold text-white">{{ number_format($room->min_participants) }}</dd>
                        </div>
                        <div class="border-b sm:border-b-0">
                            <dt class="text-ink-400">Questions</dt>
                            <dd class="mt-1 font-semibold text-white">{{ $questionCount }}</dd>
                        </div>
                        <div>
                            <dt class="text-ink-400">Registration</dt>
                            <dd class="mt-1 text-ink-100">
                                @if ($room->registration_opens_at && $room->registration_opens_at->isFuture())
                                    Opens <x-datetime :value="$room->registration_opens_at" />
                                @elseif ($room->registrationDeadline())
                                    {{ $room->registrationDeadline()->isPast() ? 'Closed' : 'Closes' }} <x-datetime :value="$room->registrationDeadline()" />
                                @else
                                    Until the competition starts
                                @endif
                            </dd>
                        </div>
                    </dl>
                </x-card>
            </section>

            <section aria-labelledby="prizes-heading">
                <h2 id="prizes-heading" class="text-xl font-semibold text-white">Prize structure</h2>
                @if ($room->hasPublishedPrizes())
                    <x-card class="mt-4 overflow-x-auto" padding="p-0">
                        <table class="w-full text-left text-sm">
                            <caption class="sr-only">Prize per finishing rank</caption>
                            <thead class="border-b border-ink-700 text-ink-400">
                                <tr>
                                    <th scope="col" class="px-5 py-3 font-medium">Rank</th>
                                    <th scope="col" class="px-5 py-3 font-medium">Prize each</th>
                                    <th scope="col" class="px-5 py-3 text-right font-medium">Tier total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-ink-800">
                                @foreach ($room->prizeTiers as $tier)
                                    <tr>
                                        <td class="px-5 py-3 font-medium text-white">{{ $tier->rank_from === $tier->rank_to ? '#'.$tier->rank_from : '#'.$tier->rank_from.'–'.$tier->rank_to }}</td>
                                        @if ($room->prize_mode === \App\Enums\PrizeMode::Percentage)
                                            <td class="px-5 py-3 text-ink-100">{{ $tier->percentLabel() }} of the pool</td>
                                            <td class="px-5 py-3 text-right text-ink-300">{{ $room->prize_pool_basis === \App\Enums\PrizePoolBasis::Guaranteed ? \App\Support\Money::format($tier->positions() * intdiv((int) $room->prize_pool_minor * (int) $tier->percent_bp, 10000), $room->currency) : '—' }}</td>
                                        @else
                                            <td class="px-5 py-3 text-ink-100">{{ \App\Support\Money::format($tier->amount_minor, $room->currency) }}</td>
                                            <td class="px-5 py-3 text-right text-ink-300">{{ \App\Support\Money::format($tier->totalAmountMinor(), $room->currency) }}</td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="border-t border-ink-700">
                                <tr>
                                    <th scope="row" colspan="2" class="px-5 py-3 font-semibold text-white">Total prizes</th>
                                    <td class="px-5 py-3 text-right font-semibold text-accent-300">{{ $room->prizeTotalMinor() === null ? 'Depends on entries' : \App\Support\Money::format($room->prizeTotalMinor(), $room->currency) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </x-card>
                    @if ($room->prize_mode === \App\Enums\PrizeMode::Percentage)
                        <p class="mt-3 text-xs text-ink-400">
                            @if ($room->prize_pool_basis === \App\Enums\PrizePoolBasis::Guaranteed)
                                Prize pool: {{ \App\Support\Money::format((int) $room->prize_pool_minor, $room->currency) }}, guaranteed.
                            @else
                                Prize pool: entry fees collected, minus refunds{{ $room->platform_fee_bp > 0 ? ', minus a '.rtrim(rtrim(number_format($room->platform_fee_bp / 100, 2), '0'), '.').'% platform fee' : '' }}. Final amounts are fixed when the competition ends.
                            @endif
                            Shares are rounded down to the paisa.
                        </p>
                    @endif
                    <p class="mt-3 text-xs text-ink-400">Tie-break: {{ $room->tie_break_policy->label() }}. Tied players share the prizes of the places they occupy equally. Prize payouts follow the <a href="{{ route('terms') }}" class="underline hover:text-white">Terms</a>.</p>
                @else
                    <x-empty-state title="No prizes for this room" class="mt-4">
                        This room is played for ranking only.
                    </x-empty-state>
                @endif
            </section>

            <section id="rules" aria-labelledby="rules-heading">
                <h2 id="rules-heading" class="text-xl font-semibold text-white">Rules at a glance</h2>
                <x-card class="mt-4">
                    <ul class="space-y-2 text-sm text-ink-300">
                        <li>Every player receives each question at the same moment; the server closes it when time runs out.</li>
                        <li>One answer per question. Your first submission is final.</li>
                        <li><strong class="text-ink-100">Start:</strong> {{ $room->type->label() }}{{ $room->type->startsWhenFull() ? ', '.$room->countdown_seconds.' seconds after the last seat is confirmed' : '' }}.</li>
                        <li><strong class="text-ink-100">If the room is not full</strong> when {{ $room->type->hasScheduledStart() ? 'the start time' : 'registration closes' }}: {{ \Illuminate\Support\Str::lcfirst($room->under_capacity_policy->label()) }}@if ($room->under_capacity_policy === \App\Enums\UnderCapacityPolicy::StartIfMinimum) (minimum {{ $room->min_participants }})@endif.</li>
                        <li><strong class="text-ink-100">Withdrawing:</strong> {{ $room->allow_withdrawal ? (config('competition.entry_fees.refund_on_withdrawal') ? 'you can leave until registration closes; any entry fee is refunded in full.' : 'you can leave until registration closes; entry fees are not refunded on withdrawal.') : 'not allowed once you join.' }}</li>
                        <li><strong class="text-ink-100">Cancellation:</strong> if the competition is cancelled, every entry fee is refunded.</li>
                    </ul>
                    <a href="{{ route('how-to-play') }}#scoring" data-modal-open="scoring-modal" class="mt-4 inline-flex text-sm font-medium text-accent-300 hover:text-accent-200">How scoring and tie-breakers work</a>
                </x-card>
                <x-modal id="scoring-modal" title="How scoring works">
                    @include('partials.scoring-rules')
                </x-modal>
            </section>

            @if ($ranking !== null)
                <section aria-labelledby="results-heading">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 id="results-heading" class="text-xl font-semibold text-white">Final results</h2>
                        <a href="{{ route('competitions.results', $room) }}" class="text-sm text-accent-300 hover:text-accent-200">Full results and your answers</a>
                    </div>
                    @if ($ranking->isEmpty())
                        <x-empty-state title="No ranked players" class="mt-4">No players finished this competition.</x-empty-state>
                    @else
                        @include('partials.ranking-table', ['results' => $ranking, 'currency' => $room->currency])
                    @endif
                </section>
            @endif
        </div>
    </div>
</x-layouts.app>
