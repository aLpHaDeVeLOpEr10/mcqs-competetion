<?php

namespace App\Models;

use App\Enums\QuizPaperStatus;
use App\Enums\RoomStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class QuizPaper extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'title',
        'description',
        'status',
        'default_time_limit_seconds',
        'target_question_count',
    ];

    protected function casts(): array
    {
        return [
            'status' => QuizPaperStatus::class,
            'default_time_limit_seconds' => 'integer',
            'target_question_count' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /**
     * A paper is locked once any non-draft room uses it: its question list,
     * order and timings can no longer change (duplicate it instead).
     */
    public function isLocked(): bool
    {
        return $this->rooms()->where('status', '!=', RoomStatus::Draft)->exists();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<QuizPaperQuestion, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(QuizPaperQuestion::class)->orderBy('position');
    }

    /**
     * @return BelongsToMany<Question, $this>
     */
    public function questions(): BelongsToMany
    {
        return $this->belongsToMany(Question::class, 'quiz_paper_questions')
            ->withPivot(['position', 'time_limit_seconds', 'points'])
            ->withTimestamps()
            ->orderByPivot('position');
    }

    /**
     * @return HasMany<Room, $this>
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }
}
