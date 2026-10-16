<?php

namespace Tests\Feature\Reports;

use App\Enums\LedgerTransactionType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\ReportExport;
use App\Models\Room;
use App\Models\User;
use App\Services\Admin\UserAdministrationService;
use App\Services\Audit\AuditLogger;
use App\Services\Competition\RoomEntryService;
use App\Services\Payments\DepositService;
use App\Services\Reports\FinanceReconciliation;
use App\Services\Reports\ReportMetrics;
use App\Services\Reports\ReportPeriod;
use App\Services\Wallet\WalletService;
use App\Support\CsvWriter;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\CreatesRooms;
use Tests\Support\UsesFakeGateway;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use CreatesRooms, RefreshDatabase, UsesFakeGateway;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // Noon in Pakistan on 5 Oct 2026 (07:00 UTC).
        $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Asia/Karachi'));
        $this->admin = User::factory()->role(UserRole::Admin)->create();
    }

    protected function period(array $input = []): ReportPeriod
    {
        return ReportPeriod::fromArray($input);
    }

    public function test_reports_are_limited_by_permission(): void
    {
        foreach ([UserRole::Player, UserRole::ContentManager, UserRole::CompetitionManager] as $role) {
            $user = User::factory()->role($role)->create();
            $this->actingAs($user)->get(route('admin.reports.index'))->assertForbidden();
            $this->actingAs($user)->get(route('admin.reports.finance', 'deposits'))->assertForbidden();
            $this->actingAs($user)->post(route('admin.reports.export'), ['report' => 'competitions'])->assertForbidden();
        }

        $finance = User::factory()->role(UserRole::FinanceManager)->create();
        $this->actingAs($finance)->get(route('admin.reports.index'))->assertOk()->assertSee('Money movements');
        $this->actingAs($finance)->get(route('admin.reports.finance', 'reconciliation'))->assertOk()->assertSee('Opening balance');

        // Without finance.view, reports show activity only and financial pages are refused.
        Gate::before(fn ($user, $ability) => $ability === 'finance.view' ? false : null);
        $this->actingAs($this->admin)->get(route('admin.reports.index'))->assertOk()->assertSee('Registered users')->assertDontSee('Money movements');
        $this->actingAs($this->admin)->get(route('admin.reports.finance', 'deposits'))->assertForbidden();
        $player = User::factory()->create();
        $this->actingAs($this->admin)->get(route('admin.users.report', $player))->assertOk()
            ->assertSee('Financial activity is visible only to finance staff')->assertDontSee('Full wallet ledger');
    }

    public function test_date_filters_use_whole_days_in_pakistan_time(): void
    {
        $at = fn (string $pkt) => User::factory()->create(['created_at' => CarbonImmutable::parse($pkt, 'Asia/Karachi')->utc()]);
        $at('2026-10-05 00:30');   // today in PKT, but still 4 Oct in UTC
        $at('2026-10-04 23:59');   // yesterday
        $at('2026-09-29 00:00');   // 7th day back
        $at('2026-09-28 23:59');   // 8 days back
        $at('2026-09-06 00:00');   // 30th day back
        $at('2026-09-05 23:59');   // 31 days back
        $count = fn (array $input) => app(ReportMetrics::class)->activity($this->period($input))['registered_users'] - 1; // minus the admin (created now)

        $this->assertSame(1, $count(['period' => 'today']));
        $this->assertSame(3, $count(['period' => '7d']));
        $this->assertSame(5, $count(['period' => '30d']));
        $this->assertSame(1, $count(['period' => 'custom', 'from' => '2026-09-28', 'to' => '2026-09-28']) + 1, 'A one-day custom range (the admin is not in it).');

        $this->assertSame('2026-10-04 19:00:00', $this->period(['period' => 'today'])->from->format('Y-m-d H:i:s'), 'PKT midnight in UTC.');

        $this->expectException(ValidationException::class);
        $this->period(['period' => 'custom', 'from' => '2026-10-05', 'to' => '2026-10-01']);
    }

    public function test_long_and_malformed_ranges_are_refused(): void
    {
        $this->actingAs($this->admin)->get(route('admin.reports.index', ['period' => 'custom', 'from' => '2024-01-01', 'to' => '2026-01-01']))
            ->assertSessionHasErrors('to');
        $this->actingAs($this->admin)->get(route('admin.reports.index', ['period' => 'forever']))->assertSessionHasErrors('period');
        $this->actingAs($this->admin)->get(route('admin.reports.index', ['period' => 'custom', 'from' => '05/10/2026', 'to' => '2026-10-05']))->assertSessionHasErrors('from');
    }

    public function test_financial_totals_and_reconciliation_come_from_the_ledger(): void
    {
        $this->enableFakeGateway();
        config(['competition.paid_entries.enabled' => true]);
        $player = User::factory()->create();

        // A real deposit of Rs 500 (payment record + ledger credit).
        $payment = app(DepositService::class)->initiate($player, 50_000, (string) Str::uuid());
        $this->post(route('payments.callback', 'fake'), $this->fake()->complete($payment->gateway_reference, 'succeeded'));
        // A failed deposit.
        $failed = app(DepositService::class)->initiate($player, 20_000, (string) Str::uuid());
        $this->post(route('payments.callback', 'fake'), $this->fake()->complete($failed->gateway_reference, 'failed'));
        // Entry fee Rs 100, then withdrawn and refunded.
        $room = $this->openRoom(['entry_fee_minor' => 10_000, 'min_participants' => 2, 'max_participants' => 10]);
        app(RoomEntryService::class)->join($player, $room);
        app(RoomEntryService::class)->withdraw($player, $room);

        $finance = app(ReportMetrics::class)->finance($this->period(['period' => 'today']));
        $this->assertSame(50_000, $finance['deposits']);
        $this->assertSame(1, $finance['deposits_count']);
        $this->assertSame(10_000, $finance['gross_entry_fees']);
        $this->assertSame(10_000, $finance['entry_fee_refunds']);
        $this->assertSame(0, $finance['net_entry_fees']);
        $this->assertSame(1, $finance['failed_payments']);
        $this->assertSame(0, $finance['platform_revenue']);

        $reconciliation = app(FinanceReconciliation::class)->for($this->period(['period' => 'today']));
        $this->assertSame(0, $reconciliation['opening']);
        $this->assertSame(50_000, $reconciliation['closing']);
        $this->assertTrue($reconciliation['balanced']);
        $this->assertSame([0, 0, 0, 0], array_column($reconciliation['checks'], 'difference'));

        // A ledger movement with no matching business record shows up as a difference.
        app(WalletService::class)->credit(app(WalletService::class)->walletFor($player), 1_234, LedgerTransactionType::PrizePayout, 'orphan-prize');
        $checks = collect(app(FinanceReconciliation::class)->for($this->period(['period' => 'today']))['checks'])->keyBy('label');
        $this->assertSame(1_234, $checks['Prizes: paid payouts (net) vs ledger prize credits']['difference']);

        // Earlier periods do not include today's money.
        $this->assertSame(0, app(ReportMetrics::class)->finance($this->period(['period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-04']))['deposits']);

        $this->actingAs($this->admin)->get(route('admin.reports.finance', ['report' => 'deposits', 'period' => 'today']))
            ->assertOk()->assertSee($payment->reference)->assertSee('Rs 500');
        $this->actingAs($this->admin)->get(route('admin.reports.index', ['period' => 'today']))->assertOk()
            ->assertSeeInOrder(['Gross entry fees', 'Rs 100', 'Entry fee refunds', 'Rs 100', 'Prize credits', 'Deposits', 'Rs 500']);
    }

    public function test_competition_report_aggregates_and_paginates(): void
    {
        $paper = $this->publishedPaper();
        Room::factory()->count(27)->fill()->create(['quiz_paper_id' => $paper->id, 'scheduled_start_at' => null]);

        $page = fn (int $n) => $this->actingAs($this->admin)->get(route('admin.reports.competitions', ['page' => $n]))->assertOk();
        $rows = fn ($response) => preg_match_all('#/admin/rooms/\d+"#', str($response->getContent())->after('<main')->toString());

        $first = $page(1);
        $first->assertSee('Competitions')->assertSee('27');
        $this->assertSame(25, $rows($first));
        $this->assertSame(2, $rows($page(2)));
    }

    public function test_audit_records_carry_the_request_id_and_never_credentials(): void
    {
        $target = User::factory()->create();

        $response = $this->actingAs($this->admin)->withHeader('X-Request-Id', '0d5b1f5e-8f5e-4f4b-9b5a-7b9e1c2d3e4f')
            ->patch(route('admin.users.status', $target), ['status' => 'restricted', 'reason' => 'Document check', 'confirm' => '1']);

        $response->assertHeader('X-Request-Id', '0d5b1f5e-8f5e-4f4b-9b5a-7b9e1c2d3e4f');
        $log = AuditLog::query()->where('action', 'user.status_changed')->sole();
        $this->assertSame('0d5b1f5e-8f5e-4f4b-9b5a-7b9e1c2d3e4f', $log->request_id);
        $this->assertSame($this->admin->id, $log->actor_id);
        $this->assertSame(['status' => 'active'], $log->old_values);
        $this->assertSame(['status' => 'restricted'], $log->new_values);
        $this->assertNotNull($log->ip_address);

        // An invalid incoming id is replaced with a fresh one.
        $fresh = $this->actingAs($this->admin)->withHeader('X-Request-Id', 'not a uuid')->get(route('admin.dashboard'))->headers->get('X-Request-Id');
        $this->assertTrue(Str::isUuid($fresh));

        // Credential-like keys are removed before anything is stored.
        $sanitized = app(AuditLogger::class)->record('test.sanitize', null, ['password' => 'secret1'], ['api_token' => 'abc', 'account_number' => 'PK36SCBL0000001123456702'],
            ['reason' => 'kept', 'nested' => ['card_number' => '4111111111111111'], 'inputs_hash' => 'kept-hash']);
        $this->assertSame(['password' => '[removed]'], $sanitized->old_values);
        $this->assertSame(['api_token' => '[removed]', 'account_number' => '[removed]'], $sanitized->new_values);
        $this->assertSame(['reason' => 'kept', 'nested' => ['card_number' => '[removed]'], 'inputs_hash' => 'kept-hash'], $sanitized->metadata);

        // Audit records cannot be changed, even directly in the database.
        $this->expectException(QueryException::class);
        DB::table('audit_logs')->where('id', $log->id)->update(['action' => 'tampered']);
    }

    public function test_exports_are_queued_audited_and_downloadable_only_by_their_owner(): void
    {
        Storage::fake('local');
        Room::factory()->fill()->create(['name' => '=HYPERLINK("http://evil.test","Click")', 'quiz_paper_id' => $this->publishedPaper()->id, 'scheduled_start_at' => null]);

        $this->actingAs($this->admin)->post(route('admin.reports.export'), ['report' => 'competitions', 'period' => '7d'])
            ->assertRedirect(route('admin.exports.index'));

        $export = ReportExport::query()->sole();
        $this->assertSame('done', $export->status, 'Built by the queued job (sync queue in tests).');
        $this->assertSame(1, $export->rows);
        $this->assertSame(1, AuditLog::query()->where('action', 'report.exported')->where('actor_id', $this->admin->id)->count());

        $csv = Storage::disk('local')->get($export->path);
        $this->assertStringStartsWith('room_id,session_id,room,status', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv, 'Formula neutralized.');
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);

        $this->actingAs($this->admin)->get(route('admin.exports.download', $export))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->actingAs(User::factory()->role(UserRole::Admin)->create())->get(route('admin.exports.download', $export))->assertForbidden();
        $this->actingAs(User::factory()->role(UserRole::ContentManager)->create())->get(route('admin.exports.download', $export))->assertForbidden();

        // Expired exports are no longer served and are pruned with their file.
        $this->travel(ReportExport::EXPIRES_DAYS + 1)->days();
        $this->actingAs($this->admin)->get(route('admin.exports.download', $export))->assertNotFound();
        $this->artisan('retention:prune')->assertSuccessful();
        $this->assertFalse(Storage::disk('local')->exists($export->path));
        $this->assertSame(0, ReportExport::query()->count());
    }

    public function test_financial_exports_need_finance_permission(): void
    {
        Gate::before(fn ($user, $ability) => $ability === 'finance.view' ? false : null);
        $this->actingAs($this->admin)->post(route('admin.reports.export'), ['report' => 'deposits'])->assertForbidden();
        $this->actingAs($this->admin)->post(route('admin.reports.export'), ['report' => 'not-a-report'])->assertSessionHasErrors('report');
        $this->assertSame(0, ReportExport::query()->count());
    }

    public function test_csv_cells_cannot_become_formulas(): void
    {
        foreach (['=SUM(A1:A9)', '+cmd|calc', '-2+3', '@SUM(1)', "\t=1", "\r=1"] as $dangerous) {
            $this->assertSame("'".$dangerous, CsvWriter::safe($dangerous));
        }
        $this->assertSame('Room A', CsvWriter::safe('Room A'));
        $this->assertSame(-1500, CsvWriter::safe(-1500), 'Numbers stay numeric.');
        $this->assertSame('', CsvWriter::safe(null));
        $this->assertSame('active', CsvWriter::safe(UserStatus::Active));
    }

    public function test_user_report_shows_participation_finance_and_history(): void
    {
        config(['competition.paid_entries.enabled' => true]);
        $player = User::factory()->create(['display_name' => 'ReportedPlayer']);
        app(WalletService::class)->credit(app(WalletService::class)->walletFor($player), 30_000, LedgerTransactionType::Deposit, 'seed');
        app(RoomEntryService::class)->join($player, $this->openRoom(['name' => 'Reported Room', 'entry_fee_minor' => 10_000]));
        app(UserAdministrationService::class)->changeStatus($this->admin, $player, UserStatus::Restricted, 'Investigation');

        $this->actingAs($this->admin)->get(route('admin.users.report', $player))->assertOk()
            ->assertSee('Restricted')->assertSee('Reported Room')->assertSee('Registered')
            ->assertSeeInOrder(['Balance', 'Rs 200', 'Deposits', 'Rs 300', 'Entry fees', '-Rs 100'])
            ->assertSee('user.status_changed');
        $this->actingAs(User::factory()->create())->get(route('admin.users.report', $player))->assertForbidden();
    }
}
