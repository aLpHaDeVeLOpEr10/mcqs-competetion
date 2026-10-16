<?php

namespace App\Models;

use App\Enums\SessionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CompetitionSession extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * Engine-managed; nothing is mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [];

    protected $attributes = [
        'status' => 'pending',
        'lock_version' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => SessionStatus::class,
            'current_question_position' => 'integer',
            'starts_at' => 'datetime',
            'prepared_at' => 'datetime',
            'config' => 'array',
            'countdown_started_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'scored_at' => 'datetime',
            'results_published_at' => 'datetime',
            'aborted_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    /**
     * The question at the current position, if any.
     */
    public function currentQuestion(): ?SessionQuestion
    {
        return $this->current_question_position === null
            ? null
            : $this->questions()->where('position', $this->current_question_position)->first();
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return HasMany<SessionQuestion, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(SessionQuestion::class)->orderBy('position');
    }

    /**
     * @return HasMany<ParticipantResult, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(ParticipantResult::class);
    }

    /**
     * @return HasMany<ResultVersion, $this>
     */
    public function resultVersions(): HasMany
    {
        return $this->hasMany(ResultVersion::class)->orderBy('version');
    }

    /**
     * @return HasMany<ResultDispute, $this>
     */
    public function disputes(): HasMany
    {
        return $this->hasMany(ResultDispute::class);
    }

    /**
     * @return HasOne<PrizeDistribution, $this>
     */
    public function prizeDistribution(): HasOne
    {
        return $this->hasOne(PrizeDistribution::class);
    }
}
