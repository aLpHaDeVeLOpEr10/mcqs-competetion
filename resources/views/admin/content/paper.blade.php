<x-admin.layout :title="$paper->title" :breadcrumbs="['Quiz papers' => route('admin.papers.index'), $paper->title => null]">
    <div class="flex flex-wrap gap-2">
        @can('content.manage')
            <x-button :href="route('admin.papers.edit', $paper)" size="sm">Open builder</x-button>
        @endcan
        <x-button :href="route('admin.papers.preview', $paper)" variant="secondary" size="sm">Preview with answer key</x-button>
    </div>

    <x-card>
        <dl class="flex flex-wrap gap-x-8 gap-y-2 text-sm">
            <div><dt class="inline text-ink-400">Status:</dt> <dd class="inline text-ink-100">{{ ucfirst($paper->status->value) }}</dd></div>
            <div><dt class="inline text-ink-400">Default time per question:</dt> <dd class="inline text-ink-100">{{ $paper->default_time_limit_seconds }} s</dd></div>
            <div><dt class="inline text-ink-400">Used by rooms:</dt> <dd class="inline text-ink-100">{{ $paper->rooms_count }}</dd></div>
        </dl>
    </x-card>

    <x-admin.table caption="Questions in order" :columns="['Position', 'Question', 'Category', 'Time limit', 'Points']" :empty="$paper->items->isEmpty()" empty-title="This paper has no questions">
        @foreach ($paper->items as $item)
            <tr>
                <td class="text-ink-400">{{ $item->position }}</td>
                <td class="max-w-md"><a href="{{ route('admin.questions.show', $item->question_id) }}" class="text-white hover:text-accent-300">{{ \Illuminate\Support\Str::limit($item->question->body, 110) }}</a></td>
                <td class="text-ink-300">{{ $item->question->category->name }}</td>
                <td class="text-ink-300">{{ $item->effectiveTimeLimitSeconds() }} s @if ($item->time_limit_seconds === null)<span class="text-xs text-ink-400">(inherited)</span>@endif</td>
                <td class="text-ink-300">{{ $item->points }}</td>
            </tr>
        @endforeach
    </x-admin.table>
</x-admin.layout>
