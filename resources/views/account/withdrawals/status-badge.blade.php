@php
    $variant = match ($status) {
        \App\Enums\WithdrawalStatus::Paid => 'accent',
        \App\Enums\WithdrawalStatus::Rejected, \App\Enums\WithdrawalStatus::Failed => 'danger',
        \App\Enums\WithdrawalStatus::Cancelled, \App\Enums\WithdrawalStatus::Reversed => 'warning',
        default => 'info',
    };
@endphp
<x-badge :variant="$variant">{{ $status->label() }}</x-badge>
