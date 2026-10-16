<?php

namespace App\Services\Engine;

use App\Enums\ParticipantStatus;
use App\Enums\SessionStatus;
use App\Models\AnswerSubmission;
use App\Models\CompetitionSession;
use App\Models\Room;
use App\Models\RoomParticipant;
use App\Models\SessionQuestionOption;
use App\Models\User;
use App\Services\Security\RiskMonitor;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Accepts live answers. The client sends only (question id, option id);
 * receipt time, correctness, response time and points are all derived on
 * the server. One answer per participant per question is guaranteed by the
 * unique index on answer_submissions, so refreshes, double clicks and
 * multiple tabs cannot create a second answer. No row lock on the session
 * is taken, so thousands of players can answer concurrently.
 */
class AnswerService
{
    public const ACCEPTED = 'accepted';

    public const DUPLICATE = 'duplicate';

    /**
     * @return array{status: string, answer: AnswerSubmission}
     *
     * @throws AnswerRejectedException
     */
    public function submit(User $user, Room $room, int $questionId, int $optionId): array
    {
        // The receipt time is captured before any other work.
        $receivedAt = CarbonImmutable::now();

        $session = CompetitionSession::query()->where('room_id', $room->id)->first();
        if ($session === null || $session->status !== SessionStatus::InProgress) {
            throw new AnswerRejectedException('not_live', 'This competition is not accepting answers.', 409);
        }

        $seat = RoomParticipant::query()->where('room_id', $room->id)->where('user_id', $user->id)->first();
        if ($seat === null || $seat->status !== ParticipantStatus::Playing) {
            throw new AnswerRejectedException('not_participant', 'You are not playing in this competition.', 403);
        }

        $question = $session->questions()->whereKey($questionId)->first();
        if ($question === null) {
            throw new AnswerRejectedException('unknown_question', 'That question is not part of this competition.', 422);
        }

        if ($question->position !== $session->current_question_position) {
            throw new AnswerRejectedException('out_of_sequence', 'That question is not the current question.', 409);
        }

        // A question accepts answers only once the engine has delivered (opened) it.
        if ($question->delivered_at === null || ! $question->acceptsAnswersAt($receivedAt)) {
            $tooEarly = $question->delivered_at === null || $receivedAt->lt($question->opens_at);

            throw new AnswerRejectedException($tooEarly ? 'not_open' : 'closed', $tooEarly ? 'This question is not open yet.' : 'Time is up for this question.', 409);
        }

        $option = SessionQuestionOption::query()->where('session_question_id', $question->id)->whereKey($optionId)->first();
        if ($option === null) {
            throw new AnswerRejectedException('invalid_option', 'That option does not belong to this question.', 422);
        }

        $existing = $this->existing($question->id, $seat->id);
        if ($existing !== null) {
            return $this->duplicate($existing, $optionId, $user, $session);
        }

        try {
            $answer = tap((new AnswerSubmission)->forceFill([
                'session_question_id' => $question->id,
                'room_participant_id' => $seat->id,
                'session_question_option_id' => $option->id,
                'received_at' => $receivedAt,
                'response_time_ms' => max(0, (int) $question->opens_at->diffInMilliseconds($receivedAt)),
                'is_correct' => $option->is_correct,
                'points_awarded' => $option->is_correct ? $question->points : 0,
            ]))->save();
        } catch (UniqueConstraintViolationException) {
            // A concurrent request for the same seat won the race.
            return $this->duplicate($this->existing($question->id, $seat->id), $optionId, $user, $session);
        }

        // Signal only: very fast answers are possible for honest players and are never rejected.
        if ($answer->response_time_ms < config('competition.security.rapid_answer_ms')) {
            app(RiskMonitor::class)->signal('rapid_answers', $user, $session, ['question_position' => $question->position, 'response_time_ms' => $answer->response_time_ms]);
        }

        return ['status' => self::ACCEPTED, 'answer' => $answer];
    }

    protected function existing(int $questionId, int $seatId): ?AnswerSubmission
    {
        return AnswerSubmission::query()->where('session_question_id', $questionId)->where('room_participant_id', $seatId)->first();
    }

    /**
     * Re-sending the same option is an idempotent retry; a different option
     * is rejected because the first answer is final.
     *
     * @return array{status: string, answer: AnswerSubmission}
     */
    protected function duplicate(AnswerSubmission $existing, int $optionId, User $user, CompetitionSession $session): array
    {
        if ($existing->session_question_option_id !== $optionId) {
            app(RiskMonitor::class)->signal('duplicate_answer_attempts', $user, $session);
            throw new AnswerRejectedException('already_answered', 'You already answered this question. Your first answer is final.', 409, $existing);
        }

        return ['status' => self::DUPLICATE, 'answer' => $existing];
    }
}
