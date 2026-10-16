<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A wallet operation was refused. Nothing was written: the ledger transaction
 * (and anything posted inside it) was rolled back. $reason is a stable code;
 * the message is safe to show to the account owner or staff.
 */
class WalletOperationException extends RuntimeException
{
    public const INSUFFICIENT_FUNDS = 'insufficient_funds';

    public const WALLET_RESTRICTED = 'wallet_restricted';

    public const ACCOUNT_RESTRICTED = 'account_restricted';

    public const CURRENCY_MISMATCH = 'currency_mismatch';

    public const IDEMPOTENCY_CONFLICT = 'idempotency_conflict';

    public const ALREADY_REVERSED = 'already_reversed';

    public const INVALID_AMOUNT = 'invalid_amount';

    public const INVALID_OPERATION = 'invalid_operation';

    public const DISABLED = 'disabled';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function insufficientFunds(): self
    {
        return new self(self::INSUFFICIENT_FUNDS, 'The wallet balance is too low for this payment.');
    }

    public static function walletRestricted(): self
    {
        return new self(self::WALLET_RESTRICTED, 'This wallet is frozen, so no money can be taken from it.');
    }

    public static function accountRestricted(): self
    {
        return new self(self::ACCOUNT_RESTRICTED, 'This account cannot make payments right now.');
    }

    public static function currencyMismatch(): self
    {
        return new self(self::CURRENCY_MISMATCH, 'The wallet currency does not match this transaction.');
    }

    public static function idempotencyConflict(): self
    {
        return new self(self::IDEMPOTENCY_CONFLICT, 'This request was already processed with different details.');
    }

    public static function alreadyReversed(): self
    {
        return new self(self::ALREADY_REVERSED, 'This transaction has already been reversed.');
    }

    public static function invalidAmount(string $message = 'The amount must be a positive whole number of paisa.'): self
    {
        return new self(self::INVALID_AMOUNT, $message);
    }

    public static function invalidOperation(string $message): self
    {
        return new self(self::INVALID_OPERATION, $message);
    }

    public static function disabled(string $message): self
    {
        return new self(self::DISABLED, $message);
    }
}
