{{-- Label + control + help/error wrapper shared by the form controls. --}}
@props(['name', 'id' => null, 'label', 'help' => null, 'required' => false, 'bag' => 'default'])

@php
    $id ??= 'field-'.$name;
    $error = $errors->getBag($bag)->first($name);
@endphp

<div {{ $attributes->class('space-y-1.5') }}>
    <label for="{{ $id }}" class="block text-sm font-medium text-ink-100">
        {{ $label }}
        @if ($required)
            <span class="text-rose-300" aria-hidden="true">*</span>
        @endif
    </label>
    {{ $slot }}
    @if ($error)
        <p id="{{ $id }}-error" class="text-sm text-rose-300">{{ $error }}</p>
    @elseif ($help)
        <p id="{{ $id }}-help" class="text-sm text-ink-400">{{ $help }}</p>
    @endif
</div>
