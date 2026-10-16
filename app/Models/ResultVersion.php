<?php

namespace App\Models;

use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One complete results calculation (initial or a correction). Append-only:
 * the inputs, their hash, the ranking and the prize allocation are kept for audit.
 */
class ResultVersion extends Model
{
    use Immutable;

    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * @var list<string>
     */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'inputs' => 'array',
            'results' => 'array',
            'prizes' => 'array',
        ];
    }

    /**
     * @return BelongsTo<CompetitionSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(CompetitionSession::class, 'competition_session_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }
}
