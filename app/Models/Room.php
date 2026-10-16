<?php

namespace App\Models;

use App\Enums\PrizeMode;
use App\Enums\PrizePoolBasis;
use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Enums\TieBreakPolicy;
use App\Enums\UnderCapacityPolicy;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Room extends Model
{
    use HasFactory;

    /**
     * Status, participant count and lock version are excluded: they change
     * only through the room state machine and entry service.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'quiz_paper_id',
        'category_id',
        'type',
        'entry_fee_minor',
        'currency',
        'min_participants',
        'max_participants',
        'registration_opens_at',
        'registration_closes_at',
        'scheduled_start_at',
        'countdown_seconds',
        'question_gap_seconds',
        'tie_break_policy',
        'under_capacity_policy',
        'allow_withdrawal',
        'prize_mode',
        'prize_pool_basis',
        'prize_pool_minor',
        'platform_fee_bp',
        'prize_review_required',
    ];

    /**
     * Settings that define the competition. They can only change while the
     * room is a draft; once published, a room is locked to these values.
     *
     * @var list<string>
     */
    public const CRITICAL_ATTRIBUTES = [
        'quiz_paper_id', 'type', 'entry_fee_minor', 'currency', 'min_participants', 'max_participants',
        'registration_opens_at', 'registration_closes_at', 'scheduled_start_at', 'countdown_seconds',
        'question_gap_seconds', 'tie_break_policy', 'under_capacity_policy', 'allow_withdrawal',
        'prize_mode', 'prize_pool_basis', 'prize_pool_minor', 'platform_fee_bp', 'prize_review_required',
    ];

    protected $attributes = [
        'status' => 'draft',
        'entry_fee_minor' => 0,
        'currency' => 'PKR',
        'participants_count' => 0,
        'lock_version' => 0,
        'tie_break_policy' => 'fastest_correct',
        'under_capacity_policy' => 'start_if_minimum',
        'allow_withdrawal' => true,
        'prize_mode' => 'fixed',
        'platform_fee_bp' => 0,
        'prize_review_required' => false,
    ];

    protected function casts(): array
    {
        return [
            'type' => RoomType::class,
            'status' => RoomStatus::class,
            'entry_fee_minor' => 'integer',
            'min_participants' => 'integer',
            'max_participants' => 'integer',
            'participants_count' => 'integer',
            'registration_opens_at' => 'datetime',
            'registration_closes_at' => 'datetime',
            'scheduled_start_at' => 'datetime',
            'starts_at' => 'datetime',
            'tie_break_policy' => TieBreakPolicy::class,
            'under_capacity_policy' => UnderCapacityPolicy::class,
            'allow_withdrawal' => 'boolean',
            'countdown_seconds' => 'integer',
            'question_gap_seconds' => 'integer',
            'published_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'lock_version' => 'integer',
            'prize_mode' => PrizeMode::class,
            'prize_pool_basis' => PrizePoolBasis::class,
            'prize_pool_minor' => 'integer',
            'platform_fee_bp' => 'integer',
            'prize_review_required' => 'boolean',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === RoomStatus::Draft;
    }

    /**
     * Registration deadline: explicit cutoff, else the scheduled start.
     */
    public function registrationDeadline(): ?CarbonInterface
    {
        return $this->registration_closes_at ?? $this->scheduled_start_at;
    }

    public function isFree(): bool
    {
        return $this->entry_fee_minor === 0;
    }

    public function remainingSeats(): int
    {
        return max(0, $this->max_participants - $this->participants_count);
    }

    /**
     * Total prize money known in advance, in minor units: the fixed tiers, or the
     * percentage share of a guaranteed pool. Null when it depends on entry fees
     * that are not collected yet.
     */
    public function prizeTotalMinor(): ?int
    {
        if ($this->prize_mode !== PrizeMode::Percentage) {
            return $this->prizeTiers->sum(fn (RoomPrizeTier $tier) => $tier->totalAmountMinor());
        }

        if ($this->prize_pool_basis === PrizePoolBasis::Guaranteed) {
            return $this->prizeTiers->sum(fn (RoomPrizeTier $tier) => $tier->positions() * intdiv((int) $this->prize_pool_minor * (int) $tier->percent_bp, 10000));
        }

        return null;
    }

    public function hasPublishedPrizes(): bool
    {
        return $this->prizeTiers->isNotEmpty() && ($this->prizeTotalMinor() ?? 1) > 0;
    }

    /**
     * The prize rules frozen into the session when the competition starts.
     *
     * @return array<string, mixed>
     */
    public function prizeRules(): array
    {
        return [
            'mode' => $this->prize_mode->value,
            'pool_basis' => $this->prize_pool_basis?->value,
            'pool_minor' => $this->prize_pool_minor,
            'platform_fee_bp' => $this->platform_fee_bp,
            'review_required' => $this->prize_review_required,
            'tiers' => $this->prizeTiers()->orderBy('rank_from')->get()
                ->map(fn (RoomPrizeTier $t) => [$t->rank_from, $t->rank_to, $t->amount_minor, $t->percent_bp])->all(),
        ];
    }

    /**
     * @return BelongsTo<QuizPaper, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<QuizPaper, $this>
     */
    public function quizPaper(): BelongsTo
    {
        return $this->belongsTo(QuizPaper::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<RoomPrizeTier, $this>
     */
    public function prizeTiers(): HasMany
    {
        return $this->hasMany(RoomPrizeTier::class)->orderBy('rank_from');
    }

    /**
     * @return HasMany<RoomParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(RoomParticipant::class);
    }

    /**
     * @return HasOne<CompetitionSession, $this>
     */
    public function session(): HasOne
    {
        return $this->hasOne(CompetitionSession::class);
    }
}
