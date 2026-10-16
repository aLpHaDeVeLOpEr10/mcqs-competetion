<?php

namespace Tests\Feature\Public;

use App\Enums\JoinBlockReason;
use App\Enums\ParticipantStatus;
use App\Enums\RoomStatus;
use App\Models\Room;
use App\Models\RoomParticipant;
use App\Models\User;
use App\Services\Competition\JoinEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class JoinEligibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function check(Room $room, ?User $user): ?JoinBlockReason
    {
        return app(JoinEligibilityService::class)->check($room, $user)->reason;
    }

    public static function roomStates(): array
    {
        return [
            [RoomStatus::Scheduled, JoinBlockReason::NotYetOpen],
            [RoomStatus::Full, JoinBlockReason::Full],
            [RoomStatus::InProgress, JoinBlockReason::InProgress],
            [RoomStatus::Completed, JoinBlockReason::Ended],
            [RoomStatus::Cancelled, JoinBlockReason::Cancelled],
            [RoomStatus::Refunded, JoinBlockReason::Cancelled],
        ];
    }

    #[DataProvider('roomStates')]
    public function test_room_state_blocks_joining(RoomStatus $status, JoinBlockReason $expected): void
    {
        $room = Room::factory()->status($status)->create();

        $this->assertSame($expected, $this->check($room, User::factory()->create()));
        $this->assertSame($expected, $this->check($room, null));
    }

    public function test_guests_must_log_in(): void
    {
        $this->assertSame(JoinBlockReason::LoginRequired, $this->check(Room::factory()->open()->create(), null));
    }

    public function test_suspended_accounts_are_restricted(): void
    {
        $room = Room::factory()->open()->create();

        $this->assertSame(JoinBlockReason::AccountRestricted, $this->check($room, User::factory()->suspended()->create()));
    }

    public function test_seat_count_at_capacity_blocks_even_if_status_lags(): void
    {
        $room = Room::factory()->open()->create(['min_participants' => 2, 'max_participants' => 2]);
        $room->forceFill(['participants_count' => 2])->save();

        $this->assertSame(JoinBlockReason::Full, $this->check($room, User::factory()->create()));
    }

    public function test_registration_window_is_respected(): void
    {
        $user = User::factory()->create();
        $notYet = Room::factory()->open()->create(['registration_opens_at' => now()->addHour()]);
        $closed = Room::factory()->open()->create(['registration_opens_at' => now()->subDay(), 'registration_closes_at' => now()->subMinute()]);

        $this->assertSame(JoinBlockReason::NotYetOpen, $this->check($notYet, $user));
        $this->assertSame(JoinBlockReason::RegistrationClosed, $this->check($closed, $user));
    }

    public function test_paid_rooms_are_blocked_while_paid_entry_is_disabled(): void
    {
        config(['competition.paid_entries.enabled' => false]);
        $room = Room::factory()->open()->withEntryFee(10_000)->create();

        $this->assertSame(JoinBlockReason::PaidEntryUnavailable, $this->check($room, User::factory()->create()));
    }

    public function test_existing_seat_is_reported_only_when_the_database_has_it(): void
    {
        $user = User::factory()->create();
        $room = Room::factory()->open()->create();

        $this->assertNotSame(JoinBlockReason::AlreadyJoined, $this->check($room, $user));

        RoomParticipant::factory()->create(['room_id' => $room->id, 'user_id' => $user->id]);
        $this->assertSame(JoinBlockReason::AlreadyJoined, $this->check($room, $user));

        // A seat in a live room is still "registered", not "competition live".
        $room->forceFill(['status' => RoomStatus::InProgress])->save();
        $this->assertSame(JoinBlockReason::AlreadyJoined, $this->check($room, $user));
    }

    public function test_withdrawn_and_disqualified_seats(): void
    {
        $user = User::factory()->create();
        $withdrawn = Room::factory()->open()->create();
        $disqualified = Room::factory()->open()->create();
        RoomParticipant::factory()->create(['room_id' => $withdrawn->id, 'user_id' => $user->id, 'status' => ParticipantStatus::Withdrawn]);
        RoomParticipant::factory()->create(['room_id' => $disqualified->id, 'user_id' => $user->id, 'status' => ParticipantStatus::Disqualified]);

        // A withdrawn player may join again (the same seat row is reused).
        $this->assertNull($this->check($withdrawn, $user));
        $this->assertSame(JoinBlockReason::Disqualified, $this->check($disqualified, $user));
    }

    public function test_an_eligible_user_sees_a_working_join_form(): void
    {
        $room = Room::factory()->open()->create();
        $user = User::factory()->create();

        $this->assertNull($this->check($room, $user));

        $this->actingAs($user)->get(route('competitions.show', $room))
            ->assertSee('action="'.route('competitions.join', $room).'"', false)
            ->assertSee('Join competition')
            ->assertDontSee('Joining opens soon');
    }

    public function test_join_form_appears_once_a_join_route_is_registered(): void
    {
        Route::post('/competitions/{room}/join', fn () => abort(501))->name(JoinEligibilityService::JOIN_ROUTE);
        app('router')->getRoutes()->refreshNameLookups();

        $room = Room::factory()->open()->create();
        $user = User::factory()->create();

        $this->assertNull($this->check($room, $user));

        $this->actingAs($user)->get(route('competitions.show', $room))
            ->assertSee('action="'.url("/competitions/{$room->id}/join").'"', false)
            ->assertSee('Join competition');
    }

    public function test_guest_sees_a_login_link_and_member_sees_confirmed_seat(): void
    {
        $room = Room::factory()->open()->create();

        $this->get(route('competitions.show', $room))
            ->assertSee('Log in to join')
            ->assertSee('href="'.route('login').'"', false);

        $user = User::factory()->create();
        RoomParticipant::factory()->create(['room_id' => $room->id, 'user_id' => $user->id]);

        $this->actingAs($user)->get(route('competitions.show', $room))
            ->assertSee("You're registered")
            ->assertDontSee('Log in to join');
    }

    public function test_listing_resolves_seats_in_a_single_query(): void
    {
        $user = User::factory()->create();
        $rooms = Room::factory()->open()->count(5)->create();
        RoomParticipant::factory()->create(['room_id' => $rooms[2]->id, 'user_id' => $user->id]);

        $queries = 0;
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'room_participants')) {
                $queries++;
            }
        });

        $result = app(JoinEligibilityService::class)->forRooms($rooms, $user);

        $this->assertSame(1, $queries);
        $this->assertSame(JoinBlockReason::AlreadyJoined, $result[$rooms[2]->id]->reason);
        $this->assertNull($result[$rooms[0]->id]->reason);
    }
}
