<x-account-layout title="Competition history">
    <p class="text-sm text-ink-400">Competitions you finished. Scores and ranks appear once results are published; the time is your total time on correct answers (the first tie-breaker).</p>

    @if ($participations->isEmpty())
        <x-empty-state title="No finished competitions yet" :action="route('competitions.index')" action-label="Browse competitions">
            Your results will be listed here after your first competition.
        </x-empty-state>
    @else
        <x-card class="overflow-x-auto" padding="p-0">
            <table class="table-stack-lg w-full lg:min-w-[44rem] text-left text-sm">
                <caption class="sr-only">Your competition history</caption>
                <thead class="border-b border-ink-700 whitespace-nowrap text-ink-400">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium">Competition</th>
                        <th scope="col" class="px-4 py-3 font-medium">Date</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">Entry fee</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">Correct</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">Score</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">Time</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">Rank</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">Prize</th>
                        <th scope="col" class="px-4 py-3 font-medium">Result</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-800">
                    @foreach ($participations as $participation)
                        @php($room = $participation->room)
                        @php($published = $room->session?->results_published_at !== null)
                        @php($result = $published ? $participation->result : null)
                        @php($payout = $published ? $participation->prizePayout : null)
                        <tr>
                            <td data-label="Competition" data-primary class="px-4 py-3"><a href="{{ route('account.competitions.show', $room) }}" class="font-medium text-white hover:text-accent-300">{{ $room->name }}</a></td>
                            <td data-label="Date" class="px-4 py-3 whitespace-nowrap text-ink-300"><x-datetime :value="$room->starts_at ?? $participation->joined_at" format="j M Y" /></td>
                            <td data-label="Entry fee" class="px-4 py-3 text-right whitespace-nowrap text-ink-300">{{ \App\Support\Money::fee($participation->entry_fee_minor, $room->currency) }}</td>
                            <td data-label="Correct" class="px-4 py-3 text-right text-ink-100">{{ $result?->correct_count ?? '—' }}</td>
                            <td data-label="Score" class="px-4 py-3 text-right text-ink-100">{{ $result?->score ?? '—' }}</td>
                            <td data-label="Time" class="px-4 py-3 text-right whitespace-nowrap text-ink-300">{{ $result ? number_format($result->correct_response_time_ms / 1000, 2).' s' : '—' }}</td>
                            <td data-label="Rank" class="px-4 py-3 text-right font-semibold text-white">{{ $result?->rank ? '#'.$result->rank : '—' }}</td>
                            <td data-label="Prize" class="px-4 py-3 text-right whitespace-nowrap text-ink-100">
                                {{ $payout && $payout->status !== \App\Enums\PayoutStatus::Cancelled ? \App\Support\Money::format($payout->net_amount_minor ?? $payout->amount_minor, $room->currency) : '—' }}
                            </td>
                            <td data-label="Result" class="px-4 py-3">
                                @if (! $published)
                                    <x-badge>Awaiting results</x-badge>
                                @elseif ($participation->status === \App\Enums\ParticipantStatus::Disqualified)
                                    <x-badge variant="danger">Disqualified</x-badge>
                                @elseif ($payout && $payout->status !== \App\Enums\PayoutStatus::Cancelled)
                                    @include('account.partials.payout-badge', ['payout' => $payout])
                                @else
                                    <x-badge variant="neutral">Final</x-badge>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-card>
        <x-pagination :paginator="$participations" noun="competitions" />
    @endif
</x-account-layout>
