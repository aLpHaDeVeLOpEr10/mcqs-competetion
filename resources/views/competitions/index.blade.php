<x-layouts.app title="Competitions">
    <x-page-header title="Competitions" eyebrow="Play">
        Find a room that suits you. Seat counts and status come straight from our servers.
    </x-page-header>

    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6 lg:px-8">
        <form method="GET" action="{{ route('competitions.index') }}" class="rounded-2xl border border-ink-700 bg-ink-900/60 p-4 sm:p-5" aria-label="Filter competitions" data-loading>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-[repeat(4,minmax(0,1fr))_auto]">
                <x-form.select name="status" label="Status" placeholder="Upcoming & live" :selected="$filters->status"
                    :options="[\App\Services\Competition\CompetitionFilters::STATUS_ALL => 'All statuses'] + collect($statusOptions)->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()" />
                <x-form.select name="fee" label="Entry fee" placeholder="Any" :selected="$filters->fee"
                    :options="['free' => 'Free', 'paid' => 'Paid']" />
                <x-form.select name="capacity" label="Room size" placeholder="Any size" :selected="$filters->capacity"
                    :options="['small' => 'Small (up to 10)', 'medium' => 'Medium (11–50)', 'large' => 'Large (51+)']" />
                <x-form.select name="category" label="Category" placeholder="All categories" :selected="$filters->categoryId"
                    :options="$categoryOptions->pluck('name', 'id')->all()" />
                <div class="flex items-end gap-2">
                    <x-button type="submit" class="flex-1 lg:flex-none" data-loading-text="Filtering…">Apply</x-button>
                    @if ($filters->isFiltered())
                        <x-button :href="route('competitions.index')" variant="ghost">Reset</x-button>
                    @endif
                </div>
            </div>
        </form>

        @if ($filters->ignored !== [])
            <x-alert variant="warning" class="mt-4">
                Some filters were not recognised and were ignored: {{ implode(', ', $filters->ignored) }}.
            </x-alert>
        @endif

        <div class="mt-8" aria-live="polite">
            @if ($rooms->isEmpty())
                @if ($filters->isFiltered())
                    <x-empty-state title="No competitions match these filters" :action="route('competitions.index')" action-label="Clear filters">
                        Try a different status, fee or category.
                    </x-empty-state>
                @else
                    <x-empty-state title="No upcoming competitions yet">
                        New rooms are published regularly. You can also browse
                        <a href="{{ route('competitions.index', ['status' => 'all']) }}" class="text-accent-300 underline">all past competitions</a>.
                    </x-empty-state>
                @endif
            @else
                <h2 class="sr-only">Results</h2>
                <div class="grid gap-5 md:grid-cols-2 lg:grid-cols-3">
                    @foreach ($rooms as $room)
                        <x-competition-card :room="$room" :categories="$categories[$room->id] ?? []" :eligibility="$eligibility[$room->id]" />
                    @endforeach
                </div>
                <x-pagination :paginator="$rooms" noun="competitions" />
            @endif
        </div>
    </div>
</x-layouts.app>
