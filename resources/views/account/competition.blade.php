<x-account-layout :title="$room->name">
    @php($start = $room->starts_at ?? $room->scheduled_start_at)

    <x-card>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2">
                <x-status-badge :status="$room->status" />
                <x-badge>{{ $seat->status->label() }}</x-badge>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-button :href="route('competitions.show', $room)" variant="ghost" size="sm">Room page</x-button>
                @if (in_array($room->status, [\App\Enums\RoomStatus::Starting, \App\Enums\RoomStatus::InProgress], true))
                    <x-button :href="route('play.show', $room)" size="sm">Enter live competition</x-button>
                @endif
                @if ($published)
                    <x-button :href="route('competitions.results', $room)" variant="secondary" size="sm">Full results</x-button>
                @endif
            </div>
        </div>

        <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-ink-400">Start</dt><dd class="mt-1 text-white">@if ($start)<x-datetime :value="$start" />@else When the room fills @endif</dd></div>
            <div><dt class="text-ink-400">Players</dt><dd class="mt-1 text-white">{{ $room->participants_count }} / {{ $room->max_participants }}</dd></div>
            <div><dt class="text-ink-400">Entry fee paid</dt><dd class="mt-1 text-white">
                @if ($seat->entryLedgerTransaction)
                    {{ \App\Support\Money::format($seat->entry_fee_minor, $room->currency) }}
                    <a href="{{ route('account.transactions.show', $seat->entryLedgerTransaction->reference) }}" class="block font-mono text-xs text-accent-300 hover:text-accent-200">{{ $seat->entryLedgerTransaction->reference }}</a>
                @else
                    Free entry
                @endif
            </dd></div>
            <div><dt class="text-ink-400">Refund</dt><dd class="mt-1 text-white">
                @if ($seat->refundLedgerTransaction)
                    <span class="text-emerald-300">Refunded</span>
                    <a href="{{ route('account.transactions.show', $seat->refundLedgerTransaction->reference) }}" class="block font-mono text-xs text-accent-300 hover:text-accent-200">{{ $seat->refundLedgerTransaction->reference }}</a>
                @else
                    —
                @endif
            </dd></div>
        </dl>
        @if ($room->cancellation_reason)
            <p class="mt-4 text-sm text-ink-400">Cancelled: {{ $room->cancellation_reason }}</p>
        @endif
    </x-card>

    <section aria-labelledby="result-heading">
        <h2 id="result-heading" class="text-lg font-semibold text-white">Your result</h2>
        @if ($result)
            <x-card class="mt-3">
                <dl class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-5">
                    <div><dt class="text-ink-400">Rank</dt><dd class="mt-1 text-2xl font-bold text-white">{{ $result->rank ? '#'.$result->rank : '—' }}</dd></div>
                    <div><dt class="text-ink-400">Score</dt><dd class="mt-1 text-2xl font-bold text-white">{{ $result->score }}</dd></div>
                    <div><dt class="text-ink-400">Correct</dt><dd class="mt-1 text-2xl font-bold text-white">{{ $result->correct_count }}</dd></div>
                    <div><dt class="text-ink-400">Correct-answer time</dt><dd class="mt-1 text-2xl font-bold text-white">{{ number_format($result->correct_response_time_ms / 1000, 2) }} s</dd></div>
                    <div><dt class="text-ink-400">Prize</dt><dd class="mt-1 text-white">
                        @if ($payout && $payout->status !== \App\Enums\PayoutStatus::Cancelled)
                            <span class="text-2xl font-bold">{{ \App\Support\Money::format($payout->net_amount_minor ?? $payout->amount_minor, $room->currency) }}</span>
                            <span class="block">@include('account.partials.payout-badge', ['payout' => $payout])</span>
                            @if ($payout->ledgerTransaction)
                                <a href="{{ route('account.transactions.show', $payout->ledgerTransaction->reference) }}" class="font-mono text-xs text-accent-300 hover:text-accent-200">{{ $payout->ledgerTransaction->reference }}</a>
                            @endif
                        @else
                            <span class="text-2xl font-bold">—</span>
                        @endif
                    </dd></div>
                </dl>
                @if ($result->excluded_reason)
                    <x-alert variant="warning" class="mt-4">You were not ranked: {{ $result->excluded_reason }}.</x-alert>
                @endif
            </x-card>
        @elseif ($room->status === \App\Enums\RoomStatus::Completed || $room->status === \App\Enums\RoomStatus::InProgress)
            <x-alert variant="info" class="mt-3">Results are not published yet. Your rank, score and the correct answers appear here once they are.</x-alert>
        @else
            <p class="mt-3 text-sm text-ink-400">Results appear here after the competition.</p>
        @endif
    </section>

    @if ($review !== null)
        <section aria-labelledby="answers-heading">
            <h2 id="answers-heading" class="text-lg font-semibold text-white">Your answers</h2>
            @unless ($published)
                <p class="mt-1 text-sm text-ink-400">Correct answers are shown once results are published.</p>
            @endunless
            @if ($review->isEmpty())
                <x-empty-state title="No questions were played" class="mt-4">This competition ended before any question was shown.</x-empty-state>
            @else
                @include('partials.answer-review', ['review' => $review])
            @endif
        </section>
    @endif
</x-account-layout>
