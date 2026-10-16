<?php

namespace App\Models;

use App\Enums\QuestionDifficulty;
use App\Enums\QuestionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Question extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'category_id',
        'body',
        'explanation',
        'image_alt',
        'difficulty',
        'status',
        'default_time_limit_seconds',
    ];

    protected $hidden = [
        'image_path',
    ];

    protected function casts(): array
    {
        return [
            'difficulty' => QuestionDifficulty::class,
            'status' => QuestionStatus::class,
            'default_time_limit_seconds' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Keep the duplicate-detection hash in step with the text.
        static::saving(function (Question $question) {
            if ($question->isDirty('body') || $question->body_hash === null) {
                $question->body_hash = static::hashBody((string) $question->body);
            }
        });
    }

    /**
     * Normalized hash used for duplicate detection: case, surrounding and
     * repeated whitespace are ignored.
     */
    public static function hashBody(string $body): string
    {
        return hash('sha256', mb_strtolower(trim(preg_replace('/\s+/u', ' ', $body))));
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return HasMany<QuestionOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('position');
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->orderBy('name');
    }

    /**
     * @return BelongsToMany<QuizPaper, $this>
     */
    public function quizPapers(): BelongsToMany
    {
        return $this->belongsToMany(QuizPaper::class, 'quiz_paper_questions')
            ->withPivot(['position', 'time_limit_seconds', 'points'])
            ->withTimestamps();
    }

    public function isArchived(): bool
    {
        return $this->status === QuestionStatus::Archived;
    }

    /**
     * Problems that stop this question being used in a published paper.
     *
     * @return list<string>
     */
    public function integrityProblems(): array
    {
        $problems = [];
        $options = $this->relationLoaded('options') ? $this->options : $this->options()->get();
        $min = (int) config('competition.questions.min_options');
        $max = (int) config('competition.questions.max_options');

        if ($this->trashed()) {
            $problems[] = 'has been deleted';
        }

        if ($this->status !== QuestionStatus::Active) {
            $problems[] = 'is '.$this->status->value.', not active';
        }

        if (trim((string) $this->body) === '') {
            $problems[] = 'has no question text';
        }

        if ($options->count() < $min || $options->count() > $max) {
            $problems[] = "has {$options->count()} options (needs {$min}–{$max})";
        }

        if ($options->where('is_correct', true)->count() !== 1) {
            $problems[] = 'does not have exactly one correct option';
        }

        if ($options->contains(fn (QuestionOption $o) => trim((string) $o->body) === '')) {
            $problems[] = 'has an empty option';
        }

        return $problems;
    }
}
