<?php

namespace Tests\Feature\Account;

use App\Enums\JoinBlockReason;
use App\Enums\LedgerTransactionType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Room;
use App\Models\RoomParticipant;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Competition\JoinEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesDomainRecords;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use CreatesDomainRecords, RefreshDatabase;

    public static function accountRoutes(): array
    {
        return [
            ['GET', 'account.show'], ['GET', 'account.profile'], ['PATCH', 'account.profile.update'],
            ['POST', 'account.email.update'], ['GET', 'account.security'], ['PUT', 'account.password.update'],
            ['DELETE', 'account.sessions.destroy'], ['GET', 'account.competitions'], ['GET', 'account.wallet'],
            ['GET', 'account.transactions'],
        ];
    }

    #[DataProvider('accountRoutes')]
    public function test_guests_are_redirected_to_login(string $method, string $route): void
    {
        $this->call($method, route($route))->assertRedirect(route('login'));
    }

    public function test_account_pages_render_for_a_signed_in_user(): void
    {
        $user = User::factory()->create();
        Wallet::factory()->for($user)->create();

        foreach (['account.show', 'account.profile', 'account.security', 'account.competitions', 'account.wallet', 'account.transactions'] as $route) {
            $this->actingAs($user)->get(route($route))->assertOk();
        }
    }

    public function test_restricted_users_see_a_banner_and_are_denied_restricted_operations(): void
    {
        $user = User::factory()->create(['status' => UserStatus::Restricted, 'status_reason' => 'Under review']);

        $this->actingAs($user)->get(route('account.show'))
            ->assertSee('Your account is restricted')
            ->assertSee('Under review');

        $this->assertFalse(Gate::forUser($user)->allows('enter-competitions'));
        $this->assertFalse(Gate::forUser($user)->allows('transact'));
        $this->assertSame(JoinBlockReason::AccountRestricted, app(JoinEligibilityService::class)->check(Room::factory()->open()->create(), $user)->reason);
    }

    public function test_suspended_users_are_denied_restricted_operations(): void
    {
        $user = User::factory()->create(['status' => UserStatus::Suspended]);

        $this->assertFalse(Gate::forUser($user)->allows('enter-competitions'));
        $this->assertFalse(Gate::forUser($user)->allows('transact'));
    }

    public function test_active_verified_users_pass_the_gates_and_only_staff_reach_admin(): void
    {
        $player = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->assertTrue(Gate::forUser($player)->allows('enter-competitions'));
        $this->assertFalse(Gate::forUser($player)->allows('admin.access'));
        $this->assertTrue(Gate::forUser($admin)->allows('admin.access'));
        $this->assertFalse(Gate::forUser(User::factory()->role(UserRole::Admin)->create(['status' => UserStatus::Restricted]))->allows('admin.access'));
    }

    public function test_users_can_only_update_themselves(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->assertTrue($user->can('update', $user));
        $this->assertFalse($user->can('update', $other));
    }

    public function test_competition_history_shows_only_my_seats_and_published_results(): void
    {
        $user = User::factory()->create();
        $mine = Room::factory()->open()->create(['name' => 'My Room']);
        RoomParticipant::factory()->create(['room_id' => $mine->id, 'user_id' => $user->id]);
        $theirs = Room::factory()->open()->create(['name' => 'Their Room']);
        RoomParticipant::factory()->create(['room_id' => $theirs->id]);

        $this->actingAs($user)->get(route('account.competitions'))
            ->assertSee('My Room')
            ->assertDontSee('Their Room');
    }

    public function test_transactions_show_only_my_wallet_entries(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->for($user)->create();
        $otherWallet = Wallet::factory()->create();

        $transaction = $this->makeLedgerTransaction();
        $this->makeLedgerEntry($transaction, $wallet, 12_345);
        $this->makeLedgerEntry($this->makeLedgerTransaction(), $otherWallet, 99_999);

        $this->actingAs($user)->get(route('account.transactions'))
            ->assertSee('+Rs 123.45')
            ->assertSee(ucfirst(str_replace('_', ' ', LedgerTransactionType::Adjustment->value)))
            ->assertDontSee('Rs 999.99');
    }

    public function test_wallet_page_moves_no_money_and_offers_no_payment_actions(): void
    {
        $user = User::factory()->create();
        Wallet::factory()->for($user)->create();

        $html = $this->actingAs($user)->get(route('account.wallet'))
            ->assertSee('Rs 0')
            ->assertSee('Deposits and withdrawals are not available')
            ->getContent();

        // The only forms on the page are the two sign-out forms in the header (desktop + mobile menu).
        $this->assertSame(2, substr_count($html, '<form'));
        $this->assertSame(2, substr_count($html, 'action="'.route('logout').'"'));
    }
}
