<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\WalletType;
use App\Models\User;
use App\Models\Wallet;

class WalletPolicy
{
    /**
     * Owners see their own wallet; finance staff see every wallet.
     */
    public function view(User $actor, Wallet $wallet): bool
    {
        return $wallet->user_id === $actor->id || $actor->hasPermission(Permission::FinanceView);
    }

    /**
     * Audited adjustments: finance staff only, player wallets only, never their own.
     */
    public function adjust(User $actor, Wallet $wallet): bool
    {
        return $actor->hasPermission(Permission::FinanceAdjust)
            && $wallet->type === WalletType::User
            && $wallet->user_id !== $actor->id;
    }
}
