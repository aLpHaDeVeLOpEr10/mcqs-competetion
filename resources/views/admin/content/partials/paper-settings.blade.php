{{-- Structural fields are read-only (still submitted unchanged) while the paper is locked. --}}
@php($structureLocked = $locked ?? false)
<x-form.input name="title" label="Title" maxlength="160" required :value="$paper->title" />
<x-form.textarea name="description" label="Description (internal)" rows="2" maxlength="2000" :value="$paper->description" />
<div class="grid gap-5 sm:grid-cols-2">
    <x-form.input name="default_time_limit_seconds" type="number" label="Default time per question (seconds)" required :value="$paper->default_time_limit_seconds"
        min="{{ config('competition.questions.min_time_limit') }}" max="{{ config('competition.questions.max_time_limit') }}" :readonly="$structureLocked"
        help="Used when neither the paper item nor the question sets its own limit." />
    <x-form.input name="target_question_count" type="number" label="Total number of questions" :value="$paper->target_question_count"
        min="1" max="{{ config('competition.papers.max_questions') }}" :readonly="$structureLocked"
        help="Optional. When set, the paper can only be published with exactly this many questions (minimum {{ config('competition.papers.min_questions') }})." />
</div>
