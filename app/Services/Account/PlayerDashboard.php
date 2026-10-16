<?php

namespace App\Services\Account;

use App\Enums\LedgerTransactionType;
use App\Enums\ParticipantStatus;
use App\Enums\PayoutStatus;
use App\Enums\RoomStatus;
use App\Models\LedgerEntry;
use App\Models\ParticipantResult;
use App\Models\PrizePayout;
use App\Models\RoomParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only queries for the signed-in player's dashboard. Every query starts
 * from the given user's own seats, wallet or notifications, so a request can
 * never reach another player's data (no ids are taken from the request).
 * Results, ranks and prizes are shown only once results are published.
 */
class PlayerDashboard
{
    public const TABS = ['upcoming', 'live', 'completed', 'cancelled'];

    /**
     * The user's seats for one tab of "My competitions".
     *
     * @return Builder<RoomParticipant>
     */
    public function competitions(User $user, string $tab): Builder
    {
        $query = $this->seats($user)->with([
            'room:id,name,status,entry_fee_minor,currency,participants_count,max_participants,scheduled_start_at,starts_at,cancelled_at,cancellation_reason',
            'room.session:id,room_id,status,results_published_at',
            'result:id,room_participant_id,score,rank,correct_count,correct_response_time_ms,prize_amount_minor',
            'prizePayout:id,room_participant_id,status,amount_minor,net_amount_minor',
        ]);

        return match ($tab) {
            'upcoming' => $query
                ->whereIn('room_participants.status', [ParticipantStatus::Reserved, ParticipantStatus::Joined])
                ->whereHas('room', fn ($q) => $q->whereIn('status', [RoomStatus::Scheduled, RoomStatus::Open, RoomStatus::Full, RoomStatus::Starting]))
                ->orderBy(DB::raw('(SELECT COALESCE(starts_at, scheduled_start_at) FROM rooms WHERE rooms.id = room_participants.room_id)')),
            'live' => $query
                ->whereNotIn('room_participants.status', [ParticipantStatus::Withdrawn, ParticipantStatus::Expired])
                ->whereHas('room', fn ($q) => $q->where('status', RoomStatus::InProgress))
                ->latest('joined_at'),
            'completed' => $query
                ->whereIn('room_participants.status', [ParticipantStatus::Finished, ParticipantStatus::Disqualified])
                ->whereHas('room', fn ($q) => $q->where('status', RoomStatus::Completed))
                ->latest('joined_at'),
            default => $query
                ->where(fn ($q) => $q
                    ->whereIn('room_participants.status', [ParticipantStatus::Withdrawn, ParticipantStatus::Expired, ParticipantStatus::Refunded])
                    ->orWhereHas('room', fn ($r) => $r->whereIn('status', [RoomStatus::Cancelled, RoomStatus::Refunded])))
                ->latest('joined_at'),
        };
    }

    /**
     * @return array<string, int>
     */
    public function tabCounts(User $user): array
    {
        return collect(self::TABS)->mapWithKeys(fn ($tab) => [$tab => $this->competitions($user, $tab)->toBase()->getCountForPagination()])->all();
    }

    /**
     * Finished competitions with their published result, for the history table.
     *
     * @return Builder<RoomParticipant>
     */
    public function history(User $user): Builder
    {
        return $this->competitions($user, 'completed');
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(User $user): array
    {
        $wallet = $user->wallet;
        $published = fn ($q) => $q->whereNotNull('results_published_at');

        return [
            'wallet' => $wallet,
            'live' => $this->competitions($user, 'live')->with('room.session:id,room_id,status')->first(),
            'upcoming' => $this->competitions($user, 'upcoming')->limit(3)->get(),
            'upcomingCount' => $this->competitions($user, 'upcoming')->count(),
            'completedCount' => $this->competitions($user, 'completed')->count(),
            // Only facts from published results and the ledger.
            'wins' => ParticipantResult::query()->where('is_winner', true)
                ->whereHas('participant', fn ($q) => $q->where('user_id', $user->id))
                ->whereHas('session', $published)->count(),
            'prizesPaidMinor' => (int) $this->payouts($user)->where('status', PayoutStatus::Paid)->sum('net_amount_minor'),
            'prizes' => $this->payouts($user)->with(['participant.room:id,name,currency', 'ledgerTransaction:id,reference'])->latest('id')->limit(5)->get(),
            'transactions' => $wallet ? LedgerEntry::query()->where('wallet_id', $wallet->id)
                ->with(['transaction' => fn ($q) => $q->select('id', 'reference', 'type', 'description', 'created_at')->withExists('reversal')])
                ->latest('id')->limit(5)->get() : collect(),
            'notifications' => $user->recentNotifications(),
            'unreadNotifications' => $user->unreadNotificationCount(),
        ];
    }

    /**
     * Money in and out of the user's own wallet, by kind (signed minor units).
     *
     * @return array<string, int>
     */
    public function walletBreakdown(User $user): array
    {
        $wallet = $user->wallet;
        if ($wallet === null) {
            return [];
        }

        $sums = DB::table('ledger_entries as e')
            ->join('ledger_transactions as t', 't.id', '=', 'e.ledger_transaction_id')
            ->where('e.wallet_id', $wallet->id)
            ->groupBy('t.type')
            ->selectRaw('t.type as type, SUM(e.amount_minor) as total')
            ->pluck('total', 'type');

        $get = fn (LedgerTransactionType ...$types) => (int) collect($types)->sum(fn ($t) => (int) ($sums[$t->value] ?? 0));

        return [
            'deposits' => $get(LedgerTransactionType::Deposit),
            'entry_fees' => $get(LedgerTransactionType::EntryFee),
            'refunds' => $get(LedgerTransactionType::EntryRefund),
            'prizes' => $get(LedgerTransactionType::PrizePayout),
            'withdrawals' => $get(LedgerTransactionType::Withdrawal),
            'other' => $get(LedgerTransactionType::Adjustment, LedgerTransactionType::Reversal),
        ];
    }

    /**
     * @return Builder<RoomParticipant>
     */
    protected function seats(User $user): Builder
    {
        return RoomParticipant::query()->where('room_participants.user_id', $user->id);
    }

    /**
     * @return Builder<PrizePayout>
     */
    protected function payouts(User $user): Builder
    {
        return PrizePayout::query()
            ->whereHas('participant', fn ($q) => $q->where('user_id', $user->id))
            ->whereNotIn('status', [PayoutStatus::Cancelled]);
    }
}
