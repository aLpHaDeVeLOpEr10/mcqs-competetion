@php
    $editing = $question->exists;
    $title = $editing ? 'Edit question #'.$question->id : 'New question';
    $max = (int) config('competition.questions.max_options');
    $oldOptions = old('options', $options);
    $oldOptions = array_pad(array_values((array) $oldOptions), $max, '');
    $selected = (int) old('correct', $correct);
@endphp

<x-admin.layout :title="$title" :breadcrumbs="['Questions' => route('admin.questions.index'), $title => null]">
    @if ($editing && ($lockedPapers ?? collect())->isNotEmpty())
        <x-alert variant="info" title="Used by published competitions">
            This question is in {{ $lockedPapers->count() }} locked paper(s). Competitions that have already been prepared keep their own snapshot, so your changes only affect future snapshots.
        </x-alert>
    @endif

    @if ($errors->any())
        <x-alert variant="error" title="Please fix the highlighted fields">{{ $errors->count() }} problem(s) found.</x-alert>
    @endif

    <form method="POST" action="{{ $editing ? route('admin.questions.update', $question) : route('admin.questions.store') }}" enctype="multipart/form-data" class="space-y-6" data-loading novalidate>
        @csrf
        @if ($editing) @method('PUT') @endif

        <x-card class="space-y-5">
            <x-form.textarea name="body" label="Question text" rows="3" maxlength="2000" required :value="$question->body" />
            <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                <x-form.select name="category_id" label="Category" required placeholder="Choose…" :options="$categories" :selected="old('category_id', $question->category_id)" />
                <x-form.select name="difficulty" label="Difficulty" required :selected="old('difficulty', $question->difficulty?->value ?? $question->difficulty)"
                    :options="collect(\App\Enums\QuestionDifficulty::cases())->mapWithKeys(fn ($d) => [$d->value => ucfirst($d->value)])->all()" />
                <x-form.select name="status" label="Status" required :selected="old('status', $question->status?->value ?? $question->status)"
                    :options="['draft' => 'Draft', 'active' => 'Active']" help="Only active questions can be published in a paper." />
                <x-form.input name="default_time_limit_seconds" type="number" label="Time limit (seconds)" :value="$question->default_time_limit_seconds"
                    min="{{ config('competition.questions.min_time_limit') }}" max="{{ config('competition.questions.max_time_limit') }}" help="Leave empty to use the paper default." />
            </div>
        </x-card>

        <x-card as="fieldset" class="space-y-3">
            <legend class="text-lg font-semibold text-white">Answer options</legend>
            <p class="text-sm text-ink-400">Fill in {{ config('competition.questions.min_options') }}–{{ $max }} options and mark exactly one as correct. Empty options are ignored.</p>
            @error('options')<p class="text-sm text-rose-300">{{ $message }}</p>@enderror
            @error('correct')<p class="text-sm text-rose-300">{{ $message }}</p>@enderror
            @foreach (range(0, $max - 1) as $i)
                <div class="flex items-center gap-3">
                    <label class="flex shrink-0 items-center gap-2 text-sm text-ink-300">
                        <input type="radio" name="correct" value="{{ $i }}" @checked($selected === $i) class="size-4 accent-accent-400" aria-label="Option {{ chr(65 + $i) }} is correct">
                        <span class="w-4 font-semibold">{{ chr(65 + $i) }}</span>
                    </label>
                    <input type="text" name="options[{{ $i }}]" value="{{ $oldOptions[$i] ?? '' }}" maxlength="500" aria-label="Option {{ chr(65 + $i) }}"
                        @class(['block w-full rounded-lg border bg-ink-900 px-3 py-2 text-sm text-ink-100', 'border-rose-400' => $errors->has('options.'.$i), 'border-ink-600 focus:border-accent-400' => ! $errors->has('options.'.$i)])>
                </div>
            @endforeach
        </x-card>

        <x-card class="space-y-5">
            <x-form.textarea name="explanation" label="Explanation (shown with the results)" rows="2" maxlength="2000" :value="$question->explanation" />
            <x-form.input name="tags" label="Tags" :value="$editing ? $question->tags->pluck('name')->implode(', ') : ''" maxlength="255" help="Separate with commas, e.g. geography, capitals." />

            <div class="grid gap-5 sm:grid-cols-2">
                <x-form.input name="image" type="file" label="Image (optional)" accept="image/jpeg,image/png,image/webp"
                    help="JPEG, PNG or WebP, up to {{ config('competition.questions.image_max_kb') / 1024 }} MB. It is re-encoded and stored privately." />
                <x-form.input name="image_alt" label="Image description (for screen readers)" :value="$question->image_alt" maxlength="255" />
            </div>
            @if ($editing && $question->image_path)
                <div class="flex items-center gap-4">
                    <img src="{{ route('admin.questions.image', $question) }}" alt="{{ $question->image_alt }}" class="h-24 rounded-lg border border-ink-700">
                    <x-form.checkbox name="remove_image">Remove the current image</x-form.checkbox>
                </div>
            @endif
        </x-card>

        <div class="flex gap-3">
            <x-button type="submit" data-loading-text="Saving…">{{ $editing ? 'Save changes' : 'Create question' }}</x-button>
            <x-button :href="$editing ? route('admin.questions.show', $question) : route('admin.questions.index')" variant="ghost">Cancel</x-button>
        </div>
    </form>
</x-admin.layout>
