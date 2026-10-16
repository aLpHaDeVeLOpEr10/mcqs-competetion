<?php

namespace App\Services\Prizes;

use App\Enums\DisputeStatus;
use App\Enums\LedgerTransactionType;
use App\Enums\PayoutStatus;
use App\Enums\Permission;
use App\Enums\PrizeDistributionStatus;
use App\Enums\RoomStatus;
use App\Enums\SessionStatus;
use App\Enums\UserStatus;
use App\Exceptions\AdministrativeActionException;
use App\Exceptions\WalletOperationException;
use App\Models\CompetitionSession;
use App\Models\PrizeDistribution;
use App\Models\PrizePayout;
use App\Models\ResultDispute;
use App\Models\ResultVersion;
use App\Models\Room;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\PrizeCredited;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\Notifier;
use App\Services\Wallet\LedgerPosting;
use App\Services\Wallet\LedgerService;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;

/**
 * Pays finalized prize entitlements into winners' wallets (internal credits;
 * no external cash movement).
 *
 * distribute() is idempotent and safe to run any number of times, from the
 * results finalizer, the prizes:distribute scheduler and staff actions:
 *
 *  1. Under row locks on room → session → distribution → payouts, verify the
 *     payout conditions (blocker()). If blocked, payouts are HELD with the
 *     reason. Otherwise eligible payouts are claimed as PROCESSING and the
 *     platform's share of entry fees is transferred once.
 *  2. Each processing payout is paid in its own transaction: lock the payout,
 *     post one balanced ledger transaction (prize pool → winner, and the tax
 *     withholding account when a rate is configured) keyed by the payout's
 *     unique idempotency key, link it, mark PAID, audit. A crash leaves the
 *     payout PROCESSING and the next run finishes it; the ledger key makes a
 *     second credit impossible.
 *  3. Winners are notified after the commits.
 */
class PrizeDistributionService
{
    public const MANUAL_HOLD = 'Held by staff: ';

    public function __construct(
        protected LedgerService $ledger,
        protected WalletService $wallets,
        protected AuditLogger $audit,
    ) {}

    /**
     * @return array{status: string, reason: ?string, paid: int, failed: int}
     */
    public function distribute(int $sessionId, ?User $actor = null): array
    {
        // Looked up before the transaction: its first statement must be the locking read.
        $roomId = CompetitionSession::query()->whereKey($sessionId)->value('room_id');
        if ($roomId === null) {
            return ['status' => 'none', 'reason' => 'Unknown competition.', 'paid' => 0, 'failed' => 0];
        }

        $claim = DB::transaction(fn () => $this->claim($roomId, $sessionId, $actor));
        if ($claim['status'] !== 'claimed') {
            return $claim + ['paid' => 0, 'failed' => 0];
        }

        $paid = [];
        $failed = 0;
        foreach ($claim['payouts'] as $payoutId) {
            try {
                if ($payout = $this->pay($payoutId)) {
                    $paid[] = $payout;
                }
            } catch (WalletOperationException $e) {
                $this->markFailed($payoutId, $e->getMessage());
                $failed++;
            }
        }

        $status = DB::transaction(fn () => $this->settleDistribution($sessionId));
        $this->notify($paid);

        return ['status' => $status, 'reason' => null, 'paid' => count($paid), 'failed' => $failed];
    }

    /**
     * Why prizes for this competition cannot be paid now, or null when they can.
     */
    public function blocker(Room $room, CompetitionSession $session, PrizeDistribution $distribution): ?string
    {
        $latest = ResultVersion::query()->where('competition_session_id', $session->id)->max('version');

        return match (true) {
            ! config('competition.prizes.payouts_enabled') => 'Prize payouts are not enabled on this platform.',
            $room->status !== RoomStatus::Completed => 'The competition was not validly completed.',
            $session->status !== SessionStatus::Completed || $session->results_published_at === null => 'Results are not final and published yet.',
            (int) $latest !== (int) ($distribution->calculation['result_version'] ?? 0) => 'Results were recalculated; the prizes must be recalculated first.',
            config('competition.prizes.hold_while_disputed') && ResultDispute::query()
                ->where('competition_session_id', $session->id)->where('status', DisputeStatus::Open)->exists() => 'A result dispute is open.',
            ($distribution->calculation['rules']['review_required'] ?? false) && $distribution->approved_at === null => 'Waiting for finance review.',
            default => null,
        };
    }

    /**
     * Finance review for competitions configured to require it.
     */
    public function approve(User $actor, PrizeDistribution $distribution, string $note): array
    {
        $this->authorize($actor);

        DB::transaction(function () use ($actor, $distribution, $note) {
            $distribution = PrizeDistribution::query()->lockForUpdate()->findOrFail($distribution->id);
            if ($distribution->approved_at !== null) {
                throw new AdministrativeActionException('These prizes are already approved.');
            }
            if (in_array($distribution->status, [PrizeDistributionStatus::Paid, PrizeDistributionStatus::Cancelled], true)) {
                throw new AdministrativeActionException('These prizes can no longer be approved.');
            }

            $distribution->forceFill(['approved_by' => $actor->id, 'approved_at' => now()])->save();
            PrizePayout::query()->where('prize_distribution_id', $distribution->id)->where('status', PayoutStatus::Pending)
                ->update(['status' => PayoutStatus::Approved->value, 'approved_by' => $actor->id, 'approved_at' => now()]);
            $this->audit->record('prizes.approved', $distribution, null, ['total_minor' => $distribution->total_amount_minor], ['note' => $note], $actor);
        });

        return $this->distribute($distribution->competition_session_id, $actor);
    }

    public function hold(User $actor, PrizePayout $payout, string $reason): void
    {
        $this->authorize($actor);
        $this->changePayout($actor, $payout, [PayoutStatus::Pending, PayoutStatus::Approved, PayoutStatus::Held, PayoutStatus::Failed], PayoutStatus::Held,
            ['status_reason' => self::MANUAL_HOLD.$reason, 'held_at' => now()], 'prize.held', $reason);
    }

    public function release(User $actor, PrizePayout $payout, string $reason): array
    {
        $this->authorize($actor);
        $this->changePayout($actor, $payout, [PayoutStatus::Held], PayoutStatus::Pending,
            ['status_reason' => null, 'held_at' => null], 'prize.released', $reason);

        return $this->distribute($payout->distribution->competition_session_id, $actor);
    }

    public function retry(User $actor, PrizePayout $payout, string $reason): array
    {
        $this->authorize($actor);
        $this->changePayout($actor, $payout, [PayoutStatus::Failed], PayoutStatus::Pending,
            ['status_reason' => null, 'failed_at' => null], 'prize.retried', $reason);

        return $this->distribute($payout->distribution->competition_session_id, $actor);
    }

    /**
     * Take a paid prize back with a ledger reversal (only while the winner still
     * holds the money). The payout becomes "reversed" for good.
     *
     * @throws WalletOperationException when the winner's balance is too low
     */
    public function reverse(User $actor, PrizePayout $payout, string $reason): PrizePayout
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $payout, $reason) {
            $payout = $this->lockPayout($payout->id, $payout->prize_distribution_id) ?? throw new AdministrativeActionException('Unknown payout.');
            if ($payout->status !== PayoutStatus::Paid) {
                throw new AdministrativeActionException('Only a paid prize can be reversed.');
            }

            $reversal = $this->wallets->refund($payout->ledgerTransaction()->firstOrFail(), 'Prize reversed: '.$reason, $actor);
            $payout->forceFill([
                'status' => PayoutStatus::Reversed,
                'status_reason' => mb_substr('Reversed: '.$reason, 0, 255),
                'reversal_ledger_transaction_id' => $reversal->id,
                'reversed_by' => $actor->id,
                'reversed_at' => now(),
            ])->save();
            $this->audit->record('prize.reversed', $payout, ['status' => 'paid'], ['status' => 'reversed', 'reversal_reference' => $reversal->reference],
                ['reason' => $reason, 'amount_minor' => $payout->net_amount_minor], $actor);

            return $payout;
        });
    }

    // ---- internals --------------------------------------------------------------------

    /**
     * @return array{status: string, reason: ?string, payouts?: list<int>}
     */
    protected function claim(int $roomId, int $sessionId, ?User $actor): array
    {
        $room = Room::query()->lockForUpdate()->findOrFail($roomId);
        $session = CompetitionSession::query()->lockForUpdate()->findOrFail($sessionId);
        $distribution = PrizeDistribution::query()->where('competition_session_id', $sessionId)->lockForUpdate()->first();

        if ($distribution === null || $distribution->status === PrizeDistributionStatus::Cancelled) {
            return ['status' => 'none', 'reason' => 'There are no prizes for this competition.'];
        }

        // Locked by primary key (record locks only): a range lock on the status index would
        // deadlock with a concurrent pay() changing a payout's status. Every payout writer
        // holds the distribution lock first, so this list cannot change underneath us.
        $open = [PayoutStatus::Pending, PayoutStatus::Approved, PayoutStatus::Held, PayoutStatus::Processing];
        $ids = PrizePayout::query()->where('prize_distribution_id', $distribution->id)->whereIn('status', $open)->orderBy('id')->pluck('id');
        $payouts = PrizePayout::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->with('participant.user')->get()
            ->filter(fn (PrizePayout $p) => in_array($p->status, $open, true));

        if ($reason = $this->blocker($room, $session, $distribution)) {
            $this->holdAll($distribution, $payouts, $reason, $actor);

            return ['status' => 'held', 'reason' => $reason];
        }

        $claimed = [];
        foreach ($payouts as $payout) {
            if ($payout->status === PayoutStatus::Held && str_starts_with((string) $payout->status_reason, self::MANUAL_HOLD)) {
                continue; // only staff can release a manual hold
            }

            $user = $payout->participant->user;
            if ($user === null || in_array($user->status, [UserStatus::Suspended, UserStatus::Closed], true)) {
                $this->setStatus($payout, PayoutStatus::Held, ['status_reason' => 'The winner\'s account is under review.', 'held_at' => now()]);

                continue;
            }

            $this->setStatus($payout, PayoutStatus::Processing, ['status_reason' => null, 'held_at' => null, 'processing_at' => $payout->processing_at ?? now()]);
            $claimed[] = $payout->id;
        }

        $this->transferPlatformShare($room, $distribution);
        $distribution->forceFill(['status' => PrizeDistributionStatus::Approved, 'hold_reason' => null])->save();

        return ['status' => 'claimed', 'reason' => null, 'payouts' => $claimed];
    }

    /**
     * Credit one processing payout. Returns null when it is no longer processing.
     */
    protected function pay(int $payoutId): ?PrizePayout
    {
        $distributionId = (int) PrizePayout::query()->whereKey($payoutId)->value('prize_distribution_id');

        return DB::transaction(function () use ($payoutId, $distributionId) {
            $payout = $this->lockPayout($payoutId, $distributionId);
            if ($payout?->status !== PayoutStatus::Processing) {
                return null;
            }

            $payout->load(['participant.user', 'participant.room:id,name,currency', 'distribution:id,competition_session_id,currency']);
            $room = $payout->participant->room;
            $currency = $payout->distribution->currency;
            $winner = $this->wallets->walletFor($payout->participant->user);
            $pool = $this->wallets->systemWallet(Wallet::SYSTEM_PRIZE_POOL, $currency);

            $gross = $payout->amount_minor;
            $tax = intdiv($gross * max(0, (int) config('competition.prizes.tax_withholding_bp')), 10000);
            $net = $gross - $tax;

            $legs = [$winner->id => $net, $pool->id => -$gross];
            if ($tax > 0) {
                $legs[$this->wallets->systemWallet(Wallet::SYSTEM_TAX_WITHHOLDING, $currency)->id] = $tax;
            }

            $transaction = $this->ledger->post(new LedgerPosting(
                type: LedgerTransactionType::PrizePayout,
                idempotencyKey: $payout->idempotency_key,
                legs: $legs,
                currency: $currency,
                description: "Prize: rank #{$payout->rank} in {$room->name}",
                related: $payout,
                metadata: [
                    'competition_session_id' => $payout->distribution->competition_session_id,
                    'room_id' => $room->id,
                    'rank' => $payout->rank,
                    'result_version' => $payout->result_version,
                    'gross_minor' => $gross,
                    'tax_withheld_minor' => $tax,
                ],
            ));

            $payout->forceFill([
                'status' => PayoutStatus::Paid,
                'status_reason' => null,
                'ledger_transaction_id' => $transaction->id,
                'tax_withheld_minor' => $tax,
                'net_amount_minor' => $net,
                'paid_at' => now(),
            ])->save();

            $this->audit->record('prize.paid', $payout, null, [
                'rank' => $payout->rank, 'gross_minor' => $gross, 'tax_withheld_minor' => $tax, 'net_minor' => $net,
                'ledger_reference' => $transaction->reference,
            ], ['competition_session_id' => $payout->distribution->competition_session_id]);

            return $payout;
        });
    }

    protected function markFailed(int $payoutId, string $reason): void
    {
        $distributionId = (int) PrizePayout::query()->whereKey($payoutId)->value('prize_distribution_id');

        DB::transaction(function () use ($payoutId, $distributionId, $reason) {
            $payout = $this->lockPayout($payoutId, $distributionId);
            if ($payout?->status === PayoutStatus::Processing) {
                $this->setStatus($payout, PayoutStatus::Failed, ['status_reason' => mb_substr($reason, 0, 255), 'failed_at' => now()]);
                $this->audit->record('prize.failed', $payout, null, ['status' => 'failed'], ['reason' => $reason]);
            }
        });
    }

    protected function settleDistribution(int $sessionId): string
    {
        $distribution = PrizeDistribution::query()->where('competition_session_id', $sessionId)->lockForUpdate()->firstOrFail();
        $counts = PrizePayout::query()->where('prize_distribution_id', $distribution->id)
            ->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');

        $outstanding = collect(PayoutStatus::outstanding())->sum(fn (PayoutStatus $s) => (int) ($counts[$s->value] ?? 0));
        if ($outstanding === 0 && (int) ($counts[PayoutStatus::Paid->value] ?? 0) > 0) {
            $distribution->forceFill(['status' => PrizeDistributionStatus::Paid, 'paid_at' => $distribution->paid_at ?? now()])->save();

            return 'paid';
        }

        if ((int) ($counts[PayoutStatus::Held->value] ?? 0) > 0 || (int) ($counts[PayoutStatus::Failed->value] ?? 0) > 0) {
            $distribution->forceFill(['status' => PrizeDistributionStatus::Held, 'hold_reason' => 'Some payouts are held or failed.'])->save();

            return 'partial';
        }

        return 'processing';
    }

    /**
     * Move the platform's share of net entry fees out of the prize pool, once.
     */
    protected function transferPlatformShare(Room $room, PrizeDistribution $distribution): void
    {
        if ($distribution->platform_amount_minor <= 0 || $distribution->platform_ledger_transaction_id !== null) {
            return;
        }

        $transaction = $this->ledger->post(new LedgerPosting(
            type: LedgerTransactionType::PlatformFee,
            idempotencyKey: 'platform_fee:'.$distribution->competition_session_id,
            legs: [
                $this->wallets->systemWallet(Wallet::SYSTEM_PRIZE_POOL, $distribution->currency)->id => -$distribution->platform_amount_minor,
                $this->wallets->systemWallet(Wallet::SYSTEM_PLATFORM_REVENUE, $distribution->currency)->id => $distribution->platform_amount_minor,
            ],
            currency: $distribution->currency,
            description: 'Platform fee: '.$room->name,
            related: $distribution,
            metadata: ['room_id' => $room->id, 'net_entry_fees_minor' => $distribution->net_entry_fees_minor],
        ));

        $distribution->forceFill(['platform_ledger_transaction_id' => $transaction->id])->save();
    }

    /**
     * @param  iterable<PrizePayout>  $payouts
     */
    protected function holdAll(PrizeDistribution $distribution, iterable $payouts, string $reason, ?User $actor): void
    {
        foreach ($payouts as $payout) {
            if ($payout->status === PayoutStatus::Processing) {
                continue; // already cleared to pay before the blocker appeared; it completes
            }
            if ($payout->status === PayoutStatus::Held && str_starts_with((string) $payout->status_reason, self::MANUAL_HOLD)) {
                continue;
            }
            if ($payout->status !== PayoutStatus::Held || $payout->status_reason !== $reason) {
                $this->setStatus($payout, PayoutStatus::Held, ['status_reason' => $reason, 'held_at' => now()]);
            }
        }

        if ($distribution->status !== PrizeDistributionStatus::Held || $distribution->hold_reason !== $reason) {
            $distribution->forceFill(['status' => PrizeDistributionStatus::Held, 'hold_reason' => $reason])->save();
            $this->audit->record('prizes.held', $distribution, null, ['status' => 'held'], ['reason' => $reason], $actor);
        }
    }

    /**
     * @param  list<PayoutStatus>  $from
     * @param  array<string, mixed>  $attributes
     */
    protected function changePayout(User $actor, PrizePayout $payout, array $from, PayoutStatus $to, array $attributes, string $action, string $reason): void
    {
        DB::transaction(function () use ($actor, $payout, $from, $to, $attributes, $action, $reason) {
            $payout = $this->lockPayout($payout->id, $payout->prize_distribution_id) ?? throw new AdministrativeActionException('Unknown payout.');
            if (! in_array($payout->status, $from, true)) {
                throw new AdministrativeActionException("A {$payout->status->label()} payout cannot be changed this way.");
            }

            $old = $payout->status;
            $this->setStatus($payout, $to, $attributes);
            $this->audit->record($action, $payout, ['status' => $old->value], ['status' => $to->value], ['reason' => $reason], $actor);
        });
    }

    /**
     * Lock order for every payout write: distribution, then the payout (by primary key),
     * then wallets (inside LedgerService). claim() takes room and session before that.
     */
    protected function lockPayout(int $payoutId, int $distributionId): ?PrizePayout
    {
        PrizeDistribution::query()->whereKey($distributionId)->lockForUpdate()->first();

        return PrizePayout::query()->whereKey($payoutId)->where('prize_distribution_id', $distributionId)->lockForUpdate()->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function setStatus(PrizePayout $payout, PayoutStatus $status, array $attributes = []): void
    {
        $payout->forceFill(['status' => $status] + $attributes)->save();
    }

    protected function authorize(User $actor): void
    {
        if (! $actor->hasPermission(Permission::PrizesManage)) {
            throw new AdministrativeActionException('You are not allowed to manage prize payouts.');
        }
    }

    /**
     * @param  list<PrizePayout>  $paid
     */
    protected function notify(array $paid): void
    {
        foreach ($paid as $payout) {
            if ($payout->participant->user) {
                app(Notifier::class)->send($payout->participant->user, new PrizeCredited($payout));
            }
        }
    }
}
