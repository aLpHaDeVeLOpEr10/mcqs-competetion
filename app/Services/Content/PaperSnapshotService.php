<?php

namespace App\Services\Content;

use App\Enums\QuizPaperStatus;
use App\Exceptions\AdministrativeActionException;
use App\Exceptions\ContentValidationException;
use App\Models\CompetitionSession;
use App\Models\QuizPaperQuestion;
use App\Models\Room;
use App\Models\SessionQuestion;
use App\Models\SessionQuestionOption;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Freezes a room's quiz paper into an immutable, competition-specific
 * snapshot: question text, image, explanation, order, effective time limits,
 * points, options and the answer key. Later edits to the bank or the paper
 * cannot change it (the database rejects updates to snapshot content).
 *
 * Called when a competition is prepared (wired into room publishing in the
 * room-management phase). Idempotent: an existing snapshot is returned as is.
 */
class PaperSnapshotService
{
    public function __construct(
        protected PaperValidator $validator,
        protected AuditLogger $audit,
    ) {}

    public function snapshot(Room $room, ?User $actor = null): CompetitionSession
    {
        return DB::transaction(function () use ($room, $actor) {
            $room = Room::query()->lockForUpdate()->findOrFail($room->id);

            if ($existing = $room->session()->first()) {
                return $existing;
            }

            $paper = $room->quizPaper()->firstOrFail();

            if ($paper->status !== QuizPaperStatus::Published) {
                throw new AdministrativeActionException('Only a published quiz paper can be used for a competition.');
            }

            if ($problems = $this->validator->problems($paper)) {
                throw new ContentValidationException('The quiz paper is not valid for a competition.', $problems);
            }

            $session = tap((new CompetitionSession)->forceFill(['room_id' => $room->id]))->save();

            $items = $paper->items()
                ->with(['question.options'])
                ->orderBy('position')
                ->get();

            foreach ($items as $item) {
                /** @var QuizPaperQuestion $item */
                $question = $item->question;

                $sessionQuestion = tap((new SessionQuestion)->forceFill([
                    'competition_session_id' => $session->id,
                    'source_question_id' => $question->id,
                    'position' => $item->position,
                    'body' => $question->body,
                    'explanation' => $question->explanation,
                    'image_path' => $question->image_path,
                    'time_limit_seconds' => $item->time_limit_seconds ?? $question->default_time_limit_seconds ?? $paper->default_time_limit_seconds,
                    'points' => $item->points,
                ]))->save();

                foreach ($question->options as $option) {
                    (new SessionQuestionOption)->forceFill([
                        'session_question_id' => $sessionQuestion->id,
                        'source_option_id' => $option->id,
                        'body' => $option->body,
                        'is_correct' => $option->is_correct,
                        'position' => $option->position,
                    ])->save();
                }
            }

            $this->audit->record('room.snapshot_created', $room, null, [
                'competition_session_id' => $session->id,
                'quiz_paper_id' => $paper->id,
                'questions' => $items->count(),
            ], actor: $actor);

            return $session;
        });
    }
}
