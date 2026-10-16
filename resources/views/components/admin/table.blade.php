{{-- Data table shell. Pass column labels in $columns; rows go in the default slot. On small screens the table keeps a 40rem minimum and scrolls inside its card instead of squeezing columns. --}}
@props(['columns', 'caption', 'empty' => false, 'emptyTitle' => 'Nothing to show', 'emptyText' => null])

@if ($empty)
    <x-empty-state :title="$emptyTitle">{{ $emptyText }}</x-empty-state>
@else
    <x-card padding="p-0" role="region" tabindex="0" :aria-label="$caption.' (scrolls sideways on small screens)'" {{ $attributes->class('overflow-x-auto') }}>
        <table class="w-full min-w-[40rem] text-left text-sm">
            <caption class="sr-only">{{ $caption }}</caption>
            <thead class="border-b border-ink-700 text-ink-400">
                <tr>
                    @foreach ($columns as $column)
                        <th scope="col" class="px-4 py-3 font-medium whitespace-nowrap">{{ $column }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-ink-800 [&_td]:px-4 [&_td]:py-3 [&_td]:align-top">
                {{ $slot }}
            </tbody>
        </table>
    </x-card>
@endif
