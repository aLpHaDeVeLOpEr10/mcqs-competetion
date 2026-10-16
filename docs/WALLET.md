# Wallet and Transaction Ledger

The internal accounting system that entry fees, refunds, prizes, deposits and withdrawals will use.
Deposits post `deposit` credits through it (Phase 10, see [PAYMENTS.md](PAYMENTS.md)). Entry fees and their refunds use it since Phase 11 (see ROOMS.md). Prize payouts use it since Phase 12 (see PRIZES.md). Withdrawals hold funds through it since Phase 15 (see WITHDRAWALS.md).

## Model: double-entry ledger

| Table | Role |
|---|---|
| `wallets` | One per player (`type=user`) per currency, plus platform wallets (`type=system`): `prize_pool`, `platform_revenue`, `gateway_clearing`, `adjustments`. `balance_minor` is a **cache**. |
| `ledger_transactions` | One business event. It has a public `reference` (ULID, shown to players), a unique `idempotency_key`, `type`, `currency`, `description`, the related record (`reference_type`/`reference_id`, the `related()` relation), `reverses_transaction_id`, `created_by` and safe `metadata`. **Append-only.** |
| `ledger_entries` | Signed movements on wallets. Credit > 0, debit < 0, and each transaction's entries sum to 0. Each entry also stores `balance_after_minor`. **Append-only.** |

Each transaction moves money between a player wallet and a platform wallet:

| Type | Player wallet | Platform wallet |
|---|---|---|
| `deposit` | credit | `gateway_clearing` |
| `withdrawal` | debit | `gateway_clearing` |
| `entry_fee` | debit | `prize_pool` |
| `entry_refund` (entry-fee refund) | credit | `prize_pool` |
| `prize_payout` (prize) | credit (net of any tax withheld, which goes to `tax_withholding`) | `prize_pool` |
| `platform_fee` | — (platform share of net entry fees) | `prize_pool` → `platform_revenue` |
| `adjustment` (admin credit or debit) | credit or debit | `adjustments` |
| `reversal` (for example a failed withdrawal) | the opposite of the original | the original's platform wallet |

**Mapping to the requested names.** `entry_fee_refund` is `entry_refund`, `prize` is
`prize_payout`, `admin_credit`/`admin_debit` is `adjustment` (direction is the entry's sign), and a
withdrawal refund is a `reversal` of the withdrawal. These names existed since Phase 01 and are kept
to avoid duplicates.

**Money.** Amounts are integers in paisa (Rs 10.00 = 1000). `App\Support\Money` formats and parses
amounts using integer and string arithmetic only.

**Status.** A posted transaction is final, so its status is `completed`. It shows as `reversed` once
a reversal transaction points at it; the row itself is never updated. `pending` and `failed` belong
to `payment_transactions` (gateway phase). A payment only reaches the ledger once it settles.

## Services (the only way money moves)

- `LedgerService::post(LedgerPosting, ?Closure $within)` is the only writer of ledger rows and
  balances. It runs these steps in one database transaction:
  1. Lock every wallet involved (`SELECT … FOR UPDATE`) in ascending id order, as the first
     statement. This serializes spending and avoids deadlocks.
  2. Check the idempotency key; a duplicate is replayed (see Idempotency).
  3. Check currency, restrictions and funds.
  4. Write the transaction and its entries, and update the cached balances.
  5. Run `$within`, so that related changes (such as marking a seat paid) commit or roll back
     together with the money.
- `WalletService`:
  - `credit()`, `debit()`, `getAvailableBalance()`, `walletFor()`;
  - `refund(original)`: full refunds only. A transaction can be reversed once, and repeating the
    call returns the same reversal;
  - `chargeEntryFee(seat)` / `refundEntryFee(seat)`;
  - `adjust()`.

  Controllers never touch balances.

### A debit is refused, and nothing is written, when:

| Reason code | Condition |
|---|---|
| `insufficient_funds` | the player's balance would go below zero. The database CHECK `balance_minor >= 0` on player wallets is the backstop. |
| `wallet_restricted` | the wallet is `frozen` |
| `account_restricted` | the owner is restricted, suspended or closed, or unverified while verification is required |
| `currency_mismatch` | a wallet's currency differs from the transaction's |
| `idempotency_conflict` | the key was already used for a different request |
| `invalid_operation` | for example, a competition seat that is not reserved, a room not taking entries, a seat without a fee, or paid entries disabled |

- Credits (refunds, prizes) are accepted on frozen wallets, because that money is owed.
- Platform wallets may go negative. For example, a sponsored prize pool goes negative when prizes
  exceed entry fees.
- Admin adjustments skip the restriction checks so corrections stay possible, but they never
  overdraw a wallet.

### Idempotency

- Every operation has a deterministic key: `entry_fee:{seat}:{joined_at}`, `reversal:{transaction}`,
  `adjustment:{form token}`, and for future modules the gateway reference or `prize:{session}:{seat}`.
- A retry with the same key and the same details returns the original transaction
  (`wasRecentlyCreated = false`) and moves nothing, even if the balance has since been spent.
- The unique index on `idempotency_key` backs this up. A concurrent duplicate that loses the race
  gets the committed original back. `reverses_transaction_id` is unique too.

### Available balance

Equal to the ledger balance. There are no holds. A future withdrawal will be posted (as a debit to
`gateway_clearing`) when it is requested, and reversed if the payout fails.

## Reconciliation

- `php artisan wallets:reconcile [--wallet=ID]` runs daily at 03:00 via the scheduler. It checks
  that:
  - each cached balance equals the sum of that wallet's entries;
  - each cached balance equals the latest entry's `balance_after_minor`;
  - every transaction balances to zero;
  - every transaction has at least two entries.
- It **only reports**. It exits 1 and logs a critical error, and never changes balances.
- The admin wallet list shows each wallet's ledger total next to its balance, and the wallet page
  shows its reconciliation status.

## Screens

- **Player (`/account/wallet`)** shows the available balance, recent and full history (date, type,
  public reference, status, amount, balance after), and a transaction page per reference.
  - Players only ever see their own entries: the wallet comes from the signed-in user, and the
    `LedgerTransactionPolicy` returns 403 for anyone else's reference.
  - Idempotency keys, metadata and other wallets are never shown.
  - "Add money" and "Withdraw" are disabled buttons with an explanation, not links.
- **Admin (`finance.view`)**: Wallets (list with a ledger-total check, and detail with entries), and
  Ledger (search by reference, key or description; detail shows entries, direction, related seat,
  payment or prize, reversal links and metadata).
- **Adjustments** (`finance.adjust`: finance manager and super admin; *not* admin) are the only
  finance write. There is no "edit balance" field.
  - **Requirements:** `WALLET_ADJUSTMENTS_ENABLED=true` (off by default), a player wallet that is not
    the actor's own, a direction, an amount of at most `WALLET_MAX_ADJUSTMENT_MINOR` (Rs 50,000), a
    reason of at least 10 characters, an explicit confirmation, and a one-time form token so a
    double submit posts once.
  - **Effect:** writes an `adjustment` ledger transaction and an audit record (`wallet.adjusted`,
    with balances before and after) in one transaction.

## Security

- No finance data is broadcast. Wallet operations dispatch no `ShouldBroadcast` events, and a test
  enforces this.
- Metadata keys that look like secrets (password, token, card, cvv, otp, api_key, …) are rejected,
  and gateway payloads stay in `payment_transactions.gateway_payload`, which is never displayed.
- Ledger rows cannot be updated or deleted, by database triggers and by the `Immutable` model guard.

## Not in this phase

- Wallet freeze/unfreeze screens.
- Partial refunds.
- Two-person approval for adjustments.
- Platform revenue split.
