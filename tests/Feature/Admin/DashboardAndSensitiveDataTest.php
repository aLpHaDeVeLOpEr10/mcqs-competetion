<?php

namespace Tests\Feature\Admin;

use App\Enums\LedgerTransactionType;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\RoomStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\PaymentTransaction;
use App\Models\Question;
use App\Models\Room;
use App\Models\RoomParticipant;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Admin\DashboardMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardAndSensitiveDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_counts_come_from_the_database(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->count(3)->create();
        User::factory()->create(['status' => UserStatus::Restricted]);
        User::factory()->create(['status' => UserStatus::Suspended]);
        Room::factory()->open()->count(2)->create();
        Room::factory()->status(RoomStatus::InProgress)->create();
        Room::factory()->status(RoomStatus::Completed)->create();
        RoomParticipant::factory()->count(4)->create();

        $metrics = app(DashboardMetrics::class);
        $this->assertSame(['total' => 10, 'active' => 8, 'restricted' => 1, 'suspended' => 1], array_intersect_key($metrics->users(), array_flip(['total', 'active', 'restricted', 'suspended'])));
        $this->assertSame(1, $metrics->rooms()['in_progress']);
        $this->assertSame(1, $metrics->rooms()['completed']);
        $this->assertSame(4, $metrics->participation()['seats']);

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Total users', '10', 'Active', '8'])
            ->assertSeeInOrder(['Live now', '1']);
    }

    public function test_finance_figures_are_distinct_and_never_called_profit(): void
    {
        $finance = User::factory()->role(UserRole::FinanceManager)->create();
        $player = User::factory()->create();
        $wallet = Wallet::factory()->for($player)->create();
        $revenue = Wallet::factory()->system(Wallet::SYSTEM_PLATFORM_REVENUE)->create();

        (new PaymentTransaction)->forceFill([
            'user_id' => $player->id, 'wallet_id' => $wallet->id, 'type' => PaymentType::Deposit,
            'status' => PaymentStatus::Succeeded, 'gateway' => 'fake', 'amount_minor' => 100_000, 'idempotency_key' => 'dep-1',
        ])->save();

        $fee = tap((new LedgerTransaction)->forceFill(['idempotency_key' => 'fee-1', 'type' => LedgerTransactionType::EntryFee]))->save();
        (new LedgerEntry)->forceFill(['ledger_transaction_id' => $fee->id, 'wallet_id' => $wallet->id, 'amount_minor' => -20_000, 'balance_after_minor' => 80_000])->save();
        (new LedgerEntry)->forceFill(['ledger_transaction_id' => $fee->id, 'wallet_id' => $revenue->id, 'amount_minor' => 20_000, 'balance_after_minor' => 20_000])->save();
        $revenue->forceFill(['balance_minor' => 20_000])->save();

        $figures = app(DashboardMetrics::class)->finance();
        $this->assertSame(100_000, $figures['gross_deposits']);
        $this->assertSame(20_000, $figures['entry_fees_charged']);
        $this->assertSame(20_000, $figures['platform_revenue_balance']);
        $this->assertSame(0, $figures['prizes_paid']);

        $this->actingAs($finance)->get(route('admin.dashboard'))
            ->assertSee('Gross deposits received')
            ->assertSee('Rs 1,000')
            ->assertSee('Entry fees charged')
            ->assertSee('None of these is profit.')
            ->assertDontSee('Profit</p>', false);
    }

    public function test_finance_figures_and_audit_feed_are_hidden_without_permission(): void
    {
        $content = User::factory()->role(UserRole::ContentManager)->create();

        $this->actingAs($content)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('Money movements')
            ->assertDontSee('Gross deposits received')
            ->assertDontSee('Recent administrative actions');
    }

    public function test_alerts_reflect_real_configuration_and_data(): void
    {
        $admin = User::factory()->admin()->create();
        Room::factory()->status(RoomStatus::Scheduled)->create(['scheduled_start_at' => now()->subHour()]);

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertSee('Paid entries are disabled.')
            ->assertSee('1 room(s) are past their scheduled start');
    }

    public function test_answer_keys_are_visible_only_to_content_staff(): void
    {
        $question = Question::factory()->withOptions()->create();
        $correct = $question->options()->get()->firstWhere('is_correct', true);

        $this->actingAs(User::factory()->role(UserRole::ContentManager)->create())
            ->get(route('admin.questions.show', $question))
            ->assertSee($correct->body)
            ->assertSee('Correct');

        $this->actingAs(User::factory()->role(UserRole::FinanceManager)->create())
            ->get(route('admin.questions.show', $question))
            ->assertForbidden();
    }

    public function test_wallet_balance_on_user_page_requires_finance_permission(): void
    {
        $player = User::factory()->create();
        Wallet::factory()->for($player)->create();

        // Admins hold finance.view; a role with users.view but not finance.view does not exist,
        // so check the gate directly for the boundary.
        $this->actingAs(User::factory()->admin()->create())->get(route('admin.users.show', $player))->assertSee('Wallet balance');
        $this->assertFalse(User::factory()->role(UserRole::CompetitionManager)->create()->can('finance.view'));
    }

    public function test_settings_mask_the_contact_address_and_offer_no_edit_form(): void
    {
        config(['competition.contact.recipient' => 'support@example.test']);

        $this->actingAs(User::factory()->admin()->create())->get(route('admin.settings'))
            ->assertOk()
            ->assertDontSee('support@example.test')
            ->assertSee('su*****@example.test')
            ->assertDontSee('name="paid_entries', false);
    }

    public function test_unavailable_features_are_labelled_not_faked(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs(User::factory()->role(UserRole::FinanceManager)->create())->get(route('admin.rooms.index'))->assertForbidden();
        $this->actingAs(User::factory()->role(UserRole::ContentManager)->create())->get(route('admin.dashboard'))->assertDontSee('New room');
        // Question management now exists; staff without content.manage still see no write actions.
        $this->actingAs(User::factory()->role(UserRole::CompetitionManager)->create())
            ->get(route('admin.questions.index'))->assertOk()->assertDontSee('New question')->assertDontSee('Import CSV');
        $this->actingAs($admin)->get(route('admin.results.index'))->assertSee('Results are read-only');
    }
}
