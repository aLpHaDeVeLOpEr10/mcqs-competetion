<x-admin.layout title="New quiz paper" :breadcrumbs="['Quiz papers' => route('admin.papers.index'), 'New' => null]">
    <x-card>
        <form method="POST" action="{{ route('admin.papers.store') }}" class="space-y-5" data-loading novalidate>
            @csrf
            @include('admin.content.partials.paper-settings', ['paper' => $paper])
            <div class="flex gap-3">
                <x-button type="submit" data-loading-text="Creating…">Create and add questions</x-button>
                <x-button :href="route('admin.papers.index')" variant="ghost">Cancel</x-button>
            </div>
        </form>
    </x-card>
</x-admin.layout>
