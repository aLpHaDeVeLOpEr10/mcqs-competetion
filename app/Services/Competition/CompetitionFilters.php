<?php

namespace App\Services\Competition;

use App\Enums\RoomStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Validated listing filters. Invalid values are dropped (and reported)
 * instead of failing the whole page, since these come from a GET query string.
 */
final class CompetitionFilters
{
    public const STATUS_ALL = 'all';

    /** @var array<string, array{0: int, 1: int|null}> inclusive seat ranges */
    public const CAPACITY_BUCKETS = [
        'small' => [1, 10],
        'medium' => [11, 50],
        'large' => [51, null],
    ];

    /**
     * @param  list<string>  $ignored
     */
    public function __construct(
        public readonly ?string $status = null,
        public readonly ?string $fee = null,
        public readonly ?string $capacity = null,
        public readonly ?int $categoryId = null,
        public readonly array $ignored = [],
    ) {}

    public static function fromRequest(Request $request): self
    {
        $input = array_filter(
            $request->only(['status', 'fee', 'capacity', 'category']),
            fn ($value) => $value !== null && $value !== '',
        );

        $validator = Validator::make($input, [
            'status' => ['string', Rule::in([self::STATUS_ALL, ...array_map(fn (RoomStatus $s) => $s->value, RoomStatus::publicCases())])],
            'fee' => ['string', Rule::in(['free', 'paid'])],
            'capacity' => ['string', Rule::in(array_keys(self::CAPACITY_BUCKETS))],
            'category' => ['integer', Rule::exists('categories', 'id')->where('is_active', true)->whereNull('deleted_at')],
        ]);

        $valid = $validator->valid();

        return new self(
            status: $valid['status'] ?? null,
            fee: $valid['fee'] ?? null,
            capacity: $valid['capacity'] ?? null,
            categoryId: isset($valid['category']) ? (int) $valid['category'] : null,
            ignored: array_keys($validator->errors()->messages()),
        );
    }

    /**
     * Statuses to query; the default is upcoming and live rooms.
     *
     * @return list<RoomStatus>
     */
    public function statuses(): array
    {
        return match ($this->status) {
            null => RoomStatus::activeCases(),
            self::STATUS_ALL => RoomStatus::publicCases(),
            default => [RoomStatus::from($this->status)],
        };
    }

    public function isFiltered(): bool
    {
        return $this->status !== null || $this->fee !== null || $this->capacity !== null || $this->categoryId !== null;
    }

    /**
     * @return array<string, string|int>
     */
    public function toQuery(): array
    {
        return array_filter([
            'status' => $this->status,
            'fee' => $this->fee,
            'capacity' => $this->capacity,
            'category' => $this->categoryId,
        ], fn ($value) => $value !== null);
    }
}
