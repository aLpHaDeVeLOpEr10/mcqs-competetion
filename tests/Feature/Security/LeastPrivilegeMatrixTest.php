<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesRooms;
use Tests\TestCase;

/**
 * One table of who may do what, written out explicitly (not derived from the role
 * code under test). L = sent to log in, F = forbidden, Y = allowed.
 * For actions, "allowed" means the permission check passed (validation may still refuse).
 */
class LeastPrivilegeMatrixTest extends TestCase
{
    use CreatesRooms, RefreshDatabase;

    private const ACTORS = ['guest', 'player', 'suspended', 'admin', 'finance', 'super'];

    /**
     * route => expected outcome per actor, in ACTORS order.
     */
    private const PAGES = [
        'account.show' => 'L Y L Y Y Y',
        'account.wallet' => 'L Y L Y Y Y',
        'account.withdrawals.index' => 'L Y L Y Y Y',
        'admin.dashboard' => 'L F L Y Y Y',
        'admin.users.index' => 'L F L Y F Y',
        'admin.questions.index' => 'L F L Y F Y',
        'admin.papers.index' => 'L F L Y F Y',
        'admin.rooms.index' => 'L F L Y F Y',
        'admin.results.index' => 'L F L Y Y Y',
        'admin.payments.index' => 'L F L Y Y Y',
        'admin.wallets.index' => 'L F L Y Y Y',
        'admin.ledger.index' => 'L F L Y Y Y',
        'admin.payouts.index' => 'L F L Y Y Y',
        'admin.withdrawals.index' => 'L F L F Y Y',
        'admin.reports.index' => 'L F L Y Y Y',
        'admin.risk.index' => 'L F L Y Y Y',
        'admin.announcements.index' => 'L F L Y F Y',
        'admin.audit.index' => 'L F L Y F Y',
        'admin.settings' => 'L F L Y F Y',
    ];

    /**
     * @return array<string, User|null>
     */
    protected function actors(): array
    {
        return [
            'guest' => null,
            'player' => User::factory()->create(),
            'suspended' => User::factory()->create(['status' => UserStatus::Suspended]),
            'admin' => User::factory()->role(UserRole::Admin)->create(),
            'finance' => User::factory()->role(UserRole::FinanceManager)->create(),
            'super' => User::factory()->role(UserRole::SuperAdmin)->create(),
        ];
    }

    protected function outcome($response): string
    {
        return match (true) {
            $response->isRedirect(route('login')) || $response->isRedirect(route('admin.login')) => 'L',
            $response->getStatusCode() === 403 => 'F',
            $response->getStatusCode() === 200 => 'Y',
            default => 'status '.$response->getStatusCode(),
        };
    }

    public function test_pages_follow_least_privilege(): void
    {
        $actors = $this->actors();
        $mismatches = [];

        foreach (self::PAGES as $route => $expected) {
            $expected = explode(' ', $expected);
            foreach (self::ACTORS as $i => $name) {
                $this->signOut();
                $user = $actors[$name];
                $response = $user ? $this->actingAs($user)->get(route($route)) : $this->get(route($route));
                $actual = $this->outcome($response);
                if ($actual !== $expected[$i]) {
                    $mismatches[] = "{$route} as {$name}: expected {$expected[$i]}, got {$actual}";
                }
            }
        }

        $this->assertSame([], $mismatches);
    }

    public function test_sensitive_actions_follow_least_privilege(): void
    {
        $actors = $this->actors();
        $target = User::factory()->create();
        $wallet = app(WalletService::class)->walletFor($target);
        $room = $this->openRoom();

        // [method, url, expected per actor]; "Y" = passed authorization (any non-403, non-login outcome).
        $actions = [
            'join a competition' => ['POST', route('competitions.join', $room), 'L Y L Y Y Y'],
            'change a role' => ['PATCH', route('admin.users.role', $target), 'L F L F F Y'],
            'change account status' => ['PATCH', route('admin.users.status', $target), 'L F L Y F Y'],
            'adjust a wallet' => ['POST', route('admin.wallets.adjust', $wallet), 'L F L F Y Y'],
            'send an announcement' => ['POST', route('admin.announcements.store'), 'L F L Y F Y'],
            'export a report' => ['POST', route('admin.reports.export'), 'L F L Y Y Y'],
            'create a question' => ['POST', route('admin.questions.store'), 'L F L Y F Y'],
            'create a room' => ['POST', route('admin.rooms.store'), 'L F L Y F Y'],
        ];

        $mismatches = [];
        foreach ($actions as $label => [$method, $url, $expected]) {
            $expected = explode(' ', $expected);
            foreach (self::ACTORS as $i => $name) {
                $this->signOut();
                $user = $actors[$name];
                $response = $user ? $this->actingAs($user)->call($method, $url) : $this->call($method, $url);
                $actual = match (true) {
                    $response->isRedirect(route('login')) || $response->isRedirect(route('admin.login')) => 'L',
                    $response->getStatusCode() === 403 => 'F',
                    $response->getStatusCode() === 429 => 'throttled',
                    default => 'Y',
                };
                if ($actual !== $expected[$i]) {
                    $mismatches[] = "{$label} as {$name}: expected {$expected[$i]}, got {$actual} ({$response->getStatusCode()})";
                }
            }
        }

        $this->assertSame([], $mismatches);
        $this->assertSame('player', $target->fresh()->role->value, 'No unauthorized change took effect.');
    }
}
