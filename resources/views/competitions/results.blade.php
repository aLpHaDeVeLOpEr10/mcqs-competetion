<x-layouts.app :title="'Results: '.$room->name">
    <x-page-header :title="$room->name" eyebrow="Final results">
        <span>Published <x-datetime :value="$session->results_published_at" /></span>
        @if ($version && $version->version > 1)
            Results were corrected after review (version {{ $version->version }}).
        @endif
    </x-page-header>

    <div class="mx-auto max-w-6xl space-y-8 px-4 py-10 sm:px-6 lg:px-8">
        @if ($myResult)
            <x-card as="section" aria-labelledby="mine-heading">
                <h2 id="mine-heading" class="text-lg font-semibold text-white">Your result</h2>
                <dl class="mt-3 grid gap-4 text-sm sm:grid-cols-4">
                    <div><dt class="text-ink-400">Rank</dt><dd class="mt-1 text-2xl font-bold text-white">{{ $myResult->rank ? '#'.$myResult->rank : '—' }}</dd></div>
                    <div><dt class="text-ink-400">Correct answers</dt><dd class="mt-1 text-2xl font-bold text-white">{{ $myResult->correct_count }}</dd></div>
                    <div><dt class="text-ink-400">Correct-answer time</dt><dd class="mt-1 text-2xl font-bold text-white">{{ number_format($myResult->correct_response_time_ms / 1000, 2) }} s</dd></div>
                    <div><dt class="text-ink-400">Prize entitlement</dt><dd class="mt-1 text-2xl font-bold text-white">{{ $myResult->prize_amount_minor > 0 ? \App\Support\Money::format($myResult->prize_amount_minor, $room->currency) : '—' }}</dd></div>
                </dl>
                @if ($myResult->excluded_reason)
                    <x-alert variant="warning" class="mt-4">You were not ranked: {{ $myResult->excluded_reason }}.</x-alert>
                @endif
                @if ($myPayout && $myPayout->status !== \App\Enums\PayoutStatus::Cancelled)
                    <div class="mt-4 rounded-lg border border-ink-700 p-3 text-sm" role="status">
                        <span class="text-ink-400">Prize payout:</span>
                        @switch($myPayout->status)
                            @case(\App\Enums\PayoutStatus::Paid)
                                <span class="font-semibold text-emerald-300">Credited to your wallet</span>
                                {{ \App\Support\Money::format((int) $myPayout->net_amount_minor, $room->currency) }}
                                @if ($myPayout->tax_withheld_minor > 0)
                                    (after {{ \App\Support\Money::format($myPayout->tax_withheld_minor, $room->currency) }} withheld tax)
                                @endif
                                · <a href="{{ route('account.transactions.show', $myPayout->ledgerTransaction->reference) }}" class="font-mono text-xs text-accent-300 hover:text-accent-200">{{ $myPayout->ledgerTransaction->reference }}</a>
                                @break
                            @case(\App\Enums\PayoutStatus::Held)
                                <span class="font-semibold text-amber-300">On hold</span> — {{ str_starts_with((string) $myPayout->status_reason, \App\Services\Prizes\PrizeDistributionService::MANUAL_HOLD) ? 'under review by our team.' : $myPayout->status_reason }}
                                @break
                            @case(\App\Enums\PayoutStatus::Reversed)
                                <span class="font-semibold text-rose-300">Reversed</span> — please contact support.
                                @break
                            @case(\App\Enums\PayoutStatus::Failed)
                                <span class="font-semibold text-rose-300">Delayed</span> — our team has been alerted and will retry.
                                @break
                            @default
                                <span class="font-semibold text-white">{{ $myPayout->status === \App\Enums\PayoutStatus::Processing ? 'Being credited' : 'Awaiting payment' }}</span>
                        @endswitch
                    </div>
                @endif
            </x-card>
        @endif

        <section aria-labelledby="ranking-heading">
            <h2 id="ranking-heading" class="text-xl font-semibold text-white">Ranking</h2>
            <p class="mt-1 text-sm text-ink-400">
                One point per correct answer. Equal scores: lower total time on correct answers wins{{ ($session->config['tie_break_policy'] ?? 'fastest_correct') === 'fastest_correct' ? ', then the earlier final correct answer' : '' }}. Players still equal share the rank and split its prizes.
            </p>
            @include('partials.ranking-table', ['results' => $ranking, 'currency' => $room->currency])
        </section>

        @if ($review)
            <section aria-labelledby="review-heading">
                <h2 id="review-heading" class="text-xl font-semibold text-white">Your answers</h2>
                @include('partials.answer-review', ['review' => $review])
            </section>
        @endif

        @if ($seat && in_array($seat->status, [\App\Enums\ParticipantStatus::Finished, \App\Enums\ParticipantStatus::Disqualified], true))
            <x-card as="section" aria-labelledby="dispute-heading">
                <h2 id="dispute-heading" class="text-lg font-semibold text-white">Dispute these results</h2>
                @if ($openDispute)
                    <p class="mt-2 text-sm text-ink-300">Your dispute is being reviewed.</p>
                @else
                    <p class="mt-1 text-sm text-ink-400">If you believe a question or result is wrong, explain why within {{ config('competition.results.dispute_window_days') }} days of publication.</p>
                    <form method="POST" action="{{ route('competitions.disputes.store', $room) }}" class="mt-4 space-y-4" data-loading novalidate>
                        @csrf
                        <x-form.textarea name="message" label="What is wrong?" rows="3" maxlength="2000" required />
                        <x-button type="submit" variant="secondary" data-loading-text="Sending…">Send dispute</x-button>
                    </form>
                @endif
            </x-card>
        @endif
    </div>
</x-layouts.app>
