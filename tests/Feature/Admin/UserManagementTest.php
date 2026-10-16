<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\AdministrativeActionException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Admin\UserAdministrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function changeStatus(User $actor, User $target, array $data = [])
    {
        return $this->actingAs($actor)->from(route('admin.users.show', $target))
            ->patch(route('admin.users.status', $target), $data + ['status' => 'suspended', 'reason' => 'Repeated rule violations', 'confirm' => '1']);
    }

    protected function changeRole(User $actor, User $target, array $data = [])
    {
        return $this->actingAs($actor)->from(route('admin.users.show', $target))
            ->patch(route('admin.users.role', $target), $data + ['role' => 'content_manager', 'reason' => 'Joining the content team', 'confirm' => '1']);
    }

    public function test_admin_can_suspend_a_player_with_reason_and_it_is_audited(): void
    {
        $admin = User::factory()->admin()->create();
        $player = User::factory()->create();
        config(['session.driver' => 'database']);
        DB::table('sessions')->insert(['id' => 'player-session', 'user_id' => $player->id, 'payload' => '', 'last_activity' => time()]);

        $this->changeStatus($admin, $player)->assertRedirect(route('admin.users.show', $player))->assertSessionHas('success');

        $player->refresh();
        $this->assertSame(UserStatus::Suspended, $player->status);
        $this->assertSame('Repeated rule violations', $player->status_reason);
        $this->assertDatabaseMissing('sessions', ['id' => 'player-session']);

        $log = AuditLog::where('action', 'user.status_changed')->sole();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame($player->id, $log->auditable_id);
        $this->assertSame(['status' => 'active'], $log->old_values);
        $this->assertSame(['status' => 'suspended'], $log->new_values);
        $this->assertSame('Repeated rule violations', $log->metadata['reason']);
    }

    public function test_reactivation_clears_the_reason(): void
    {
        $admin = User::factory()->admin()->create();
        $player = User::factory()->create(['status' => UserStatus::Restricted, 'status_reason' => 'Review']);

        $this->changeStatus($admin, $player, ['status' => 'active', 'reason' => 'Review complete']);

        $this->assertSame(UserStatus::Active, $player->fresh()->status);
        $this->assertNull($player->fresh()->status_reason);
    }

    public function test_status_change_requires_reason_and_explicit_confirmation(): void
    {
        $admin = User::factory()->admin()->create();
        $player = User::factory()->create();

        $this->changeStatus($admin, $player, ['reason' => '', 'confirm' => null])->assertSessionHasErrorsIn('status', ['reason', 'confirm']);
        $this->changeStatus($admin, $player, ['status' => 'banished'])->assertSessionHasErrorsIn('status', ['status']);

        $this->assertSame(UserStatus::Active, $player->fresh()->status);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_staff_cannot_change_their_own_status_or_a_peer_or_senior(): void
    {
        $admin = User::factory()->admin()->create();
        $otherAdmin = User::factory()->admin()->create();
        $super = User::factory()->role(UserRole::SuperAdmin)->create();

        $this->changeStatus($admin, $admin)->assertForbidden();
        $this->changeStatus($admin, $otherAdmin)->assertForbidden();
        $this->changeStatus($admin, $super)->assertForbidden();

        $this->assertSame(UserStatus::Active, $otherAdmin->fresh()->status);
        $this->assertSame(UserStatus::Active, $super->fresh()->status);
    }

    public function test_super_admin_can_suspend_an_admin(): void
    {
        $super = User::factory()->role(UserRole::SuperAdmin)->create();
        $admin = User::factory()->admin()->create();

        $this->changeStatus($super, $admin)->assertSessionHas('success');
        $this->assertSame(UserStatus::Suspended, $admin->fresh()->status);
    }

    public function test_managers_without_permission_cannot_change_status(): void
    {
        $finance = User::factory()->role(UserRole::FinanceManager)->create();
        $player = User::factory()->create();

        $this->changeStatus($finance, $player)->assertForbidden();
        $this->assertSame(UserStatus::Active, $player->fresh()->status);
    }

    public function test_only_super_admins_can_assign_roles_and_it_is_audited(): void
    {
        $admin = User::factory()->admin()->create();
        $super = User::factory()->role(UserRole::SuperAdmin)->create();
        $player = User::factory()->create();

        $this->changeRole($admin, $player)->assertForbidden();
        $this->assertSame(UserRole::Player, $player->fresh()->role);

        $this->changeRole($super, $player)->assertSessionHas('success');
        $this->assertSame(UserRole::ContentManager, $player->fresh()->role);

        $log = AuditLog::where('action', 'user.role_changed')->sole();
        $this->assertSame(['role' => 'player'], $log->old_values);
        $this->assertSame(['role' => 'content_manager'], $log->new_values);
        $this->assertSame($super->id, $log->actor_id);
    }

    public function test_admins_cannot_promote_themselves(): void
    {
        $admin = User::factory()->admin()->create();
        $super = User::factory()->role(UserRole::SuperAdmin)->create();

        $this->changeRole($admin, $admin, ['role' => 'super_admin'])->assertForbidden();
        $this->changeRole($super, $super, ['role' => 'player'])->assertForbidden();

        $this->assertSame(UserRole::Admin, $admin->fresh()->role);
        $this->assertSame(UserRole::SuperAdmin, $super->fresh()->role);
    }

    public function test_the_last_super_admin_cannot_be_demoted(): void
    {
        $first = User::factory()->role(UserRole::SuperAdmin)->create();
        $second = User::factory()->role(UserRole::SuperAdmin)->create();

        $this->changeRole($first, $second, ['role' => 'admin'])->assertSessionHas('success');

        // $first is now the only super admin; $second (now admin) cannot assign roles at all.
        $this->changeRole($second->fresh(), $first, ['role' => 'admin'])->assertForbidden();
        $this->assertSame(UserRole::SuperAdmin, $first->fresh()->role);
        $this->assertSame(1, User::where('role', UserRole::SuperAdmin)->count());
    }

    public function test_service_refuses_to_demote_the_only_super_admin_even_if_called_directly(): void
    {
        $only = User::factory()->role(UserRole::SuperAdmin)->create();

        $this->expectException(AdministrativeActionException::class);

        app(UserAdministrationService::class)->changeRole($only, $only, UserRole::Admin, 'testing guard');
    }

    public function test_no_op_changes_are_rejected_without_an_audit_record(): void
    {
        $super = User::factory()->role(UserRole::SuperAdmin)->create();
        $player = User::factory()->create();

        $this->changeRole($super, $player, ['role' => 'player'])->assertSessionHas('error');
        $this->changeStatus($super, $player, ['status' => 'active'])->assertSessionHas('error');

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_user_search_and_filters(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['name' => 'Zainab Raza', 'email' => 'zainab@example.test']);
        User::factory()->create(['name' => 'Bilal Khan', 'status' => UserStatus::Suspended]);

        $this->actingAs($admin)->get(route('admin.users.index', ['q' => 'zain']))->assertSee('Zainab Raza')->assertDontSee('Bilal Khan');
        $this->actingAs($admin)->get(route('admin.users.index', ['status' => 'suspended']))->assertSee('Bilal Khan')->assertDontSee('Zainab Raza');
        $this->actingAs($admin)->get(route('admin.users.index', ['q' => '%']))->assertDontSee('Zainab Raza');
        $this->actingAs($admin)->get(route('admin.users.index', ['role' => 'emperor']))->assertSee('Some filters were not recognised');
    }

    public function test_action_panels_render_only_when_the_policy_allows(): void
    {
        $admin = User::factory()->admin()->create();
        $player = User::factory()->create();

        $this->actingAs($admin)->get(route('admin.users.show', $player))
            ->assertSee('Change account status')
            ->assertDontSee('Change role');

        $this->actingAs($admin)->get(route('admin.users.show', $admin))
            ->assertDontSee('Change account status')
            ->assertSee('it is your own account');
    }

    public function test_staff_logins_are_audited(): void
    {
        $admin = User::factory()->admin()->create();
        $player = User::factory()->create();

        $this->post(route('login'), ['email' => $admin->email, 'password' => 'password']);
        $this->post(route('logout'));
        $this->post(route('login'), ['email' => $player->email, 'password' => 'password']);

        $this->assertSame(1, AuditLog::where('action', 'auth.staff_login')->count());
        $this->assertSame($admin->id, AuditLog::where('action', 'auth.staff_login')->value('actor_id'));
    }
}
