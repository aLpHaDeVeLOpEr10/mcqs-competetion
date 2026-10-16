<x-admin.layout :title="'Question #'.$question->id" :breadcrumbs="['Questions' => route('admin.questions.index'), '#'.$question->id => null]">
    @can('content.manage')
        <div class="flex flex-wrap gap-2">
            @if ($question->isArchived())
                <form method="POST" action="{{ route('admin.questions.restore', $question) }}">
                    @csrf @method('PATCH')
                    <x-button type="submit" variant="secondary" size="sm">Restore as draft</x-button>
                </form>
            @else
                <x-button :href="route('admin.questions.edit', $question)" size="sm">Edit question</x-button>
                <form method="POST" action="{{ route('admin.questions.archive', $question) }}" data-confirm="Archive this question? It will no longer be available for new papers.">
                    @csrf @method('PATCH')
                    <x-button type="submit" variant="secondary" size="sm">Archive</x-button>
                </form>
            @endif
        </div>
    @endcan

    <x-card>
        <p class="text-white">{{ $question->body }}</p>
        @if ($question->image_path)
            <img src="{{ route('admin.questions.image', $question) }}" alt="{{ $question->image_alt }}" class="mt-4 max-h-64 rounded-lg border border-ink-700">
        @endif
        <dl class="mt-4 flex flex-wrap gap-x-8 gap-y-2 text-sm">
            <div><dt class="inline text-ink-400">Category:</dt> <dd class="inline text-ink-100">{{ $question->category->name }}</dd></div>
            <div><dt class="inline text-ink-400">Difficulty:</dt> <dd class="inline text-ink-100">{{ ucfirst($question->difficulty->value) }}</dd></div>
            <div><dt class="inline text-ink-400">Status:</dt> <dd class="inline"><x-badge :variant="$question->status === \App\Enums\QuestionStatus::Active ? 'accent' : 'neutral'">{{ ucfirst($question->status->value) }}</x-badge></dd></div>
            <div><dt class="inline text-ink-400">Default time limit:</dt> <dd class="inline text-ink-100">{{ $question->default_time_limit_seconds ? $question->default_time_limit_seconds.' s' : 'Paper default' }}</dd></div>
            <div><dt class="inline text-ink-400">Tags:</dt> <dd class="inline text-ink-100">{{ $question->tags->pluck('name')->implode(', ') ?: '—' }}</dd></div>
        </dl>
    </x-card>

    <section aria-labelledby="options-heading">
        <h2 id="options-heading" class="text-lg font-semibold text-white">Options and answer key</h2>
        <p class="mt-1 text-sm text-ink-400">Visible only to staff with content permission. Never sent to players before a question closes.</p>
        <ol class="mt-3 space-y-2">
            @foreach ($question->options as $option)
                <li @class(['flex items-center gap-3 rounded-lg border px-4 py-2.5 text-sm', 'border-emerald-400/40 bg-emerald-400/10 text-white' => $option->is_correct, 'border-ink-700 text-ink-300' => ! $option->is_correct])>
                    <span class="font-semibold">{{ chr(64 + $option->position) }}.</span> {{ $option->body }}
                    @if ($option->is_correct)<x-badge variant="accent" class="ml-auto">Correct</x-badge>@endif
                </li>
            @endforeach
        </ol>
        @if ($problems = $question->integrityProblems())
            <x-alert variant="warning" class="mt-3" title="Not ready for a published paper">This question {{ implode('; ', $problems) }}.</x-alert>
        @endif
    </section>

    @if ($question->explanation)
        <x-card><p class="text-sm text-ink-300"><span class="font-semibold text-ink-100">Explanation:</span> {{ $question->explanation }}</p></x-card>
    @endif

    <section aria-labelledby="papers-heading">
        <h2 id="papers-heading" class="text-lg font-semibold text-white">Used in quiz papers</h2>
        @forelse ($papers as $paper)
            <a href="{{ route('admin.papers.show', $paper) }}" class="mt-2 block text-sm text-accent-300 hover:text-accent-200">{{ $paper->title }} <span class="text-ink-400">({{ $paper->status->value }})</span></a>
        @empty
            <p class="mt-2 text-sm text-ink-400">Not used in any paper.</p>
        @endforelse
        <p class="mt-3 text-xs text-ink-400">Competitions keep an immutable snapshot taken when they are prepared; editing this question never changes them.</p>
    </section>
</x-admin.layout>
