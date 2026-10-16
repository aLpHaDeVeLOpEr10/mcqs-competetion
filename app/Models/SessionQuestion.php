<?php

namespace App\Models;

use App\Exceptions\ImmutableRecordException;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Immutable snapshot of a question as used in one competition session.
 */
class SessionQuestion extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * Content frozen at snapshot time. Only the engine timing columns
     * (opens_at, closes_at, revealed_at) may change; the database enforces the same.
     */
    public const IMMUTABLE_COLUMNS = [
        'competition_session_id', 'source_question_id', 'position', 'body',
        'explanation', 'image_path', 'time_limit_seconds', 'points',
    ];

    protected $hidden = ['image_path'];

    protected static function booted(): void
    {
        static::updating(function (SessionQuestion $question) {
            if ($question->isDirty(self::IMMUTABLE_COLUMNS)) {
                throw ImmutableRecordException::for($question, 'content update');
            }
        });
        static::deleting(fn (SessionQuestion $question) => throw ImmutableRecordException::for($question, 'delete'));
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'time_limit_seconds' => 'integer',
            'points' => 'integer',
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'revealed_at' => 'datetime',
            'delivered_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * Server-side answer window: opens_at <= t <= closes_at + grace.
     */
    public function acceptsAnswersAt(CarbonInterface $time): bool
    {
        if ($this->opens_at === null || $this->closes_at === null) {
            return false;
        }

        $deadline = $this->closes_at->copy()->addMilliseconds((int) config('competition.engine.answer_grace_ms'));

        return $time->gte($this->opens_at) && $time->lte($deadline);
    }

    /**
     * @return BelongsTo<CompetitionSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(CompetitionSession::class, 'competition_session_id');
    }

    /**
     * @return BelongsTo<Question, $this>
     */
    public function sourceQuestion(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'source_question_id')->withTrashed();
    }

    /**
     * @return HasMany<SessionQuestionOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(SessionQuestionOption::class)->orderBy('position');
    }

    /**
     * @return HasMany<AnswerSubmission, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(AnswerSubmission::class);
    }
}
