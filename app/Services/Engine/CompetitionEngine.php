<?php

namespace App\Services\Engine;

use App\Enums\ParticipantStatus;
use App\Enums\RoomStatus;
use App\Enums\SessionStatus;
use App\Events\Competition\CompetitionCompleted;
use App\Events\Competition\CompetitionEvent;
use App\Events\Competition\CompetitionProgressUpdated;
use App\Events\Competition\CompetitionStarting;
use App\Events\Competition\QuestionClosed;
use App\Events\Competition\QuestionOpened;
use App\Exceptions\AdministrativeActionException;
use App\Models\AnswerSubmission;
use App\Models\CompetitionSession;
use App\Models\Room;
use App\Models\RoomParticipant;
use App\Models\SessionQuestion;
use App\Notifications\CompetitionStarted;
use App\Services\Audit\AuditLogger;
use App\Services\Competition\RoomEntryService;
use App\Services\Competition\RoomLifecycle;
use App\Services\Notifications\Notifier;
use App\Services\Results\ResultsFinalizer;
use App\Services\Results\ScoringPolicy;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The live competition state machine.
 *
 * Timing policy: one SHARED GLOBAL DEADLINE per question. When a question is
 * scheduled the server writes opens_at and closes_at = opens_at + time limit
 * on the snapshot row. Every participant gets the same window; an answer is
 * accepted iff its server receipt time is within [opens_at, closes_at +
 * grace]. Late delivery to one browser does not extend that player's time.
 *
 * advance() is the only way the competition moves. It is idempotent: under
 * row locks (room, then session; the same order as RoomEntryService) it
 * performs only the steps that are due at "now", so it can be run by the
 * delayed AdvanceCompetition job, the competitions:tick scheduler, or both,
 * any number of times, late, or after a crash. A question's window is set
 * when the previous one is closed (closed + gap), so an outage delays the
 * next question rather than skipping it or shortening anyone's time.
 *
 * Steps: prepare (pending → countdown) → start (countdown → in progress,
 * room in progress, seats playing) → deliver question → close question →
 * schedule next … → finalize (in progress → scoring, seats finished).
 */
class CompetitionEngine
{
    protected const MAX_STEPS_PER_RUN = 8;

    public function __construct(
        protected RoomLifecycle $lifecycle,
        protected RoomEntryService $entries,
        protected AuditLogger $audit,
        protected EngineBroadcaster $broadcaster,
        protected CompetitionWaker $waker,
    ) {}

    /**
     * @return CarbonImmutable|null the next moment something will be due
     */
    public function advance(int $sessionId, ?CarbonInterface $now = null): ?CarbonImmutable
    {
        /** @var list<CompetitionEvent> $events */
        $events = [];

        // Looked up BEFORE the transaction: under REPEATABLE READ the first plain read
        // fixes the snapshot, so the first statement inside must be the locking read.
        // Otherwise a run that waited for the lock could read stale question rows.
        $roomId = CompetitionSession::query()->whereKey($sessionId)->value('room_id');
        if ($roomId === null) {
            return null;
        }

        $next = DB::transaction(function () use ($roomId, $sessionId, $now, &$events) {
            $room = Room::query()->lockForUpdate()->findOrFail($roomId);
            $session = CompetitionSession::query()->lockForUpdate()->findOrFail($sessionId);
            $now = CarbonImmutable::instance($now ?? now());

            for ($i = 0; $i < self::MAX_STEPS_PER_RUN && $this->step($room, $session, $now, $events); $i++) {
                // Keep stepping while something is due (e.g. catching up after downtime).
            }

            return $this->nextWakeAt($session);
        });

        $this->broadcaster->send($events);

        if ($next !== null) {
            $this->waker->wake($sessionId, $next);
        } elseif (CompetitionSession::query()->whereKey($sessionId)->where('status', SessionStatus::Scoring)->exists()) {
            // Live part over: compute and publish results (the tick retries if this fails).
            app(ResultsFinalizer::class)->finalize($sessionId);
        }

        return $next;
    }

    /**
     * Perform one due step. Returns false when nothing is due.
     */
    protected function step(Room $room, CompetitionSession $session, CarbonImmutable $now, array &$events): bool
    {
        return match ($session->status) {
            SessionStatus::Pending => $room->status === RoomStatus::Starting && $this->prepare($room, $session, $now, $events),
            SessionStatus::Countdown => $session->starts_at->lte($now) && $this->start($room, $session, $now, $events),
            SessionStatus::InProgress => $this->stepQuestion($room, $session, $now, $events),
            default => false,
        };
    }

    protected function prepare(Room $room, CompetitionSession $session, CarbonImmutable $now, array &$events): bool
    {
        $questions = $session->questions()->orderBy('position')->get(['position', 'time_limit_seconds', 'points']);

        if ($questions->isEmpty()) {
            $this->abort($room, $session, $now, 'The competition snapshot has no questions.', $events);

            return true;
        }

        $config = [
            'timing_policy' => 'global_deadline',
            'scoring_policy' => ScoringPolicy::VERSION,
            'question_count' => $questions->count(),
            'answer_grace_ms' => (int) config('competition.engine.answer_grace_ms'),
            'question_gap_seconds' => $room->question_gap_seconds,
            'countdown_seconds' => $room->countdown_seconds,
            'tie_break_policy' => $room->tie_break_policy->value,
            'entry_fee_minor' => $room->entry_fee_minor,
            'questions' => $questions->map(fn ($q) => [$q->position, $q->time_limit_seconds, $q->points])->all(),
            // Frozen prize configuration used by the results calculation and payouts.
            'prize_rules' => $room->prizeRules(),
            'prize_tiers' => $room->prizeTiers()->orderBy('rank_from')->get()->map(fn ($t) => [$t->rank_from, $t->rank_to, $t->amount_minor])->all(),
        ];

        $this->setStatus($session, SessionStatus::Countdown, [
            'prepared_at' => $now,
            'countdown_started_at' => $now,
            'starts_at' => $room->starts_at ?? $now,
            'config' => $config,
        ]);

        $this->audit->record('competition.prepared', $session, null, [
            'starts_at' => $session->starts_at->format('Y-m-d\TH:i:s.vP'),
            'questions' => $config['question_count'],
        ]);
        $events[] = new CompetitionStarting($session);

        return true;
    }

    protected function start(Room $room, CompetitionSession $session, CarbonImmutable $now, array &$events): bool
    {
        // Seats that never confirmed cannot play.
        $this->entries->expireReservationsLocked($room, $now, force: true);

        $players = RoomParticipant::query()->where('room_id', $room->id)->where('status', ParticipantStatus::Joined)
            ->update(['status' => ParticipantStatus::Playing->value, 'updated_at' => $now]);

        if ($players === 0) {
            $this->abort($room, $session, $now, 'No confirmed players at start time.', $events);

            return true;
        }

        $this->lifecycle->transition($room, RoomStatus::InProgress, null, 'Competition started', ['players' => $players]);
        $this->setStatus($session, SessionStatus::InProgress, ['started_at' => $now, 'current_question_position' => 1]);
        $this->schedule($session->currentQuestion(), $now);

        // Queued and chunked: a large room must not delay the engine.
        app(Notifier::class)->toRoom($room->id, [ParticipantStatus::Playing], new CompetitionStarted($room));

        $this->audit->record('competition.started', $session, null, [
            'started_at' => $now->format('Y-m-d\TH:i:s.vP'),
            'players' => $players,
        ]);

        return true;
    }

    protected function stepQuestion(Room $room, CompetitionSession $session, CarbonImmutable $now, array &$events): bool
    {
        $question = $session->currentQuestion();
        $total = (int) ($session->config['question_count'] ?? $session->questions()->count());

        if ($question->delivered_at === null && $question->opens_at->lte($now)) {
            // Delivery is the real opening. Before it nobody can see or answer the question,
            // so if the engine runs late the window starts now and every player keeps the
            // full time limit. Once delivered, the window never moves.
            $question->forceFill([
                'opens_at' => $now,
                'closes_at' => $now->addSeconds($question->time_limit_seconds),
                'delivered_at' => $now,
            ])->save();
            $events[] = new QuestionOpened($question, $total);

            return true;
        }

        $closesWithGrace = $question->closes_at->copy()->addMilliseconds((int) config('competition.engine.answer_grace_ms'));

        if ($question->delivered_at !== null && $question->closed_at === null && $closesWithGrace->lte($now)) {
            $this->close($room, $session, $question, $total, $now, $events);

            return true;
        }

        return false;
    }

    protected function close(Room $room, CompetitionSession $session, SessionQuestion $question, int $total, CarbonImmutable $now, array &$events): void
    {
        $question->forceFill(['closed_at' => $now])->save();

        $answered = AnswerSubmission::query()->where('session_question_id', $question->id)->count();
        $players = RoomParticipant::query()->where('room_id', $room->id)->where('status', ParticipantStatus::Playing)->count();
        $next = $session->questions()->where('position', $question->position + 1)->first();

        if ($next !== null) {
            $this->schedule($next, $now->addSeconds($room->question_gap_seconds));
            $session->forceFill(['current_question_position' => $next->position])->save();
        }

        $events[] = new QuestionClosed($question, $next);
        $events[] = new CompetitionProgressUpdated($session->id, $question->position, $total, $answered, $players);

        if ($next === null) {
            $this->finish($room, $session, $now, $events);
        }
    }

    /**
     * The live part is over. advance() then runs ResultsFinalizer, which
     * scores, completes the session and room, and publishes results.
     */
    protected function finish(Room $room, CompetitionSession $session, CarbonImmutable $now, array &$events): void
    {
        RoomParticipant::query()->where('room_id', $room->id)->where('status', ParticipantStatus::Playing)
            ->update(['status' => ParticipantStatus::Finished->value, 'updated_at' => $now]);

        $this->setStatus($session, SessionStatus::Scoring, ['ended_at' => $now]);
        $this->audit->record('competition.finished', $session, null, [
            'ended_at' => $now->format('Y-m-d\TH:i:s.vP'),
            'answers' => AnswerSubmission::query()->whereIn('session_question_id', $session->questions()->select('id'))->count(),
        ]);
        $events[] = new CompetitionCompleted($session);
    }

    /**
     * Cancel the room; RoomLifecycle aborts this session and notifies players.
     */
    protected function abort(Room $room, CompetitionSession $session, CarbonImmutable $now, string $reason, array &$events): void
    {
        $this->lifecycle->cancelLocked($room, null, $reason);
        $session->refresh();
    }

    /**
     * Fix a question's shared window. Written once; the engine never moves it.
     */
    protected function schedule(SessionQuestion $question, CarbonImmutable $opensAt): void
    {
        if ($question->opens_at !== null) {
            return;
        }

        $question->forceFill([
            'opens_at' => $opensAt,
            'closes_at' => $opensAt->addSeconds($question->time_limit_seconds),
        ])->save();
    }

    protected function setStatus(CompetitionSession $session, SessionStatus $to, array $attributes = []): void
    {
        if (! $session->status->canTransitionTo($to)) {
            throw new AdministrativeActionException("A session cannot move from {$session->status->value} to {$to->value}.");
        }

        $session->forceFill(['status' => $to, 'lock_version' => $session->lock_version + 1] + $attributes)->save();
    }

    protected function nextWakeAt(CompetitionSession $session): ?CarbonImmutable
    {
        if ($session->status === SessionStatus::Countdown) {
            return CarbonImmutable::instance($session->starts_at);
        }

        if ($session->status !== SessionStatus::InProgress) {
            return null;
        }

        $question = $session->currentQuestion();

        return CarbonImmutable::instance($question->delivered_at === null
            ? $question->opens_at
            : $question->closes_at->copy()->addMilliseconds((int) config('competition.engine.answer_grace_ms')));
    }
}
