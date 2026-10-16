<?php

namespace App\Services\Admin;

use App\Enums\LedgerTransactionType;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\PayoutStatus;
use App\Enums\RefundStatus;
use App\Enums\RoomStatus;
use App\Enums\SessionStatus;
use App\Enums\UserStatus;
use App\Enums\WalletType;
use App\Models\AuditLog;
use App\Models\Wallet;
use App\Services\Compliance\LaunchGate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Read-only figures for the admin dashboard, all derived from the database.
 * Money values are integer minor units.
 *
 * The all-time totals (users, participation, money) scan whole tables, so their
 * cost grows with history. They are cached for competition.cache.dashboard_seconds
 * (default 60; 0 = always live) and shown with their "as of" time. They are
 * display-only: no decision, balance or permission ever reads this cache, and
 * alerts and recent audit actions are always live.
 */
class DashboardMetrics
{
    protected const CACHE_PREFIX = 'admin-dashboard:v1:';

    /**
     * @template T
     *
     * @param  callable(): T  $compute
     * @return T
     */
    protected function cached(string $key, callable $compute): mixed
    {
        $seconds = (int) config('competition.cache.dashboard_seconds');
        if ($seconds <= 0) {
            return $compute();
        }

        return Cache::remember(self::CACHE_PREFIX.$key, $seconds, fn () => ['value' => $compute(), 'at' => now()->toIso8601String()])['value'];
    }

    /**
     * When the cached totals were computed (null when live or not cached yet).
     */
    public function figuresAsOf(): ?string
    {
        if ((int) config('competition.cache.dashboard_seconds') <= 0) {
            return null;
        }

        return Cache::get(self::CACHE_PREFIX.'users')['at'] ?? null;
    }

    /**
     * @return array{total: int, active: int, restricted: int, suspended: int, new_7_days: int, verified: int}
     */
    public function users(): array
    {
        return $this->cached('users', fn () => $this->computeUsers());
    }

    /**
     * @return array{total: int, active: int, restricted: int, suspended: int, new_7_days: int, verified: int}
     */
    protected function computeUsers(): array
    {
        $byStatus = DB::table('users')->whereNull('deleted_at')
            ->select('status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'total' => (int) $byStatus->sum(),
            'active' => (int) ($byStatus[UserStatus::Active->value] ?? 0),
            'restricted' => (int) ($byStatus[UserStatus::Restricted->value] ?? 0),
            'suspended' => (int) ($byStatus[UserStatus::Suspended->value] ?? 0),
            'new_7_days' => DB::table('users')->whereNull('deleted_at')->where('created_at', '>=', now()->subDays(7))->count(),
            'verified' => DB::table('users')->whereNull('deleted_at')->whereNotNull('email_verified_at')->count(),
        ];
    }

    /**
     * @return array<string, int> room count per status value
     */
    public function rooms(): array
    {
        $counts = DB::table('rooms')
            ->select('status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return collect(RoomStatus::cases())
            ->mapWithKeys(fn (RoomStatus $s) => [$s->value => (int) ($counts[$s->value] ?? 0)])
            ->all();
    }

    /**
     * @return array{seats: int, players: int, sessions_completed: int}
     */
    public function participation(): array
    {
        return $this->cached('participation', fn () => [
            'seats' => DB::table('room_participants')->count(),
            'players' => DB::table('room_participants')->distinct()->count('user_id'),
            'sessions_completed' => DB::table('competition_sessions')->where('status', SessionStatus::Completed)->count(),
        ]);
    }

    /**
     * Separate, non-overlapping money figures. None of these is profit.
     *
     * @return array<string, int>
     */
    public function finance(): array
    {
        return $this->cached('finance', fn () => $this->computeFinance());
    }

    /**
     * @return array<string, int>
     */
    protected function computeFinance(): array
    {
        $succeededPayments = fn (PaymentType $type) => (int) DB::table('payment_transactions')
            ->where('type', $type)->where('status', PaymentStatus::Succeeded)->sum('amount_minor');

        // Signed sum of ledger movements on player wallets for one transaction type.
        $playerLedger = fn (LedgerTransactionType $type) => (int) DB::table('ledger_entries')
            ->join('ledger_transactions', 'ledger_transactions.id', '=', 'ledger_entries.ledger_transaction_id')
            ->join('wallets', 'wallets.id', '=', 'ledger_entries.wallet_id')
            ->where('wallets.type', WalletType::User)
            ->where('ledger_transactions.type', $type)
            ->sum('ledger_entries.amount_minor');

        $outstanding = DB::table('prize_payouts')->whereIn('status', PayoutStatus::outstanding());

        return [
            'gross_deposits' => $succeededPayments(PaymentType::Deposit),
            'withdrawals_paid' => $succeededPayments(PaymentType::Withdrawal),
            'gateway_refunds' => (int) DB::table('payment_refunds')->where('status', RefundStatus::Succeeded)->sum('amount_minor'),
            'entry_fees_charged' => -$playerLedger(LedgerTransactionType::EntryFee),
            'entry_fees_refunded' => $playerLedger(LedgerTransactionType::EntryRefund),
            'prizes_paid' => $playerLedger(LedgerTransactionType::PrizePayout),
            'platform_revenue_balance' => (int) Wallet::query()->where('system_code', Wallet::SYSTEM_PLATFORM_REVENUE)->sum('balance_minor'),
            'outstanding_payouts_count' => (clone $outstanding)->count(),
            'outstanding_payouts_amount' => (int) (clone $outstanding)->sum('amount_minor'),
        ];
    }

    /**
     * Operational warnings derived from configuration and data.
     *
     * @return list<array{level: string, message: string}>
     */
    public function alerts(): array
    {
        $alerts = [];

        if (config('app.debug') && app()->isProduction()) {
            $alerts[] = ['level' => 'error', 'message' => 'APP_DEBUG is enabled in production. Disable it immediately.'];
        }

        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            $alerts[] = ['level' => 'warning', 'message' => 'Mail is written to the log ('.config('mail.default').' mailer). Players will not receive verification or password-reset emails.'];
        }

        $failedJobs = DB::table('failed_jobs')->count();
        if ($failedJobs > 0) {
            $alerts[] = ['level' => 'warning', 'message' => "{$failedJobs} queued job(s) have failed and need attention."];
        }

        $overdue = DB::table('rooms')
            ->whereIn('status', [RoomStatus::Scheduled, RoomStatus::Open, RoomStatus::Full])
            ->whereNotNull('scheduled_start_at')
            ->where('scheduled_start_at', '<', now())
            ->count();
        if ($overdue > 0) {
            $alerts[] = ['level' => 'warning', 'message' => "{$overdue} room(s) are past their scheduled start but have not started. The competition engine is not yet running."];
        }

        $outstanding = DB::table('prize_payouts')->whereIn('status', PayoutStatus::outstanding())->count();
        if ($outstanding > 0) {
            $alerts[] = ['level' => 'warning', 'message' => "{$outstanding} prize payout record(s) are not yet paid (pending, approved, processing, held or failed)."];
        }

        $gate = app(LaunchGate::class);
        if ($blocked = $gate->blocked()) {
            $alerts[] = ['level' => 'error', 'message' => 'Real-money switches are set ('.implode(', ', $blocked).') but held OFF by the launch gate: '
                .count($gate->missing()).' compliance requirement(s) unconfirmed or no recorded approval. See Settings.'];
        }

        if (! config('competition.paid_entries.enabled')) {
            $alerts[] = ['level' => 'info', 'message' => 'Paid entries are disabled. Only free rooms can be joined.'];
        }

        if (config('competition.payments.gateway') === 'disabled') {
            $alerts[] = ['level' => 'info', 'message' => 'No payment gateway is configured. Deposits and withdrawals are unavailable.'];
        }

        return $alerts;
    }

    /**
     * @return Collection<int, AuditLog>
     */
    public function recentActions(int $limit = 8): Collection
    {
        return AuditLog::query()->with('actor:id,name,display_name,deleted_at')->latest('id')->limit($limit)->get();
    }
}
