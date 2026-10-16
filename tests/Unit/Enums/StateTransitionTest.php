<?php

namespace Tests\Unit\Enums;

use App\Enums\ParticipantStatus;
use App\Enums\RoomStatus;
use App\Enums\SessionStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StateTransitionTest extends TestCase
{
    public static function roomTransitions(): array
    {
        return [
            'draft to open' => [RoomStatus::Draft, RoomStatus::Open, true],
            'open to full' => [RoomStatus::Open, RoomStatus::Full, true],
            'full back to open after withdrawal' => [RoomStatus::Full, RoomStatus::Open, true],
            'open to starting' => [RoomStatus::Open, RoomStatus::Starting, true],
            'full to starting' => [RoomStatus::Full, RoomStatus::Starting, true],
            'starting to in progress' => [RoomStatus::Starting, RoomStatus::InProgress, true],
            'starting can be cancelled' => [RoomStatus::Starting, RoomStatus::Cancelled, true],
            'open cannot skip starting' => [RoomStatus::Open, RoomStatus::InProgress, false],
            'starting cannot reopen' => [RoomStatus::Starting, RoomStatus::Open, false],
            'in progress to completed' => [RoomStatus::InProgress, RoomStatus::Completed, true],
            'cancelled to refunded' => [RoomStatus::Cancelled, RoomStatus::Refunded, true],
            'draft cannot jump to in progress' => [RoomStatus::Draft, RoomStatus::InProgress, false],
            'completed is terminal' => [RoomStatus::Completed, RoomStatus::Cancelled, false],
            'refunded is terminal' => [RoomStatus::Refunded, RoomStatus::Open, false],
            'in progress cannot reopen' => [RoomStatus::InProgress, RoomStatus::Open, false],
            'completed cannot be refunded' => [RoomStatus::Completed, RoomStatus::Refunded, false],
        ];
    }

    #[DataProvider('roomTransitions')]
    public function test_room_transitions(RoomStatus $from, RoomStatus $to, bool $allowed): void
    {
        $this->assertSame($allowed, $from->canTransitionTo($to));
    }

    public function test_only_open_rooms_accept_participants(): void
    {
        foreach (RoomStatus::cases() as $status) {
            $this->assertSame($status === RoomStatus::Open, $status->acceptsParticipants(), $status->value);
        }
    }

    public function test_session_moves_forward_only(): void
    {
        $order = [SessionStatus::Pending, SessionStatus::Countdown, SessionStatus::InProgress, SessionStatus::Scoring, SessionStatus::Completed];

        foreach ($order as $i => $status) {
            foreach ($order as $j => $other) {
                $this->assertSame($j === $i + 1, $status->canTransitionTo($other), "{$status->value} -> {$other->value}");
            }
        }
    }

    public function test_any_live_session_can_abort_but_terminal_states_cannot(): void
    {
        foreach (SessionStatus::cases() as $status) {
            $this->assertSame(! $status->isTerminal(), $status->canTransitionTo(SessionStatus::Aborted), $status->value);
        }
    }

    public function test_participant_transitions(): void
    {
        $this->assertTrue(ParticipantStatus::Joined->canTransitionTo(ParticipantStatus::Playing));
        $this->assertTrue(ParticipantStatus::Withdrawn->canTransitionTo(ParticipantStatus::Joined));
        $this->assertTrue(ParticipantStatus::Playing->canTransitionTo(ParticipantStatus::Refunded));
        $this->assertFalse(ParticipantStatus::Refunded->canTransitionTo(ParticipantStatus::Joined));
        $this->assertFalse(ParticipantStatus::Finished->canTransitionTo(ParticipantStatus::Refunded));
        $this->assertFalse(ParticipantStatus::Withdrawn->canTransitionTo(ParticipantStatus::Playing));
    }

    public function test_withdrawn_and_refunded_seats_do_not_count_toward_capacity(): void
    {
        $this->assertTrue(ParticipantStatus::Joined->occupiesSeat());
        $this->assertFalse(ParticipantStatus::Withdrawn->occupiesSeat());
        $this->assertFalse(ParticipantStatus::Refunded->occupiesSeat());
    }
}
