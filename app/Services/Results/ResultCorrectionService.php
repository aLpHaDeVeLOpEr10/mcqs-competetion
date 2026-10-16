<?php

namespace App\Services\Results;

use App\Enums\DisputeStatus;
use App\Enums\ParticipantStatus;
use App\Enums\SessionStatus;
use App\Events\Competition\ResultsPublished;
use App\Exceptions\AdministrativeActionException;
use App\Models\CompetitionSession;
use App\Models\ResultDispute;
use App\Models\ResultVersion;
use App\Models\Room;
use App\Models\RoomParticipant;
use App\Models\SessionQuestion;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Engine\EngineBroadcaster;
use Illuminate\Support\Facades\DB;

/**
 * Authorized corrections to completed results. Results are never edited by
 * hand: a correction changes an input (voids a question, disqualifies a
 * player) and the whole competition is recalculated as a NEW result
 * version. Every previous version stays in result_versions (append-only),
 * and the audit log records actor, reason and both version numbers.
 * Not possible once any prize has been paid.
 */
class ResultCorrectionService
{
    public function __construct(
        protected ResultsCalculator $calculator,
        protected AuditLogger $audit,
        protected EngineBroadcaster $broadcaster,
    ) {}

    public function voidQuestion(User $actor, CompetitionSession $session, SessionQuestion $question, string $reason, ?ResultDispute $dispute = null): ResultVersion
    {
        return $this->correct($actor, $session, "Question #{$question->position} voided: {$reason}", function () use ($session, $question, $reason) {
            $question = SessionQuestion::query()->lockForUpdate()->findOrFail($question->id);

            if ($question->competition_session_id !== $session->id) {
                throw new AdministrativeActionException('That question is not part of this competition.');
            }
            if ($question->voided_at !== null) {
                throw new AdministrativeActionException('That question is already voided.');
            }

            $question->forceFill(['voided_at' => now(), 'void_reason' => mb_substr($reason, 0, 255)])->save();

            return ['action' => 'question_voided', 'session_question_id' => $question->id, 'position' => $question->position];
        }, $dispute);
    }

    public function disqualify(User $actor, CompetitionSession $session, RoomParticipant $seat, string $reason, ?ResultDispute $dispute = null): ResultVersion
    {
        return $this->correct($actor, $session, "Participant #{$seat->id} disqualified: {$reason}", function () use ($session, $seat, $reason) {
            $seat = RoomParticipant::query()->lockForUpdate()->findOrFail($seat->id);

            if ($seat->room_id !== $session->room_id) {
                throw new AdministrativeActionException('That player is not part of this competition.');
            }
            if (! $seat->status->canTransitionTo(ParticipantStatus::Disqualified)) {
                throw new AdministrativeActionException("A {$seat->status->value} player cannot be disqualified.");
            }

            $seat->forceFill(['status' => ParticipantStatus::Disqualified, 'disqualification_reason' => mb_substr($reason, 0, 255)])->save();

            return ['action' => 'participant_disqualified', 'room_participant_id' => $seat->id];
        }, $dispute);
    }

    protected function correct(User $actor, CompetitionSession $session, string $reason, callable $change, ?ResultDispute $dispute): ResultVersion
    {
        $version = DB::transaction(function () use ($actor, $session, $reason, $change, $dispute) {
            $room = Room::query()->lockForUpdate()->findOrFail($session->room_id);
            $session = CompetitionSession::query()->lockForUpdate()->findOrFail($session->id);

            if ($session->status !== SessionStatus::Completed) {
                throw new AdministrativeActionException('Only finalized results can be corrected.');
            }

            $before = (int) $session->resultVersions()->max('version');
            $details = $change();
            $version = $this->calculator->calculate($room, $session, $reason, $actor);

            $this->audit->record('results.corrected', $session,
                ['version' => $before],
                ['version' => $version->version, 'inputs_hash' => $version->inputs_hash],
                $details + ['reason' => $reason, 'dispute_id' => $dispute?->id],
                $actor,
            );

            if ($dispute !== null) {
                $dispute->forceFill([
                    'status' => DisputeStatus::Resolved,
                    'resolution' => $reason,
                    'resolved_by' => $actor->id,
                    'resolved_at' => now(),
                    'result_version_before' => $before,
                    'result_version_after' => $version->version,
                ])->save();
            }

            return $version;
        });

        if ($session->fresh()->results_published_at !== null) {
            $this->broadcaster->send([new ResultsPublished($session->fresh(), $version->version)]);
        }

        return $version;
    }
}
