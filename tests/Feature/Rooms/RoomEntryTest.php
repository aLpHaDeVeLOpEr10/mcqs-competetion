<?php

namespace Tests\Feature\Rooms;

use App\Contracts\Payments\EntryPayment;
use App\Enums\JoinBlockReason;
use App\Enums\ParticipantStatus;
use App\Enums\RoomStatus;
use App\Enums\UserStatus;
use App\Exceptions\AdministrativeActionException;
use App\Exceptions\EntryDeniedException;
use App\Models\AuditLog;
use App\Models\RoomParticipant;
use App\Models\User;
use App\Services\Competition\RoomEntryService;
use App\Services\Competition\RoomScheduler;
use App\Services\Payments\DeferredEntryPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesRooms;
use Tests\TestCase;

class RoomEntryTest extends TestCase
{
    use CreatesRooms, RefreshDatabase;

    protected function entries(): RoomEntryService
    {
        return app(RoomEntryService::class);
    }

    protected function denied(callable $attempt): JoinBlockReason
    {
        try {
            $attempt();
        } catch (EntryDeniedException $e) {
            return $e->reason;
        }

        $this->fail('The entry was not denied.');
    }

    public function test_free_join_confirms_a_seat_with_server_timestamps_and_audit(): void
    {
        $room = $this->openRoom(['max_participants' => 5]);
        $user = User::factory()->create();
        $this->travelTo(now()->setMicrosecond(0));

        $result = $this->entries()->join($user, $room);

        $this->assertSame(RoomEntryService::JOINED, $result['outcome']);
        $seat = $result['seat']->fresh();
        $this->assertSame(ParticipantStatus::Joined, $seat->status);
        $this->assertSame(0, $seat->entry_fee_minor);
        $this->assertTrue($seat->joined_at->equalTo(now()));
        $this->assertNotNull($seat->confirmed_at);
        $this->assertSame(1, $room->fresh()->participants_count);
        $this->assertSame(1, AuditLog::where('action', 'room.seat_joined')->count());
    }

    public function test_joining_twice_is_idempotent(): void
    {
        $room = $this->openRoom();
        $user = User::factory()->create();

        $first = $this->entries()->join($user, $room);
        $second = $this->entries()->join($user, $room);

        $this->assertSame(RoomEntryService::ALREADY, $second['outcome']);
        $this->assertTrue($first['seat']->is($second['seat']));
        $this->assertSame(1, RoomParticipant::count());
        $this->assertSame(1, $room->fresh()->participants_count);
    }

    public function test_capacity_is_enforced_and_the_room_becomes_full(): void
    {
        $room = $this->openRoom(['min_participants' => 2, 'max_participants' => 2]);
        [$a, $b, $c] = User::factory()->count(3)->create();

        $this->entries()->join($a, $room);
        $this->entries()->join($b, $room);

        $this->assertSame(RoomStatus::Full, $room->fresh()->status);
        $this->assertSame(JoinBlockReason::Full, $this->denied(fn () => $this->entries()->join($c, $room)));
        $this->assertSame(2, $room->fresh()->participants_count);
    }

    public function test_withdrawal_releases_the_seat_and_rejoin_reuses_the_row(): void
    {
        $room = $this->openRoom(['min_participants' => 2, 'max_participants' => 2]);
        [$a, $b] = User::factory()->count(2)->create();
        $this->entries()->join($a, $room);
        $seat = $this->entries()->join($b, $room)['seat'];

        $this->entries()->withdraw($b, $room);

        $this->assertSame(ParticipantStatus::Withdrawn, $seat->fresh()->status);
        $this->assertSame(RoomStatus::Open, $room->fresh()->status);
        $this->assertSame(1, $room->fresh()->participants_count);

        $again = $this->entries()->join($b, $room)['seat'];
        $this->assertTrue($again->is($seat));
        $this->assertSame(ParticipantStatus::Joined, $again->fresh()->status);
        $this->assertSame(2, RoomParticipant::count());
    }

    public function test_withdrawal_rules(): void
    {
        $noWithdrawals = $this->openRoom(['allow_withdrawal' => false]);
        $user = User::factory()->create();
        $this->entries()->join($user, $noWithdrawals);

        $this->expectException(AdministrativeActionException::class);
        $this->entries()->withdraw($user, $noWithdrawals);
    }

    public function test_cannot_join_or_withdraw_after_the_registration_cutoff(): void
    {
        $room = $this->openRoom(['registration_closes_at' => now()->addHour(), 'scheduled_start_at' => now()->addHours(2)]);
        $early = User::factory()->create();
        $this->entries()->join($early, $room);

        $this->travel(61)->minutes();

        $this->assertSame(JoinBlockReason::RegistrationClosed, $this->denied(fn () => $this->entries()->join(User::factory()->create(), $room)));
        $this->assertNotNull($this->entries()->withdrawalBlocker($room->fresh(), RoomParticipant::first()));
    }

    public function test_ineligible_accounts_are_refused(): void
    {
        $room = $this->openRoom();

        $this->assertSame(JoinBlockReason::AccountRestricted, $this->denied(fn () => $this->entries()->join(User::factory()->create(['status' => UserStatus::Restricted]), $room)));
        $this->assertSame(JoinBlockReason::EmailNotVerified, $this->denied(fn () => $this->entries()->join(User::factory()->unverified()->create(), $room)));
        $this->assertSame(0, RoomParticipant::count());
    }

    public function test_paid_rooms_are_refused_while_paid_entries_are_disabled(): void
    {
        config(['competition.paid_entries.enabled' => true]);
        $room = $this->openRoom(['entry_fee_minor' => 10_000]);
        config(['competition.paid_entries.enabled' => false]);

        $this->assertSame(JoinBlockReason::PaidEntryUnavailable, $this->denied(fn () => $this->entries()->join(User::factory()->create(), $room)));
    }

    public function test_paid_entry_reserves_a_seat_without_charging(): void
    {
        $this->useDeferredEntryPayments();
        config(['competition.paid_entries.enabled' => true, 'competition.rooms.reservation_minutes' => 10]);
        $room = $this->openRoom(['entry_fee_minor' => 25_000]);
        $user = User::factory()->create();

        $result = $this->entries()->join($user, $room);

        $this->assertSame(RoomEntryService::RESERVED, $result['outcome']);
        $seat = $result['seat']->fresh();
        $this->assertSame(ParticipantStatus::Reserved, $seat->status);
        $this->assertSame(25_000, $seat->entry_fee_minor);
        $this->assertTrue($seat->reserved_until->between(now()->addMinutes(9), now()->addMinutes(10)));
        $this->assertSame(1, $room->fresh()->participants_count);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertSame(RoomEntryService::ALREADY, $this->entries()->join($user, $room)['outcome']);
    }

    public function test_expired_reservations_free_their_seat_lazily_on_the_next_join(): void
    {
        $this->useDeferredEntryPayments();
        config(['competition.paid_entries.enabled' => true, 'competition.rooms.reservation_minutes' => 10]);
        $room = $this->openRoom(['entry_fee_minor' => 5_000, 'min_participants' => 1, 'max_participants' => 1]);
        [$slow, $next] = User::factory()->count(2)->create();

        $seat = $this->entries()->join($slow, $room)['seat'];
        $this->assertSame(RoomStatus::Full, $room->fresh()->status);
        $this->assertSame(JoinBlockReason::Full, $this->denied(fn () => $this->entries()->join($next, $room)));

        $this->travel(11)->minutes();

        $this->assertSame(RoomEntryService::RESERVED, $this->entries()->join($next, $room)['outcome']);
        $this->assertSame(ParticipantStatus::Expired, $seat->fresh()->status);
        $this->assertNotNull($seat->fresh()->expired_at);
        $this->assertSame(1, $room->fresh()->participants_count);
        $this->assertSame(1, AuditLog::where('action', 'room.seat_expired')->count());
    }

    public function test_the_scheduler_expires_abandoned_reservations(): void
    {
        $this->useDeferredEntryPayments();
        config(['competition.paid_entries.enabled' => true]);
        $room = $this->openRoom(['entry_fee_minor' => 5_000, 'min_participants' => 1, 'max_participants' => 3]);
        $seat = $this->entries()->join(User::factory()->create(), $room)['seat'];

        $this->assertSame(0, app(RoomScheduler::class)->advance()['reservations_expired']);
        $this->travel(config('competition.rooms.reservation_minutes') + 1)->minutes();
        $this->assertSame(1, app(RoomScheduler::class)->advance()['reservations_expired']);

        $this->assertSame(ParticipantStatus::Expired, $seat->fresh()->status);
        $this->assertSame(0, $room->fresh()->participants_count);
    }

    public function test_reservations_can_be_confirmed_only_while_active(): void
    {
        $this->useDeferredEntryPayments();
        config(['competition.paid_entries.enabled' => true]);
        $room = $this->openRoom(['entry_fee_minor' => 5_000]);
        $active = $this->entries()->join(User::factory()->create(), $room)['seat'];
        $late = $this->entries()->join(User::factory()->create(), $room)['seat'];

        $this->entries()->confirmReservation($active, 'pay-ref-1');
        $this->assertSame(ParticipantStatus::Joined, $active->fresh()->status);
        $this->assertSame('pay-ref-1', $active->fresh()->payment_reference);

        $this->travel(config('competition.rooms.reservation_minutes') + 1)->minutes();
        $this->expectException(AdministrativeActionException::class);
        $this->entries()->confirmReservation($late);
    }

    public function test_http_join_and_withdraw(): void
    {
        $room = $this->openRoom();
        $user = User::factory()->create();

        $this->post(route('competitions.join', $room))->assertRedirect(route('login'));

        $this->actingAs($user)->post(route('competitions.join', $room))
            ->assertRedirect(route('competitions.show', $room))->assertSessionHas('success', "You're in! Your seat is confirmed.");
        $this->actingAs($user)->post(route('competitions.join', $room))->assertSessionHas('success', 'You already have a seat in this room.');
        $this->assertSame(1, $room->fresh()->participants_count);

        $this->actingAs($user)->get(route('competitions.show', $room))->assertSee("You're registered")->assertSee('Withdraw from this room');

        $this->actingAs($user)->post(route('competitions.withdraw', $room))->assertSessionHas('success');
        $this->assertSame(0, $room->fresh()->participants_count);
    }

    public function test_http_join_reports_server_side_refusals(): void
    {
        $room = $this->draftRoom();

        $this->actingAs(User::factory()->create())->post(route('competitions.join', $room))
            ->assertSessionHas('error', JoinBlockReason::NotYetOpen->message());
        $this->assertSame(0, RoomParticipant::count());
    }

    /**
     * Reservation-based entry (asynchronous payment), not the default wallet debit.
     */
    protected function useDeferredEntryPayments(): void
    {
        $this->app->bind(EntryPayment::class, DeferredEntryPayment::class);
    }
}
