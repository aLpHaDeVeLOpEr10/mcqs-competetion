<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\PaymentCallback;
use App\Models\PaymentTransaction;
use App\Services\Payments\DepositService;
use Illuminate\Console\Command;

/**
 * Re-checks unfinished deposits with their provider (server-to-server status
 * query, where supported), so a lost callback still settles the payment and
 * abandoned payments expire. Only verified outcomes move money.
 */
class PaymentsReconcile extends Command
{
    protected $signature = 'payments:reconcile {--limit=200 : Maximum payments to check per run}';

    protected $description = 'Verify unfinished deposits with the payment provider and expire abandoned ones';

    public function handle(DepositService $deposits): int
    {
        $payments = PaymentTransaction::query()
            ->where('type', PaymentType::Deposit)
            ->whereIn('status', PaymentStatus::open())
            // Give the provider's own callback a moment first.
            ->where('created_at', '<=', now()->subMinutes(2))
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        $counts = array_fill_keys([PaymentCallback::APPLIED, PaymentCallback::PENDING, PaymentCallback::DUPLICATE, PaymentCallback::REJECTED], 0);
        foreach ($payments as $payment) {
            $counts[$deposits->refresh($payment)]++;
        }

        $this->info(sprintf('Checked %d deposit(s): %d settled, %d still pending, %d already final, %d inconsistent.',
            $payments->count(), $counts['applied'], $counts['pending'], $counts['duplicate'], $counts['rejected']));

        return $counts['rejected'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
