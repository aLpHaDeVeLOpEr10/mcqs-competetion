<?php

namespace App\Services\Wallet;

use App\Enums\LedgerTransactionType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * One balanced ledger transaction to be posted by LedgerService.
 *
 * $legs maps wallet id => signed amount in minor units (credit > 0, debit < 0)
 * and must sum to zero. $restrictionsApply lists the wallet ids whose debits
 * are subject to the frozen-wallet and restricted-account checks; funds are
 * always checked for every player wallet.
 */
final class LedgerPosting
{
    /**
     * @param  array<int, int>  $legs
     * @param  array<string, mixed>  $metadata
     * @param  list<int>  $restrictionsApply
     */
    public function __construct(
        public readonly LedgerTransactionType $type,
        public readonly string $idempotencyKey,
        public readonly array $legs,
        public readonly string $currency,
        public readonly ?string $description = null,
        public readonly ?Model $related = null,
        public readonly array $metadata = [],
        public readonly ?User $actor = null,
        public readonly ?int $reverses = null,
        public readonly array $restrictionsApply = [],
    ) {}
}
