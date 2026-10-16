<?php

namespace App\Services\Engine;

use App\Contracts\Payments\EntryPayment;
use App\Enums\ParticipantStatus;
use App\Enums\RoomStatus;
use App\Enums\SessionStatus;
use App\Models\CompetitionSession;
use App\Models\Room;
use App\Models\RoomParticipant;
use App\Services\Competition\RoomLifecycle;
use App\Services\Competition\RoomScheduler;
use App\Services\Payments\EntryRefundPolicy;
use App\Services\Results\ResultsFinalizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Finds competitions stuck in, or inconsistent across, transitional states and
 * reconciles them from AUTHORITATIVE PERSISTED STATE ONLY: the timestamps and
 * statuses in the database.
 *
 * Every repair goes through the same idempotent, row-locked services used in
 * normal operation (engine, finalizer, room lifecycle, entry refunds), so
 * running this any number of times, or alongside workers and the tick, is safe.
 * It never creates, edits or removes answers, scores or results; states it
 * cannot derive safely are REPORTED for an operator and left untouched.
 *
 * Lock order (as everywhere): room → session → seats → wallets.
 */
class CompetitionRecovery
{
    public function __construct(
        protected CompetitionEngine $engine,
        protected ResultsFinalizer $finalizer,
        protected RoomLifecycle $lifecycle,
        protected RoomScheduler $rooms,
        protected EntryPayment $payments,
    ) {}

    /**
     * @return list<array{check: string, subject: string, detail: string, action: string}>
     */
    public function run(bool $repair = true, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $issues = [];

        // 1. Room transitions that are due (abandoned reservations, registration, starts).
        if ($repair) {
            $summary = $this->rooms->advance($now);
            foreach (['reservations_expired', 'opened', 'started', 'cancelled'] as $key) {
                if ($summary[$key] > 0) {
                    $issues[] = $this->issue('room_transition_due', 'rooms', "{$summary[$key]} {$key}", 'applied');
                }
            }
        }

        // 2. Live sessions with a step that is overdue by their own persisted timestamps.
        foreach ($this->overdueSessions($now) as $sessionId => $detail) {
            $issues[] = $this->issue('session_overdue', "session #{$sessionId}", $detail,
                $repair ? $this->attempt(fn () => $this->engine->advance($sessionId, $now), 'advanced') : 'would advance');
        }

        // 3. Live part over but results not finalized (engine finalizes after "scoring").
        foreach (CompetitionSession::query()->where('status', SessionStatus::Scoring)->pluck('id') as $sessionId) {
            $issues[] = $this->issue('results_not_finalized', "session #{$sessionId}", 'session is in scoring',
                $repair ? $this->attempt(fn () => $this->finalizer->finalize($sessionId), 'finalized') : 'would finalize');
        }

        // 4. Results stored but the room did not follow.
        $lagging = CompetitionSession::query()->where('status', SessionStatus::Completed)
            ->whereHas('room', fn ($q) => $q->where('status', RoomStatus::InProgress))->pluck('room_id', 'id');
        foreach ($lagging as $sessionId => $roomId) {
            $issues[] = $this->issue('room_not_completed', "room #{$roomId}", "session #{$sessionId} is completed",
                $repair ? $this->attempt(fn () => $this->completeRoom($roomId, $sessionId), 'room completed') : 'would complete room');
        }

        // 5. Cancelled competitions holding entry fees that were never returned.
        foreach ($this->unrefundedSeats() as $seat) {
            $issues[] = $this->issue('entry_fee_not_refunded', "seat #{$seat->id}", "room #{$seat->room_id} is {$seat->room_status}",
                $repair ? $this->attempt(fn () => $this->refundSeat($seat->room_id, $seat->id), 'refunded') : 'would refund');
        }

        // 6. Inconsistencies that cannot be derived safely: reported only.
        foreach ($this->reportOnly() as $issue) {
            $issues[] = $issue;
        }

        return $issues;
    }

    /**
     * @return array<int, string> session id => what is overdue
     */
    protected function overdueSessions(CarbonImmutable $now): array
    {
        $grace = (int) config('competition.engine.answer_grace_ms');
        $overdue = [];

        $sessions = CompetitionSession::query()->with('room:id,status')
            ->whereIn('status', [SessionStatus::Pending, SessionStatus::Countdown, SessionStatus::InProgress])->get();

        foreach ($sessions as $session) {
            $detail = match ($session->status) {
                SessionStatus::Pending => $session->room?->status === RoomStatus::Starting ? 'room is starting but the session was never prepared' : null,
                SessionStatus::Countdown => $session->starts_at?->lte($now) ? 'start was due at '.$session->starts_at->toIso8601String() : null,
                default => $this->overdueQuestion($session, $now, $grace),
            };

            if ($detail !== null) {
                $overdue[$session->id] = $detail;
            }
        }

        return $overdue;
    }

    protected function overdueQuestion(CompetitionSession $session, CarbonImmutable $now, int $graceMs): ?string
    {
        $question = $session->currentQuestion();
        if ($question === null) {
            return 'no current question';
        }

        if ($question->delivered_at === null && $question->opens_at?->lte($now)) {
            return "question {$question->position} was due to open at ".$question->opens_at->toIso8601String();
        }

        if ($question->delivered_at !== null && $question->closed_at === null && $question->closes_at->copy()->addMilliseconds($graceMs)->lte($now)) {
            return "question {$question->position} was due to close at ".$question->closes_at->toIso8601String();
        }

        return null;
    }

    protected function completeRoom(int $roomId, int $sessionId): void
    {
        DB::transaction(function () use ($roomId, $sessionId) {
            $room = Room::query()->lockForUpdate()->findOrFail($roomId);
            $session = CompetitionSession::query()->lockForUpdate()->findOrFail($sessionId);

            if ($session->status === SessionStatus::Completed && $room->status === RoomStatus::InProgress) {
                $this->lifecycle->transition($room, RoomStatus::Completed, null, 'Recovered: results were already finalized');
            }
        });
    }

    /**
     * @return Collection<int, object{id: int, room_id: int, room_status: string}>
     */
    protected function unrefundedSeats()
    {
        return DB::table('room_participants as p')->join('rooms as r', 'r.id', '=', 'p.room_id')
            ->whereIn('r.status', [RoomStatus::Cancelled->value, RoomStatus::Refunded->value])
            ->whereNotNull('p.entry_ledger_transaction_id')->whereNull('p.refund_ledger_transaction_id')
            ->whereIn('p.status', [ParticipantStatus::Reserved->value, ParticipantStatus::Joined->value, ParticipantStatus::Playing->value,
                ParticipantStatus::Expired->value, ParticipantStatus::Refunded->value])
            ->orderBy('p.id')->get(['p.id', 'p.room_id', 'r.status as room_status']);
    }

    /**
     * Same refund path as a cancellation (exactly-once ledger reversal), room row locked first.
     */
    protected function refundSeat(int $roomId, int $seatId): void
    {
        DB::transaction(function () use ($roomId, $seatId) {
            $room = Room::query()->lockForUpdate()->findOrFail($roomId);
            $seat = RoomParticipant::query()->lockForUpdate()->findOrFail($seatId);

            $this->payments->release($room, $seat, EntryRefundPolicy::ROOM_CANCELLED);
            $seat->refresh();

            if ($seat->refund_ledger_transaction_id !== null && $seat->status->canTransitionTo(ParticipantStatus::Refunded)) {
                $seat->forceFill(['status' => ParticipantStatus::Refunded])->save();
            }
            if ($seat->refund_ledger_transaction_id !== null && $room->status === RoomStatus::Cancelled) {
                $this->lifecycle->transition($room, RoomStatus::Refunded, null, 'Recovered: entry fees refunded');
            }
        });
    }

    /**
     * @return list<array{check: string, subject: string, detail: string, action: string}>
     */
    protected function reportOnly(): array
    {
        $issues = [];
        $manual = 'reported (needs an operator)';

        CompetitionSession::query()->where('status', SessionStatus::Aborted)
            ->whereHas('room', fn ($q) => $q->whereNotIn('status', [RoomStatus::Cancelled, RoomStatus::Refunded]))
            ->pluck('room_id', 'id')
            ->each(function ($roomId, $sessionId) use (&$issues, $manual) {
                $issues[] = $this->issue('aborted_session_room_active', "room #{$roomId}", "session #{$sessionId} is aborted", $manual);
            });

        Room::query()->whereIn('status', [RoomStatus::Starting, RoomStatus::InProgress])->doesntHave('session')
            ->pluck('id')->each(function ($roomId) use (&$issues, $manual) {
                $issues[] = $this->issue('live_room_without_session', "room #{$roomId}", 'room is live but has no competition session', $manual);
            });

        RoomParticipant::query()->where('status', ParticipantStatus::Playing)
            ->whereHas('room.session', fn ($q) => $q->whereIn('status', [SessionStatus::Completed, SessionStatus::Aborted]))
            ->pluck('room_id', 'id')->each(function ($roomId, $seatId) use (&$issues, $manual) {
                $issues[] = $this->issue('seat_still_playing', "seat #{$seatId}", "room #{$roomId}'s competition has ended", $manual);
            });

        return $issues;
    }

    protected function attempt(callable $repair, string $done): string
    {
        try {
            $repair();

            return $done;
        } catch (Throwable $e) {
            report($e);

            return 'failed: '.mb_substr($e->getMessage(), 0, 120);
        }
    }

    /**
     * @return array{check: string, subject: string, detail: string, action: string}
     */
    protected function issue(string $check, string $subject, string $detail, string $action): array
    {
        return compact('check', 'subject', 'detail', 'action');
    }
}
