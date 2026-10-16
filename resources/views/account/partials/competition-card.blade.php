{{-- One of the player's own competitions. Rank and prize appear only once results are published. --}}
@php
    $room = $participation->room;
    $published = $room->session?->results_published_at !== null;
    $result = $published ? $participation->result : null;
    $payout = $published ? $participation->prizePayout : null;
    $start = $room->starts_at ?? $room->scheduled_start_at;
@endphp
<x-card class="flex h-full flex-col">
    <div class="flex items-start justify-between gap-3">
        <h3 class="min-w-0 font-semibold break-words text-white">
            <a href="{{ route('account.competitions.show', $room) }}" class="hover:text-accent-300">{{ $room->name }}</a>
        </h3>
        <x-status-badge :status="$room->status" />
    </div>

    <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
        <div><dt class="text-ink-400">Entry fee</dt><dd class="text-white">{{ \App\Support\Money::fee($participation->entry_fee_minor, $room->currency) }}</dd></div>
        <div><dt class="text-ink-400">Players</dt><dd class="text-white">{{ $room->participants_count }} / {{ $room->max_participants }}</dd></div>
        <div><dt class="text-ink-400">Start</dt><dd class="text-white">@if ($start)<x-datetime :value="$start" format="j M Y, g:i A" />@else When full @endif</dd></div>
        <div><dt class="text-ink-400">Your participation</dt><dd class="text-white">{{ $participation->status->label() }}</dd></div>
        @if ($result)
            <div><dt class="text-ink-400">Result</dt><dd class="text-white">{{ $result->rank ? '#'.$result->rank : 'Not ranked' }} · {{ $result->correct_count }} correct</dd></div>
            <div><dt class="text-ink-400">Prize</dt><dd class="text-white">
                @if ($payout && $payout->status !== \App\Enums\PayoutStatus::Cancelled)
                    {{ \App\Support\Money::format($payout->net_amount_minor ?? $payout->amount_minor, $room->currency) }}
                    @include('account.partials.payout-badge', ['payout' => $payout])
                @else
                    —
                @endif
            </dd></div>
        @elseif ($room->status === \App\Enums\RoomStatus::Completed)
            <div class="col-span-2"><dt class="sr-only">Result</dt><dd class="text-ink-400">Results are not published yet.</dd></div>
        @endif
        @if ($participation->refund_ledger_transaction_id)
            <div class="col-span-2"><dt class="sr-only">Refund</dt><dd class="text-emerald-300">Entry fee refunded</dd></div>
        @endif
    </dl>

    <div class="mt-auto flex flex-wrap gap-2 pt-4">
        <x-button :href="route('account.competitions.show', $room)" variant="secondary" size="sm">Details</x-button>
        @if ($room->status === \App\Enums\RoomStatus::InProgress || $room->status === \App\Enums\RoomStatus::Starting)
            <x-button :href="route('play.show', $room)" size="sm">Enter live competition</x-button>
        @endif
        @if ($published)
            <x-button :href="route('competitions.results', $room)" variant="ghost" size="sm">Full results</x-button>
        @endif
    </div>
</x-card>
