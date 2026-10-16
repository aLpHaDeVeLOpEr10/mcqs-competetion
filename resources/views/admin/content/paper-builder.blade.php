@php
    $archived = $paper->status === \App\Enums\QuizPaperStatus::Archived;
    $editable = ! $locked && ! $archived;
    $statusVariant = match ($paper->status) { \App\Enums\QuizPaperStatus::Published => 'accent', \App\Enums\QuizPaperStatus::Archived => 'danger', default => 'neutral' };
@endphp

<x-admin.layout :title="$paper->title" :breadcrumbs="['Quiz papers' => route('admin.papers.index'), $paper->title => route('admin.papers.show', $paper), 'Builder' => null]">
    <div class="flex flex-wrap items-center gap-3">
        <x-badge :variant="$statusVariant" dot>{{ ucfirst($paper->status->value) }}</x-badge>
        <span class="text-sm text-ink-400">{{ $items->count() }} question(s){{ $paper->target_question_count ? ' of '.$paper->target_question_count.' required' : '' }}</span>
        <x-button :href="route('admin.papers.preview', $paper)" variant="secondary" size="sm">Preview</x-button>

        @if ($paper->status === \App\Enums\QuizPaperStatus::Draft)
            <form method="POST" action="{{ route('admin.papers.publish', $paper) }}">@csrf @method('PATCH')
                <x-button type="submit" size="sm" :disabled="$problems !== []">Publish</x-button>
            </form>
        @elseif ($paper->status === \App\Enums\QuizPaperStatus::Published && ! $locked)
            <form method="POST" action="{{ route('admin.papers.unpublish', $paper) }}">@csrf @method('PATCH')
                <x-button type="submit" variant="secondary" size="sm">Return to draft</x-button>
            </form>
        @endif

        <form method="POST" action="{{ route('admin.papers.duplicate', $paper) }}">@csrf
            <x-button type="submit" variant="ghost" size="sm">Duplicate</x-button>
        </form>

        @if ($archived)
            <form method="POST" action="{{ route('admin.papers.restore', $paper) }}">@csrf @method('PATCH')
                <x-button type="submit" variant="ghost" size="sm">Restore</x-button>
            </form>
        @else
            <form method="POST" action="{{ route('admin.papers.archive', $paper) }}" data-confirm="Archive this paper? It can no longer be used for new competitions.">@csrf @method('PATCH')
                <x-button type="submit" variant="ghost" size="sm">Archive</x-button>
            </form>
        @endif
    </div>

    @if (session('problems'))
        <x-alert variant="error" title="Publishing was blocked">
            <ul class="list-disc pl-5">@foreach (session('problems') as $problem)<li>{{ $problem }}</li>@endforeach</ul>
        </x-alert>
    @endif

    @if ($locked)
        <x-alert variant="info" title="Locked">This paper is used by a published competition. Its questions, order and timings cannot change. Use <strong>Duplicate</strong> to create an editable copy.</x-alert>
    @elseif ($archived)
        <x-alert variant="info">This paper is archived. Restore it to make changes.</x-alert>
    @elseif ($problems !== [])
        <x-alert variant="warning" title="Not ready to publish">
            <ul class="list-disc pl-5">@foreach ($problems as $problem)<li>{{ $problem }}</li>@endforeach</ul>
        </x-alert>
    @else
        <x-alert variant="success">This paper passes all publishing checks.</x-alert>
    @endif

    <details class="rounded-2xl border border-ink-700 bg-ink-900/60" @if ($errors->hasAny(['title', 'default_time_limit_seconds', 'target_question_count'])) open @endif>
        <summary class="px-5 py-4 font-semibold text-white">Paper settings</summary>
        <form method="POST" action="{{ route('admin.papers.update', $paper) }}" class="space-y-5 border-t border-ink-700 px-5 py-5" data-loading novalidate>
            @csrf @method('PUT')
            @include('admin.content.partials.paper-settings', ['paper' => $paper])
            <x-button type="submit" :disabled="$archived" data-loading-text="Saving…">Save settings</x-button>
            @if ($locked)<p class="text-xs text-ink-400">Only the title and description can change while the paper is locked.</p>@endif
        </form>
    </details>

    <section aria-labelledby="items-heading">
        <h2 id="items-heading" class="text-lg font-semibold text-white">Questions in order</h2>
        @if ($items->isEmpty())
            <x-empty-state title="No questions yet" class="mt-3">Add questions from the bank below.</x-empty-state>
        @else
            <ol class="mt-3 space-y-3">
                @foreach ($items as $item)
                    <li>
                        <x-card padding="p-4">
                            <div class="flex flex-wrap items-start gap-4">
                                <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-ink-800 text-sm font-bold text-accent-300">{{ $item->position }}</span>
                                <div class="min-w-0 flex-1">
                                    <a href="{{ route('admin.questions.show', $item->question_id) }}" class="text-sm text-white hover:text-accent-300">{{ \Illuminate\Support\Str::limit($item->question->body, 140) }}</a>
                                    <p class="mt-1 text-xs text-ink-400">{{ $item->question->category->name }} · {{ ucfirst($item->question->status->value) }} · effective time {{ $item->effectiveTimeLimitSeconds() }} s</p>
                                </div>
                                @if ($editable)
                                    <div class="flex items-center gap-1">
                                        @foreach (['up' => '↑', 'down' => '↓'] as $direction => $arrow)
                                            <form method="POST" action="{{ route('admin.papers.items.move', [$paper, $item]) }}">@csrf @method('PATCH')
                                                <input type="hidden" name="direction" value="{{ $direction }}">
                                                <button type="submit" class="rounded-md px-2 py-1 text-ink-300 hover:bg-ink-800 hover:text-white disabled:opacity-30"
                                                    @disabled(($direction === 'up' && $loop->parent->first) || ($direction === 'down' && $loop->parent->last))
                                                    aria-label="Move question {{ $item->position }} {{ $direction }}">{{ $arrow }}</button>
                                            </form>
                                        @endforeach
                                        <form method="POST" action="{{ route('admin.papers.items.destroy', [$paper, $item]) }}">@csrf @method('DELETE')
                                            <button type="submit" class="rounded-md px-2 py-1 text-sm text-rose-300 hover:bg-rose-500/10" aria-label="Remove question {{ $item->position }}">Remove</button>
                                        </form>
                                    </div>
                                @endif
                            </div>
                            @if ($editable)
                                <form method="POST" action="{{ route('admin.papers.items.update', [$paper, $item]) }}" class="mt-3 flex flex-wrap items-end gap-3 border-t border-ink-800 pt-3">
                                    @csrf @method('PATCH')
                                    <label class="text-xs text-ink-400">Time override (s)
                                        <input type="number" name="time_limit_seconds" value="{{ $item->time_limit_seconds }}" min="{{ config('competition.questions.min_time_limit') }}" max="{{ config('competition.questions.max_time_limit') }}"
                                            placeholder="inherit" class="mt-1 block w-28 rounded-lg border border-ink-600 bg-ink-900 px-2 py-1.5 text-sm text-ink-100">
                                    </label>
                                    <label class="text-xs text-ink-400">Points
                                        <input type="number" name="points" value="{{ $item->points }}" min="1" max="100" required class="mt-1 block w-20 rounded-lg border border-ink-600 bg-ink-900 px-2 py-1.5 text-sm text-ink-100">
                                    </label>
                                    <x-button type="submit" variant="secondary" size="sm">Save</x-button>
                                </form>
                            @endif
                        </x-card>
                    </li>
                @endforeach
            </ol>
        @endif
    </section>

    @if ($editable)
        <section aria-labelledby="add-heading" class="space-y-3">
            <h2 id="add-heading" class="text-lg font-semibold text-white">Add questions from the bank</h2>
            <x-card padding="p-4">
                <form method="POST" action="{{ route('admin.papers.items.random', $paper) }}" class="flex flex-wrap items-end gap-3" data-loading>
                    @csrf
                    <div class="w-32"><x-form.select name="count" id="random-count" label="Random questions" :options="$randomCountOptions" :selected="old('count', 10)" required /></div>
                    <div class="min-w-48 flex-1"><x-form.select name="category" id="random-category" label="Category" placeholder="All categories" :selected="old('category', $filters->get('category'))" :options="$categoryOptions" /></div>
                    <div class="min-w-40 flex-1"><x-form.select name="difficulty" id="random-difficulty" label="Difficulty" placeholder="Any difficulty" :selected="old('difficulty')" :options="$difficultyOptions" /></div>
                    <x-button type="submit" data-loading-text="Adding…">Add random</x-button>
                </form>
                <p class="mt-2 text-xs text-ink-400">Picks active questions that are not already in this paper.</p>
            </x-card>
            <x-admin.filters :action="route('admin.papers.edit', $paper)" :filters="$filters">
                <x-form.input name="q" label="Search" :value="$filters->get('q')" placeholder="Question text" />
                <x-form.select name="category" label="Category" placeholder="All categories" :selected="$filters->get('category')" :options="$categoryOptions" />
            </x-admin.filters>

            @if ($candidates->isEmpty())
                <x-empty-state title="No more questions to add">Every matching non-archived question is already in this paper.</x-empty-state>
            @else
                <form method="POST" action="{{ route('admin.papers.items.store', $paper) }}" data-loading>
                    @csrf
                    <x-admin.table caption="Questions available to add" :columns="['', 'Question', 'Category', 'Status']">
                        @foreach ($candidates as $candidate)
                            <tr>
                                <td><input type="checkbox" name="question_ids[]" value="{{ $candidate->id }}" class="size-4 accent-accent-400" aria-label="Select question {{ $candidate->id }}"></td>
                                <td class="max-w-md text-ink-100">{{ \Illuminate\Support\Str::limit($candidate->body, 120) }}</td>
                                <td class="text-ink-300">{{ $candidate->category->name }}</td>
                                <td><x-badge>{{ ucfirst($candidate->status->value) }}</x-badge></td>
                            </tr>
                        @endforeach
                    </x-admin.table>
                    <x-button type="submit" class="mt-3" data-loading-text="Adding…">Add selected to paper</x-button>
                </form>
                <x-pagination :paginator="$candidates" noun="questions" />
            @endif
        </section>
    @endif
</x-admin.layout>
