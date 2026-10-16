<x-layouts.app title="Leaderboard">
    <x-page-header title="Leaderboard" eyebrow="Results">
        Results of completed competitions, shown once they have been published.
    </x-page-header>

    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6 lg:px-8">
        @if ($rooms->isEmpty())
            <x-empty-state title="No published results yet" :action="route('competitions.index')" action-label="Browse competitions">
                When a competition finishes and its results are published, the top players will appear here.
            </x-empty-state>
        @else
            <div class="grid gap-5 md:grid-cols-2">
                @foreach ($rooms as $room)
                    <x-card as="article">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h2 class="font-semibold text-white">
                                    <a href="{{ route('competitions.show', $room) }}" class="hover:text-accent-300">{{ $room->name }}</a>
                                </h2>
                                <p class="mt-1 text-xs text-ink-400">Published <x-datetime :value="$room->session->results_published_at" format="j M Y" /></p>
                            </div>
                            <x-status-badge :status="$room->status" />
                        </div>
                        @if ($room->session->results->isEmpty())
                            <p class="mt-5 text-sm text-ink-400">No ranked players.</p>
                        @else
                            <ol class="mt-5 space-y-2">
                                @foreach ($room->session->results as $result)
                                    <li class="flex items-center justify-between rounded-lg bg-ink-900 px-3 py-2 text-sm">
                                        <span class="flex items-center gap-3">
                                            <span class="w-8 font-bold text-accent-300">#{{ $result->rank }}</span>
                                            <span class="text-ink-100">{{ $result->participant->user?->publicName() ?? 'Player' }}</span>
                                        </span>
                                        <span class="font-semibold text-white">{{ number_format($result->score) }} pts</span>
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                        <a href="{{ route('competitions.show', $room) }}#results-heading" class="mt-4 inline-flex text-sm font-medium text-accent-300 hover:text-accent-200">Full results</a>
                    </x-card>
                @endforeach
            </div>
            <x-pagination :paginator="$rooms" noun="competitions" />
        @endif
    </div>
</x-layouts.app>
