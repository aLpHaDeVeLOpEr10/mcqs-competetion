{{-- $options: [value => label]. $selected overrides old() for GET filter forms. --}}
@props(['name', 'id' => null, 'label', 'options' => [], 'selected' => null, 'placeholder' => null, 'help' => null, 'required' => false, 'disabled' => false, 'bag' => 'default'])

@php
    $id ??= 'field-'.$name;
    $invalid = $errors->getBag($bag)->has($name);
    $current = (string) ($selected ?? old($name));
    $describedBy = $invalid ? "{$id}-error" : ($help ? "{$id}-help" : null);
@endphp

<x-form.field :name="$name" :label="$label" :help="$help" :required="$required" :bag="$bag" :id="$id">
    <select id="{{ $id }}" name="{{ $name }}"
        @required($required) @disabled($disabled)
        @if ($invalid) aria-invalid="true" @endif
        @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->class([
            'block w-full rounded-lg border bg-ink-900 px-3 py-2.5 text-sm text-ink-100 disabled:opacity-60',
            'border-rose-400' => $invalid,
            'border-ink-600 focus:border-accent-400' => ! $invalid,
        ]) }}>
        @if ($placeholder !== null)
            <option value="">{{ $placeholder }}</option>
        @endif
        @foreach ($options as $value => $text)
            <option value="{{ $value }}" @selected($current === (string) $value)>{{ $text }}</option>
        @endforeach
    </select>
</x-form.field>
