<?php

namespace Tests\Feature\Engine;

use App\Enums\ParticipantStatus;
use App\Enums\RoomStatus;
use App\Enums\SessionStatus;
use App\Events\Competition\CompetitionCompleted;
use App\Events\Competition\CompetitionProgressUpdated;
use App\Events\Competition\CompetitionStarting;
use App\Events\Competition\QuestionClosed;
use App\Events\Competition\QuestionOpened;
use App\Jobs\AdvanceCompetition;
use App\Models\AuditLog;
use App\Models\RoomParticipant;
use App\Services\Competition\RoomLifecycle;
use App\Services\Engine\CompetitionEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CreatesLiveCompetition;
use Tests\TestCase;

class CompetitionEngineTest extends TestCase
{
    use CreatesLiveCompetition, RefreshDatabase;

    protected array $liveEvents = [CompetitionStarting::class, QuestionOpened::class, QuestionClosed::class, CompetitionProgressUpdated::class, CompetitionCompleted::class];

    public function test_preparation_records_start_time_and_configuration_and_is_idempotent(): void
    {
        Event::fake($this->liveEvents);
        $this->startingCompetition();

        $this->advance();
        $this->advance();

        $this->assertSame(SessionStatus::Countdown, $this->session->status);
        $this->assertTrue($this->session->starts_at->equalTo($this->room->starts_at));
        $this->assertSame('global_deadline', $this->session->config['timing_policy']);
        $this->assertSame(5, $this->session->config['question_count']);
        $this->assertSame(500, $this->session->config['answer_grace_ms']);
        $this->assertSame(1, AuditLog::where('action', 'competition.prepared')->count());
        Event::assertDispatchedTimes(CompetitionStarting::class, 1);
    }

    public function test_nothing_starts_before_the_authoritative_start_time(): void
    {
        $this->startingCompetition();
        $this->advance();

        $this->travel(9)->seconds();
        $this->advance();

        $this->assertSame(SessionStatus::Countdown, $this->session->status);
        $this->assertNull($this->question(1)->opens_at);
    }

    public function test_start_locks_participants_and_opens_question_one_with_a_shared_deadline(): void
    {
        Event::fake($this->liveEvents);
        $this->liveCompetition();

        $q1 = $this->question(1);
        $this->assertSame(SessionStatus::InProgress, $this->session->status);
        $this->assertSame(RoomStatus::InProgress, $this->room->status);
        $this->assertSame(1, $this->session->current_question_position);
        $this->assertSame(3, RoomParticipant::where('room_id', $this->room->id)->where('status', ParticipantStatus::Playing)->count());
        $this->assertTrue($q1->opens_at->equalTo(now()));
        $this->assertTrue($q1->closes_at->equalTo(now()->addSeconds($q1->time_limit_seconds)));
        $this->assertNotNull($q1->delivered_at);

        Event::assertDispatched(QuestionOpened::class, function (QuestionOpened $event) use ($q1) {
            $payload = json_encode($event->broadcastWith());

            return $event->question->is($q1)
                && $event->broadcastOn()[0]->name === 'private-competition.'.$this->session->id
                && ! str_contains($payload, 'is_correct')
                && ! str_contains($payload, 'explanation')
                && ! str_contains($payload, 'correct');
        });
    }

    public function test_question_closes_only_after_deadline_plus_grace_and_next_opens_after_the_gap(): void
    {
        Event::fake($this->liveEvents);
        $this->liveCompetition();
        $q1 = $this->question(1);

        $this->travelTo($q1->closes_at->copy()->addMilliseconds(499));
        $this->advance();
        $this->assertNull($this->question(1)->closed_at);

        $this->travelTo($q1->closes_at->copy()->addMilliseconds(500));
        $this->advance();

        $q2 = $this->question(2);
        $this->assertNotNull($this->question(1)->closed_at);
        $this->assertSame(2, $this->session->current_question_position);
        $this->assertTrue($q2->opens_at->equalTo(now()->addSeconds(3)));
        $this->assertNull($q2->delivered_at);

        Event::assertDispatched(QuestionClosed::class, fn ($e) => ! str_contains(json_encode($e->broadcastWith()), 'correct'));
        Event::assertDispatched(CompetitionProgressUpdated::class, fn ($e) => $e->answered === 0 && $e->players === 3);

        $this->travel(3)->seconds();
        $this->advance();
        $this->assertNotNull($this->question(2)->delivered_at);
    }

    public function test_a_full_competition_runs_to_completion(): void
    {
        Event::fake($this->liveEvents);
        $this->liveCompetition();

        for ($position = 1; $position <= 5; $position++) {
            $question = $this->question($position);
            $this->travelTo($question->opens_at);
            $this->advance();
            $this->travelTo($question->fresh()->closes_at->copy()->addSecond());
            $this->advance();
        }

        // The live part ends in "scoring"; results are then finalized automatically.
        $this->assertSame(SessionStatus::Completed, $this->session->status);
        $this->assertSame(RoomStatus::Completed, $this->room->status);
        $this->assertSame(1, $this->session->resultVersions()->count());
        $this->assertNotNull($this->session->ended_at);
        $this->assertSame(3, RoomParticipant::where('room_id', $this->room->id)->where('status', ParticipantStatus::Finished)->count());
        $this->assertSame(5, $this->session->questions()->whereNotNull('closed_at')->count());
        Event::assertDispatchedTimes(QuestionOpened::class, 5);
        Event::assertDispatched(CompetitionCompleted::class, fn ($e) => $e->broadcastWith()['status'] === 'scoring');
        $this->assertNull(app(CompetitionEngine::class)->advance($this->session->id));
    }

    public function test_repeated_advances_at_the_same_moment_are_idempotent(): void
    {
        Event::fake($this->liveEvents);
        $this->liveCompetition();
        $audits = AuditLog::count();

        foreach (range(1, 5) as $i) {
            $this->advance();
        }

        Event::assertDispatchedTimes(QuestionOpened::class, 1);
        $this->assertSame($audits, AuditLog::count());
        $this->assertSame(1, $this->session->current_question_position);
    }

    public function test_late_delivery_gives_players_the_full_time_limit(): void
    {
        $this->liveCompetition();
        $this->travelTo($this->question(1)->closes_at->copy()->addSecond());
        $this->advance(); // q2 planned for now + 3 s

        // The engine only runs again 4 seconds after q2's planned opening (worker down).
        $planned = $this->question(2)->opens_at->copy();
        $this->travelTo($planned->copy()->addSeconds(4));
        $this->advance();

        $q2 = $this->question(2);
        $this->assertTrue($q2->delivered_at->equalTo(now()));
        $this->assertTrue($q2->opens_at->equalTo(now()), 'The window starts at actual delivery, not the plan.');
        $this->assertTrue($q2->closes_at->equalTo(now()->addSeconds($q2->time_limit_seconds)), 'Full time limit preserved.');

        // Delivered windows never move again.
        $this->travel(2)->seconds();
        $this->advance();
        $this->assertTrue($q2->closes_at->equalTo($this->question(2)->closes_at));
    }

    public function test_an_undelivered_question_is_neither_visible_nor_answerable(): void
    {
        $this->liveCompetition();
        $this->travelTo($this->question(1)->closes_at->copy()->addSecond());
        $this->advance();
        $q2 = $this->question(2);

        // Planned opening time has passed, but the engine has not delivered it yet.
        $this->travelTo($q2->opens_at->copy()->addSecond());

        $state = $this->actingAs($this->players[0])->getJson(route('play.state', $this->room))->json();
        $this->assertNull($state['question']);

        $this->answer($this->players[0], $q2, $this->optionId($q2))->assertStatus(409)->assertJson(['reason' => 'not_open']);
    }

    public function test_after_a_long_outage_questions_are_delayed_not_skipped(): void
    {
        $this->liveCompetition();
        $q1 = $this->question(1);

        // Workers and scheduler were down for ten minutes.
        $this->travel(10)->minutes();
        $this->advance();

        $q2 = $this->question(2);
        $this->assertNotNull($this->question(1)->closed_at);
        $this->assertSame(2, $this->session->current_question_position);
        $this->assertTrue($q2->opens_at->equalTo(now()->addSeconds(3)), 'Question 2 opens after the gap, from now.');
        $this->assertSame($q2->time_limit_seconds, (int) $q2->opens_at->diffInSeconds($q2->closes_at), 'Its full time limit is preserved.');
        $this->assertNull($this->question(3)->opens_at);
        $this->assertTrue($q1->closes_at->equalTo($this->question(1)->closes_at), 'An existing deadline never moves.');
    }

    public function test_wake_up_jobs_are_scheduled_for_the_next_due_moment(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();
        $this->liveCompetition();

        $q1 = $this->question(1);
        Queue::assertPushed(AdvanceCompetition::class, function (AdvanceCompetition $job) use ($q1) {
            return $job->sessionId === $this->session->id
                && $job->delay !== null
                && $job->delay->equalTo($q1->closes_at->copy()->addMilliseconds(500));
        });

        // Running the job (e.g. twice, after a worker restart) is safe.
        $this->travelTo($q1->closes_at->copy()->addSecond());
        (new AdvanceCompetition($this->session->id))->handle(app(CompetitionEngine::class));
        (new AdvanceCompetition($this->session->id))->handle(app(CompetitionEngine::class));
        $this->assertSame(2, $this->session->fresh()->current_question_position);
    }

    public function test_the_tick_command_recovers_without_any_queue_worker(): void
    {
        $this->startingCompetition();

        $this->artisan('competitions:tick')->expectsOutputToContain('sessions advanced: 1')->assertSuccessful();
        $this->assertSame(SessionStatus::Countdown, $this->session->fresh()->status);

        $this->travel(10)->seconds();
        $this->artisan('competitions:tick')->assertSuccessful();
        $this->assertSame(SessionStatus::InProgress, $this->session->fresh()->status);
        $this->assertNotNull($this->question(1)->delivered_at);
    }

    public function test_a_websocket_outage_does_not_stop_the_competition(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => '1',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => 1, // nothing listens here
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'broadcasting.connections.reverb.options.useTLS' => false,
        ]);
        Log::spy();

        $this->liveCompetition();

        $this->assertSame(SessionStatus::InProgress, $this->session->status);
        $this->assertNotNull($this->question(1)->delivered_at);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'broadcast failed'))->atLeast()->once();
    }

    public function test_cancelling_a_live_room_aborts_the_session(): void
    {
        Event::fake($this->liveEvents);
        $this->liveCompetition();

        app(RoomLifecycle::class)->cancel($this->room, null, 'Technical fault');

        $this->session->refresh();
        $this->assertSame(SessionStatus::Aborted, $this->session->status);
        $this->assertSame('Technical fault', $this->session->abort_reason);
        Event::assertDispatched(CompetitionCompleted::class, fn ($e) => $e->broadcastWith()['status'] === 'aborted');

        $this->travel(1)->hour();
        $this->assertNull(app(CompetitionEngine::class)->advance($this->session->id));
    }
}
