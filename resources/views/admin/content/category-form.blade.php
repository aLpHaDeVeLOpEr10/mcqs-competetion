<x-admin.layout :title="'Edit '.$category->name" :breadcrumbs="['Categories' => route('admin.categories.index'), $category->name => null]">
    <x-card>
        <form method="POST" action="{{ route('admin.categories.update', $category) }}" class="space-y-5" data-loading novalidate>
            @csrf
            @method('PUT')
            <div class="grid gap-5 sm:grid-cols-2">
                <x-form.input name="name" label="Name" maxlength="120" required :value="$category->name" />
                <x-form.select name="parent_id" label="Parent" placeholder="None" :options="$parents" :selected="old('parent_id', $category->parent_id)" />
                <x-form.input name="sort_order" type="number" label="Sort order" min="0" max="1000" :value="$category->sort_order" />
            </div>
            <x-form.textarea name="description" label="Description" rows="2" maxlength="1000" :value="$category->description" />
            <input type="hidden" name="is_active" value="0">
            <x-form.checkbox name="is_active" :checked="$category->is_active">Active (inactive categories cannot receive new or imported questions)</x-form.checkbox>
            <div class="flex gap-3">
                <x-button type="submit" data-loading-text="Saving…">Save category</x-button>
                <x-button :href="route('admin.categories.index')" variant="ghost">Cancel</x-button>
            </div>
        </form>
    </x-card>
</x-admin.layout>
