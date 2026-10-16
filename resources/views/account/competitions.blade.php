<x-account-layout title="My competitions">
    @php
        $labels = ['upcoming' => 'Upcoming', 'live' => 'Live', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
        $empty = [
            'upcoming' => ['Nothing coming up', 'Competitions you join will appear here until they start.'],
            'live' => ['No live competitions', 'When a competition you joined starts, it appears here.'],
            'completed' => ['No completed competitions yet', 'Finished competitions appear here with your rank and prize once results are published.'],
            'cancelled' => ['Nothing cancelled', 'Cancelled competitions and rooms you left appear here, with any entry fee refund.'],
        ];
    @endphp

    <nav aria-label="Competition status" class="-mx-1 overflow-x-auto">
        <ul class="flex min-w-max gap-1 px-1 text-sm font-medium">
            @foreach ($labels as $key => $label)
                <li>
                    <a href="{{ route('account.competitions', ['tab' => $key]) }}" @if ($tab === $key) aria-current="page" @endif
                        @class(['inline-flex items-center gap-2 rounded-lg px-3 py-2', 'bg-ink-800 text-white' => $tab === $key, 'text-ink-300 hover:bg-ink-800/60 hover:text-white' => $tab !== $key])>
                        {{ $label }}
                        <span class="rounded-full bg-ink-700 px-2 text-xs text-ink-300">{{ $counts[$key] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>

    @if ($participations->isEmpty())
        <x-empty-state :title="$empty[$tab][0]" :action="$tab === 'upcoming' ? route('competitions.index') : null" action-label="Browse competitions">
            {{ $empty[$tab][1] }}
        </x-empty-state>
    @else
        <ul class="grid gap-4 md:grid-cols-2">
            @foreach ($participations as $participation)
                <li>@include('account.partials.competition-card', ['participation' => $participation])</li>
            @endforeach
        </ul>
        <x-pagination :paginator="$participations" noun="competitions" />
    @endif
</x-account-layout>
