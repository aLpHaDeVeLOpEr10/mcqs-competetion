<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Withdrawals\WithdrawalService;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\UsesWithdrawals;
use Tests\TestCase;

/**
 * Phase 23 audit: privilege-escalation attempts that must fail.
 */
class PrivilegeEscalationTest extends TestCase
{
    use RefreshDatabase, UsesWithdrawals;

    public function test_registration_ignores_role_status_and_verification_fields(): void
    {
        config(['competition.accounts.email_verification' => true]);

        $this->post('/register', [
            'name' => 'Mallory Example', 'display_name' => 'mallory', 'email' => 'mallory@example.com',
            'password' => 'Str0ng-Passw0rd!', 'password_confirmation' => 'Str0ng-Passw0rd!', 'terms' => '1',
            'role' => UserRole::SuperAdmin->value, 'status' => UserStatus::Active->value,
            'email_verified_at' => now()->toDateTimeString(), 'id' => 1,
        ])->assertRedirect();

        $user = User::query()->where('email', 'mallory@example.com')->sole();
        $this->assertSame(UserRole::Player, $user->role);
        $this->assertNull($user->email_verified_at);
        $this->assertFalse($user->isStaff());
    }

    public function test_profile_updates_cannot_change_role_status_email_or_wallet(): void
    {
        $player = User::factory()->create(['status' => UserStatus::Restricted]);
        $email = $player->email;

        $this->actingAs($player)->patch(route('account.profile.update'), [
            'name' => 'New Name', 'display_name' => 'newname',
            'role' => UserRole::SuperAdmin->value, 'status' => UserStatus::Active->value, 'email' => 'attacker@example.com',
            'balance_minor' => 999_999_999,
        ])->assertRedirect();

        $player->refresh();
        $this->assertSame(['New Name', UserRole::Player, UserStatus::Restricted, $email], [$player->name, $player->role, $player->status, $player->email]);
    }

    public function test_an_admin_cannot_create_a_super_admin_or_promote_anyone(): void
    {
        $admin = User::factory()->role(UserRole::Admin)->create();
        $target = User::factory()->create();

        foreach ([$admin, $target] as $who) {
            $this->actingAs($admin)->patch(route('admin.users.role', $who), ['role' => UserRole::SuperAdmin->value, 'reason' => 'x', 'confirm' => '1'])
                ->assertForbidden();
        }
        $this->assertSame(UserRole::Admin, $admin->fresh()->role);
        $this->assertSame(UserRole::Player, $target->fresh()->role);

        $this->assertSame(0, User::query()->where('role', UserRole::SuperAdmin)->count(), 'No super admin was created.');
    }

    public function test_a_player_cannot_subscribe_to_another_users_private_channel(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => '1',
        ]);
        app(BroadcastManager::class)->forgetDrivers();
        require base_path('routes/channels.php');
        [$alice, $bob] = User::factory()->count(2)->create();

        $this->actingAs($alice)->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-App.Models.User.'.$alice->id])->assertOk();
        $this->actingAs($alice)->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-App.Models.User.'.$bob->id])->assertForbidden();
        $this->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-App.Models.User.'.$bob->id])->assertForbidden();
    }

    public function test_admins_without_finance_rights_cannot_decide_withdrawals(): void
    {
        $this->enableWithdrawals();
        $player = $this->funded(200_000);
        $withdrawal = app(WithdrawalService::class)->request($player, $this->destination($player), 100_000, 'token-1');
        $admin = User::factory()->role(UserRole::Admin)->create();

        foreach (['review', 'approve', 'reject', 'initiate'] as $action) {
            $this->actingAs($admin)->post(route("admin.withdrawals.{$action}", $withdrawal), ['reason' => 'x', 'note' => 'x', 'confirm' => '1'])->assertForbidden();
        }
        $this->assertSame('requested', $withdrawal->fresh()->status->value);
    }
}
