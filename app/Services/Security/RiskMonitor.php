<?php

namespace App\Services\Security;

use App\Models\RiskFlag;
use App\Models\User;
use App\Support\SensitiveData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records fraud / abuse SIGNALS for administrative review.
 *
 * - A signal is counted on one row per (type, user, subject, day); it is only
 *   "watching" until it occurs $threshold times, then it becomes "open" in
 *   Admin → Risk review.
 * - Signals never block, penalize or accuse anyone. Staff decide.
 * - IP addresses may be kept as context but are never a signal on their own
 *   (shared networks are common).
 * - Recording a signal can never break the operation that produced it.
 */
class RiskMonitor
{
    /**
     * Types: description and default severity / threshold.
     *
     * @var array<string, array{0: string, 1: string, 2: int}>
     */
    public const TYPES = [
        // Payments and wallets
        'payment_verification_failed' => ['Provider notifications for this payment failed verification', 'medium', 2],
        'invalid_payment_callback' => ['Unauthenticated payment notifications received (no matching payment)', 'low', 5],
        'provider_reference_conflict' => ['A provider reported a different reference for an existing payment', 'high', 1],
        'withdrawal_without_play' => ['Withdrawal requested soon after a deposit without playing', 'medium', 1],
        'withdrawals_cancelled' => ['Several withdrawals cancelled in one day', 'low', 3],
        'repeated_entry_refunds' => ['Many entry-fee refunds in one day (join/withdraw cycling)', 'low', 5],
        'wallet_discrepancy' => ['Wallet balance disagrees with the ledger', 'high', 1],
        'rate_limited' => ['Repeatedly hit rate limits', 'low', 3],
        // Competition integrity
        'rapid_answers' => ['Answers submitted faster than a person can usually read', 'low', 3],
        'duplicate_answer_attempts' => ['Repeated attempts to change a submitted answer', 'low', 5],
        'frequent_reloads' => ['Reloaded the live competition many times', 'low', 10],
        'multiple_sessions' => ['Signed in on several devices during a live competition', 'low', 1],
    ];

    /**
     * Records the signal once the surrounding database transaction commits (immediately
     * when there is none): a rolled-back operation leaves no signal, and the flag row is
     * never locked for the duration of a money transaction.
     *
     * @param  array<string, mixed>  $details
     */
    public function later(string $type, ?User $user, ?Model $subject = null, array $details = []): void
    {
        DB::afterCommit(fn () => $this->signal($type, $user, $subject, $details));
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public function signal(string $type, ?User $user, ?Model $subject = null, array $details = [], ?string $severity = null, ?int $threshold = null): ?RiskFlag
    {
        try {
            [, $defaultSeverity, $defaultThreshold] = self::TYPES[$type];
            $threshold ??= $defaultThreshold;
            $now = now();
            $key = implode('|', [$type, $user?->id ?? '-', $subject ? $subject->getMorphClass().':'.$subject->getKey() : '-', $now->format('Ymd')]);
            $details = SensitiveData::strip($details, 191, SensitiveData::CREDENTIALS);

            $inserted = DB::table('risk_flags')->insertOrIgnore([
                'type' => $type,
                'severity' => $severity ?? $defaultSeverity,
                'user_id' => $user?->id,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'occurrences' => 1,
                'threshold' => $threshold,
                'details' => json_encode($details),
                'status' => $threshold <= 1 ? 'open' : 'watching',
                'dedupe_key' => $key,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($inserted === 0) {
                // Atomic increment; "watching" becomes "open" once the threshold is reached.
                // MySQL applies SET assignments left to right using updated values, so the
                // status is computed first, from the old count.
                DB::table('risk_flags')->where('dedupe_key', $key)->update([
                    'status' => DB::raw("CASE WHEN status = 'watching' AND occurrences + 1 >= threshold THEN 'open' ELSE status END"),
                    'occurrences' => DB::raw('occurrences + 1'),
                    'details' => json_encode($details),
                    'last_seen_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return RiskFlag::query()->where('dedupe_key', $key)->first();
        } catch (Throwable $e) {
            Log::warning('Risk signal could not be recorded.', ['type' => $type, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
