{{--
    Two-step confirmation for sensitive actions. The disclosure works without
    JavaScript; the server independently requires reason + "confirm".
--}}
@props(['title', 'action', 'method' => 'POST', 'bag' => 'default', 'submitLabel', 'danger' => false])

<details @class(['group rounded-xl border', 'border-rose-400/30' => $danger, 'border-ink-700' => ! $danger]) @if ($errors->getBag($bag)->any()) open @endif>
    <summary class="flex items-center justify-between gap-3 px-4 py-3 text-sm font-semibold text-ink-100">
        {{ $title }}
        <svg class="size-4 text-ink-400 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
    </summary>
    <form method="POST" action="{{ $action }}" class="space-y-4 border-t border-ink-700 px-4 py-4" data-loading novalidate>
        @csrf
        @method($method)
        {{ $slot }}
        <x-form.input name="reason" :id="$bag.'-reason'" :label="'Reason (recorded in the audit log)'" maxlength="255" required :bag="$bag" />
        @php($confirmError = $errors->getBag($bag)->first('confirm'))
        <div class="space-y-1">
            <label class="flex items-start gap-3 text-sm text-ink-300">
                <input type="checkbox" name="confirm" value="1" required class="mt-0.5 size-4 accent-accent-400" @if ($confirmError) aria-invalid="true" @endif>
                I understand this change takes effect immediately and is recorded.
            </label>
            @if ($confirmError)
                <p class="text-sm text-rose-300">{{ $confirmError }}</p>
            @endif
        </div>
        <x-button type="submit" :variant="$danger ? 'danger' : 'primary'" data-loading-text="Applying…">{{ $submitLabel }}</x-button>
    </form>
</details>
