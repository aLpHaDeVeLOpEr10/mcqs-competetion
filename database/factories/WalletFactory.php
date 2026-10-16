<?php

namespace Database\Factories;

use App\Enums\WalletStatus;
use App\Enums\WalletType;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Wallets always start at zero. Balances must come from ledger entries.
 *
 * @extends Factory<Wallet>
 */
class WalletFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type' => WalletType::User,
            'user_id' => User::factory(),
            'system_code' => null,
            'currency' => 'PKR',
            'balance_minor' => 0,
            'status' => WalletStatus::Active,
        ];
    }

    public function system(string $code): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => WalletType::System,
            'user_id' => null,
            'system_code' => $code,
        ]);
    }
}
