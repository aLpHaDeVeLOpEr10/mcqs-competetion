<?php

namespace Tests\Feature\Account;

use App\Enums\LedgerTransactionType;
use App\Enums\ParticipantStatus;
use App\Enums\RoomStatus;
use App\Enums\UserRole;
use App\Models\Room;
use App\Models\RoomParticipant;
use App\Models\User;
use App\Services\Competition\RoomEntryService;
use App\Services\Competition\RoomLifecycle;
use App\Services\Results\ResultsFinalizer;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesDomainRecords;
use Tests\Support\PlaysCompetitions;
use Tests\TestCase;

class PlayerDashboardTest extends TestCase
{
    use CreatesDomainRecords, PlaysCompetitions, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['competition.prizes.payouts_enabled' => true]);
    }

    /**
     * Player 0 wins Rs 100 (5 correct), player 1 finishes second without a prize.
     */
    protected function finishedCompetition(): void
    {
        $this->competitionWithPrizes(2, [[1, 1, 10_000]], ['name' => 'Finished Cup']);
        $plan = [];
        foreach (range(1, 5) as $q) {
            $plan[$q] = [0 => [1000, true], 1 => [2000, $q <= 2]];
        }
        $this->playOut($plan);
        $this->travelBack();
    }

    public function test_the_dashboard_shows_server_derived_figures(): void
    {
        $this->finishedCompetition();
        $winner = $this->players[0];

        $this->actingAs($winner)->get(route('account.show'))->assertOk()
            ->assertSeeInOrder(['Wallet balance', 'Rs 100', 'Upcoming', '0', 'Completed', '1', '1 win', 'Prizes received', 'Rs 100'])
            ->assertSee('Finished Cup')
            ->assertSee('Credited')
            ->assertSee('Prize')
            ->assertSee('You won a prize in Finished Cup')
            ->assertSee('You finished #1. Rs 100 has been added to your wallet.')
            ->assertSee('4 new'); // registered, started, results published, prize

        // The runner-up has played but won nothing.
        $this->actingAs($this->players[1])->get(route('account.show'))->assertOk()
            ->assertSee('0 wins')->assertSee('No prizes yet')->assertDontSee('You won');
    }

    public function test_a_new_player_sees_empty_states_everywhere(): void
    {
        $user = User::factory()->create();
        app(WalletService::class)->walletFor($user); // created at registration

        $this->actingAs($user)->get(route('account.show'))->assertOk()
            ->assertSee('Nothing coming up')->assertSee('No prizes yet')->assertSee('No transactions yet')->assertSee('No notifications');
        foreach (['upcoming' => 'Nothing coming up', 'live' => 'No live competitions', 'completed' => 'No completed competitions yet', 'cancelled' => 'Nothing cancelled'] as $tab => $text) {
            $this->actingAs($user)->get(route('account.competitions', ['tab' => $tab]))->assertOk()->assertSee($text);
        }
        $this->actingAs($user)->get(route('account.competitions.history'))->assertOk()->assertSee('No finished competitions yet');
        $this->actingAs($user)->get(route('account.notifications'))->assertOk()->assertSee('No notifications');
        $this->actingAs($user)->get(route('account.wallet'))->assertOk()->assertSee('Withdrawals are not available yet');
        $this->actingAs($user)->get(route('account.transactions', ['type' => 'prize_payout']))->assertOk()->assertSee('No prizes yet');
    }

    public function test_competitions_are_grouped_into_tabs(): void
    {
        $this->finishedCompetition();
        $player = $this->players[0];

        $upcoming = $this->openRoom(['name' => 'Upcoming Room', 'min_participants' => 2, 'max_participants' => 10]);
        app(RoomEntryService::class)->join($player, $upcoming);

        $left = $this->openRoom(['name' => 'Left Room', 'min_participants' => 2, 'max_participants' => 10]);
        app(RoomEntryService::class)->join($player, $left);
        app(RoomEntryService::class)->withdraw($player, $left);

        $cancelled = $this->openRoom(['name' => 'Cancelled Room', 'min_participants' => 2, 'max_participants' => 10]);
        app(RoomEntryService::class)->join($player, $cancelled);
        app(RoomLifecycle::class)->cancel($cancelled, null, 'Venue problem');

        $live = $this->openRoom(['name' => 'Live Room', 'min_participants' => 2, 'max_participants' => 10]);
        app(RoomEntryService::class)->join($player, $live);
        $live->forceFill(['status' => RoomStatus::InProgress])->save();

        $expect = [
            'upcoming' => ['Upcoming Room'],
            'live' => ['Live Room'],
            'completed' => ['Finished Cup'],
            'cancelled' => ['Left Room', 'Cancelled Room'],
        ];
        $all = array_merge(...array_values($expect));

        foreach ($expect as $tab => $names) {
            // Only the page body: the header bell lists recent notifications, which name rooms too.
            $main = str($this->actingAs($player)->get(route('account.competitions', ['tab' => $tab]))->assertOk()->getContent())->after('<main')->before('</main>')->toString();
            foreach ($all as $name) {
                $this->assertSame(in_array($name, $names, true), str_contains($main, e($name)), "{$tab} tab and {$name}");
            }
        }

        // Card details: fee, players, participation and the published result with prize.
        $this->actingAs($player)->get(route('account.competitions', ['tab' => 'completed']))
            ->assertSee('Free')->assertSee('2 / 2')->assertSee('Played')->assertSee('#1 · 5 correct')->assertSee('Rs 100')->assertSee('Credited');
        $this->actingAs($player)->get(route('account.competitions', ['tab' => 'cancelled']))->assertSee('You withdrew');
        $this->actingAs($player)->get(route('account.show'))->assertSee('You are in a live competition')->assertSee('Live Room');

        // Unknown tabs fall back to "upcoming".
        $main = str($this->actingAs($player)->get(route('account.competitions', ['tab' => 'everything']))->assertOk()->getContent())->after('<main')->before('</main>');
        $this->assertTrue($main->contains('Upcoming Room'));
        $this->assertFalse($main->contains('Finished Cup'));
    }

    public function test_history_is_paginated(): void
    {
        $user = User::factory()->create();
        $paper = $this->publishedPaper();
        Room::factory()->count(21)->status(RoomStatus::Completed)->create(['quiz_paper_id' => $paper->id])
            ->each(fn (Room $room, int $i) => RoomParticipant::factory()->create([
                'room_id' => $room->id, 'user_id' => $user->id, 'status' => ParticipantStatus::Finished, 'joined_at' => now()->subMinutes($i),
            ]));

        $this->actingAs($user)->get(route('account.competitions.history'))->assertOk()
            ->assertSee('Showing')->assertSee('21')->assertSee('Awaiting results');
        $rows = fn (int $page) => preg_match_all('#/account/competitions/\d+"#',
            $this->actingAs($user)->get(route('account.competitions.history', ['page' => $page]))->assertOk()->getContent());
        $this->assertSame(20, $rows(1));
        $this->assertSame(1, $rows(2));
    }

    public function test_results_and_correct_answers_are_hidden_until_publication(): void
    {
        config(['competition.results.auto_publish' => false]);
        $this->finishedCompetition();
        $player = $this->players[1];
        $room = $this->room;

        $this->actingAs($player)->get(route('account.competitions.show', $room))->assertOk()
            ->assertSee('Results are not published yet')
            ->assertSee('Your answers')->assertSee('Submitted ·')->assertSee('Your answer:')
            ->assertDontSee('Correct answer:')->assertDontSee('Incorrect ·');
        $this->actingAs($player)->get(route('account.competitions.history'))->assertSee('Awaiting results')->assertDontSee('#2');

        app(ResultsFinalizer::class)->publish($this->session->id, User::factory()->role(UserRole::Admin)->create());

        $this->actingAs($player)->get(route('account.competitions.show', $room))->assertOk()
            ->assertSee('Correct answer:')->assertSee('Incorrect ·')->assertSee('Correct ·')
            ->assertSeeInOrder(['Rank', '#2', 'Score', '2', 'Correct', '2']);
        $this->actingAs($player)->get(route('account.competitions.history'))->assertSee('#2')->assertSee('Final');
    }

    public function test_one_player_cannot_reach_another_players_private_data(): void
    {
        $this->finishedCompetition();
        [$owner, $other] = [$this->players[0], User::factory()->create(['display_name' => 'Outsider'])];
        app(WalletService::class)->walletFor($other); // created at registration
        $prize = $owner->roomParticipations()->first()->prizePayout;
        $payment = $this->makePayment($owner->wallet, 'owner-deposit');

        // Wallet: own balance only, and another player's transaction is refused.
        $this->actingAs($other)->get(route('account.wallet', ['wallet_id' => $owner->wallet->id, 'user_id' => $owner->id]))
            ->assertOk()->assertSee('Rs 0')->assertDontSee($prize->ledgerTransaction->reference);
        $this->actingAs($other)->get(route('account.transactions', ['wallet' => $owner->wallet->id]))->assertDontSee($prize->ledgerTransaction->reference);
        $this->actingAs($other)->get(route('account.transactions.show', $prize->ledgerTransaction->reference))->assertForbidden();

        // Competition history: not seated in the room → not found; lists show only own seats.
        $this->actingAs($other)->get(route('account.competitions.show', $this->room))->assertNotFound();
        $this->actingAs($other)->get(route('account.competitions', ['tab' => 'completed']))->assertDontSee('Finished Cup');
        $this->actingAs($other)->get(route('account.competitions.history'))->assertDontSee('Finished Cup');

        // Payment records.
        $this->actingAs($other)->get(route('account.deposits.show', $payment->reference))->assertNotFound();
        $this->actingAs($other)->get(route('account.deposits.index'))->assertDontSee($payment->reference);

        // Notifications: marking own as read never touches someone else's.
        $ownerUnread = $owner->unreadNotifications()->count();
        $this->assertGreaterThan(0, $ownerUnread);
        $this->actingAs($other)->post(route('account.notifications.read'))->assertRedirect();
        $this->assertSame($ownerUnread, $owner->unreadNotifications()->count());
        $this->actingAs($other)->get(route('account.notifications'))->assertDontSee('You won');

        // Profile controls act only on the signed-in user; privileged fields are ignored.
        $this->actingAs($other)->patch(route('account.profile.update'), [
            'name' => 'Outsider Name', 'display_name' => 'Outsider2', 'user_id' => $owner->id, 'id' => $owner->id,
            'role' => UserRole::SuperAdmin->value, 'status' => 'active', 'email' => 'stolen@example.test',
        ])->assertRedirect();
        $owner->refresh();
        $other->refresh();
        $this->assertNotSame('Outsider2', $owner->display_name);
        $this->assertSame('Outsider2', $other->display_name);
        $this->assertSame(UserRole::Player, $other->role);
        $this->assertNotSame('stolen@example.test', $other->email);
        $this->actingAs($other)->get(route('admin.users.show', $owner))->assertForbidden();

        // Guests are sent to log in.
        $this->signOut();
        foreach (['account.show', 'account.competitions', 'account.competitions.history', 'account.wallet', 'account.notifications'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
    }

    public function test_notifications_can_be_marked_read(): void
    {
        $this->finishedCompetition();
        $winner = $this->players[0];

        $this->actingAs($winner)->get(route('account.notifications'))->assertOk()->assertSee('Mark all as read')->assertSee('Unread:');
        $this->actingAs($winner)->post(route('account.notifications.read'))->assertSessionHas('success');
        $this->assertSame(0, $winner->unreadNotifications()->count());
        $this->actingAs($winner)->get(route('account.notifications'))->assertDontSee('Mark all as read');
    }

    public function test_the_wallet_breaks_money_down_by_kind_and_filters_history(): void
    {
        $this->finishedCompetition();
        $winner = $this->players[0];
        $wallets = app(WalletService::class);
        $wallets->credit($winner->wallet, 50_000, LedgerTransactionType::Deposit, 'deposit-1');

        $this->actingAs($winner)->get(route('account.wallet'))->assertOk()
            ->assertSeeInOrder(['Deposits', 'Rs 500', 'Entry fees', 'Rs 0', 'Refunds', 'Rs 0', 'Prize credits', 'Rs 100', 'Withdrawals']);
        $this->actingAs($winner)->get(route('account.transactions', ['type' => 'prize_payout']))->assertOk()->assertSee('+Rs 100')->assertDontSee('+Rs 500');
        $this->actingAs($winner)->get(route('account.transactions', ['type' => 'not-a-type']))->assertOk()->assertSee('+Rs 500')->assertSee('+Rs 100');
    }
}
