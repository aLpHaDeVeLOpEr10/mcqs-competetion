@props(['name', 'id' => null, 'label', 'type' => 'text', 'value' => null, 'help' => null, 'required' => false, 'disabled' => false, 'bag' => 'default'])

@php
    $id ??= 'field-'.$name;
    $invalid = $errors->getBag($bag)->has($name);
    $describedBy = $invalid ? "{$id}-error" : ($help ? "{$id}-help" : null);
    // Passwords are never echoed back into the page.
    $current = $type === 'password' ? null : old($name, $value);
@endphp

<x-form.field :name="$name" :label="$label" :help="$help" :required="$required" :bag="$bag" :id="$id">
    <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}" @if ($current !== null) value="{{ $current }}" @endif
        @required($required) @disabled($disabled)
        @if ($invalid) aria-invalid="true" @endif
        @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->class([
            'block w-full rounded-lg border bg-ink-900 px-3 py-2.5 text-sm text-ink-100 placeholder:text-ink-400 disabled:opacity-60',
            'border-rose-400' => $invalid,
            'border-ink-600 focus:border-accent-400' => ! $invalid,
        ]) }}>
</x-form.field>
