@props(['name', 'id' => null, 'label', 'rows' => 5, 'value' => null, 'help' => null, 'required' => false, 'disabled' => false, 'bag' => 'default'])

@php
    $id ??= 'field-'.$name;
    $invalid = $errors->getBag($bag)->has($name);
    $describedBy = $invalid ? "{$id}-error" : ($help ? "{$id}-help" : null);
@endphp

<x-form.field :name="$name" :label="$label" :help="$help" :required="$required" :bag="$bag" :id="$id">
    <textarea id="{{ $id }}" name="{{ $name }}" rows="{{ $rows }}"
        @required($required) @disabled($disabled)
        @if ($invalid) aria-invalid="true" @endif
        @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->class([
            'block w-full rounded-lg border bg-ink-900 px-3 py-2.5 text-sm text-ink-100 placeholder:text-ink-400 disabled:opacity-60',
            'border-rose-400' => $invalid,
            'border-ink-600 focus:border-accent-400' => ! $invalid,
        ]) }}>{{ old($name, $value) }}</textarea>
</x-form.field>
