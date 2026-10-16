{{--
    A player's own answers (App\Services\Results\AnswerReview). Correct answers and
    explanations appear only when the review was built with reveal = true.
--}}
<ol class="mt-4 space-y-3">
    @foreach ($review as $item)
        @php($answer = $item['answer'])
        @php($time = $answer ? number_format($answer->response_time_ms / 1000, 2).' s' : null)
        <li>
            <x-card padding="p-4">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <p class="min-w-0 font-medium break-words text-white">{{ $item['question']->position }}. {{ $item['question']->body }}</p>
                    @switch($item['outcome'])
                        @case('voided') <x-badge variant="warning">Voided — not scored</x-badge> @break
                        @case('unanswered') <x-badge>Not answered</x-badge> @break
                        @case('correct') <x-badge variant="accent">Correct · {{ $time }}</x-badge> @break
                        @case('incorrect') <x-badge variant="danger">Incorrect · {{ $time }}</x-badge> @break
                        @default <x-badge variant="info">Submitted · {{ $time }}</x-badge>
                    @endswitch
                </div>
                @if ($item['correct'])
                    <p class="mt-2 text-sm text-ink-300">Correct answer: <span class="text-white">{{ $item['correct']->body }}</span>
                        @if ($answer && $item['outcome'] !== 'correct')
                            · Your answer: {{ $item['chosen']?->body }}
                        @endif
                    </p>
                    @if ($item['question']->explanation)
                        <p class="mt-1 text-xs text-ink-400">{{ $item['question']->explanation }}</p>
                    @endif
                @elseif ($answer)
                    <p class="mt-2 text-sm text-ink-300">Your answer: <span class="text-white">{{ $item['chosen']?->body }}</span></p>
                @endif
            </x-card>
        </li>
    @endforeach
</ol>
