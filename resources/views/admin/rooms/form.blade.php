@php
    $editing = $room->exists;
    $locked = $editing && ! $room->isDraft();
    $title = $editing ? 'Edit '.$room->name : 'New room';
    $local = fn ($value) => $value ? \App\Support\DisplayTime::local($value)->format('Y-m-d\TH:i') : null;
    $percentMode = ($room->prize_mode?->value ?? 'fixed') === 'percentage';
    $percentInput = fn (?int $bp) => $bp === null ? '' : rtrim(rtrim(number_format($bp / 100, 2, '.', ''), '0'), '.');
    $tiers = old('prizes', $room->prizeTiers?->map(fn ($t) => ['rank_from' => $t->rank_from, 'rank_to' => $t->rank_to,
        'amount' => $percentMode ? $percentInput($t->percent_bp) : \App\Support\Money::toInput($t->amount_minor)])->all() ?? []);
    $tiers = array_pad(array_values($tiers), max(5, count($tiers) + 1), ['rank_from' => '', 'rank_to' => '', 'amount' => '']);
    $enumOptions = fn ($enum) => collect($enum::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all();
@endphp

<x-admin.layout :title="$title" :breadcrumbs="['Rooms' => route('admin.rooms.index'), $title => null]">
    @if ($locked)
        <x-alert variant="info" title="Competition settings are locked">
            This room has been published, so players joined under its current terms. Only the title, description and category can change.
        </x-alert>
    @endif

    @if ($errors->any())
        <x-alert variant="error" title="Please fix the highlighted fields">{{ $errors->count() }} problem(s) found.</x-alert>
    @endif

    <form method="POST" action="{{ $editing ? route('admin.rooms.update', $room) : route('admin.rooms.store') }}" class="space-y-6" data-loading novalidate>
        @csrf
        @if ($editing) @method('PUT') @endif

        <x-card as="fieldset" class="space-y-5">
            <legend class="text-lg font-semibold text-white">Basics</legend>
            <x-form.input name="name" label="Title" maxlength="160" required :value="$room->name" />
            <x-form.textarea name="description" label="Description (shown publicly)" rows="2" maxlength="2000" :value="$room->description" />
            <div class="grid gap-5 sm:grid-cols-2">
                <x-form.select name="category_id" label="Category (optional)" placeholder="Derived from the paper" :options="$categories" :selected="old('category_id', $room->category_id)" />
                <x-form.select name="quiz_paper_id" label="Published quiz paper" placeholder="Choose…" required :options="$papers" :selected="old('quiz_paper_id', $room->quiz_paper_id)" :disabled="$locked" />
            </div>
        </x-card>

        @if ($locked)
            {{-- Disabled inputs are not submitted; resend current values so validation passes and nothing changes. --}}
            <input type="hidden" name="quiz_paper_id" value="{{ $room->quiz_paper_id }}">
            @foreach (['type' => $room->type->value, 'entry_fee' => \App\Support\Money::toInput($room->entry_fee_minor), 'max_participants' => $room->max_participants, 'min_participants' => $room->min_participants,
                'registration_opens_at' => $local($room->registration_opens_at), 'registration_closes_at' => $local($room->registration_closes_at), 'scheduled_start_at' => $local($room->scheduled_start_at),
                'countdown_seconds' => $room->countdown_seconds, 'question_gap_seconds' => $room->question_gap_seconds, 'tie_break_policy' => $room->tie_break_policy->value,
                'under_capacity_policy' => $room->under_capacity_policy->value, 'allow_withdrawal' => $room->allow_withdrawal ? '1' : '0',
                'prize_mode' => $room->prize_mode->value, 'prize_pool_basis' => $room->prize_pool_basis?->value, 'prize_pool' => $room->prize_pool_minor ? \App\Support\Money::toInput($room->prize_pool_minor) : '',
                'platform_fee' => $percentInput($room->platform_fee_bp ?: null), 'prize_review_required' => $room->prize_review_required ? '1' : '0'] as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            @foreach ($room->prizeTiers as $i => $tier)
                <input type="hidden" name="prizes[{{ $i }}][rank_from]" value="{{ $tier->rank_from }}">
                <input type="hidden" name="prizes[{{ $i }}][rank_to]" value="{{ $tier->rank_to }}">
                <input type="hidden" name="prizes[{{ $i }}][amount]" value="{{ $percentMode ? $percentInput($tier->percent_bp) : \App\Support\Money::toInput($tier->amount_minor) }}">
            @endforeach
        @endif

        <fieldset @disabled($locked) class="space-y-6">

            <x-card as="fieldset" class="space-y-5">
                <legend class="text-lg font-semibold text-white">Entry and capacity</legend>
                <div class="grid gap-5 sm:grid-cols-3">
                    <x-form.input name="entry_fee" label="Entry fee (Rs)" inputmode="decimal" required :value="\App\Support\Money::toInput((int) $room->entry_fee_minor)"
                        :help="config('competition.paid_entries.enabled') ? '0 for a free room.' : 'Paid entries are disabled; only 0 can be published.'" />
                    <x-form.input name="max_participants" type="number" label="Maximum players" min="2" max="{{ config('competition.rooms.max_capacity') }}" required :value="$room->max_participants" />
                    <x-form.input name="min_participants" type="number" label="Minimum players to start" min="1" required :value="$room->min_participants" />
                </div>
            </x-card>

            <x-card as="fieldset" class="space-y-5">
                <legend class="text-lg font-semibold text-white">Schedule (Pakistan time)</legend>
                <x-form.select name="type" label="Start policy" required :options="$enumOptions(\App\Enums\RoomType::class)" :selected="old('type', $room->type?->value ?? $room->type)" />
                <div class="grid gap-5 sm:grid-cols-3">
                    <x-form.input name="registration_opens_at" type="datetime-local" label="Registration opens" :value="$local($room->registration_opens_at)" help="Empty = when published." />
                    <x-form.input name="registration_closes_at" type="datetime-local" label="Registration closes" :value="$local($room->registration_closes_at)" help="Required for “starts when full”. Default: the start time." />
                    <x-form.input name="scheduled_start_at" type="datetime-local" label="Scheduled start" :value="$local($room->scheduled_start_at)" help="Required unless the room only starts when full." />
                </div>
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-form.input name="countdown_seconds" type="number" label="Countdown after filling (seconds)" min="0" max="600" required :value="$room->countdown_seconds" />
                    <x-form.input name="question_gap_seconds" type="number" label="Pause between questions (seconds)" min="0" max="60" required :value="$room->question_gap_seconds" />
                </div>
            </x-card>

            <x-card as="fieldset" class="space-y-5">
                <legend class="text-lg font-semibold text-white">Rules</legend>
                <x-form.select name="under_capacity_policy" label="If the start time or deadline arrives before the room is full" required :options="$enumOptions(\App\Enums\UnderCapacityPolicy::class)" :selected="old('under_capacity_policy', $room->under_capacity_policy?->value ?? 'start_if_minimum')" />
                <x-form.select name="tie_break_policy" label="Tie-break policy" required :options="$enumOptions(\App\Enums\TieBreakPolicy::class)" :selected="old('tie_break_policy', $room->tie_break_policy?->value ?? 'fastest_correct')" />
                <input type="hidden" name="allow_withdrawal" value="0">
                <x-form.checkbox name="allow_withdrawal" :checked="$room->allow_withdrawal ?? true">Players may withdraw until registration closes (any entry fee is refunded)</x-form.checkbox>
                <p class="text-sm text-ink-400">Cancelled competitions release every seat and refund all entry fees.</p>
            </x-card>

            <x-card as="fieldset" class="space-y-3">
                <legend class="text-lg font-semibold text-white">Prize structure</legend>
                <p class="text-sm text-ink-400">Ranks must start at 1 and continue without gaps. Leave rows empty for no prizes. The last column is per player in the tier: rupees for fixed prizes, or a percentage of the pool.</p>
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-form.select name="prize_mode" label="Prize structure" required :options="$enumOptions(\App\Enums\PrizeMode::class)" :selected="old('prize_mode', $room->prize_mode?->value ?? 'fixed')" />
                    <x-form.select name="prize_pool_basis" label="Prize pool (percentage only)" placeholder="—" :options="$enumOptions(\App\Enums\PrizePoolBasis::class)" :selected="old('prize_pool_basis', $room->prize_pool_basis?->value)" />
                    <x-form.input name="prize_pool" label="Guaranteed pool (Rs)" inputmode="decimal" :value="$room->prize_pool_minor ? \App\Support\Money::toInput($room->prize_pool_minor) : ''" help="Only for a guaranteed pool." />
                    <x-form.input name="platform_fee" label="Platform fee (% of net entry fees)" inputmode="decimal" :value="$percentInput($room->platform_fee_bp ?: null)" help="Only for a pool from entry fees. Net = fees collected minus refunds." />
                </div>
                <input type="hidden" name="prize_review_required" value="0">
                <x-form.checkbox name="prize_review_required" :checked="$room->prize_review_required ?? false">Prizes are paid only after a finance review</x-form.checkbox>
                @error('prizes')<p class="text-sm text-rose-300">{{ $message }}</p>@enderror
                @foreach ($tiers as $i => $tier)
                    <div class="grid grid-cols-3 gap-3">
                        @foreach (['rank_from' => 'From rank', 'rank_to' => 'To rank', 'amount' => 'Prize each (Rs or %)'] as $field => $label)
                            <label class="text-xs text-ink-400">{{ $label }}
                                <input type="{{ $field === 'amount' ? 'text' : 'number' }}" name="prizes[{{ $i }}][{{ $field }}]" value="{{ $tier[$field] ?? '' }}" @if ($field !== 'amount') min="1" @else inputmode="decimal" @endif
                                    @class(['mt-1 block w-full rounded-lg border bg-ink-900 px-3 py-2 text-sm text-ink-100', 'border-rose-400' => $errors->has("prizes.{$i}.{$field}"), 'border-ink-600' => ! $errors->has("prizes.{$i}.{$field}")])>
                                @error("prizes.{$i}.{$field}")<span class="mt-1 block text-rose-300">{{ $message }}</span>@enderror
                            </label>
                        @endforeach
                    </div>
                @endforeach
            </x-card>
        </fieldset>

        <div class="flex gap-3">
            <x-button type="submit" data-loading-text="Saving…">{{ $editing ? 'Save room' : 'Save draft' }}</x-button>
            <x-button :href="$editing ? route('admin.rooms.show', $room) : route('admin.rooms.index')" variant="ghost">Cancel</x-button>
        </div>
    </form>
</x-admin.layout>
