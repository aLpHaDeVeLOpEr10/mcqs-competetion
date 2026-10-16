@props(['name', 'value' => '1', 'checked' => false])

@php
    $id = 'field-'.$name;
    $invalid = $errors->has($name);
@endphp

<div {{ $attributes->only('class')->class('space-y-1.5') }}>
    <div class="flex items-start gap-3">
        <input id="{{ $id }}" name="{{ $name }}" type="checkbox" value="{{ $value }}" @checked(old($name, $checked))
            @if ($invalid) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
            {{ $attributes->except('class')->class('mt-0.5 size-4 rounded border-ink-600 bg-ink-900 accent-accent-400') }}>
        <label for="{{ $id }}" class="text-sm text-ink-300">{{ $slot }}</label>
    </div>
    @error($name)
        <p id="{{ $id }}-error" class="text-sm text-rose-300">{{ $message }}</p>
    @enderror
</div>
