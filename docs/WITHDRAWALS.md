# Withdrawals and Finance Administration

**Status: off by default.**
- Requests are accepted only when `WITHDRAWALS_ENABLED=true`.
- Money leaves the platform only through a configured payout provider
  (`WITHDRAWAL_PAYOUT_GATEWAY`). The default `disabled` driver pays nothing: an approved withdrawal
  stays approved, and the admin page says so.
- No real provider is integrated, and no provider API has been invented. A driver is written from
  the provider's official documentation.

## Lifecycle (`App\Enums\WithdrawalStatus`)

```
requested ──► under_review ──► approved ──► processing ──► paid ──► reversed (provider returned it)
   │                │               │              └──► failed
   ├──► cancelled   └──► rejected ◄─┘
   └──► rejected
```

- Every change goes through `WithdrawalService::transition()`. It locks the withdrawal row,
  validates the transition, writes `withdrawal.<status>` to the audit log (actor, from/to, reason,
  amount, masked destination, provider reference) and notifies the player after the commit.
- Records are never deleted, including rejected and failed ones.

## Fund hold (ledger-based)

- **At request**, in the same transaction that creates the request, the player's wallet is
  **debited** by amount + fee, under row locks on every wallet involved, taken in id order:
  - amount → `gateway_clearing`;
  - fee → `platform_revenue` (ledger type `withdrawal`).
- **Consequences:** held money cannot be spent or withdrawn twice, and the displayed balance is
  what remains. Insufficient funds, a frozen wallet or a restricted account roll the request back.
- **Rejected, cancelled, failed or reversed** releases the funds: the hold transaction is
  reversed, **exactly once**.
  - Guarded by the unique `reverses_transaction_id` and the unique
    `release_ledger_transaction_id`.
  - The fee is refunded too.
- **Paid:** the money stays in `gateway_clearing`, which settles with the provider.

## Eligibility (re-checked on the server when confirming)

| Rule | Default |
|---|---|
| Withdrawals enabled | off (`WITHDRAWALS_ENABLED`) |
| Amount per withdrawal | Rs 500 – Rs 25,000 |
| Per 24 hours (excluding rejected, cancelled and failed) | Rs 50,000 |
| Unfinished withdrawals at once | 1 |
| Account | active, with a verified email (same rule as payments) |
| Destination | the user's own, active, and past its cooling-off period (24 h after adding) |
| Fee | flat + basis points, disclosed on the review step (default 0) |

**Flow:** choose an account and amount → review (amount, fee, total, destination) → confirm
(one-time token; a double submit creates one request) → pending reference.

## Payout destinations

- **Types:** bank account (Pakistani IBAN, with the ISO 13616 checksum verified) or mobile wallet
  (03XXXXXXXXX), from an approved provider list in config.
- **Storage:** the account number is stored **encrypted** (Laravel `encrypted` cast, `APP_KEY`)
  because a payout genuinely needs it, alongside its last four digits and a keyed fingerprint
  (HMAC). The fingerprint prevents saving the same account twice. No passwords or PINs are stored.
- **Display:** shown only masked ("Meezan Bank •••• 6702"). Withdrawals keep a masked snapshot.
  Audit logs, notifications and pages never contain the full number, and `PayoutRequest` hides it
  from dumps.
- **Changes:** adding or removing an account needs the current password (step-up). Every change
  is audited and sends a security notification that cannot be switched off. A new account can be
  used only after the cooling-off period.

## Finance administration

| Permission | Allows | Roles |
|---|---|---|
| `withdrawals.review` | the review queue, the withdrawal detail page, starting a review, asking the provider for a status | finance manager, super admin |
| `withdrawals.approve` | approve, reject (reason required), send the payout | finance manager, super admin |

**Admins do not get these automatically.** Staff can never act on their own withdrawal (four
eyes). All actions are rate-limited.

The detail page shows:
- the player, the account status and the current balance;
- the masked destination;
- the hold and release ledger links;
- the provider reference and status;
- recent wallet movements;
- the player's other withdrawals;
- the full audit history and the provider notifications.

## Payout provider (`App\Contracts\Payments\PayoutGateway`)

- **Interface:** `createPayout`, `handleCallback`, `queryPayout`, `supportsStatusQuery`.
- **Drivers:** `disabled` (default) and `fake` (local/testing only: refused in production, without
  `FAKE_PAYOUT_ENABLED=true`, or without a secret).
- **Sending:** the withdrawal is claimed as `processing` first, so it can't be initiated twice.
  The provider is then called with **our reference as its idempotency key**.
  - A definitive refusal leads to `failed`, and the funds are released.
  - A timeout leaves the outcome unknown: the withdrawal stays `processing`.
  - `withdrawals:reconcile` (every 10 minutes) asks the provider. If the provider never recorded
    the payout, it is re-sent with the same reference, so it can never be paid twice.
- **Callbacks** go to `POST /withdrawals/callback/{gateway}` (CSRF-exempt, rate-limited):
  1. The driver checks the signature with `hash_equals`.
  2. Reference, provider reference, amount and currency are checked against our record.
  3. The outcome is confirmed by a status query.
  4. It is applied idempotently: a duplicate changes nothing.

  Notifications are logged, sanitized, in `payment_callbacks` with `withdrawal_id`.
- **Returns:** paid withdrawals from the last 7 days are re-checked for a provider-reported
  return (`reversed`).

## Configuration

`WITHDRAWALS_ENABLED`, `WITHDRAWAL_PAYOUT_GATEWAY`, `WITHDRAWAL_MIN_MINOR`, `WITHDRAWAL_MAX_MINOR`,
`WITHDRAWAL_DAILY_MAX_MINOR`, `WITHDRAWAL_MAX_PENDING`, `WITHDRAWAL_FEE_FLAT_MINOR`,
`WITHDRAWAL_FEE_BP`, `WITHDRAWAL_DESTINATION_COOLDOWN_HOURS`, and `FAKE_PAYOUT_ENABLED` /
`FAKE_PAYOUT_SECRET` / `FAKE_PAYOUT_CACHE_STORE` (local/testing).

**A real driver additionally needs**, from environment variables only and never committed:
- merchant credentials;
- a signing key;
- base URLs from the provider's documentation.

**Before any real money moves**, it also needs the legal and compliance approval described in
PHASE_PLAN.
