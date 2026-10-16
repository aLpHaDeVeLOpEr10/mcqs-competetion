{{-- A player's prize payout state, in player-facing words. --}}
@switch($payout->status)
    @case(\App\Enums\PayoutStatus::Paid) <x-badge variant="accent">Credited</x-badge> @break
    @case(\App\Enums\PayoutStatus::Held) <x-badge variant="warning">On hold</x-badge> @break
    @case(\App\Enums\PayoutStatus::Reversed) <x-badge variant="danger">Reversed</x-badge> @break
    @case(\App\Enums\PayoutStatus::Failed) <x-badge variant="warning">Delayed</x-badge> @break
    @case(\App\Enums\PayoutStatus::Cancelled) <x-badge>Cancelled</x-badge> @break
    @default <x-badge variant="info">Awaiting payment</x-badge>
@endswitch
