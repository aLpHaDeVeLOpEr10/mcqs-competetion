<x-admin.layout title="Categories" :breadcrumbs="['Categories' => null]">
    @can('content.manage')
        <x-card as="section" aria-labelledby="new-category-heading">
            <h2 id="new-category-heading" class="font-semibold text-white">New category</h2>
            <form method="POST" action="{{ route('admin.categories.store') }}" class="mt-4 grid gap-4 sm:grid-cols-[1fr_1fr_8rem_auto] sm:items-end" data-loading novalidate>
                @csrf
                <x-form.input name="name" label="Name" maxlength="120" required />
                <x-form.select name="parent_id" label="Parent (optional)" placeholder="None" :options="$parentOptions" />
                <x-form.input name="sort_order" type="number" label="Sort order" min="0" max="1000" :value="0" />
                <input type="hidden" name="is_active" value="1">
                <x-button type="submit" data-loading-text="Saving…">Add</x-button>
            </form>
        </x-card>
    @endcan

    <x-admin.filters :action="route('admin.categories.index')" :filters="$filters">
        <x-form.input name="q" label="Search" :value="$filters->get('q')" placeholder="Name or slug" />
    </x-admin.filters>

    <x-admin.table caption="Categories" :columns="['Name', 'Slug', 'Parent', 'Questions', 'Active', '']" :empty="$categories->isEmpty()" empty-title="No categories">
        @foreach ($categories as $category)
            <tr>
                <td class="font-medium text-white">{{ $category->name }}</td>
                <td class="font-mono text-xs text-ink-400">{{ $category->slug }}</td>
                <td class="text-ink-300">{{ $category->parent?->name ?? '—' }}</td>
                <td><a href="{{ route('admin.questions.index', ['category' => $category->id]) }}" class="text-accent-300 hover:text-accent-200">{{ $category->questions_count }}</a></td>
                <td class="text-ink-300">{{ $category->is_active ? 'Yes' : 'No' }}</td>
                <td class="text-right">
                    @can('content.manage')
                        <a href="{{ route('admin.categories.edit', $category) }}" class="text-sm text-accent-300 hover:text-accent-200">Edit</a>
                    @endcan
                </td>
            </tr>
        @endforeach
    </x-admin.table>
    <x-pagination :paginator="$categories" noun="categories" />
</x-admin.layout>
