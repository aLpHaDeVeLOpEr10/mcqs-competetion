<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizPaperQuestion extends Model
{
    protected $fillable = [
        'quiz_paper_id',
        'question_id',
        'position',
        'time_limit_seconds',
        'points',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'time_limit_seconds' => 'integer',
            'points' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<QuizPaper, $this>
     */
    public function quizPaper(): BelongsTo
    {
        return $this->belongsTo(QuizPaper::class);
    }

    /**
     * @return BelongsTo<Question, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /**
     * Item override, then question default, then paper default.
     */
    public function effectiveTimeLimitSeconds(): int
    {
        return $this->time_limit_seconds
            ?? $this->question->default_time_limit_seconds
            ?? $this->quizPaper->default_time_limit_seconds;
    }
}
