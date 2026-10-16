<?php

namespace App\Console\Commands;

use App\Enums\WithdrawalStatus;
use App\Models\Withdrawal;
use App\Services\Withdrawals\WithdrawalService;
use Illuminate\Console\Command;

/**
 * Confirms payouts in progress with their provider (status query), so a lost
 * callback or a timed-out payout request still reaches its final state.
 * Paid payouts from the last 7 days are re-checked for provider returns.
 */
class WithdrawalsReconcile extends Command
{
    protected $signature = 'withdrawals:reconcile';

    protected $description = 'Verify processing (and recently paid) withdrawals with the payout provider';

    public function handle(WithdrawalService $withdrawals): int
    {
        $candidates = Withdrawal::query()
            ->whereNotNull('gateway')
            ->where(fn ($q) => $q->where('status', WithdrawalStatus::Processing)
                ->orWhere(fn ($p) => $p->where('status', WithdrawalStatus::Paid)->where('paid_at', '>=', now()->subDays(7))))
            ->orderBy('id')->limit(500)->get();

        $changed = 0;
        foreach ($candidates as $withdrawal) {
            $changed += (int) ($withdrawals->refresh($withdrawal)->status !== $withdrawal->status);
        }

        $this->info("Checked {$candidates->count()} withdrawal(s); {$changed} changed.");

        return self::SUCCESS;
    }
}
