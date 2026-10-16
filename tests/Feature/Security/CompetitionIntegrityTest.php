<?php

namespace Tests\Feature\Security;

use App\Enums\UserStatus;
use App\Models\AnswerSubmission;
use App\Models\RiskFlag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesLiveCompetition;
use Tests\TestCase;

class CompetitionIntegrityTest extends TestCase
{
    use CreatesLiveCompetition, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->liveCompetition();
    }

    public function test_a_question_from_another_room_cannot_be_answered_through_this_room(): void
    {
        [$roomA, $playerA] = [$this->room, $this->players[0]];
        $this->liveCompetition();                    // a second live room
        $foreign = $this->question(1);

        $this->actingAs($playerA)->postJson(route('play.answer', $roomA), ['question_id' => $foreign->id, 'option_id' => $this->optionId($foreign)])
            ->assertStatus(422)->assertJson(['reason' => 'unknown_question']);
        // ...nor through the other room, where they have no seat.
        $this->actingAs($playerA)->postJson(route('play.answer', $this->room), ['question_id' => $foreign->id, 'option_id' => $this->optionId($foreign)])
            ->assertForbidden();

        $this->assertSame(0, AnswerSubmission::count());
    }

    public function test_instant_answers_and_answer_changes_are_signals_not_rejections(): void
    {
        $q1 = $this->question(1);

        // 0 ms after opening: accepted (fast players exist), but recorded for review.
        $this->answer($this->players[0], $q1, $this->optionId($q1))->assertOk()->assertJson(['accepted' => true]);
        $flag = RiskFlag::query()->where('type', 'rapid_answers')->sole();
        $this->assertSame([$this->players[0]->id, 'watching'], [$flag->user_id, $flag->status]);
        $this->assertTrue($flag->subject->is($this->session));

        // A normally paced answer is not a signal.
        $this->travel(3)->seconds();
        $this->answer($this->players[1], $q1, $this->optionId($q1))->assertOk();
        $this->assertSame(1, RiskFlag::query()->where('type', 'rapid_answers')->count());

        // Trying to change a final answer is refused and counted.
        $this->answer($this->players[0], $q1, $this->optionId($q1, correct: false))->assertStatus(409);
        $this->assertSame(1, RiskFlag::query()->where('type', 'duplicate_answer_attempts')->sole()->occurrences);
        $this->assertSame(2, AnswerSubmission::count());
        $this->assertTrue(AnswerSubmission::all()->every->is_correct, 'The first answer stands.');
    }

    public function test_state_polling_never_uses_up_the_answer_budget(): void
    {
        $q1 = $this->question(1);
        $player = $this->players[0];

        // Far more polling than the answer limit allows (WebSocket down, several tabs).
        for ($i = 0; $i < 90; $i++) {
            $this->actingAs($player)->getJson(route('play.state', $this->room))->assertOk();
        }
        $this->travel(2)->seconds();

        $this->answer($player, $q1, $this->optionId($q1))->assertOk()->assertJson(['accepted' => true]);
    }

    public function test_answer_floods_are_rate_limited_per_room_and_recorded(): void
    {
        $q1 = $this->question(1);
        $player = $this->players[0];
        $this->travel(2)->seconds();

        for ($i = 0; $i < 60; $i++) {
            $this->answer($player, $q1, $this->optionId($q1))->assertOk();
        }
        $this->answer($player, $q1, $this->optionId($q1))->assertStatus(429);

        $flag = RiskFlag::query()->where('type', 'rate_limited')->sole();
        $this->assertSame([$player->id, 'play.answer'], [$flag->user_id, $flag->details['route']]);
        $this->assertSame(1, AnswerSubmission::count());

        // Other players are unaffected.
        $this->answer($this->players[1], $q1, $this->optionId($q1))->assertOk();
    }

    public function test_reloads_and_several_devices_during_a_live_competition_are_signals(): void
    {
        $player = $this->players[0];
        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($player)->get(route('play.show', $this->room))->assertOk();
        }
        $this->assertSame([10, 'open'], [
            RiskFlag::query()->where('type', 'frequent_reloads')->value('occurrences'),
            RiskFlag::query()->where('type', 'frequent_reloads')->value('status'),
        ]);

        config(['session.driver' => 'database']);
        foreach (['phone', 'laptop'] as $device) {
            DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $player->id, 'ip_address' => '10.0.0.1', 'user_agent' => $device, 'payload' => '', 'last_activity' => now()->getTimestamp()]);
        }
        $this->actingAs($player)->get(route('play.show', $this->room))->assertOk();

        $flag = RiskFlag::query()->where('type', 'multiple_sessions')->sole();
        $this->assertSame(['open', 'low'], [$flag->status, $flag->severity]);
        $this->assertGreaterThanOrEqual(2, $flag->details['active_sessions']);
    }

    public function test_a_suspended_player_cannot_keep_answering(): void
    {
        $q1 = $this->question(1);
        $player = $this->players[0];
        $player->forceFill(['status' => UserStatus::Suspended])->save();

        $response = $this->actingAs($player)->postJson(route('play.answer', $this->room), ['question_id' => $q1->id, 'option_id' => $this->optionId($q1)]);

        $response->assertUnauthorized();
        $this->assertSame(0, AnswerSubmission::count());
    }

    public function test_private_user_channels_admit_only_their_owner(): void
    {
        $callback = app('Illuminate\Contracts\Broadcasting\Broadcaster')->getChannels()['App.Models.User.{id}'];
        $user = User::factory()->create();

        $this->assertTrue($callback($user, (string) $user->id));
        $this->assertFalse($callback($user, (string) ($user->id + 1)));
    }
}
