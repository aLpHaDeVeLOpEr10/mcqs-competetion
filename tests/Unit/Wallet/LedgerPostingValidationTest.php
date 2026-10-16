<?php

namespace Tests\Unit\Wallet;

use App\Enums\LedgerTransactionType;
use App\Exceptions\WalletOperationException;
use App\Services\Wallet\LedgerPosting;
use App\Services\Wallet\LedgerService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;
use Throwable;

/**
 * Postings are validated before any database work: these never open a transaction.
 */
class LedgerPostingValidationTest extends TestCase
{
    protected function rejection(array $legs, string $key = 'key-1', array $metadata = []): ?string
    {
        DB::shouldReceive('transaction')->never();

        try {
            app(LedgerService::class)->post(new LedgerPosting(LedgerTransactionType::Adjustment, $key, $legs, 'PKR', metadata: $metadata));
        } catch (InvalidArgumentException|WalletOperationException $e) {
            return $e::class;
        } catch (Throwable $e) {
            return 'unexpected: '.$e::class.' '.$e->getMessage();
        }

        return null;
    }

    public function test_entries_must_balance_to_zero(): void
    {
        $this->assertSame(InvalidArgumentException::class, $this->rejection([1 => 100, 2 => -99]));
        $this->assertSame(InvalidArgumentException::class, $this->rejection([1 => 100, 2 => -50, 3 => -49]));
    }

    public function test_at_least_two_wallets_and_no_zero_or_non_integer_legs(): void
    {
        $this->assertSame(InvalidArgumentException::class, $this->rejection([1 => 0]));
        $this->assertSame(WalletOperationException::class, $this->rejection([1 => 0, 2 => 0]));
        $this->assertSame(WalletOperationException::class, $this->rejection([1 => 100, 2 => -100.0]));
        $this->assertSame(WalletOperationException::class, $this->rejection(['1' => 100, 'x' => -100]));
    }

    public function test_an_idempotency_key_is_required_and_bounded(): void
    {
        $this->assertSame(InvalidArgumentException::class, $this->rejection([1 => 100, 2 => -100], ''));
        $this->assertSame(InvalidArgumentException::class, $this->rejection([1 => 100, 2 => -100], str_repeat('k', 192)));
    }

    public function test_secrets_cannot_be_written_into_metadata(): void
    {
        $this->assertSame(InvalidArgumentException::class, $this->rejection([1 => 100, 2 => -100], 'k', ['card_number' => '4111']));
        $this->assertSame(InvalidArgumentException::class, $this->rejection([1 => 100, 2 => -100], 'k', ['provider' => ['api_key' => 'x']]));
    }
}
