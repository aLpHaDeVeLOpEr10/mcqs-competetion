<?php

namespace Tests\Feature\Engine;

use App\Models\AnswerSubmission;
use App\Models\User;
use App\Services\Competition\RoomLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesLiveCompetition;
use Tests\TestCase;

class AnswerSubmissionTest extends TestCase
{
    use CreatesLiveCompetition, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->liveCompetition();
    }

    public function test_a_valid_answer_is_recorded_with_server_time_and_derived_outcome(): void
    {
        $q1 = $this->question(1);
        $this->travel(2500)->milliseconds();

        $response = $this->answer($this->players[0], $q1, $this->optionId($q1))
            ->assertOk()
            ->assertJson(['accepted' => true, 'duplicate' => false]);

        // Nothing about correctness, score or timing goes back to the browser.
        $body = $response->getContent();
        foreach (['is_correct', 'points', 'score', 'response_time'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }

        $answer = AnswerSubmission::sole();
        $this->assertSame($q1->id, $answer->session_question_id);
        $this->assertTrue($answer->received_at->equalTo(now()));
        $this->assertSame(2500, $answer->response_time_ms);
        $this->assertTrue($answer->is_correct);
        $this->assertSame($q1->points, $answer->points_awarded);
    }

    public function test_client_supplied_correctness_time_and_score_are_ignored(): void
    {
        $q1 = $this->question(1);

        $this->actingAs($this->players[0])->postJson(route('play.answer', $this->room), [
            'question_id' => $q1->id,
            'option_id' => $this->optionId($q1, correct: false),
            'is_correct' => true,
            'points_awarded' => 999,
            'received_at' => now()->subMinute()->toIso8601String(),
            'response_time_ms' => 1,
        ])->assertOk();

        $answer = AnswerSubmission::sole();
        $this->assertFalse($answer->is_correct);
        $this->assertSame(0, $answer->points_awarded);
        $this->assertTrue($answer->received_at->equalTo(now()));
    }

    public function test_duplicates_are_idempotent_and_the_first_answer_is_final(): void
    {
        $q1 = $this->question(1);
        $first = $this->optionId($q1);

        $this->answer($this->players[0], $q1, $first)->assertOk();
        $this->answer($this->players[0], $q1, $first)->assertOk()->assertJson(['accepted' => true, 'duplicate' => true]);
        $this->answer($this->players[0], $q1, $this->optionId($q1, correct: false))
            ->assertStatus(409)
            ->assertJson(['accepted' => false, 'reason' => 'already_answered', 'my_answer' => ['option_id' => $first]]);

        $this->assertSame(1, AnswerSubmission::count());
        $this->assertSame($first, AnswerSubmission::sole()->session_question_option_id);
    }

    public function test_answers_inside_the_grace_window_count_and_later_ones_are_rejected(): void
    {
        $q1 = $this->question(1);

        $this->travelTo($q1->closes_at->copy()->addMilliseconds(500));
        $this->answer($this->players[0], $q1, $this->optionId($q1))->assertOk();

        $this->travelTo($q1->closes_at->copy()->addMilliseconds(501));
        $this->answer($this->players[1], $q1, $this->optionId($q1))->assertStatus(409)->assertJson(['reason' => 'closed']);

        $this->assertSame(1, AnswerSubmission::count());
    }

    public function test_late_answers_are_rejected_even_if_the_engine_has_not_closed_the_question_yet(): void
    {
        $q1 = $this->question(1);
        $this->travelTo($q1->closes_at->copy()->addSeconds(5)); // engine not run: question still "current"

        $this->answer($this->players[0], $q1, $this->optionId($q1))->assertStatus(409)->assertJson(['reason' => 'closed']);
        $this->assertNull($q1->fresh()->closed_at);
    }

    public function test_out_of_sequence_and_not_yet_open_questions_are_rejected(): void
    {
        $q1 = $this->question(1);
        $q2 = $this->question(2);

        $this->answer($this->players[0], $q2, $this->optionId($q2))->assertStatus(409)->assertJson(['reason' => 'out_of_sequence']);

        // Close question 1; question 2 is now current but opens after the 3-second gap.
        $this->travelTo($q1->closes_at->copy()->addSecond());
        $this->advance();
        $this->answer($this->players[0], $q2, $this->optionId($q2))->assertStatus(409)->assertJson(['reason' => 'not_open']);
        $this->answer($this->players[0], $q1, $this->optionId($q1))->assertStatus(409)->assertJson(['reason' => 'out_of_sequence']);

        $this->assertSame(0, AnswerSubmission::count());
    }

    public function test_options_must_belong_to_the_question_and_input_must_be_well_formed(): void
    {
        $q1 = $this->question(1);
        $q2 = $this->question(2);

        $this->answer($this->players[0], $q1, $this->optionId($q2))->assertStatus(422)->assertJson(['reason' => 'invalid_option']);
        $this->actingAs($this->players[0])->postJson(route('play.answer', $this->room), ['question_id' => 'abc', 'option_id' => []])
            ->assertStatus(422)->assertJsonValidationErrors(['question_id', 'option_id']);

        $this->answer($this->players[0], $q1, 999999)->assertStatus(422);
        $this->assertSame(0, AnswerSubmission::count());
    }

    public function test_only_seated_players_of_a_live_competition_can_answer(): void
    {
        $q1 = $this->question(1);

        $this->postJson(route('play.answer', $this->room), ['question_id' => $q1->id, 'option_id' => $this->optionId($q1)])->assertUnauthorized();
        $this->answer(User::factory()->create(), $q1, $this->optionId($q1))->assertForbidden();

        // After the competition is aborted, answers stop.
        app(RoomLifecycle::class)->cancel($this->room, null, 'Fault');
        $this->answer($this->players[0], $q1, $this->optionId($q1))->assertStatus(409)->assertJson(['reason' => 'not_live']);

        $this->assertSame(0, AnswerSubmission::count());
    }
}
