@props(['room', 'categories' => [], 'eligibility'])

<x-card as="article" padding="p-5" {{ $attributes->class('flex h-full flex-col transition-colors hover:border-ink-600') }}>
    <div class="flex items-center justify-between gap-3">
        <x-status-badge :status="$room->status" />
        <span class="text-xs text-ink-400">{{ $room->type->label() }}</span>
    </div>

    <h3 class="mt-4 text-lg leading-snug font-semibold text-white">
        <a href="{{ route('competitions.show', $room) }}" class="hover:text-accent-300">{{ $room->name }}</a>
    </h3>
    <p class="mt-1 text-sm text-ink-400">
        {{ $categories === [] ? 'General' : implode(' · ', array_slice($categories, 0, 3)) }}@if (count($categories) > 3) +{{ count($categories) - 3 }}@endif
    </p>

    <dl class="mt-5 grid grid-cols-2 gap-x-4 gap-y-4 text-sm">
        <div>
            <dt class="text-ink-400">Entry</dt>
            <dd class="mt-0.5 font-semibold text-white">{{ \App\Support\Money::fee($room->entry_fee_minor, $room->currency) }}</dd>
        </div>
        <div>
            <dt class="text-ink-400">Prizes</dt>
            <dd class="mt-0.5 font-semibold text-white">
                @if ($room->hasPublishedPrizes())
                    {{ $room->prizeTotalMinor() === null ? 'From entry fees' : \App\Support\Money::format($room->prizeTotalMinor(), $room->currency) }}
                @else
                    <span class="font-normal text-ink-300">No prizes</span>
                @endif
            </dd>
        </div>
        <div class="col-span-2">
            <dt class="text-ink-400">Starts</dt>
            <dd class="mt-0.5 text-ink-100">
                @if ($room->scheduled_start_at)
                    <x-datetime :value="$room->scheduled_start_at" />
                @else
                    When all {{ $room->max_participants }} seats are filled
                @endif
            </dd>
        </div>
        <div class="col-span-2">
            <dt class="sr-only">Players</dt>
            <dd><x-seat-meter :current="$room->participants_count" :max="$room->max_participants" /></dd>
        </div>
    </dl>

    <div class="mt-auto flex items-center gap-3 pt-6">
        <x-join-action :room="$room" :eligibility="$eligibility" size="sm" :show-message="false" class="flex-1" />
        <x-button :href="route('competitions.show', $room)" variant="ghost" size="sm" aria-label="View details for {{ $room->name }}">Details</x-button>
    </div>
</x-card>
