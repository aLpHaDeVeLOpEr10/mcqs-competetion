<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParticipantResult extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * @var list<string>
     */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'correct_count' => 'integer',
            'answered_count' => 'integer',
            'correct_response_time_ms' => 'integer',
            'rank' => 'integer',
            'last_correct_elapsed_ms' => 'integer',
            'is_winner' => 'boolean',
            'prize_eligible' => 'boolean',
            'prize_amount_minor' => 'integer',
            'result_version' => 'integer',
            'computed_at' => 'datetime',
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
     * @return BelongsTo<RoomParticipant, $this>
     */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(RoomParticipant::class, 'room_participant_id');
    }
}
