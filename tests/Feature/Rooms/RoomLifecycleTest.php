<?php

namespace Tests\Feature\Rooms;

use App\Contracts\Payments\EntryPayment;
use App\Enums\ParticipantStatus;
use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Enums\UserRole;
use App\Exceptions\AdministrativeActionException;
use App\Models\AuditLog;
use App\Models\QuizPaper;
use App\Models\Room;
use App\Models\User;
use App\Services\Competition\RoomEntryService;
use App\Services\Competition\RoomLifecycle;
use App\Services\Payments\DeferredEntryPayment;
use App\Support\DisplayTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesRooms;
use Tests\TestCase;

class RoomLifecycleTest extends TestCase
{
    use CreatesRooms, RefreshDatabase;

    protected User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->role(UserRole::CompetitionManager)->create();
    }

    protected function formPayload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Friday Night Quiz',
            'description' => 'Ten questions, fast.',
            'quiz_paper_id' => $this->publishedPaper()->id,
            'type' => 'scheduled',
            'entry_fee' => '0',
            'max_participants' => 20,
            'min_participants' => 3,
            'registration_closes_at' => now('Asia/Karachi')->addDay()->setTime(19, 30)->format('Y-m-d\TH:i'),
            'scheduled_start_at' => now('Asia/Karachi')->addDay()->setTime(20, 0)->format('Y-m-d\TH:i'),
            'countdown_seconds' => 15,
            'question_gap_seconds' => 3,
            'tie_break_policy' => 'fastest_correct',
            'under_capacity_policy' => 'start_if_minimum',
            'allow_withdrawal' => '1',
            'prizes' => [
                ['rank_from' => 1, 'rank_to' => 1, 'amount' => '1,000'],
                ['rank_from' => 2, 'rank_to' => 3, 'amount' => '250.50'],
            ],
        ];
    }

    public function test_managers_create_draft_rooms_with_pkt_times_and_exact_money(): void
    {
        $this->actingAs($this->manager)->post(route('admin.rooms.store'), $this->formPayload())->assertRedirect()->assertSessionHasNoErrors();

        $room = Room::sole();
        $this->assertSame(RoomStatus::Draft, $room->status);
        $this->assertSame('15:00:00', $room->scheduled_start_at->format('H:i:s')); // 20:00 PKT stored as UTC
        $this->assertSame('14:30:00', $room->registration_closes_at->format('H:i:s'));
        $this->assertSame([[1, 1, 100_000], [2, 3, 25_050]], $room->prizeTiers->map(fn ($t) => [$t->rank_from, $t->rank_to, $t->amount_minor])->all());
        $this->assertSame(1, AuditLog::where('action', 'room.created')->count());
    }

    public function test_form_validation(): void
    {
        $draftPaper = QuizPaper::factory()->create();

        $this->actingAs($this->manager)->post(route('admin.rooms.store'), $this->formPayload([
            'quiz_paper_id' => $draftPaper->id,
            'entry_fee' => '12.345',
            'min_participants' => 50,
            'type' => 'whenever',
            'prizes' => [['rank_from' => 3, 'rank_to' => 1, 'amount' => '10'], ['rank_from' => 1, 'rank_to' => '', 'amount' => '']],
        ]))->assertSessionHasErrors(['quiz_paper_id', 'entry_fee', 'min_participants', 'type', 'prizes.0.rank_to', 'prizes.1.amount']);
    }

    public function test_publish_validation_problems(): void
    {
        $cases = [
            'start too soon' => [['scheduled_start_at' => now()->addMinute()], 'at least 5 minutes'],
            'fill room without deadline' => [['type' => RoomType::Fill, 'scheduled_start_at' => null, 'registration_closes_at' => null], 'need a registration deadline'],
            'closes after start' => [['registration_closes_at' => now()->addDays(3)], 'no later than the scheduled start'],
            'paid while disabled' => [['entry_fee_minor' => 5_000], 'Paid entries are disabled'],
        ];

        foreach ($cases as $name => [$attributes, $expected]) {
            $room = Room::factory()->create($attributes + ['quiz_paper_id' => $this->publishedPaper()->id]);

            $this->actingAs($this->manager)->patch(route('admin.rooms.publish', $room))->assertSessionHas('error');
            $this->assertTrue(collect(session('problems'))->contains(fn ($p) => str_contains($p, $expected)), "{$name}: ".implode(' | ', session('problems') ?? []));
            $this->assertSame(RoomStatus::Draft, $room->fresh()->status);
        }
    }

    public function test_prize_ranks_must_be_contiguous_and_within_capacity(): void
    {
        $room = $this->draftRoom(['max_participants' => 5]);
        $room->prizeTiers()->createMany([
            ['rank_from' => 1, 'rank_to' => 1, 'amount_minor' => 100],
            ['rank_from' => 3, 'rank_to' => 6, 'amount_minor' => 50],
        ]);

        $this->actingAs($this->manager)->patch(route('admin.rooms.publish', $room));

        $this->assertContains('Prize ranks must start at 1 and continue without gaps or overlaps.', session('problems'));
    }

    public function test_publishing_snapshots_the_paper_and_opens_registration(): void
    {
        $room = $this->draftRoom();

        $this->actingAs($this->manager)->patch(route('admin.rooms.publish', $room))->assertSessionHas('success');

        $room->refresh();
        $this->assertSame(RoomStatus::Open, $room->status);
        $this->assertNotNull($room->published_at);
        $this->assertSame(5, $room->session->questions()->count());
        $this->assertSame(['draft' => 'open'], [AuditLog::where('action', 'room.status_changed')->sole()->old_values['status'] => AuditLog::where('action', 'room.status_changed')->sole()->new_values['status']]);
    }

    public function test_future_registration_publishes_as_scheduled_and_the_scheduler_opens_it(): void
    {
        $room = $this->draftRoom(['registration_opens_at' => now()->addHour(), 'scheduled_start_at' => now()->addDay()]);
        app(RoomLifecycle::class)->publish($this->manager, $room);
        $this->assertSame(RoomStatus::Scheduled, $room->fresh()->status);

        $this->travel(61)->minutes();
        $this->artisan('rooms:advance')->assertSuccessful();

        $this->assertSame(RoomStatus::Open, $room->fresh()->status);
    }

    public function test_critical_settings_lock_after_publishing(): void
    {
        $room = $this->openRoom();
        $payload = fn (array $o = []) => $o + [
            'name' => $room->name, 'quiz_paper_id' => $room->quiz_paper_id, 'type' => $room->type->value, 'entry_fee' => '0',
            'max_participants' => $room->max_participants, 'min_participants' => $room->min_participants,
            'scheduled_start_at' => DisplayTime::local($room->scheduled_start_at)->format('Y-m-d\TH:i'),
            'countdown_seconds' => $room->countdown_seconds, 'question_gap_seconds' => $room->question_gap_seconds,
            'tie_break_policy' => $room->tie_break_policy->value, 'under_capacity_policy' => $room->under_capacity_policy->value, 'allow_withdrawal' => '1',
        ];

        $this->actingAs($this->manager)->put(route('admin.rooms.update', $room), $payload(['max_participants' => 999]))->assertSessionHas('error');
        $this->actingAs($this->manager)->put(route('admin.rooms.update', $room), $payload(['prizes' => [['rank_from' => 1, 'rank_to' => 1, 'amount' => '100']]]))->assertSessionHas('error');
        $this->assertSame(50, $room->fresh()->max_participants);

        $this->actingAs($this->manager)->put(route('admin.rooms.update', $room), $payload(['name' => 'Renamed room']))->assertSessionHas('success');
        $this->assertSame('Renamed room', $room->fresh()->name);

        // The edit form resubmits locked values unchanged.
        $this->actingAs($this->manager)->get(route('admin.rooms.edit', $room))->assertSee('Competition settings are locked');
    }

    public function test_cancellation_releases_seats_keeps_history_and_is_audited(): void
    {
        $this->useDeferredEntryPayments();
        config(['competition.paid_entries.enabled' => true]);
        $room = $this->openRoom(['entry_fee_minor' => 1_000]);
        $reserved = app(RoomEntryService::class)->join(User::factory()->create(), $room)['seat'];

        $this->actingAs($this->manager)->patch(route('admin.rooms.cancel', $room), ['reason' => '', 'confirm' => null])
            ->assertSessionHasErrorsIn('cancel', ['reason', 'confirm']);

        $this->actingAs($this->manager)->patch(route('admin.rooms.cancel', $room), ['reason' => 'Venue problem', 'confirm' => '1'])->assertSessionHas('success');

        $room->refresh();
        $this->assertSame(RoomStatus::Cancelled, $room->status);
        $this->assertSame('Venue problem', $room->cancellation_reason);
        $this->assertSame(ParticipantStatus::Expired, $reserved->fresh()->status);
        $this->assertDatabaseHas('room_participants', ['id' => $reserved->id]);

        $this->expectException(AdministrativeActionException::class);
        app(RoomLifecycle::class)->cancel($room, $this->manager, 'again');
    }

    public function test_invalid_transitions_are_refused(): void
    {
        $room = $this->openRoom();

        $this->expectException(AdministrativeActionException::class);
        app(RoomLifecycle::class)->transition($room, RoomStatus::Completed, $this->manager);
    }

    public function test_only_room_managers_can_create_publish_or_cancel(): void
    {
        $room = $this->draftRoom();

        foreach ([UserRole::ContentManager, UserRole::FinanceManager, UserRole::Player] as $role) {
            $user = User::factory()->role($role)->create();
            $this->actingAs($user)->post(route('admin.rooms.store'), $this->formPayload())->assertForbidden();
            $this->actingAs($user)->patch(route('admin.rooms.publish', $room))->assertForbidden();
            $this->actingAs($user)->patch(route('admin.rooms.cancel', $room), ['reason' => 'nope nope', 'confirm' => '1'])->assertForbidden();
        }

        $this->assertSame(RoomStatus::Draft, $room->fresh()->status);
    }

    /**
     * Reservation-based entry (asynchronous payment), not the default wallet debit.
     */
    protected function useDeferredEntryPayments(): void
    {
        $this->app->bind(EntryPayment::class, DeferredEntryPayment::class);
    }
}
