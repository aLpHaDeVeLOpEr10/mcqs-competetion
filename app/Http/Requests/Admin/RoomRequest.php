<?php

namespace App\Http\Requests\Admin;

use App\Enums\PrizeMode;
use App\Enums\PrizePoolBasis;
use App\Enums\QuizPaperStatus;
use App\Enums\RoomType;
use App\Enums\TieBreakPolicy;
use App\Enums\UnderCapacityPolicy;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Room settings form. Times are entered in the display timezone (PKT) and
 * stored in UTC; money is entered in rupees and stored in paisa.
 * Cross-field rules that depend on "now" are checked at publish time by
 * RoomConfigValidator, so drafts can be saved incomplete.
 */
class RoomRequest extends FormRequest
{
    /** Rupees with optional thousands separators and up to 2 decimals: 1000, 1,000, 250.50 */
    public const AMOUNT_PATTERN = '/^(\d{1,9}|\d{1,3}(,\d{3}){1,2})(\.\d{1,2})?$/';

    /** A percentage with up to 2 decimals: 50, 12.5, 0.25 */
    public const PERCENT_PATTERN = '/^\d{1,3}(\.\d{1,2})?$/';

    public function authorize(): bool
    {
        return $this->user()->can('rooms.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $max = (int) config('competition.rooms.max_capacity');

        return [
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'quiz_paper_id' => ['required', 'integer', Rule::exists('quiz_papers', 'id')->where('status', QuizPaperStatus::Published->value)->whereNull('deleted_at')],
            'type' => ['required', Rule::enum(RoomType::class)],
            'entry_fee' => ['required', 'string', 'max:20', 'regex:'.self::AMOUNT_PATTERN],
            'max_participants' => ['required', 'integer', 'between:2,'.$max],
            'min_participants' => ['required', 'integer', 'min:1', 'lte:max_participants'],
            'registration_opens_at' => ['nullable', 'date'],
            'registration_closes_at' => ['nullable', 'date'],
            'scheduled_start_at' => ['nullable', 'date'],
            'countdown_seconds' => ['required', 'integer', 'between:0,600'],
            'question_gap_seconds' => ['required', 'integer', 'between:0,60'],
            'tie_break_policy' => ['required', Rule::enum(TieBreakPolicy::class)],
            'under_capacity_policy' => ['required', Rule::enum(UnderCapacityPolicy::class)],
            'allow_withdrawal' => ['nullable', 'boolean'],
            'prizes' => ['nullable', 'array', 'max:'.config('competition.rooms.max_prize_tiers')],
            'prizes.*.rank_from' => ['nullable', 'integer', 'min:1'],
            'prizes.*.rank_to' => ['nullable', 'integer', 'min:1'],
            'prizes.*.amount' => ['nullable', 'string', 'max:20', 'regex:'.self::AMOUNT_PATTERN],
            'prize_mode' => ['nullable', Rule::enum(PrizeMode::class)],
            'prize_pool_basis' => ['nullable', 'required_if:prize_mode,percentage', Rule::enum(PrizePoolBasis::class)],
            'prize_pool' => ['nullable', 'required_if:prize_pool_basis,guaranteed', 'string', 'max:20', 'regex:'.self::AMOUNT_PATTERN],
            'platform_fee' => ['nullable', 'string', 'regex:'.self::PERCENT_PATTERN],
            'prize_review_required' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quiz_paper_id.exists' => 'Choose a published quiz paper.',
            'entry_fee.regex' => 'Enter the fee in rupees, e.g. 100 or 99.50.',
            'prizes.*.amount.regex' => 'Enter prize amounts in rupees, e.g. 500 or 250.50.',
            'min_participants.lte' => 'The minimum cannot exceed the maximum.',
            'platform_fee.regex' => 'Enter the platform fee as a percentage, e.g. 10 or 12.5.',
            'prize_pool_basis.required_if' => 'Choose where the percentage prize pool comes from.',
            'prize_pool.required_if' => 'Enter the guaranteed prize pool in rupees.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ((array) $this->input('prizes', []) as $i => $row) {
                $filled = array_filter([$row['rank_from'] ?? null, $row['rank_to'] ?? null, $row['amount'] ?? null], fn ($v) => $v !== null && $v !== '');
                if ($filled !== [] && count($filled) < 3) {
                    $validator->errors()->add("prizes.{$i}.amount", 'Fill in all three prize fields, or leave the row empty.');
                }
                $percent = (string) ($row['amount'] ?? '');
                if ($this->input('prize_mode') === PrizeMode::Percentage->value && $percent !== ''
                    && (! preg_match(self::PERCENT_PATTERN, $percent) || self::basisPoints($percent) < 1 || self::basisPoints($percent) > 10000)) {
                    $validator->errors()->add("prizes.{$i}.amount", 'Enter a percentage of the pool between 0.01 and 100.');
                }
                if (isset($row['rank_from'], $row['rank_to']) && $row['rank_from'] !== '' && $row['rank_to'] !== '' && (int) $row['rank_to'] < (int) $row['rank_from']) {
                    $validator->errors()->add("prizes.{$i}.rank_to", '"To" rank cannot be lower than "from" rank.');
                }
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function roomData(): array
    {
        $data = $this->validated();
        $percentage = ($data['prize_mode'] ?? null) === PrizeMode::Percentage->value;

        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'category_id' => $data['category_id'] ?? null,
            'quiz_paper_id' => (int) $data['quiz_paper_id'],
            'type' => $data['type'],
            'entry_fee_minor' => Money::parse($data['entry_fee']),
            'currency' => config('competition.currency.code'),
            'max_participants' => (int) $data['max_participants'],
            'min_participants' => (int) $data['min_participants'],
            'registration_opens_at' => $this->utc($data['registration_opens_at'] ?? null),
            'registration_closes_at' => $this->utc($data['registration_closes_at'] ?? null),
            'scheduled_start_at' => $data['type'] === RoomType::Fill->value ? null : $this->utc($data['scheduled_start_at'] ?? null),
            'countdown_seconds' => (int) $data['countdown_seconds'],
            'question_gap_seconds' => (int) $data['question_gap_seconds'],
            'tie_break_policy' => $data['tie_break_policy'],
            'under_capacity_policy' => $data['under_capacity_policy'],
            'allow_withdrawal' => $this->boolean('allow_withdrawal'),
            'prize_mode' => $data['prize_mode'] ?? PrizeMode::Fixed->value,
            'prize_pool_basis' => $percentage ? ($data['prize_pool_basis'] ?? null) : null,
            'prize_pool_minor' => $percentage && ($data['prize_pool_basis'] ?? null) === PrizePoolBasis::Guaranteed->value && filled($data['prize_pool'] ?? null)
                ? Money::parse($data['prize_pool']) : null,
            'platform_fee_bp' => $percentage && ($data['prize_pool_basis'] ?? null) === PrizePoolBasis::EntryFees->value && filled($data['platform_fee'] ?? null)
                ? self::basisPoints($data['platform_fee']) : 0,
            'prize_review_required' => $this->boolean('prize_review_required'),
        ];
    }

    /**
     * @return list<array{rank_from: int, rank_to: int, amount_minor: int}>
     */
    public function prizeTiers(): array
    {
        return collect($this->validated('prizes') ?? [])
            ->filter(fn ($row) => ($row['amount'] ?? '') !== '' && ($row['rank_from'] ?? '') !== '')
            ->map(fn ($row) => [
                'rank_from' => (int) $row['rank_from'],
                'rank_to' => (int) $row['rank_to'],
            ] + ($this->validated('prize_mode') === PrizeMode::Percentage->value
                ? ['amount_minor' => 0, 'percent_bp' => self::basisPoints($row['amount'])]
                : ['amount_minor' => Money::parse($row['amount']), 'percent_bp' => null]))
            ->sortBy('rank_from')
            ->values()
            ->all();
    }

    /**
     * "12.5" (percent) → 1250 basis points, using string arithmetic only.
     */
    public static function basisPoints(string $percent): int
    {
        [$whole, $fraction] = array_pad(explode('.', trim($percent), 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    protected function utc(?string $local): ?CarbonImmutable
    {
        return $local ? CarbonImmutable::parse($local, config('competition.display_timezone'))->utc() : null;
    }
}
