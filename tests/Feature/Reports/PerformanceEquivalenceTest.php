<?php

namespace Tests\Feature\Reports;

use App\Enums\LedgerTransactionType;
use App\Enums\RoomType;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\Competition\RoomEntryService;
use App\Services\Competition\RoomLifecycle;
use App\Services\Reports\ReportCatalog;
use App\Services\Reports\ReportPeriod;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesRooms;
use Tests\TestCase;

/**
 * Phase 21 optimizations must not change any figure: each is compared with the
 * straightforward calculation it replaced.
 */
class PerformanceEquivalenceTest extends TestCase
{
    use CreatesRooms, RefreshDatabase;

    public function test_the_deferred_competition_report_matches_the_direct_query_page_by_page(): void
    {
        config(['competition.paid_entries.enabled' => true]);
        $wallets = app(WalletService::class);
        $players = User::factory()->count(3)->create()->each(fn ($u) => $wallets->credit($wallets->walletFor($u), 1_000_000, LedgerTransactionType::Deposit, 'seed:'.$u->id));

        foreach (range(1, 17) as $i) {
            // Fill rooms are dated by creation, so all of them fall in the default 30-day period.
            $room = $this->openRoom(['type' => RoomType::Fill, 'entry_fee_minor' => 1_000 * $i, 'min_participants' => 3, 'max_participants' => 10, 'name' => "Room {$i}"]);
            foreach ($players->take(1 + $i % 3) as $player) {
                app(RoomEntryService::class)->join($player, $room->fresh());
            }
            if ($i % 4 === 0) {
                app(RoomLifecycle::class)->cancel($room->fresh(), null, 'Test');   // refunds
            }
        }

        $catalog = app(ReportCatalog::class);
        $period = ReportPeriod::fromRequest(Request::create('/'));
        $direct = (new \ReflectionMethod($catalog, 'competitions'))->invoke($catalog, $period);

        foreach ([1, 2, 3] as $pageNo) {
            Paginator::currentPageResolver(fn () => $pageNo);
            $old = (clone $direct)->paginate(7);
            $new = $catalog->page('competitions', $period, 7);

            $this->assertSame($old->total(), $new->total());
            $this->assertEquals(collect($old->items())->map(fn ($r) => (array) $r)->all(), collect($new->items())->map(fn ($r) => (array) $r)->all(), "Page {$pageNo} is identical.");
        }

        $all = (clone $direct)->get();
        $this->assertSame([
            'competitions' => $all->count(),
            'gross_collected' => (int) $all->sum('gross_collected'),
            'refunds' => (int) $all->sum('refunds'),
        ], $catalog->totals('competitions', $period));
        $this->assertGreaterThan(0, $catalog->totals('competitions', $period)['refunds'], 'The data includes refunds.');
    }

    public function test_dashboard_totals_are_cached_for_display_only_and_still_permission_gated(): void
    {
        config(['competition.cache.dashboard_seconds' => 60]);
        $super = User::factory()->role(UserRole::SuperAdmin)->create();
        $content = User::factory()->role(UserRole::ContentManager)->create();

        $this->actingAs($super)->get(route('admin.dashboard'))->assertOk()->assertSee('Totals as of');
        $before = $this->actingAs($super)->get(route('admin.dashboard'))->viewData('users')['total'];

        User::factory()->count(3)->create();
        $this->assertSame($before, $this->actingAs($super)->get(route('admin.dashboard'))->viewData('users')['total'], 'Reused within the cache window.');
        $this->assertNull($this->actingAs($content)->get(route('admin.dashboard'))->viewData('finance'), 'Cached finance figures are still only shown to finance viewers.');

        $this->travel(61)->seconds();
        $this->assertSame($before + 3, $this->actingAs($super)->get(route('admin.dashboard'))->viewData('users')['total'], 'Refreshed after the window.');

        config(['competition.cache.dashboard_seconds' => 0]);
        User::factory()->create();
        $this->assertSame($before + 4, $this->actingAs($super)->get(route('admin.dashboard'))->viewData('users')['total'], 'Live when disabled.');
    }

    public function test_notifications_are_queried_once_per_request_and_never_reused_across_requests(): void
    {
        $user = User::factory()->create();
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            if (str_contains($q->sql, 'from `notifications`')) {
                $queries[] = $q->sql;
            }
        });

        $this->actingAs($user)->get(route('account.show'))->assertOk();
        $this->assertCount(2, $queries, 'Header bell and overview share one count and one list query.');

        DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => 'test', 'notifiable_type' => $user->getMorphClass(), 'notifiable_id' => $user->id, 'data' => json_encode(['type' => 'test', 'title' => 'Hello', 'message' => 'Hi']), 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(1, $this->actingAs($user)->get(route('account.show'))->viewData('unreadNotifications'), 'A new request sees the new notification.');
    }
}
