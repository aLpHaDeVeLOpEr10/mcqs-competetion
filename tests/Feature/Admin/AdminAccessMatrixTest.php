<?php

namespace Tests\Feature\Admin;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\QuizPaper;
use App\Models\Room;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesDomainRecords;
use Tests\TestCase;

class AdminAccessMatrixTest extends TestCase
{
    use CreatesDomainRecords, RefreshDatabase;

    /**
     * Every admin GET page with the permission that guards it.
     *
     * @return array<string, array{0: string, 1: Permission}>
     */
    protected function adminPages(): array
    {
        $paper = QuizPaper::factory()->withQuestions(1)->create();
        $room = Room::factory()->create(['quiz_paper_id' => $paper->id]);
        $target = User::factory()->create();
        $transaction = $this->makeLedgerTransaction();
        $log = tap((new AuditLog)->forceFill(['action' => 'test.seed']))->save();

        return [
            'dashboard' => [route('admin.dashboard'), Permission::AdminAccess],
            'users' => [route('admin.users.index'), Permission::UsersView],
            'user' => [route('admin.users.show', $target), Permission::UsersView],
            'categories' => [route('admin.categories.index'), Permission::ContentView],
            'questions' => [route('admin.questions.index'), Permission::ContentView],
            'question' => [route('admin.questions.show', $paper->questions()->first()), Permission::ContentView],
            'papers' => [route('admin.papers.index'), Permission::ContentView],
            'paper' => [route('admin.papers.show', $paper), Permission::ContentView],
            'rooms' => [route('admin.rooms.index'), Permission::RoomsView],
            'room' => [route('admin.rooms.show', $room), Permission::RoomsView],
            'results' => [route('admin.results.index'), Permission::ResultsView],
            'payments' => [route('admin.payments.index'), Permission::FinanceView],
            'ledger' => [route('admin.ledger.index'), Permission::FinanceView],
            'ledger transaction' => [route('admin.ledger.show', $transaction), Permission::FinanceView],
            'wallets' => [route('admin.wallets.index'), Permission::FinanceView],
            'prize payouts' => [route('admin.payouts.index'), Permission::FinanceView],
            'reports' => [route('admin.reports.index'), Permission::ReportsView],
            'competition report' => [route('admin.reports.competitions'), Permission::ReportsView],
            'financial reports' => [route('admin.reports.finance', 'deposits'), Permission::FinanceView],
            'exports' => [route('admin.exports.index'), Permission::ReportsExport],
            'user report' => [route('admin.users.report', $target), Permission::UsersView],
            'wallet' => [route('admin.wallets.show', $target->wallet()->firstOr(fn () => Wallet::factory()->create(['user_id' => $target->id]))), Permission::FinanceView],
            'audit' => [route('admin.audit.index'), Permission::AuditView],
            'audit record' => [route('admin.audit.show', $log), Permission::AuditView],
            'settings' => [route('admin.settings'), Permission::SettingsView],
        ];
    }

    public static function roles(): array
    {
        return array_map(fn (UserRole $role) => [$role], array_combine(UserRole::values(), UserRole::cases()));
    }

    #[DataProvider('roles')]
    public function test_each_role_reaches_exactly_the_pages_its_permissions_allow(UserRole $role): void
    {
        $user = User::factory()->role($role)->create();

        foreach ($this->adminPages() as $name => [$url, $permission]) {
            $expected = $role->grants($permission) ? 200 : 403;

            $this->actingAs($user)->get($url)->assertStatus($expected, "{$role->value} → {$name}");
        }
    }

    public function test_guests_are_redirected_to_login_from_every_admin_page(): void
    {
        foreach ($this->adminPages() as $name => [$url]) {
            $this->get($url)->assertRedirect(route('admin.login'));
        }
    }

    public function test_players_get_403_on_every_admin_page_and_mutation(): void
    {
        $player = User::factory()->create();
        $target = User::factory()->create();

        foreach ($this->adminPages() as $name => [$url]) {
            $this->actingAs($player)->get($url)->assertForbidden();
        }

        $this->actingAs($player)->patch(route('admin.users.status', $target), ['status' => 'suspended', 'reason' => 'nope nope', 'confirm' => '1'])->assertForbidden();
        $this->actingAs($player)->patch(route('admin.users.role', $player), ['role' => 'super_admin', 'reason' => 'nope nope', 'confirm' => '1'])->assertForbidden();
        $this->assertSame(UserRole::Player, $player->fresh()->role);
        $this->assertSame(UserStatus::Active, $target->fresh()->status);
    }

    public function test_restricted_or_unverified_staff_lose_admin_access(): void
    {
        $restricted = User::factory()->role(UserRole::SuperAdmin)->create(['status' => UserStatus::Restricted]);
        $unverified = User::factory()->role(UserRole::SuperAdmin)->unverified()->create();

        $this->actingAs($restricted)->get(route('admin.dashboard'))->assertForbidden();
        $this->actingAs($unverified)->get(route('admin.dashboard'))->assertForbidden();

        config(['competition.accounts.email_verification' => false]);
        $this->actingAs($unverified)->get(route('admin.dashboard'))->assertOk();
    }

    public function test_suspended_staff_are_signed_out(): void
    {
        $admin = User::factory()->role(UserRole::SuperAdmin)->create(['status' => UserStatus::Suspended]);

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
        $this->assertGuest();
    }

    public function test_navigation_shows_only_permitted_sections_but_hidden_pages_stay_forbidden(): void
    {
        $content = User::factory()->role(UserRole::ContentManager)->create();

        $this->actingAs($content)->get(route('admin.dashboard'))
            ->assertSee(route('admin.questions.index'), false)
            ->assertDontSee(route('admin.payments.index'), false)
            ->assertDontSee(route('admin.users.index'), false);

        // Hiding the link is not the security boundary: the route itself refuses.
        $this->actingAs($content)->get(route('admin.payments.index'))->assertForbidden();
    }

    public function test_there_are_no_routes_that_modify_ledger_audit_or_results(): void
    {
        $mutating = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'admin.'))
            ->reject(fn ($route) => $route->methods() === ['GET', 'HEAD']);

        $forbiddenPrefixes = ['admin.ledger', 'admin.audit', 'admin.payments', 'admin.settings'];
        foreach ($mutating as $route) {
            foreach ($forbiddenPrefixes as $prefix) {
                $this->assertFalse(str_starts_with($route->getName(), $prefix), "{$route->getName()} must not exist");
            }
        }

        // Every content write route is guarded by content.manage.
        $mutating->filter(fn ($route) => preg_match('/^admin\.(questions|papers|categories)\./', $route->getName()))
            ->each(fn ($route) => $this->assertContains('can:content.manage', $route->gatherMiddleware(), $route->getName()));

        // Result actions are corrections/finalization (never direct edits) and need results.manage.
        $mutating->filter(fn ($route) => preg_match('/^admin\.(results|disputes)\./', $route->getName()))
            ->each(fn ($route) => $this->assertContains('can:results.manage', $route->gatherMiddleware(), $route->getName()));

        // The only wallet write is an audited ledger adjustment, guarded by finance.adjust.
        $mutating->filter(fn ($route) => str_starts_with($route->getName(), 'admin.wallets.'))
            ->each(fn ($route) => $this->assertContains('can:finance.adjust', $route->gatherMiddleware(), $route->getName()));

        // Prize payout actions need prizes.manage (finance managers and super admins).
        $mutating->filter(fn ($route) => str_starts_with($route->getName(), 'admin.payouts.'))
            ->each(fn ($route) => $this->assertContains('can:prizes.manage', $route->gatherMiddleware(), $route->getName()));

        // Withdrawals: starting a review or re-checking needs withdrawals.review; decisions and payouts withdrawals.approve.
        foreach (['admin.withdrawals.review' => 'can:withdrawals.review', 'admin.withdrawals.refresh' => 'can:withdrawals.review',
            'admin.withdrawals.approve' => 'can:withdrawals.approve', 'admin.withdrawals.reject' => 'can:withdrawals.approve', 'admin.withdrawals.initiate' => 'can:withdrawals.approve'] as $name => $ability) {
            $this->assertContains($ability, $mutating->first(fn ($r) => $r->getName() === $name)->gatherMiddleware(), $name);
        }

        // Report exports need reports.export.
        $this->assertContains('can:reports.export', $mutating->first(fn ($r) => $r->getName() === 'admin.reports.export')->gatherMiddleware());

        // Announcements need announcements.manage.
        $mutating->filter(fn ($route) => str_starts_with($route->getName(), 'admin.announcements.'))
            ->each(fn ($route) => $this->assertContains('can:announcements.manage', $route->gatherMiddleware(), $route->getName()));

        // Every room write route is guarded by rooms.manage.
        $mutating->filter(fn ($route) => str_starts_with($route->getName(), 'admin.rooms.'))
            ->each(fn ($route) => $this->assertContains('can:rooms.manage', $route->gatherMiddleware(), $route->getName()));

        // Risk review only records a decision on a flag (never changes an account); needs risk.review.
        $this->assertContains('can:risk.review', $mutating->first(fn ($route) => $route->getName() === 'admin.risk.review')->gatherMiddleware());

        $this->assertSame(['admin.users.status', 'admin.users.role', 'admin.risk.review'], $mutating
            ->reject(fn ($route) => in_array($route->getName(), ['admin.login.store', 'admin.logout'], true))
            ->reject(fn ($route) => preg_match('/^admin\.(questions|papers|categories|rooms|results|disputes)\./', $route->getName()) || $route->getName() === 'admin.wallets.adjust' || str_starts_with($route->getName(), 'admin.payouts.') || $route->getName() === 'admin.announcements.store' || $route->getName() === 'admin.reports.export' || str_starts_with($route->getName(), 'admin.withdrawals.'))
            ->map(fn ($route) => $route->getName())->values()->all());
    }

    public function test_content_write_routes_follow_the_permission_matrix(): void
    {
        $paper = QuizPaper::factory()->create();

        foreach (UserRole::cases() as $role) {
            $user = User::factory()->role($role)->create();
            $expected = $role->grants(Permission::ContentManage) ? 200 : 403;

            $this->actingAs($user)->get(route('admin.questions.create'))->assertStatus($expected, $role->value);
            $this->actingAs($user)->get(route('admin.papers.edit', $paper))->assertStatus($expected, $role->value);
            $this->actingAs($user)->get(route('admin.questions.import'))->assertStatus($expected, $role->value);
        }
    }
}
