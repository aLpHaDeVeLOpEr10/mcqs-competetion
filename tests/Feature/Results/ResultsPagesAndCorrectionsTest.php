<?php

namespace Tests\Feature\Results;

use App\Enums\DisputeStatus;
use App\Enums\ParticipantStatus;
use App\Enums\PayoutStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\ParticipantResult;
use App\Models\PrizePayout;
use App\Models\ResultDispute;
use App\Models\ResultVersion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\PlaysCompetitions;
use Tests\TestCase;

class ResultsPagesAndCorrectionsTest extends TestCase
{
    use PlaysCompetitions, RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->competitionWithPrizes(3, [[1, 1, 100_000], [2, 2, 50_000]]);

        // Player 0: Q1 correct (fast), Q2 wrong. Player 1: Q1 correct (slow), Q2 correct. Player 2: nothing.
        $this->playOut([
            1 => [0 => [1000, true], 1 => [4000, true]],
            2 => [0 => [1000, false], 1 => [2000, true]],
        ]);
    }

    protected function resultFor(int $playerIndex): ParticipantResult
    {
        return ParticipantResult::query()->whereHas('participant', fn ($q) => $q->where('user_id', $this->players[$playerIndex]->id))->sole();
    }

    public function test_results_page_shows_ranking_and_reveals_answers_only_to_participants(): void
    {
        $q1 = $this->question(1);
        $correct = $q1->options()->where('is_correct', true)->value('body');

        $public = $this->get(route('competitions.results', $this->room))->assertOk()
            ->assertSeeInOrder([$this->players[1]->display_name, $this->players[0]->display_name]);
        $public->assertDontSee('Your answers');
        foreach ($this->players as $player) {
            $public->assertDontSee($player->email);
        }

        $this->actingAs($this->players[0])->get(route('competitions.results', $this->room))
            ->assertSee('Your result')
            ->assertSee('Your answers')
            ->assertSeeInOrder(['Correct answer:', $correct])
            ->assertSee('Dispute these results');
    }

    public function test_ranking_marks_only_the_signed_in_players_row(): void
    {
        $this->get(route('competitions.results', $this->room))->assertOk()->assertDontSee('(You)');

        $this->actingAs($this->players[0])->get(route('competitions.results', $this->room))
            ->assertSeeInOrder([$this->players[1]->display_name, $this->players[0]->display_name, '(You)'])
            ->assertSeeTextInOrder(['(You)', $this->players[2]->display_name]);

        $this->actingAs($this->admin)->get(route('competitions.results', $this->room))->assertDontSee('(You)');
    }

    public function test_results_page_is_hidden_until_publication(): void
    {
        $this->session->forceFill(['results_published_at' => null])->save();

        $this->get(route('competitions.results', $this->room))->assertNotFound();
    }

    public function test_voiding_a_question_recalculates_as_a_new_version_and_keeps_the_old_one(): void
    {
        $this->assertSame([2, 1], [$this->resultFor(0)->rank, $this->resultFor(1)->rank]);

        $this->actingAs($this->admin)->post(route('admin.results.void', $this->session), [
            'session_question_id' => $this->question(2)->id, 'reason' => 'Two options were correct', 'confirm' => '1',
        ])->assertSessionHas('success');

        // Only Q1 counts now: both have 1 correct; player 0 was faster.
        $this->assertSame([1, 2], [$this->resultFor(0)->rank, $this->resultFor(1)->rank]);
        $this->assertSame(2, $this->resultFor(0)->result_version);
        $this->assertSame([1, 2], ResultVersion::orderBy('version')->pluck('version')->all());
        $this->assertSame('Initial results', ResultVersion::where('version', 1)->value('reason'));
        $this->assertSame(100_000, PrizePayout::where('room_participant_id', $this->resultFor(0)->room_participant_id)->value('amount_minor'));

        $audit = AuditLog::where('action', 'results.corrected')->sole();
        $this->assertSame(['version' => 1], $audit->old_values);
        $this->assertSame(2, $audit->new_values['version']);
        $this->assertSame($this->admin->id, $audit->actor_id);
    }

    public function test_disqualification_removes_the_rank_and_reallocates_prizes(): void
    {
        $winnerSeat = $this->resultFor(1)->room_participant_id;

        $this->actingAs($this->admin)->post(route('admin.results.disqualify', $this->session), [
            'room_participant_id' => $winnerSeat, 'reason' => 'Shared answers', 'confirm' => '1',
        ])->assertSessionHas('success');

        $this->assertNull($this->resultFor(1)->rank);
        $this->assertStringContainsString('Shared answers', $this->resultFor(1)->excluded_reason);
        $this->assertSame(1, $this->resultFor(0)->rank);
        $this->assertSame(PayoutStatus::Cancelled, PrizePayout::where('room_participant_id', $winnerSeat)->value('status'));
        $this->assertSame(100_000, PrizePayout::where('room_participant_id', $this->resultFor(0)->room_participant_id)->value('amount_minor'));
        $this->assertSame(ParticipantStatus::Disqualified, $this->resultFor(1)->participant->status);
    }

    public function test_disputes_can_be_raised_rejected_or_resolved_by_a_correction(): void
    {
        $this->actingAs($this->players[0])->post(route('competitions.disputes.store', $this->room), ['message' => 'Question 2 had two right answers.'])
            ->assertSessionHas('success');
        $this->actingAs($this->players[0])->post(route('competitions.disputes.store', $this->room), ['message' => 'Again, please look at it.'])
            ->assertSessionHas('error');
        $this->actingAs(User::factory()->create())->post(route('competitions.disputes.store', $this->room), ['message' => 'I was not even playing.'])
            ->assertSessionHas('error');

        $dispute = ResultDispute::sole();
        $this->actingAs($this->admin)->post(route('admin.results.void', $this->session), [
            'session_question_id' => $this->question(2)->id, 'reason' => 'Upheld player dispute', 'confirm' => '1', 'dispute_id' => $dispute->id,
        ]);

        $dispute->refresh();
        $this->assertSame(DisputeStatus::Resolved, $dispute->status);
        $this->assertSame([1, 2], [$dispute->result_version_before, $dispute->result_version_after]);

        $this->actingAs($this->players[1])->post(route('competitions.disputes.store', $this->room), ['message' => 'My time looks wrong to me.']);
        $second = ResultDispute::where('room_participant_id', $this->resultFor(1)->room_participant_id)->sole();
        $this->actingAs($this->admin)->post(route('admin.disputes.reject', $second), ['resolution' => 'Times are server-recorded and correct.'])->assertSessionHas('success');
        $this->assertSame(DisputeStatus::Rejected, $second->fresh()->status);
    }

    public function test_disputes_close_after_the_window(): void
    {
        $this->travel(config('competition.results.dispute_window_days') + 1)->days();

        $this->actingAs($this->players[0])->post(route('competitions.disputes.store', $this->room), ['message' => 'Too late but trying anyway.'])
            ->assertSessionHas('error');
        $this->assertSame(0, ResultDispute::count());
    }

    public function test_results_cannot_be_recalculated_after_a_prize_is_paid(): void
    {
        DB::table('prize_payouts')->limit(1)->update(['status' => 'paid']);

        $this->actingAs($this->admin)->post(route('admin.results.void', $this->session), [
            'session_question_id' => $this->question(1)->id, 'reason' => 'Late change', 'confirm' => '1',
        ])->assertSessionHas('error');

        $this->assertSame(1, ResultVersion::count());
        $this->assertNull($this->question(1)->voided_at, 'The whole correction rolled back.');
    }

    public function test_only_results_managers_can_correct_and_corrections_need_reason_and_confirmation(): void
    {
        foreach ([UserRole::CompetitionManager, UserRole::FinanceManager, UserRole::Player] as $role) {
            $this->actingAs(User::factory()->role($role)->create())->post(route('admin.results.void', $this->session), [
                'session_question_id' => $this->question(1)->id, 'reason' => 'Not allowed', 'confirm' => '1',
            ])->assertForbidden();
        }

        $this->actingAs($this->admin)->post(route('admin.results.void', $this->session), ['session_question_id' => $this->question(1)->id])
            ->assertSessionHasErrorsIn('void', ['reason', 'confirm']);

        $this->assertSame(1, ResultVersion::count());

        $this->actingAs(User::factory()->role(UserRole::CompetitionManager)->create())->get(route('admin.results.show', $this->session))->assertOk()->assertDontSee('Corrections');
        $this->actingAs($this->admin)->get(route('admin.results.show', $this->session))->assertOk()->assertSee('Corrections')->assertSee('Result versions');
    }

    public function test_result_versions_are_append_only(): void
    {
        $this->expectException(QueryException::class);
        DB::table('result_versions')->update(['reason' => 'tampered']);
    }

    public function test_the_live_page_links_to_published_results(): void
    {
        $this->actingAs($this->players[0])->getJson(route('play.state', $this->room))
            ->assertJsonPath('session.status', 'completed')
            ->assertJsonPath('results_url', route('competitions.results', $this->room));
    }
}
