@props(['status'])

@php
    $variant = match ($status) {
        \App\Enums\RoomStatus::Open => 'accent',
        \App\Enums\RoomStatus::Scheduled => 'info',
        \App\Enums\RoomStatus::Full => 'warning',
        \App\Enums\RoomStatus::InProgress => 'live',
        \App\Enums\RoomStatus::Cancelled, \App\Enums\RoomStatus::Refunded => 'danger',
        default => 'neutral',
    };
@endphp

<x-badge :variant="$variant" dot {{ $attributes }}>{{ $status->label() }}</x-badge>
