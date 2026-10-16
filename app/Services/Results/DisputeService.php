<?php

namespace App\Services\Results;

use App\Enums\DisputeStatus;
use App\Enums\ParticipantStatus;
use App\Enums\SessionStatus;
use App\Exceptions\AdministrativeActionException;
use App\Models\CompetitionSession;
use App\Models\ResultDispute;
use App\Models\Room;
use App\Models\RoomParticipant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Players may dispute published results within the dispute window. Staff
 * reject a dispute with a written reason, or resolve it by applying a
 * correction (ResultCorrectionService), which links the dispute to the
 * result versions before and after.
 */
class DisputeService
{
    public function __construct(protected AuditLogger $audit) {}

    public function raise(User $user, Room $room, string $message): ResultDispute
    {
        return DB::transaction(function () use ($user, $room, $message) {
            $session = CompetitionSession::query()->where('room_id', $room->id)->lockForUpdate()->first();
            $seat = RoomParticipant::query()->where('room_id', $room->id)->where('user_id', $user->id)->first();

            if ($session === null || $session->status !== SessionStatus::Completed || $session->results_published_at === null) {
                throw new AdministrativeActionException('Results for this competition are not published yet.');
            }

            if ($seat === null || ! in_array($seat->status, [ParticipantStatus::Finished, ParticipantStatus::Disqualified], true)) {
                throw new AdministrativeActionException('Only players who took part can dispute these results.');
            }

            $days = (int) config('competition.results.dispute_window_days');
            if ($session->results_published_at->copy()->addDays($days)->isPast()) {
                throw new AdministrativeActionException("Disputes can only be raised within {$days} days of publication.");
            }

            $open = ResultDispute::query()->where('competition_session_id', $session->id)
                ->where('room_participant_id', $seat->id)->where('status', DisputeStatus::Open)->exists();
            if ($open) {
                throw new AdministrativeActionException('You already have an open dispute for this competition.');
            }

            $dispute = tap((new ResultDispute)->forceFill([
                'competition_session_id' => $session->id,
                'room_participant_id' => $seat->id,
                'raised_by' => $user->id,
                'message' => $message,
                'status' => DisputeStatus::Open,
            ]))->save();

            $this->audit->record('results.dispute_raised', $dispute, null, ['competition_session_id' => $session->id], actor: $user);

            return $dispute;
        });
    }

    public function reject(User $actor, ResultDispute $dispute, string $resolution): void
    {
        DB::transaction(function () use ($actor, $dispute, $resolution) {
            $dispute = ResultDispute::query()->lockForUpdate()->findOrFail($dispute->id);

            if ($dispute->status !== DisputeStatus::Open) {
                throw new AdministrativeActionException('This dispute has already been handled.');
            }

            $dispute->forceFill([
                'status' => DisputeStatus::Rejected,
                'resolution' => $resolution,
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
            ])->save();

            $this->audit->record('results.dispute_rejected', $dispute, ['status' => 'open'], ['status' => 'rejected'], ['resolution' => $resolution], $actor);
        });
    }
}
