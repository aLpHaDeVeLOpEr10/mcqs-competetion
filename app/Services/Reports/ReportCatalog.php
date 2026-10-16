<?php

namespace App\Services\Reports;

use App\Enums\LedgerTransactionType;
use App\Enums\Permission;
use App\Models\RoomParticipant;
use App\Models\User;
use App\Support\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The reports that can be listed and exported: their permission, base query
 * (Query Builder, aggregated in SQL, paginated or streamed — never loaded
 * whole) and columns. Pages and CSV exports share these definitions, so an
 * export can never contain more than its page or bypass its permission.
 */
class ReportCatalog
{
    /**
     * @var array<string, array{title: string, permission: Permission}>
     */
    public const REPORTS = [
        'competitions' => ['title' => 'Competitions', 'permission' => Permission::ReportsView],
        'deposits' => ['title' => 'Deposits', 'permission' => Permission::FinanceView],
        'entry_fees' => ['title' => 'Entry fees', 'permission' => Permission::FinanceView],
        'refunds' => ['title' => 'Entry fee refunds', 'permission' => Permission::FinanceView],
        'prize_credits' => ['title' => 'Prize credits', 'permission' => Permission::FinanceView],
        'withdrawals' => ['title' => 'Withdrawals', 'permission' => Permission::FinanceView],
        'adjustments' => ['title' => 'Administrative adjustments', 'permission' => Permission::FinanceView],
    ];

    public const LEDGER_REPORTS = [
        'entry_fees' => LedgerTransactionType::EntryFee,
        'refunds' => LedgerTransactionType::EntryRefund,
        'prize_credits' => LedgerTransactionType::PrizePayout,
        'adjustments' => LedgerTransactionType::Adjustment,
    ];

    public function __construct(protected ReportMetrics $metrics) {}

    public static function allowed(User $user, string $report): bool
    {
        return isset(self::REPORTS[$report]) && $user->can(self::REPORTS[$report]['permission']->value);
    }

    /**
     * Rows for a report in a period, newest first.
     */
    public function query(string $report, ReportPeriod $p): Builder
    {
        return match (true) {
            $report === 'competitions' => $this->competitions($p),
            $report === 'deposits' => $p->apply(DB::table('payment_transactions as pt')->join('users as u', 'u.id', '=', 'pt.user_id')
                ->where('pt.type', 'deposit')
                ->select('pt.id', 'pt.created_at', 'pt.reference', 'u.email', 'pt.amount_minor', 'pt.currency', 'pt.status', 'pt.gateway', 'pt.gateway_reference', 'pt.completed_at'), 'pt.created_at')
                ->orderByDesc('pt.id'),
            $report === 'withdrawals' => $p->apply(DB::table('withdrawals as wd')->join('users as u', 'u.id', '=', 'wd.user_id')
                ->select('wd.id', 'wd.created_at', 'wd.reference', 'u.email', 'wd.amount_minor', 'wd.fee_minor', 'wd.currency', 'wd.status', 'wd.provider_reference', 'wd.paid_at'), 'wd.created_at')
                ->orderByDesc('wd.id'),
            isset(self::LEDGER_REPORTS[$report]) => $p->apply($this->metrics->userLedger()->join('users as u', 'u.id', '=', 'w.user_id')
                ->where('t.type', self::LEDGER_REPORTS[$report]->value)
                ->select('e.id', 't.created_at', 't.reference', 'u.email', 'e.amount_minor', 't.currency', 't.description'), 't.created_at')
                ->orderByDesc('e.id'),
            default => throw new InvalidArgumentException("Unknown report [{$report}]."),
        };
    }

    /**
     * One page of a report. The competition report uses a deferred join: the page's rooms
     * are chosen from the cheap filtered/ordered room list first, and the per-room
     * aggregates are computed for those rows only (not for every room in the period).
     * Same rows, same order, same values as paginating query() directly.
     */
    public function page(string $report, ReportPeriod $p, int $perPage): LengthAwarePaginator
    {
        if ($report !== 'competitions') {
            return $this->query($report, $p)->paginate($perPage)->withQueryString();
        }

        $page = $this->competitionRooms($p)->select('r.id')->paginate($perPage)->withQueryString();
        $ids = collect($page->items())->pluck('id');

        return $page->setCollection($ids->isEmpty() ? collect() : $this->competitions($p)->whereIn('r.id', $ids)->get());
    }

    /**
     * Totals for the whole period (not just the page).
     *
     * @return array<string, int>
     */
    public function totals(string $report, ReportPeriod $p): array
    {
        $base = fn () => $this->query($report, $p)->reorder();

        return match (true) {
            $report === 'competitions' => $this->competitionTotals($p),
            $report === 'deposits' => [
                'count' => $base()->count(),
                'succeeded_amount' => (int) $base()->where('pt.status', 'succeeded')->sum('pt.amount_minor'),
                'failed_or_expired' => $base()->whereIn('pt.status', ['failed', 'expired'])->count(),
            ],
            $report === 'withdrawals' => [
                'count' => $base()->count(),
                'requested_amount' => (int) $base()->sum('wd.amount_minor'),
                'paid_amount' => (int) $base()->where('wd.status', 'paid')->sum('wd.amount_minor'),
                'fees' => (int) $base()->whereNotIn('wd.status', ['rejected', 'cancelled', 'failed', 'reversed'])->sum('wd.fee_minor'),
            ],
            default => [
                'count' => $base()->count(),
                'net_amount' => (int) $base()->sum('e.amount_minor'),
            ],
        };
    }

    /**
     * CSV header and row mapping.
     *
     * @return array{0: list<string>, 1: callable(object): list<mixed>}
     */
    public function columns(string $report): array
    {
        $time = fn ($value) => $value ? DisplayTime::format(CarbonImmutable::parse($value, 'UTC'), 'Y-m-d H:i:s') : '';
        $money = fn ($minor) => number_format(((int) $minor) / (int) config('competition.currency.minor_units_per_major'), 2, '.', '');

        return match (true) {
            $report === 'competitions' => [
                ['room_id', 'session_id', 'room', 'status', 'entry_fee', 'participants', 'gross_collected', 'refunds', 'prize_pool', 'winners', 'started_at_pkt', 'ended_at_pkt'],
                fn ($r) => [$r->id, $r->session_id, $r->name, $r->status, $money($r->entry_fee_minor), (int) $r->participants, $money($r->gross_collected), $money($r->refunds),
                    $r->prize_pool_minor === null ? '' : $money($r->prize_pool_minor), (int) $r->winners, $time($r->started_at), $time($r->ended_at)],
            ],
            $report === 'deposits' => [
                ['created_at_pkt', 'reference', 'player_email', 'amount', 'currency', 'status', 'gateway', 'provider_reference', 'completed_at_pkt'],
                fn ($r) => [$time($r->created_at), $r->reference, $r->email, $money($r->amount_minor), $r->currency, $r->status, $r->gateway, $r->gateway_reference, $time($r->completed_at)],
            ],
            $report === 'withdrawals' => [
                ['created_at_pkt', 'reference', 'player_email', 'amount', 'fee', 'currency', 'status', 'provider_reference', 'paid_at_pkt'],
                fn ($r) => [$time($r->created_at), $r->reference, $r->email, $money($r->amount_minor), $money($r->fee_minor), $r->currency, $r->status, $r->provider_reference, $time($r->paid_at)],
            ],
            default => [
                ['created_at_pkt', 'ledger_reference', 'player_email', 'amount', 'currency', 'description'],
                fn ($r) => [$time($r->created_at), $r->reference, $r->email, $money($r->amount_minor), $r->currency, $r->description],
            ],
        };
    }

    /**
     * Rooms whose competition started (or was scheduled / created) in the period,
     * with per-room aggregates computed in SQL (no N+1).
     */
    protected function competitions(ReportPeriod $p): Builder
    {
        return $this->competitionRooms($p)
            ->select('r.id', 'r.name', 'r.status', 'r.entry_fee_minor', 'r.currency', 'r.max_participants',
                's.id as session_id', 's.started_at', 's.ended_at', 'd.prize_pool_minor', 'd.total_amount_minor as prizes_awarded')
            ->selectSub(DB::table('room_participants')->whereColumn('room_id', 'r.id')->whereNotNull('confirmed_at')->selectRaw('COUNT(*)'), 'participants')
            ->selectSub($this->roomLedger('entry_fee', '-'), 'gross_collected')
            ->selectSub($this->roomLedger('entry_refund', ''), 'refunds')
            ->selectSub(DB::table('participant_results')->whereColumn('competition_session_id', 's.id')->where('is_winner', true)->selectRaw('COUNT(*)'), 'winners');
    }

    /**
     * The rooms of the competition report (filter and order only, no aggregates).
     */
    protected function competitionRooms(ReportPeriod $p): Builder
    {
        $start = 'COALESCE(s.started_at, r.scheduled_start_at, r.created_at)';

        return DB::table('rooms as r')
            ->leftJoin('competition_sessions as s', 's.room_id', '=', 'r.id')
            ->leftJoin('prize_distributions as d', 'd.competition_session_id', '=', 's.id')
            ->whereRaw("{$start} >= ? AND {$start} < ?", [$p->from, $p->to])
            ->orderByRaw("{$start} DESC")->orderByDesc('r.id');
    }

    /**
     * Net ledger movement on player wallets for one transaction type, for the seats of room r.
     */
    protected function roomLedger(string $type, string $sign): Builder
    {
        return DB::table('ledger_entries as e')
            ->join('ledger_transactions as t', 't.id', '=', 'e.ledger_transaction_id')
            ->join('wallets as w', 'w.id', '=', 'e.wallet_id')
            ->join('room_participants as rp', 'rp.id', '=', 't.reference_id')
            ->where('t.reference_type', (new RoomParticipant)->getMorphClass())->where('t.type', $type)->where('w.type', 'user')
            ->whereColumn('rp.room_id', 'r.id')
            ->selectRaw("COALESCE({$sign}SUM(e.amount_minor), 0)");
    }

    /**
     * Competition report totals in one query, computing only the two money aggregates.
     *
     * @return array{competitions: int, gross_collected: int, refunds: int}
     */
    protected function competitionTotals(ReportPeriod $p): array
    {
        $rows = $this->competitionRooms($p)->reorder()->select('r.id')
            ->selectSub($this->roomLedger('entry_fee', '-'), 'gross_collected')
            ->selectSub($this->roomLedger('entry_refund', ''), 'refunds');

        $totals = DB::query()->fromSub($rows, 'c')
            ->selectRaw('COUNT(*) as competitions, COALESCE(SUM(gross_collected), 0) as gross_collected, COALESCE(SUM(refunds), 0) as refunds')
            ->first();

        return ['competitions' => (int) $totals->competitions, 'gross_collected' => (int) $totals->gross_collected, 'refunds' => (int) $totals->refunds];
    }
}
