<?php

namespace App\Services\Prizes;

use App\Enums\LedgerTransactionType;
use App\Enums\PrizeMode;
use App\Enums\PrizePoolBasis;
use App\Enums\WalletType;
use App\Models\RoomParticipant;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Turns the frozen prize rules into per-position amounts, keeping the money
 * figures separate:
 *
 *   gross entry fees   what players paid for this room (ledger entry_fee debits)
 *   refunds            entry fees returned (ledger entry_refund credits)
 *   net entry fees     gross − refunds
 *   platform amount    floor(net × platform_fee_bp / 10000)   (entry_fees basis only)
 *   prize pool         fixed: sum of tier amounts
 *                      percentage + guaranteed: the configured pool
 *                      percentage + entry_fees: net − platform amount
 *
 * Percentage tiers give each position floor(pool × percent_bp / 10000); the
 * rounding remainder is reported (never given to anyone). Integer maths only.
 * Taxes are applied per payout at payment time (PrizeDistributionService).
 */
class PrizePoolCalculator
{
    /**
     * @param  array<string, mixed>  $rules  frozen prize rules (Room::prizeRules())
     * @return array{mode: string, basis: ?string, gross_entry_fees_minor: int, refunds_minor: int, net_entry_fees_minor: int,
     *               platform_fee_bp: int, platform_amount_minor: int, prize_pool_minor: int, percent_rounding_minor: int,
     *               tiers: list<array{0: int, 1: int, 2: int}>, rules: array<string, mixed>}
     */
    public function calculate(int $roomId, array $rules): array
    {
        [$gross, $refunds] = $this->entryFees($roomId);

        return $this->resolve($rules, $gross, $refunds);
    }

    /**
     * Pure part, for unit tests.
     *
     * @param  array<string, mixed>  $rules
     */
    public function resolve(array $rules, int $grossMinor, int $refundsMinor): array
    {
        $mode = PrizeMode::from($rules['mode'] ?? 'fixed');
        $basis = isset($rules['pool_basis']) ? PrizePoolBasis::from($rules['pool_basis']) : null;
        $net = $grossMinor - $refundsMinor;
        $feeBp = (int) ($rules['platform_fee_bp'] ?? 0);
        $platform = 0;
        $percentRounding = 0;
        $resolved = [];

        if ($mode === PrizeMode::Fixed) {
            foreach ($rules['tiers'] ?? [] as [$from, $to, $amount]) {
                $resolved[] = [(int) $from, (int) $to, (int) $amount];
            }
            $pool = array_sum(array_map(fn ($t) => ($t[1] - $t[0] + 1) * $t[2], $resolved));
        } else {
            $pool = match ($basis) {
                PrizePoolBasis::Guaranteed => (int) ($rules['pool_minor'] ?? 0),
                PrizePoolBasis::EntryFees => $net - ($platform = intdiv(max(0, $net) * $feeBp, 10000)),
                null => throw new InvalidArgumentException('A percentage prize structure needs a pool basis.'),
            };
            $pool = max(0, $pool);

            $allocated = 0;
            foreach ($rules['tiers'] ?? [] as $tier) {
                [$from, $to] = [(int) $tier[0], (int) $tier[1]];
                $each = intdiv($pool * (int) ($tier[3] ?? 0), 10000);
                $resolved[] = [$from, $to, $each];
                $allocated += $each * ($to - $from + 1);
            }

            if ($allocated > $pool) {
                throw new InvalidArgumentException('Percentage prize tiers exceed 100% of the pool.');
            }
            $percentRounding = $pool - $allocated - $this->unassignedShare($rules, $pool);
        }

        return [
            'mode' => $mode->value,
            'basis' => $basis?->value,
            'gross_entry_fees_minor' => $grossMinor,
            'refunds_minor' => $refundsMinor,
            'net_entry_fees_minor' => $net,
            'platform_fee_bp' => $feeBp,
            'platform_amount_minor' => $platform,
            'prize_pool_minor' => $pool,
            'percent_rounding_minor' => $percentRounding,
            'tiers' => $resolved,
            'rules' => $rules,
        ];
    }

    /**
     * Part of a percentage pool that no tier claims (tiers summing below 100 %).
     *
     * @param  array<string, mixed>  $rules
     */
    protected function unassignedShare(array $rules, int $pool): int
    {
        $bp = 0;
        foreach ($rules['tiers'] ?? [] as $tier) {
            $bp += (int) ($tier[3] ?? 0) * ((int) $tier[1] - (int) $tier[0] + 1);
        }

        return intdiv($pool * max(0, 10000 - $bp), 10000);
    }

    /**
     * Entry fees actually collected and refunded for the room's seats, from the ledger.
     *
     * @return array{0: int, 1: int}
     */
    protected function entryFees(int $roomId): array
    {
        $sum = fn (LedgerTransactionType $type, string $sign) => (int) DB::table('ledger_entries as e')
            ->join('ledger_transactions as t', 't.id', '=', 'e.ledger_transaction_id')
            ->join('wallets as w', 'w.id', '=', 'e.wallet_id')
            ->where('w.type', WalletType::User->value)
            ->where('t.type', $type->value)
            ->where('t.reference_type', (new RoomParticipant)->getMorphClass())
            ->whereIn('t.reference_id', RoomParticipant::query()->select('id')->where('room_id', $roomId))
            ->where('e.amount_minor', $sign, 0)
            ->sum('e.amount_minor');

        return [-$sum(LedgerTransactionType::EntryFee, '<'), $sum(LedgerTransactionType::EntryRefund, '>')];
    }
}
