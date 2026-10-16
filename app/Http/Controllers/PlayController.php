<?php

namespace App\Http\Controllers;

use App\Broadcasting\CompetitionChannel;
use App\Enums\SessionStatus;
use App\Models\CompetitionSession;
use App\Models\Room;
use App\Models\RoomParticipant;
use App\Models\SessionQuestion;
use App\Services\Content\QuestionImageStore;
use App\Services\Engine\AnswerRejectedException;
use App\Services\Engine\AnswerService;
use App\Services\Engine\PlayerStateService;
use App\Services\Security\RiskMonitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The live competition screen. Every endpoint requires a confirmed seat.
 */
class PlayController extends Controller
{
    public function show(Request $request, Room $room, PlayerStateService $state): View
    {
        $seat = $this->seat($request, $room);
        $this->recordSignals($request, $room);

        return view('play.show', ['room' => $room, 'initialState' => $state->forParticipant($room, $seat)]);
    }

    public function state(Request $request, Room $room, PlayerStateService $state): JsonResponse
    {
        return response()->json($state->forParticipant($room, $this->seat($request, $room)))
            ->header('Cache-Control', 'no-store');
    }

    public function answer(Request $request, Room $room, AnswerService $answers): JsonResponse
    {
        $this->seat($request, $room);

        $data = $request->validate([
            'question_id' => ['required', 'integer', 'min:1'],
            'option_id' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $result = $answers->submit($request->user(), $room, (int) $data['question_id'], (int) $data['option_id']);
        } catch (AnswerRejectedException $e) {
            return response()->json(array_filter([
                'accepted' => false,
                'reason' => $e->reason,
                'message' => $e->getMessage(),
                'my_answer' => $e->existing ? ['option_id' => $e->existing->session_question_option_id] : null,
            ], fn ($v) => $v !== null), $e->status);
        }

        // Correctness, score and timing are deliberately not returned.
        return response()->json([
            'accepted' => true,
            'duplicate' => $result['status'] === AnswerService::DUPLICATE,
            'my_answer' => [
                'option_id' => $result['answer']->session_question_option_id,
                'received_at' => $result['answer']->received_at->format('Y-m-d\TH:i:s.vP'),
            ],
        ]);
    }

    /**
     * A question image, only for seated players and only once it has opened.
     */
    public function image(Request $request, SessionQuestion $question, QuestionImageStore $images): StreamedResponse
    {
        $room = $question->session->room;
        $this->seat($request, $room);

        abort_if($question->image_path === null || $question->delivered_at === null, 404);

        return $images->response($question->image_path);
    }

    /**
     * Review signals while a competition is live (never a sanction): many reloads of the
     * play screen, and being signed in on several devices at once. Reloading and a
     * second device are often innocent (flaky network, phone + laptop), so these are
     * low severity and staff decide.
     */
    protected function recordSignals(Request $request, Room $room): void
    {
        $session = CompetitionSession::query()->where('room_id', $room->id)->first();
        if ($session === null || $session->status !== SessionStatus::InProgress) {
            return;
        }

        $risk = app(RiskMonitor::class);
        $risk->signal('frequent_reloads', $request->user(), $session);

        // Database sessions only: other drivers cannot list a user's sessions.
        if (config('session.driver') === 'database') {
            $devices = DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
                ->where('user_id', $request->user()->id)
                ->where('last_activity', '>=', now()->subMinutes(10)->getTimestamp())
                ->count();
            if ($devices > 1) {
                $risk->signal('multiple_sessions', $request->user(), $session, ['active_sessions' => $devices]);
            }
        }
    }

    protected function seat(Request $request, Room $room): RoomParticipant
    {
        $seat = RoomParticipant::query()->where('room_id', $room->id)->where('user_id', $request->user()->id)
            ->whereIn('status', CompetitionChannel::SEATED)->first();

        abort_if($seat === null, 403, 'You do not have a seat in this competition.');

        return $seat;
    }
}
