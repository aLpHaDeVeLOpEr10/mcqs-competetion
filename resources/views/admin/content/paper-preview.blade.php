<x-admin.layout :title="'Preview: '.$paper->title" :breadcrumbs="['Quiz papers' => route('admin.papers.index'), $paper->title => route('admin.papers.show', $paper), 'Preview' => null]">
    <p class="text-sm text-ink-400">This is exactly what a competition snapshot would contain right now. Correct answers are highlighted for staff only.</p>

    @if ($problems !== [])
        <x-alert variant="warning" title="Publishing checks failed">
            <ul class="list-disc pl-5">@foreach ($problems as $problem)<li>{{ $problem }}</li>@endforeach</ul>
        </x-alert>
    @else
        <x-alert variant="success">All publishing checks pass.</x-alert>
    @endif

    @can('content.manage')
        <x-button :href="route('admin.papers.edit', $paper)" variant="secondary" size="sm">Back to builder</x-button>
    @endcan

    <ol class="space-y-4">
        @foreach ($items as $item)
            <li>
                <x-card>
                    <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-ink-400">
                        <span>Question {{ $item->position }} of {{ $items->count() }}</span>
                        <span>{{ $item->effectiveTimeLimitSeconds() }} s · {{ $item->points }} point{{ $item->points === 1 ? '' : 's' }}</span>
                    </div>
                    <p class="mt-2 font-semibold text-white">{{ $item->question->body }}</p>
                    @if ($item->question->image_path)
                        <img src="{{ route('admin.questions.image', $item->question_id) }}" alt="{{ $item->question->image_alt }}" class="mt-3 max-h-48 rounded-lg border border-ink-700">
                    @endif
                    <ul class="mt-3 grid gap-2 sm:grid-cols-2">
                        @foreach ($item->question->options as $option)
                            <li @class(['rounded-lg border px-3 py-2 text-sm', 'border-emerald-400/40 bg-emerald-400/10 text-white' => $option->is_correct, 'border-ink-700 text-ink-300' => ! $option->is_correct])>
                                <span class="font-semibold">{{ chr(64 + $option->position) }}.</span> {{ $option->body }}
                                @if ($option->is_correct)<span class="sr-only">(correct)</span>@endif
                            </li>
                        @endforeach
                    </ul>
                    @if ($item->question->explanation)
                        <p class="mt-3 text-xs text-ink-400">Explanation: {{ $item->question->explanation }}</p>
                    @endif
                </x-card>
            </li>
        @endforeach
    </ol>
</x-admin.layout>
