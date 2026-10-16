<?php

namespace Tests\Feature\Reliability;

use App\Enums\SessionStatus;
use App\Events\Competition\CompetitionProgressUpdated;
use App\Events\Competition\QuestionClosed;
use App\Events\Competition\QuestionOpened;
use App\Exceptions\AdministrativeActionException;
use App\Jobs\AdvanceCompetition;
use App\Models\AnswerSubmission;
use App\Models\AuditLog;
use App\Models\ResultVersion;
use App\Models\SessionQuestion;
use App\Services\Engine\CompetitionEngine;
use App\Services\Engine\CompetitionWaker;
use App\Services\Engine\PlayerStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\CreatesLiveCompetition;
use Tests\TestCase;

class EngineRecoveryTest extends TestCase
{
    use CreatesLiveCompetition, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->liveCompetition();
    }

    protected function runJob(): void
    {
        (new AdvanceCompetition($this->session->id))->handle(app(CompetitionEngine::class));
        $this->session->refresh();
    }

    /**
     * Engine steps recorded for this session (each happens once per question).
     */
    protected function deliveries(): array
    {
        return $this->session->questions()->orderBy('position')->get()
            ->map(fn (SessionQuestion $q) => [$q->position, $q->delivered_at?->format('H:i:s.v'), $q->closed_at?->format('H:i:s.v')])->all();
    }

    public function test_repeated_wake_ups_for_the_same_moment_queue_one_job(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();
        // The engine's next moment: question 1 closes (deadline + answer grace).
        $closes = $this->question(1)->closes_at->copy()->addMilliseconds((int) config('competition.engine.answer_grace_ms'));

        // The engine and every tick ask for the same next moment: one job.
        foreach (range(1, 5) as $i) {
            app(CompetitionWaker::class)->wake($this->session->id, $closes);
        }
        $this->artisan('competitions:tick')->assertSuccessful();
        $this->artisan('competitions:tick')->assertSuccessful();
        Queue::assertPushed(AdvanceCompetition::class, 1);
        Queue::assertPushedOn('competition', AdvanceCompetition::class);

        // A different moment is a different job.
        app(CompetitionWaker::class)->wake($this->session->id, $closes->copy()->addSeconds(5));
        Queue::assertPushed(AdvanceCompetition::class, 2);
    }

    public function test_duplicate_jobs_perform_each_step_once(): void
    {
        $this->travelTo($this->question(1)->closes_at->copy()->addSecond());

        $this->runJob();
        $after = $this->deliveries();
        $audits = AuditLog::query()->count();

        $this->runJob();
        $this->runJob();
        $this->assertSame($after, $this->deliveries());
        $this->assertSame($audits, AuditLog::query()->count());
        $this->assertNotNull($this->question(1)->closed_at);
        $this->assertNull($this->question(2)->delivered_at, 'The gap before question 2 is respected.');
    }

    public function test_a_late_job_after_an_outage_never_skips_or_shortens_a_question(): void
    {
        $q1 = $this->question(1);
        $this->travel(2)->seconds();
        $this->answer($this->players[0], $q1, $this->optionId($q1))->assertOk();

        // Workers and scheduler down for five minutes.
        $this->travelTo($q1->closes_at->copy()->addMinutes(5));
        $this->runJob();

        $q2 = $this->question(2);
        $this->assertNotNull($this->question(1)->closed_at);
        $this->assertNull($q2->delivered_at, 'Nobody saw question 2 during the outage.');
        $this->assertSame(2, $this->session->current_question_position);

        $this->travelTo($q2->opens_at);
        $this->runJob();
        $q2 = $this->question(2);
        $this->assertSame($q2->time_limit_seconds, (int) $q2->opens_at->diffInSeconds($q2->closes_at), 'Full time for every player.');
        $this->assertSame(1, AnswerSubmission::count(), 'The answer given before the outage is kept; none are invented.');
    }

    public function test_a_crash_mid_transition_rolls_back_and_the_retry_completes_it(): void
    {
        $this->travelTo($this->question(1)->closes_at->copy()->addSecond());
        $crash = true;
        Event::listen('eloquent.saving: '.SessionQuestion::class, function () use (&$crash) {
            if ($crash) {
                $crash = false;
                throw new RuntimeException('Worker killed mid-transition');
            }
        });

        try {
            $this->runJob();
            $this->fail('The simulated crash should surface.');
        } catch (RuntimeException) {
        }
        $this->assertNull($this->question(1)->closed_at, 'Nothing half-applied.');
        $this->assertSame(1, $this->session->fresh()->current_question_position);

        $this->runJob();   // the queue's retry, or the next tick
        $this->assertNotNull($this->question(1)->closed_at);
        $this->assertSame(2, $this->session->current_question_position);
    }

    public function test_the_scheduler_alone_runs_a_whole_competition_across_process_restarts(): void
    {
        $answered = [];

        for ($i = 0; $i < 400 && $this->session->fresh()->status !== SessionStatus::Completed; $i++) {
            $this->travel(1)->seconds();
            // Every so often the "process restarts": in-memory caches and locks are gone.
            if ($i % 7 === 0) {
                Cache::flush();
            }
            $this->artisan('competitions:tick');

            $current = $this->session->fresh()->currentQuestion();
            if ($current?->delivered_at !== null && $current->closed_at === null && ! isset($answered[$current->id])) {
                $answered[$current->id] = true;
                $this->answer($this->players[0], $current, $this->optionId($current))->assertOk();
            }
        }

        $this->session->refresh();
        $this->assertSame(SessionStatus::Completed, $this->session->status);
        $this->assertSame(1, ResultVersion::query()->where('competition_session_id', $this->session->id)->count());
        $this->assertTrue($this->session->questions()->get()->every(fn ($q) => $q->delivered_at !== null && $q->closed_at !== null));
        $this->assertSame(count($answered), AnswerSubmission::count());
        $this->assertSame($this->session->questions()->count(), count($answered));
    }

    public function test_missed_websocket_events_are_recovered_from_persisted_state(): void
    {
        // Reverb is down: every broadcast fails.
        Event::listen([QuestionOpened::class, QuestionClosed::class, CompetitionProgressUpdated::class], fn () => throw new RuntimeException('WebSocket server unreachable'));
        $state = fn () => app(PlayerStateService::class)->forParticipant($this->room, $this->room->participants()->where('user_id', $this->players[0]->id)->first());

        $this->travelTo($this->question(1)->closes_at->copy()->addSecond());
        $this->advance();                                  // QuestionClosed: not delivered to anyone
        $this->travelTo($this->question(2)->opens_at);
        $this->advance();                                  // QuestionOpened: not delivered either

        $q2 = $this->question(2);
        $this->assertNotNull($q2->delivered_at, 'The competition continued without WebSockets.');

        // The browser reconnects / polls: it gets the current question and the original deadline.
        $first = $state();
        $this->assertSame($q2->id, $first['question']['id']);
        $this->assertSame($q2->closes_at->format('Y-m-d\TH:i:s.vP'), $first['question']['closes_at']);

        $this->travel(4)->seconds();
        $again = $state();
        $this->assertSame($first['question']['closes_at'], $again['question']['closes_at'], 'Reconnecting never resets a deadline.');
        $this->assertSame($first['question']['opens_at'], $again['question']['opens_at']);

        // The same through the HTTP endpoint the page polls.
        $this->actingAs($this->players[0])->getJson(route('play.state', $this->room))
            ->assertOk()->assertJsonPath('question.id', $q2->id)->assertJsonPath('question.closes_at', $first['question']['closes_at']);
    }

    public function test_a_permanent_failure_is_not_retried(): void
    {
        $engine = $this->mock(CompetitionEngine::class);
        $engine->shouldReceive('advance')->andThrow(new AdministrativeActionException('A session cannot move from completed to in_progress.'));

        $job = (new AdvanceCompetition($this->session->id))->withFakeQueueInteractions();
        $job->handle($engine);

        $job->assertFailed();
        $this->assertSame([1, 2, 5, 10], $job->backoff());
        $this->assertSame(30, $job->timeout);
    }
}
