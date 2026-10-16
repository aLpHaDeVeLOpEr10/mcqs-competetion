<?php

namespace Tests\Feature\Rooms;

use App\Enums\PrizeMode;
use App\Enums\PrizePoolBasis;
use App\Enums\UserRole;
use App\Models\Room;
use App\Models\User;
use App\Services\Competition\RoomConfigValidator;
use App\Services\Competition\RoomLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesRooms;
use Tests\TestCase;

class PrizeConfigurationTest extends TestCase
{
    use CreatesRooms, RefreshDatabase;

    protected User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        config(['competition.paid_entries.enabled' => true]);
        $this->manager = User::factory()->role(UserRole::CompetitionManager)->create();
    }

    protected function payload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Percentage Cup',
            'quiz_paper_id' => $this->publishedPaper()->id,
            'type' => 'scheduled',
            'entry_fee' => '100',
            'max_participants' => 20,
            'min_participants' => 3,
            'scheduled_start_at' => now('Asia/Karachi')->addDay()->setTime(20, 0)->format('Y-m-d\TH:i'),
            'countdown_seconds' => 15,
            'question_gap_seconds' => 3,
            'tie_break_policy' => 'fastest_correct',
            'under_capacity_policy' => 'start_if_minimum',
            'prize_mode' => 'percentage',
            'prize_pool_basis' => 'entry_fees',
            'platform_fee' => '12.5',
            'prize_review_required' => '1',
            'prizes' => [
                ['rank_from' => 1, 'rank_to' => 1, 'amount' => '50'],
                ['rank_from' => 2, 'rank_to' => 3, 'amount' => '12.25'],
            ],
        ];
    }

    public function test_percentage_prizes_from_entry_fees_are_saved_in_basis_points(): void
    {
        $this->actingAs($this->manager)->post(route('admin.rooms.store'), $this->payload())->assertSessionHasNoErrors();

        $room = Room::sole();
        $this->assertSame(PrizeMode::Percentage, $room->prize_mode);
        $this->assertSame(PrizePoolBasis::EntryFees, $room->prize_pool_basis);
        $this->assertSame(1250, $room->platform_fee_bp);
        $this->assertTrue($room->prize_review_required);
        $this->assertNull($room->prize_pool_minor);
        $this->assertSame([[1, 1, 0, 5000], [2, 3, 0, 1225]], $room->prizeTiers->map(fn ($t) => [$t->rank_from, $t->rank_to, $t->amount_minor, $t->percent_bp])->all());
        $this->assertSame([], app(RoomConfigValidator::class)->problems($room));

        // The edit form shows percentages back, and the public page explains the pool.
        $this->actingAs($this->manager)->get(route('admin.rooms.edit', $room))->assertSee('value="12.25"', false)->assertSee('value="12.5"', false);
        app(RoomLifecycle::class)->publish($this->manager, $room);
        $this->get(route('competitions.show', $room))->assertSee('50% of the pool')->assertSee('12.25% of the pool')
            ->assertSee('entry fees collected, minus refunds, minus a 12.5% platform fee')->assertSee('Depends on entries');
    }

    public function test_a_guaranteed_pool_needs_an_amount_and_shows_the_split(): void
    {
        $this->actingAs($this->manager)->post(route('admin.rooms.store'), $this->payload(['prize_pool_basis' => 'guaranteed', 'entry_fee' => '0']))
            ->assertSessionHasErrors('prize_pool');

        $this->actingAs($this->manager)->post(route('admin.rooms.store'), $this->payload(['prize_pool_basis' => 'guaranteed', 'prize_pool' => '1,000', 'entry_fee' => '0']))
            ->assertSessionHasNoErrors();
        $room = Room::sole();
        $this->assertSame(100_000, $room->prize_pool_minor);
        $this->assertSame(0, $room->platform_fee_bp, 'A platform fee only applies to pools funded by entry fees.');
        $this->assertSame(74_500, $room->prizeTotalMinor(), '50 % + 2 × 12.25 % of Rs 1,000.');
    }

    public function test_invalid_percentage_configurations_are_refused(): void
    {
        $this->actingAs($this->manager)->post(route('admin.rooms.store'), $this->payload(['prizes' => [['rank_from' => 1, 'rank_to' => 1, 'amount' => '120']]]))
            ->assertSessionHasErrors('prizes.0.amount');
        $this->actingAs($this->manager)->post(route('admin.rooms.store'), $this->payload(['prize_pool_basis' => '']))
            ->assertSessionHasErrors('prize_pool_basis');

        // Saved as a draft, but publishing explains what is wrong.
        $this->actingAs($this->manager)->post(route('admin.rooms.store'), $this->payload([
            'entry_fee' => '0',
            'prizes' => [['rank_from' => 1, 'rank_to' => 1, 'amount' => '60'], ['rank_from' => 2, 'rank_to' => 2, 'amount' => '50']],
        ]))->assertSessionHasNoErrors();

        $problems = app(RoomConfigValidator::class)->problems(Room::sole());
        $this->assertContains('Prize percentages add up to more than 100% of the pool.', $problems);
        $this->assertContains('A prize pool funded by entry fees needs an entry fee.', $problems);
    }

    public function test_prize_rules_are_locked_once_published(): void
    {
        $this->actingAs($this->manager)->post(route('admin.rooms.store'), $this->payload())->assertSessionHasNoErrors();
        $room = Room::sole();
        app(RoomLifecycle::class)->publish($this->manager, $room);

        $this->actingAs($this->manager)->put(route('admin.rooms.update', $room), $this->payload(['platform_fee' => '30']))
            ->assertSessionHas('error');
        $this->assertSame(1250, $room->fresh()->platform_fee_bp);
    }
}
