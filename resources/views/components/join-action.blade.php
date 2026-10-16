{{--
    Renders exactly what JoinEligibilityService decided. The join form is
    rendered only when the server says the user can join; seat state comes
    from the database, never from the client.
--}}
@props(['room', 'eligibility', 'size' => 'md', 'showMessage' => true, 'seat' => null, 'canWithdraw' => false])

@php
    $reason = $eligibility->reason;
    $messageId = 'join-message-'.$room->id;
@endphp

<div {{ $attributes->class('space-y-2') }}>
    @if ($eligibility->canJoin())
        <form method="POST" action="{{ route(\App\Services\Competition\JoinEligibilityService::JOIN_ROUTE, $room) }}" data-loading>
            @csrf
            <x-button type="submit" :size="$size" class="w-full" data-loading-text="Joining…">{{ $eligibility->label() }}</x-button>
        </form>
    @elseif ($reason === \App\Enums\JoinBlockReason::LoginRequired)
        <x-button :href="route('login')" :size="$size" class="w-full">{{ $eligibility->label() }}</x-button>
    @elseif (in_array($reason, [\App\Enums\JoinBlockReason::AlreadyJoined, \App\Enums\JoinBlockReason::SeatReserved], true))
        <p @class(['flex w-full items-center justify-center gap-2 rounded-lg border px-4 py-2.5 text-sm font-semibold',
            'border-emerald-400/30 bg-emerald-400/10 text-emerald-300' => $reason === \App\Enums\JoinBlockReason::AlreadyJoined,
            'border-amber-400/30 bg-amber-400/10 text-amber-300' => $reason === \App\Enums\JoinBlockReason::SeatReserved])>
            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M5 13l4 4L19 7" /></svg>
            {{ $eligibility->label() }}
        </p>
        @if ($reason === \App\Enums\JoinBlockReason::SeatReserved && $seat?->reserved_until)
            <p class="text-xs text-amber-200">Held until <x-datetime :value="$seat->reserved_until" format="g:i A" />.</p>
        @endif
    @else
        <x-button variant="secondary" :size="$size" class="w-full" disabled :aria-describedby="$showMessage ? $messageId : null">{{ $eligibility->label() }}</x-button>
    @endif

    @if ($showMessage && $eligibility->message())
        <p id="{{ $messageId }}" class="text-xs text-ink-400">{{ $eligibility->message() }}</p>
    @endif

    @if ($reason === \App\Enums\JoinBlockReason::InsufficientBalance)
        <a href="{{ route('account.wallet') }}" class="block text-center text-xs font-medium text-accent-300 hover:text-accent-200">Go to your wallet</a>
    @endif

    @if ($canWithdraw)
        <form method="POST" action="{{ route('competitions.withdraw', $room) }}" data-confirm="Leave this room? Your seat will be released.">
            @csrf
            <button type="submit" class="w-full text-center text-xs text-ink-400 underline underline-offset-2 hover:text-white">Withdraw from this room</button>
        </form>
    @endif
</div>
