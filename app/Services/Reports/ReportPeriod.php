<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A reporting window. Presets and custom dates are interpreted as whole days
 * in the display timezone (PKT) and converted to a UTC half-open interval
 * [from, to) that is compared with the UTC timestamps in the database.
 */
final class ReportPeriod
{
    public const PRESETS = ['today' => 'Today', '7d' => 'Last 7 days', '30d' => 'Last 30 days', 'custom' => 'Custom range'];

    public const MAX_DAYS = 366;

    private function __construct(
        public readonly string $preset,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly CarbonImmutable $firstDay,
        public readonly CarbonImmutable $lastDay,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return self::fromArray($request->only(['period', 'from', 'to']));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $data = Validator::make($input, [
            'period' => ['nullable', 'in:'.implode(',', array_keys(self::PRESETS))],
            'from' => ['nullable', 'required_if:period,custom', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_if:period,custom', 'date_format:Y-m-d', 'after_or_equal:from'],
        ])->validate();

        $tz = config('competition.display_timezone');
        $today = CarbonImmutable::now($tz)->startOfDay();
        $preset = $data['period'] ?? '30d';

        [$first, $last] = match ($preset) {
            'today' => [$today, $today],
            '7d' => [$today->subDays(6), $today],
            '30d' => [$today->subDays(29), $today],
            'custom' => [CarbonImmutable::createFromFormat('!Y-m-d', $data['from'], $tz), CarbonImmutable::createFromFormat('!Y-m-d', $data['to'], $tz)],
        };

        if ($first->diffInDays($last) + 1 > self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => 'Choose a range of at most '.self::MAX_DAYS.' days.']);
        }

        return new self($preset, $first->utc(), $last->addDay()->utc(), $first, $last);
    }

    public function label(): string
    {
        return $this->preset === 'custom' || $this->preset === 'today'
            ? ($this->firstDay->equalTo($this->lastDay) ? $this->firstDay->format('j M Y') : $this->firstDay->format('j M Y').' – '.$this->lastDay->format('j M Y'))
            : self::PRESETS[$this->preset].' ('.$this->firstDay->format('j M').' – '.$this->lastDay->format('j M Y').')';
    }

    /**
     * Query-string parameters reproducing this period (for links and exports).
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return $this->preset === 'custom'
            ? ['period' => 'custom', 'from' => $this->firstDay->format('Y-m-d'), 'to' => $this->lastDay->format('Y-m-d')]
            : ['period' => $this->preset];
    }

    /**
     * Apply [from, to) to a timestamp column.
     */
    public function apply($query, string $column)
    {
        return $query->where($column, '>=', $this->from)->where($column, '<', $this->to);
    }
}
