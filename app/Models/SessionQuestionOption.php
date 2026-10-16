<?php

namespace App\Models;

use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot of an answer option, including the answer key. Append-only.
 */
class SessionQuestionOption extends Model
{
    use Immutable;

    /**
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * The answer key is never serialized by default; it is revealed only by
     * an explicit, time-gated code path.
     *
     * @var list<string>
     */
    protected $hidden = [
        'is_correct',
        'correct_marker',
        'source_option_id',
    ];

    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<SessionQuestion, $this>
     */
    public function sessionQuestion(): BelongsTo
    {
        return $this->belongsTo(SessionQuestion::class);
    }
}
