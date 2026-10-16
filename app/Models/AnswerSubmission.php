<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnswerSubmission extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * Created only by the answer service from server-side values.
     *
     * @var list<string>
     */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'response_time_ms' => 'integer',
            'is_correct' => 'boolean',
            'points_awarded' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<SessionQuestion, $this>
     */
    public function sessionQuestion(): BelongsTo
    {
        return $this->belongsTo(SessionQuestion::class);
    }

    /**
     * @return BelongsTo<RoomParticipant, $this>
     */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(RoomParticipant::class, 'room_participant_id');
    }

    /**
     * @return BelongsTo<SessionQuestionOption, $this>
     */
    public function selectedOption(): BelongsTo
    {
        return $this->belongsTo(SessionQuestionOption::class, 'session_question_option_id');
    }
}
