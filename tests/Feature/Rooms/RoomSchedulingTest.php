<?php

namespace Tests\Feature\Rooms;

use App\Contracts\Payments\EntryPayment;
use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Enums\UnderCapacityPolicy;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Competition\RoomEntryService;
use App\Services\Competition\RoomScheduler;
use App\Services\Payments\DeferredEntryPayment;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesRooms;
use Tests\TestCase;

class RoomSchedulingTest extends TestCase
{
    use CreatesRooms, RefreshDatabase;

    protected function fill($room, int $players): void
    {
        foreach (User::factory()->count($players)->create() as $user) {
            app(RoomEntryService::class)->join($user, $room);
        }
    }

    protected function advance(): array
    {
        return app(RoomScheduler::class)->advance();
    }

    public function test_fill_room_starts_after_the_countdown_when_the_last_seat_is_confirmed(): void
    {
        $room = $this->openRoom(['type' => RoomType::Fill, 'min_participants' => 3, 'max_participants' => 3, 'countdown_seconds' => 15]);
        $this->travelTo(now()->setMicrosecond(0));

        $this->fill($room, 3);

        $room->refresh();
        $this->assertSame(RoomStatus::Starting, $room->status);
        $this->assertTrue($room->starts_at->equalTo(now()->addSeconds(15)));
    }

    public function test_fill_room_waits_for_pending_reservations_before_starting(): void
    {
        $this->useDeferredEntryPayments();
        config(['competition.paid_entries.enabled' => true]);
        $room = $this->openRoom(['type' => RoomType::Fill, 'entry_fee_minor' => 1_000, 'min_participants' => 2, 'max_participants' => 2]);
        $this->fill($room, 2);

        $this->assertSame(RoomStatus::Full, $room->fresh()->status);

        foreach ($room->participants as $seat) {
            app(RoomEntryService::class)->confirmReservation($seat);
        }

        $this->assertSame(RoomStatus::Starting, $room->fresh()->status);
    }

    public function test_scheduled_room_starts_at_its_time_when_the_minimum_is_met(): void
    {
        $room = $this->openRoom(['min_participants' => 2, 'max_participants' => 10, 'scheduled_start_at' => now()->addHour()]);
        $this->fill($room, 2);

        $this->assertSame(['started' => 0], array_intersect_key($this->advance(), ['started' => 0]));
        $this->assertSame(RoomStatus::Open, $room->fresh()->status);

        $this->travel(61)->minutes();
        $this->assertSame(1, $this->advance()['started']);
        $this->assertSame(RoomStatus::Starting, $room->fresh()->status);
    }

    public function test_scheduled_room_is_cancelled_below_the_minimum(): void
    {
        $room = $this->openRoom(['min_participants' => 3, 'max_participants' => 10, 'scheduled_start_at' => now()->addHour()]);
        $this->fill($room, 2);

        $this->travel(61)->minutes();
        $this->assertSame(1, $this->advance()['cancelled']);

        $room->refresh();
        $this->assertSame(RoomStatus::Cancelled, $room->status);
        $this->assertStringContainsString('2 confirmed, 3 required', $room->cancellation_reason);
    }

    public function test_cancel_policy_requires_a_full_room(): void
    {
        $room = $this->openRoom(['min_participants' => 2, 'max_participants' => 4, 'under_capacity_policy' => UnderCapacityPolicy::Cancel, 'scheduled_start_at' => now()->addHour()]);
        $this->fill($room, 3);

        $this->travel(61)->minutes();
        $this->advance();

        $this->assertSame(RoomStatus::Cancelled, $room->fresh()->status);
    }

    public function test_scheduled_room_that_fills_early_waits_for_its_start_time(): void
    {
        $room = $this->openRoom(['min_participants' => 2, 'max_participants' => 2, 'scheduled_start_at' => now()->addHour()]);
        $this->fill($room, 2);

        $this->assertSame(RoomStatus::Full, $room->fresh()->status);
        $this->advance();
        $this->assertSame(RoomStatus::Full, $room->fresh()->status);
    }

    public function test_hybrid_room_starts_early_when_full(): void
    {
        $room = $this->openRoom(['type' => RoomType::Hybrid, 'min_participants' => 2, 'max_participants' => 2, 'scheduled_start_at' => now()->addHour()]);
        $this->fill($room, 2);

        $this->assertSame(RoomStatus::Starting, $room->fresh()->status);
    }

    public function test_fill_room_deadline_applies_the_minimum_rule(): void
    {
        $starts = $this->openRoom(['type' => RoomType::Fill, 'min_participants' => 2, 'max_participants' => 5, 'registration_closes_at' => now()->addHour()]);
        $this->fill($starts, 2);
        $cancels = $this->openRoom(['type' => RoomType::Fill, 'min_participants' => 3, 'max_participants' => 5, 'registration_closes_at' => now()->addHour()]);
        $this->fill($cancels, 1);

        $this->travel(61)->minutes();
        $summary = $this->advance();

        $this->assertSame(1, $summary['started']);
        $this->assertSame(1, $summary['cancelled']);
        $this->assertSame(RoomStatus::Starting, $starts->fresh()->status);
        $this->assertSame(RoomStatus::Cancelled, $cancels->fresh()->status);
    }

    public function test_reservations_still_pending_at_start_time_are_expired_and_do_not_count(): void
    {
        $this->useDeferredEntryPayments();
        config(['competition.paid_entries.enabled' => true, 'competition.rooms.reservation_minutes' => 120]);
        $room = $this->openRoom(['entry_fee_minor' => 1_000, 'min_participants' => 1, 'max_participants' => 5, 'scheduled_start_at' => now()->addHour()]);
        $this->fill($room, 2);
        app(RoomEntryService::class)->confirmReservation($room->participants()->first());

        $this->travel(61)->minutes();
        $this->advance();

        $room->refresh();
        $this->assertSame(RoomStatus::Starting, $room->status);
        $this->assertSame(1, $room->participants()->where('status', 'joined')->count());
        $this->assertSame(1, $room->participants()->where('status', 'expired')->count());
        $this->assertSame(1, $room->participants_count);
    }

    public function test_the_scheduler_is_idempotent(): void
    {
        $room = $this->openRoom(['min_participants' => 1, 'max_participants' => 5, 'scheduled_start_at' => now()->addHour()]);
        $this->fill($room, 1);
        $this->travel(61)->minutes();

        $this->artisan('rooms:advance')->expectsOutputToContain('started: 1')->assertSuccessful();
        $this->artisan('rooms:advance')->expectsOutputToContain('started: 0')->assertSuccessful();

        $this->assertSame(1, AuditLog::where('action', 'room.status_changed')->where('new_values->status', 'starting')->count());
    }

    public function test_room_and_competition_heartbeat_runs_every_five_seconds(): void
    {
        // competitions:tick runs the room scheduler and the live engine.
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'competitions:tick'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertSame(5, $event->repeatSeconds);
    }

    /**
     * Reservation-based entry (asynchronous payment), not the default wallet debit.
     */
    protected function useDeferredEntryPayments(): void
    {
        $this->app->bind(EntryPayment::class, DeferredEntryPayment::class);
    }
}
