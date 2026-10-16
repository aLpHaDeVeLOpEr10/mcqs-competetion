<?php

namespace Database\Seeders;

use App\Enums\WalletType;
use App\Models\Wallet;
use Illuminate\Database\Seeder;

/**
 * Reference data required in every environment. Idempotent; creates
 * zero-balance platform wallets and never posts ledger entries.
 */
class SystemWalletSeeder extends Seeder
{
    public function run(): void
    {
        $currency = config('competition.currency.code');

        foreach (Wallet::systemCodes() as $code) {
            $exists = Wallet::query()
                ->where('system_code', $code)
                ->where('currency', $currency)
                ->exists();

            if (! $exists) {
                (new Wallet)->forceFill([
                    'type' => WalletType::System,
                    'system_code' => $code,
                    'currency' => $currency,
                ])->save();
            }
        }
    }
}
