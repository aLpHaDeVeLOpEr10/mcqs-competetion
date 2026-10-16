@php
    $variant = match ($status) {
        \App\Enums\PayoutStatus::Paid => 'accent',
        \App\Enums\PayoutStatus::Failed, \App\Enums\PayoutStatus::Reversed => 'danger',
        \App\Enums\PayoutStatus::Held => 'warning',
        \App\Enums\PayoutStatus::Cancelled => 'neutral',
        default => 'info',
    };
@endphp
<x-badge :variant="$variant">{{ $status->label() }}</x-badge>
