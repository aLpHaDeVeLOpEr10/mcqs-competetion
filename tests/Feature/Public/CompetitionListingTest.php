<?php

namespace Tests\Feature\Public;

use App\Enums\RoomStatus;
use App\Models\Category;
use App\Models\Question;
use App\Models\QuizPaper;
use App\Models\QuizPaperQuestion;
use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompetitionListingTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_listing_shows_only_upcoming_and_live_public_rooms(): void
    {
        Room::factory()->open()->create(['name' => 'Open Room']);
        Room::factory()->status(RoomStatus::Scheduled)->create(['name' => 'Scheduled Room']);
        Room::factory()->status(RoomStatus::InProgress)->create(['name' => 'Live Room']);
        Room::factory()->status(RoomStatus::Draft)->create(['name' => 'Secret Draft']);
        Room::factory()->status(RoomStatus::Completed)->create(['name' => 'Finished Room']);
        Room::factory()->status(RoomStatus::Cancelled)->create(['name' => 'Cancelled Room']);

        $this->get(route('competitions.index'))
            ->assertOk()
            ->assertSeeInOrder(['Live Room', 'Open Room', 'Scheduled Room'])
            ->assertDontSee('Secret Draft')
            ->assertDontSee('Finished Room')
            ->assertDontSee('Cancelled Room');
    }

    public function test_drafts_never_appear_even_when_all_statuses_are_requested(): void
    {
        Room::factory()->status(RoomStatus::Draft)->create(['name' => 'Secret Draft']);
        Room::factory()->status(RoomStatus::Completed)->create(['name' => 'Finished Room']);

        $this->get(route('competitions.index', ['status' => 'all']))
            ->assertSee('Finished Room')
            ->assertDontSee('Secret Draft');

        $this->get(route('competitions.index', ['status' => 'draft']))
            ->assertDontSee('Secret Draft')
            ->assertSee('Some filters were not recognised');
    }

    public function test_status_filter(): void
    {
        Room::factory()->open()->create(['name' => 'Open Room']);
        Room::factory()->status(RoomStatus::Full)->create(['name' => 'Full Room']);

        $this->get(route('competitions.index', ['status' => 'full']))
            ->assertSee('Full Room')
            ->assertDontSee('Open Room');
    }

    public function test_entry_fee_filter(): void
    {
        Room::factory()->open()->create(['name' => 'Free Room']);
        Room::factory()->open()->withEntryFee(10_000)->create(['name' => 'Paid Room']);

        $this->get(route('competitions.index', ['fee' => 'free']))->assertSee('Free Room')->assertDontSee('Paid Room');
        $this->get(route('competitions.index', ['fee' => 'paid']))->assertSee('Paid Room')->assertDontSee('Free Room')->assertSee('Rs 100');
    }

    public function test_capacity_filter(): void
    {
        Room::factory()->open()->create(['name' => 'Tiny Room', 'max_participants' => 8]);
        Room::factory()->open()->create(['name' => 'Mid Room', 'max_participants' => 30]);
        Room::factory()->open()->create(['name' => 'Huge Room', 'max_participants' => 200]);

        $this->get(route('competitions.index', ['capacity' => 'small']))
            ->assertSee('Tiny Room')->assertDontSee('Mid Room')->assertDontSee('Huge Room');
        $this->get(route('competitions.index', ['capacity' => 'large']))
            ->assertSee('Huge Room')->assertDontSee('Tiny Room');
    }

    public function test_category_filter_uses_the_rooms_quiz_paper(): void
    {
        $science = Category::factory()->create(['name' => 'Science']);
        $history = Category::factory()->create(['name' => 'History']);

        Room::factory()->open()->create(['name' => 'Science Room', 'quiz_paper_id' => $this->paperWith($science)->id]);
        Room::factory()->open()->create(['name' => 'History Room', 'quiz_paper_id' => $this->paperWith($history)->id]);

        $this->get(route('competitions.index', ['category' => $science->id]))
            ->assertSee('Science Room')
            ->assertDontSee('History Room');
    }

    public function test_invalid_filters_are_ignored_with_a_notice(): void
    {
        Room::factory()->open()->create(['name' => 'Free Room']);

        $this->get(route('competitions.index', ['fee' => 'cheap', 'capacity' => 'enormous', 'category' => 999]))
            ->assertOk()
            ->assertSee('Some filters were not recognised and were ignored: fee, capacity, category.')
            ->assertSee('Free Room');
    }

    public function test_cards_show_real_counts_fee_category_start_and_prizes(): void
    {
        $category = Category::factory()->create(['name' => 'Computer Science']);
        $room = Room::factory()->open()->create([
            'name' => 'Card Room',
            'quiz_paper_id' => $this->paperWith($category)->id,
            'max_participants' => 20,
            'scheduled_start_at' => '2026-12-01 15:00:00', // UTC
        ]);
        $room->forceFill(['participants_count' => 7])->save();
        $room->prizeTiers()->create(['rank_from' => 1, 'rank_to' => 1, 'amount_minor' => 50_000]);
        $room->prizeTiers()->create(['rank_from' => 2, 'rank_to' => 3, 'amount_minor' => 10_000]);

        $this->get(route('competitions.index'))
            ->assertSee('Card Room')
            ->assertSee('Computer Science')
            ->assertSee('Free')
            ->assertSee('7')
            ->assertSee('/ 20 players')
            ->assertSee('Rs 700')               // 500 + 2 × 100
            ->assertSee('Tue, 1 Dec 2026 · 8:00 PM PKT');  // shown in Pakistan time
    }

    public function test_listing_is_paginated(): void
    {
        Room::factory()->open()->count(14)->sequence(fn ($s) => ['name' => 'Room #'.($s->index + 1)])->create();

        $this->get(route('competitions.index'))
            ->assertSee('Showing')
            ->assertSee('of <span class="font-medium text-ink-100">14</span> competitions', false)
            ->assertSee('rel="next"', false);

        $this->get(route('competitions.index', ['page' => 2]))->assertOk()->assertSee('13–14');
    }

    public function test_pagination_keeps_filters(): void
    {
        Room::factory()->open()->count(13)->create();

        $this->get(route('competitions.index', ['fee' => 'free']))
            ->assertSee('fee=free&amp;page=2', false);
    }

    public function test_empty_states(): void
    {
        $this->get(route('competitions.index'))->assertSee('No upcoming competitions yet');
        $this->get(route('competitions.index', ['fee' => 'paid']))->assertSee('No competitions match these filters');
    }

    protected function paperWith(Category $category): QuizPaper
    {
        $paper = QuizPaper::factory()->published()->create();
        QuizPaperQuestion::create([
            'quiz_paper_id' => $paper->id,
            'question_id' => Question::factory()->withOptions()->create(['category_id' => $category->id])->id,
            'position' => 1,
        ]);

        return $paper;
    }
}
