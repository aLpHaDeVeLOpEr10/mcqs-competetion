<?php

namespace Tests\Feature\Engine;

use App\Broadcasting\CompetitionChannel;
use App\Enums\ParticipantStatus;
use App\Enums\UserStatus;
use App\Events\Competition\CompetitionStarting;
use App\Events\Competition\QuestionOpened;
use App\Models\RoomParticipant;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesLiveCompetition;
use Tests\TestCase;

class PlayStateAndChannelTest extends TestCase
{
    use CreatesLiveCompetition, RefreshDatabase;

    protected function state(User $user)
    {
        return $this->actingAs($user)->getJson(route('play.state', $this->room));
    }

    public function test_countdown_state_has_the_start_time_but_no_question_content(): void
    {
        $this->startingCompetition();
        $this->advance();

        $state = $this->state($this->players[0])->assertOk()->json();

        $this->assertSame('countdown', $state['session']['status']);
        $this->assertSame($this->session->starts_at->format('Y-m-d\TH:i:s.vP'), $state['session']['starts_at']);
        $this->assertNull($state['question']);
        $this->assertStringNotContainsString($this->question(1)->body, json_encode($state));
    }

    public function test_refreshing_or_reconnecting_restores_the_same_question_and_deadline(): void
    {
        $this->liveCompetition();
        $q1 = $this->question(1);

        $first = $this->state($this->players[0])->assertOk()->json();
        $this->travel(4)->seconds();
        $second = $this->state($this->players[0])->assertOk()->json();

        $this->assertSame($q1->id, $second['question']['id']);
        $this->assertSame($first['question']['closes_at'], $second['question']['closes_at'], 'A refresh never resets the deadline.');
        $this->assertSame($q1->closes_at->format('Y-m-d\TH:i:s.vP'), $second['question']['closes_at']);
        $this->assertNotSame($first['server_now'], $second['server_now']);
        $this->assertCount($q1->options()->count(), $second['question']['options']);

        $json = json_encode($second);
        foreach (['is_correct', 'explanation', 'correct_marker', 'source_option_id', 'image_path'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_an_answer_from_one_tab_is_visible_to_every_tab(): void
    {
        $this->liveCompetition();
        $q1 = $this->question(1);
        $option = $this->optionId($q1);

        $this->answer($this->players[0], $q1, $option)->assertOk();

        $this->assertSame($option, $this->state($this->players[0])->json('my_answer.option_id'));
        $this->assertNull($this->state($this->players[1])->json('my_answer'));
    }

    public function test_between_questions_the_next_opening_time_is_shown_without_content(): void
    {
        $this->liveCompetition();
        $this->travelTo($this->question(1)->closes_at->copy()->addSecond());
        $this->advance();

        $state = $this->state($this->players[0])->json();

        $this->assertNull($state['question']);
        $this->assertSame($this->question(2)->opens_at->format('Y-m-d\TH:i:s.vP'), $state['next_opens_at']);
        $this->assertStringNotContainsString($this->question(2)->body, json_encode($state));
    }

    public function test_play_pages_require_a_seat(): void
    {
        $this->liveCompetition();

        $this->get(route('play.show', $this->room))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('play.show', $this->room))->assertForbidden();
        $this->actingAs(User::factory()->create())->getJson(route('play.state', $this->room))->assertForbidden();

        $this->actingAs($this->players[0])->get(route('play.show', $this->room))
            ->assertOk()
            ->assertSee('data-state-url="'.route('play.state', $this->room).'"', false)
            ->assertDontSee('is_correct');
    }

    public function test_question_images_are_served_only_to_players_once_delivered(): void
    {
        Storage::fake('local');
        $path = 'question-images/'.str_repeat('a', 40).'.webp';
        Storage::disk('local')->put($path, 'RIFF....WEBP');

        // The image is on the bank question before publishing, so the snapshot captures it.
        $paper = $this->publishedPaper();
        $paper->items()->where('position', 1)->firstOrFail()->question->forceFill(['image_path' => $path])->save();
        $this->startingCompetition(3, ['quiz_paper_id' => $paper->id]);
        $q1 = $this->question(1);
        $this->assertSame($path, $q1->image_path);

        $this->actingAs($this->players[0])->get(route('play.image', $q1))->assertNotFound();

        $this->advance();
        $this->travel(10)->seconds();
        $this->advance();

        $this->actingAs($this->players[0])->get(route('play.image', $q1))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('play.image', $q1))->assertForbidden();
    }

    public function test_channel_authorization_admits_only_seated_active_players(): void
    {
        $this->liveCompetition();
        $channel = new CompetitionChannel;
        $outsider = User::factory()->create();
        $withdrawn = User::factory()->create();
        RoomParticipant::factory()->create(['room_id' => $this->room->id, 'user_id' => $withdrawn->id, 'status' => ParticipantStatus::Withdrawn]);

        $this->assertTrue($channel->join($this->players[0], $this->session));
        $this->assertFalse($channel->join($outsider, $this->session));
        $this->assertFalse($channel->join($withdrawn, $this->session));

        $this->players[1]->forceFill(['status' => UserStatus::Suspended])->save();
        $this->assertFalse($channel->join($this->players[1]->fresh(), $this->session));
    }

    public function test_the_broadcasting_auth_endpoint_signs_only_authorized_subscriptions(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => '1',
        ]);
        // Channels were registered on the boot-time (null) broadcaster; register them on Reverb.
        app(BroadcastManager::class)->forgetDrivers();
        require base_path('routes/channels.php');
        // This test is about subscription auth; don't try to reach a WebSocket server.
        Event::fake([QuestionOpened::class, CompetitionStarting::class]);
        $this->liveCompetition();
        $channel = 'private-competition.'.$this->session->id;
        $payload = ['socket_id' => '1234.5678', 'channel_name' => $channel];

        $this->actingAs($this->players[0])->post('/broadcasting/auth', $payload)
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->actingAs(User::factory()->create())->post('/broadcasting/auth', $payload)->assertForbidden();
        $this->actingAs($this->players[0])->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-competition.999999'])->assertForbidden();
    }
}
