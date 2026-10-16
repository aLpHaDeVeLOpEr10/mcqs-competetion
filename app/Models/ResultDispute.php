<?php

namespace App\Models;

use App\Enums\DisputeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResultDispute extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * @var list<string>
     */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'status' => DisputeStatus::class,
            'resolved_at' => 'datetime',
            'result_version_before' => 'integer',
            'result_version_after' => 'integer',
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function raiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by')->withTrashed();
    }
}
