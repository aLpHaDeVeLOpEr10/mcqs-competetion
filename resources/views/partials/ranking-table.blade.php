{{-- Public ranking: display names, ranks, scores, times and prize entitlements only. --}}
<x-card class="mt-4 overflow-x-auto" padding="p-0">
    <table class="table-stack w-full text-left text-sm">
        <caption class="sr-only">Final ranking</caption>
        <thead class="border-b border-ink-700 text-ink-400">
            <tr>
                <th scope="col" class="px-5 py-3 font-medium">Rank</th>
                <th scope="col" class="px-5 py-3 font-medium">Player</th>
                <th scope="col" class="px-5 py-3 text-right font-medium">Correct</th>
                <th scope="col" class="px-5 py-3 text-right font-medium">Time</th>
                <th scope="col" class="px-5 py-3 text-right font-medium">Prize</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-ink-800">
            @foreach ($results as $result)
                @php($isYou = auth()->check() && (int) $result->participant->user_id === (int) auth()->id())
                <tr @class(['bg-accent-400/5' => $isYou]) @if ($isYou) aria-current="true" @endif>
                    <td data-label="Rank" class="px-5 py-3 font-semibold {{ $result->rank && $result->rank <= 3 ? 'text-accent-300' : 'text-white' }}">{{ $result->rank ? '#'.$result->rank : '—' }}</td>
                    <td data-label="Player" data-primary class="px-5 py-3 text-ink-100">{{ $result->participant->user?->publicName() ?? 'Player' }}
                        @if ($isYou)<span class="text-xs font-semibold text-accent-300">(You)</span>@endif
                        @if ($result->excluded_reason)<span class="text-xs text-ink-400">(not ranked)</span>@endif
                    </td>
                    <td data-label="Correct" class="px-5 py-3 text-right font-semibold text-white">{{ $result->correct_count }}</td>
                    <td data-label="Time" class="px-5 py-3 text-right whitespace-nowrap text-ink-300">{{ number_format($result->correct_response_time_ms / 1000, 2) }} s</td>
                    <td data-label="Prize" class="px-5 py-3 text-right whitespace-nowrap text-ink-300">{{ $result->prize_amount_minor > 0 ? \App\Support\Money::format($result->prize_amount_minor, $currency ?? 'PKR') : '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</x-card>
