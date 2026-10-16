<x-admin.layout title="Questions" :breadcrumbs="['Questions' => null]">
    @can('content.manage')
        <div class="flex flex-wrap gap-2">
            <x-button :href="route('admin.questions.create')" size="sm">New question</x-button>
            <x-button :href="route('admin.questions.import')" variant="secondary" size="sm">Import CSV</x-button>
        </div>
    @endcan

    <x-admin.filters :action="route('admin.questions.index')" :filters="$filters">
        <x-form.input name="q" label="Search" :value="$filters->get('q')" placeholder="Question text" />
        <x-form.select name="category" label="Category" placeholder="All categories" :selected="$filters->get('category')" :options="$categoryOptions" />
        <x-form.select name="status" label="Status" placeholder="Any status" :selected="$filters->get('status')"
            :options="collect(\App\Enums\QuestionStatus::cases())->mapWithKeys(fn ($s) => [$s->value => ucfirst($s->value)])->all()" />
        <x-form.select name="difficulty" label="Difficulty" placeholder="Any difficulty" :selected="$filters->get('difficulty')"
            :options="collect(\App\Enums\QuestionDifficulty::cases())->mapWithKeys(fn ($d) => [$d->value => ucfirst($d->value)])->all()" />
        <x-form.select name="tag" label="Tag" placeholder="Any tag" :selected="$filters->get('tag')" :options="$tagOptions" />
    </x-admin.filters>

    <x-admin.table caption="Questions" :columns="['#', 'Question', 'Category', 'Difficulty', 'Status', 'Options']" :empty="$questions->isEmpty()" empty-title="No questions match">
        @foreach ($questions as $question)
            <tr>
                <td class="text-ink-400">{{ $question->id }}</td>
                <td class="max-w-md">
                    <a href="{{ route('admin.questions.show', $question) }}" class="text-white hover:text-accent-300">{{ \Illuminate\Support\Str::limit($question->body, 110) }}</a>
                    @if ($question->tags->isNotEmpty())
                        <p class="mt-1 text-xs text-ink-400">{{ $question->tags->pluck('name')->implode(' · ') }}</p>
                    @endif
                </td>
                <td class="text-ink-300">{{ $question->category->name }}</td>
                <td class="text-ink-300">{{ ucfirst($question->difficulty->value) }}</td>
                <td><x-badge :variant="$question->status === \App\Enums\QuestionStatus::Active ? 'accent' : 'neutral'">{{ ucfirst($question->status->value) }}</x-badge></td>
                <td class="text-ink-300">{{ $question->options_count }}</td>
            </tr>
        @endforeach
    </x-admin.table>
    <x-pagination :paginator="$questions" noun="questions" />
</x-admin.layout>
