@php
    $variant = match ($status) {
        \App\Enums\PaymentStatus::Succeeded => 'accent',
        \App\Enums\PaymentStatus::Failed, \App\Enums\PaymentStatus::Cancelled => 'danger',
        \App\Enums\PaymentStatus::Expired, \App\Enums\PaymentStatus::Refunded => 'warning',
        default => 'info',
    };
@endphp
<x-badge :variant="$variant">{{ $status->label() }}</x-badge>
