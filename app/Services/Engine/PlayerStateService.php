<?php

namespace App\Services\Engine;

use App\Enums\SessionStatus;
use App\Models\AnswerSubmission;
use App\Models\CompetitionSession;
use App\Models\Room;
use App\Models\RoomParticipant;
use Carbon\CarbonImmutable;

/**
 * The authoritative live state for one participant, used on page load,
 * reconnect, tab focus and as the polling fallback when WebSockets are down.
 * Deadlines come from the database, so refreshing or reconnecting never
 * resets a countdown. Correctness is never included.
 */
class PlayerStateService
{
    /**
     * @return array<string, mixed>
     */
    public function forParticipant(Room $room, RoomParticipant $seat): array
    {
        $now = CarbonImmutable::now();
        $session = CompetitionSession::query()->where('room_id', $room->id)->first();
        $iso = fn ($t) => $t?->format('Y-m-d\TH:i:s.vP');

        $state = [
            'server_now' => $iso($now),
            'room' => ['id' => $room->id, 'name' => $room->name, 'status' => $room->status->value],
            'session' => null,
            'question' => null,
            'my_answer' => null,
            'seat_status' => $seat->status->value,
            'answered_count' => 0,
        ];

        if ($session === null) {
            return $state;
        }

        $total = (int) ($session->config['question_count'] ?? $session->questions()->count());
        $state['session'] = [
            'id' => $session->id,
            'status' => $session->status->value,
            'starts_at' => $iso($session->starts_at),
            'position' => $session->current_question_position,
            'total' => $total,
            'channel' => 'competition.'.$session->id,
        ];
        $state['results_url'] = $session->results_published_at ? route('competitions.results', $room) : null;

        $myAnswers = AnswerSubmission::query()->where('room_participant_id', $seat->id)->get(['session_question_id', 'session_question_option_id', 'received_at']);
        $state['answered_count'] = $myAnswers->count();

        $question = $session->status === SessionStatus::InProgress ? $session->currentQuestion() : null;

        // Content is only released once the engine has delivered (opened) the question.
        if ($question !== null && $question->delivered_at !== null) {
            $state['question'] = QuestionPresenter::forPlayer($question, $total) + [
                'closed' => $question->closed_at !== null || ! $question->acceptsAnswersAt($now),
            ];

            $mine = $myAnswers->firstWhere('session_question_id', $question->id);
            $state['my_answer'] = $mine ? ['option_id' => $mine->session_question_option_id, 'received_at' => $iso($mine->received_at)] : null;
        } elseif ($question !== null) {
            $state['next_opens_at'] = $iso($question->opens_at);
        }

        return $state;
    }
}
