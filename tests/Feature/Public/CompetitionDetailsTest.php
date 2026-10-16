<?php

namespace Tests\Feature\Public;

use App\Enums\RoomStatus;
use App\Models\QuizPaper;
use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesPublishedResults;
use Tests\TestCase;

class CompetitionDetailsTest extends TestCase
{
    use CreatesPublishedResults, RefreshDatabase;

    public function test_details_show_fee_capacity_players_start_and_prize_structure(): void
    {
        $room = Room::factory()->open()->withEntryFee(25_000)->create([
            'name' => 'Evening Showdown',
            'min_participants' => 4,
            'max_participants' => 40,
            'scheduled_start_at' => '2026-12-01 15:00:00',
        ]);
        $room->forceFill(['participants_count' => 12])->save();
        $room->prizeTiers()->create(['rank_from' => 1, 'rank_to' => 1, 'amount_minor' => 300_000]);
        $room->prizeTiers()->create(['rank_from' => 2, 'rank_to' => 5, 'amount_minor' => 50_000]);

        $this->get(route('competitions.show', $room))
            ->assertOk()
            ->assertSee('Evening Showdown')
            ->assertSee('Rs 250')
            ->assertSee('/ 40 players')
            ->assertSee('Tue, 1 Dec 2026 · 8:00 PM PKT')
            ->assertSeeInOrder(['#1', 'Rs 3,000', '#2–5', 'Rs 500', 'Rs 2,000', 'Total prizes', 'Rs 5,000']);
    }

    public function test_room_without_prizes_says_so(): void
    {
        $room = Room::factory()->open()->create();

        $this->get(route('competitions.show', $room))->assertSee('No prizes for this room');
    }

    public function test_draft_rooms_are_not_public(): void
    {
        $room = Room::factory()->status(RoomStatus::Draft)->create();

        $this->get(route('competitions.show', $room))->assertNotFound();
    }

    public function test_questions_and_answer_keys_are_never_exposed(): void
    {
        $paper = QuizPaper::factory()->published()->withQuestions(3)->create();
        $room = Room::factory()->open()->create(['quiz_paper_id' => $paper->id]);

        $response = $this->get(route('competitions.show', $room))->assertOk()->assertSee('Questions');

        foreach ($paper->questions()->with('options')->get() as $question) {
            $response->assertDontSee($question->body);
            foreach ($question->options as $option) {
                $response->assertDontSee($option->body);
            }
        }
        $response->assertDontSee('is_correct');
    }

    public function test_question_count_is_shown(): void
    {
        $paper = QuizPaper::factory()->published()->withQuestions(4)->create();
        $room = Room::factory()->open()->create(['quiz_paper_id' => $paper->id]);

        $this->get(route('competitions.show', $room))->assertSeeInOrder(['Questions', '4']);
    }

    public function test_published_ranking_shows_display_names_only(): void
    {
        $room = $this->completedRoom([['quizwhiz', 9, 1], ['fastfinger', 7, 2]]);
        $emails = $room->participants()->with('user')->get()->pluck('user.email');
        $realNames = $room->participants()->with('user')->get()->pluck('user.name');

        $response = $this->get(route('competitions.show', $room))
            ->assertSee('Final results')
            ->assertSeeInOrder(['#1', 'quizwhiz', '9', '#2', 'fastfinger', '7']);

        $emails->each(fn ($email) => $response->assertDontSee($email));
        $realNames->each(fn ($name) => $response->assertDontSee($name));
    }

    public function test_unpublished_results_are_hidden(): void
    {
        $room = $this->completedRoom([['quizwhiz', 9, 1]], published: false);

        $this->get(route('competitions.show', $room))
            ->assertDontSee('Final results')
            ->assertDontSee('quizwhiz');
    }

    public function test_scoring_modal_has_a_no_javascript_fallback_link(): void
    {
        $room = Room::factory()->open()->create();

        $this->get(route('competitions.show', $room))
            ->assertSee('href="'.route('how-to-play').'#scoring" data-modal-open="scoring-modal"', false)
            ->assertSee('<dialog id="scoring-modal"', false);
    }
}
