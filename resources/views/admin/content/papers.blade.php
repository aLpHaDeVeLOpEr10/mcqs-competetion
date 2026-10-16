<x-admin.layout title="Quiz papers" :breadcrumbs="['Quiz papers' => null]">
    @can('content.manage')
        <x-button :href="route('admin.papers.create')" size="sm">New quiz paper</x-button>
    @endcan

    <x-admin.filters :action="route('admin.papers.index')" :filters="$filters">
        <x-form.input name="q" label="Search" :value="$filters->get('q')" placeholder="Title" />
        <x-form.select name="status" label="Status" placeholder="Any status" :selected="$filters->get('status')"
            :options="collect(\App\Enums\QuizPaperStatus::cases())->mapWithKeys(fn ($s) => [$s->value => ucfirst($s->value)])->all()" />
    </x-admin.filters>

    <x-admin.table caption="Quiz papers" :columns="['Title', 'Status', 'Questions', 'Default time', 'Used by rooms']" :empty="$papers->isEmpty()" empty-title="No quiz papers">
        @foreach ($papers as $paper)
            <tr>
                <td><a href="{{ route('admin.papers.show', $paper) }}" class="font-medium text-white hover:text-accent-300">{{ $paper->title }}</a></td>
                <td><x-badge>{{ ucfirst($paper->status->value) }}</x-badge></td>
                <td class="text-ink-300">{{ $paper->items_count }}</td>
                <td class="text-ink-300">{{ $paper->default_time_limit_seconds }} s</td>
                <td class="text-ink-300">{{ $paper->rooms_count }}</td>
            </tr>
        @endforeach
    </x-admin.table>
    <x-pagination :paginator="$papers" noun="papers" />
</x-admin.layout>
