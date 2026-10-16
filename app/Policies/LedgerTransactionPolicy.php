<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\LedgerTransaction;
use App\Models\User;

class LedgerTransactionPolicy
{
    /**
     * Players see a transaction only when it moved money on their own wallet.
     */
    public function view(User $actor, LedgerTransaction $transaction): bool
    {
        return $actor->hasPermission(Permission::FinanceView)
            || $transaction->entries()->whereHas('wallet', fn ($q) => $q->where('user_id', $actor->id))->exists();
    }
}
