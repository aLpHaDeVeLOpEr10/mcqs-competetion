# Payment Gateway and Deposits

**Status:** real-money deposits are **off** (`DEPOSITS_ENABLED=false`, `PAYMENT_GATEWAY=disabled`).
No real provider is integrated. A JazzCash, Easypaisa or other driver is added only from the
provider's official merchant documentation, once the provider and the jurisdiction permit this
competition model. No endpoint or credential has been invented.

## Architecture

```
DepositController ─► DepositService ─► PaymentGateway (interface) ◄─ DisabledPaymentGateway (default)
PaymentCallbackController ─┘      │                                ◄─ FakePaymentGateway (local/testing only)
payments:reconcile ────────┘      │                                ◄─ <real provider driver, later>
                                   └─► WalletService ─► LedgerService (Phase 09)
```

- **`App\Contracts\Payments\PaymentGateway`** (provider-specific code lives only in drivers):

  | Method | Purpose |
  |---|---|
  | `createPayment(PaymentRequest)` | Returns a `CheckoutSession`: a redirect URL, or an auto-posted form for hosted checkouts |
  | `handleCallback(Request)` | Authenticates the notification (signature with `hash_equals`, merchant identity) and normalizes it into a `GatewayResult` |
  | `queryPaymentStatus(payment)` | Server-to-server status check |
  | `refundPayment(...)` | Refund, if the provider supports it |
  | `supportsStatusQuery()`, `supportsRefunds()` | Capabilities, so the service follows what the provider actually offers |

  Drivers throw `GatewayException` (unreachable, timeout, refused; the outcome is unknown) or
  `InvalidCallbackException` (bad signature, malformed, wrong merchant).
- **`PaymentGatewayManager`** resolves `config('competition.payments.drivers')[PAYMENT_GATEWAY]`.
- **`DepositService`** holds the provider-independent lifecycle. It never makes a provider call
  inside a database transaction.

## Deposit lifecycle

`created → pending → processing → succeeded | failed | expired`. `refunded` is reserved: there are
no refunds yet.

1. **Validate.** Deposits must be enabled, the account must be active, and the amount (parsed
   server-side with integer arithmetic) must be within the limits:
   - minimum Rs 100, maximum Rs 25,000;
   - Rs 50,000 per 24 hours, counting unfinished and successful deposits;
   - at most 3 unfinished deposits.

   Checks run under a lock on the player's wallet row, so parallel requests cannot race past the
   limits. A one-time form token makes a double submit create a single payment.
2. **Generate our reference** (ULID) and an idempotency key.
3. **Create the payment** with status `created` and an expiry (30 minutes).
4. **`createPayment()`** at the provider, then status `pending` with the provider reference and
   checkout instructions.
   - On timeout or error the payment stays `created` with `failure_code=provider_timeout|unavailable`,
     and the player is told no money was taken. The player never reached the provider, so they
     cannot have paid.
5. **Send the player to the provider:** redirect, or an auto-submitted form.
6. **The provider notifies `POST /payments/callback/{gateway}`.** This route is CSRF-exempt
   (providers cannot send tokens) and rate-limited to 120/min.
7. **Verify.**
   1. The driver authenticates the notification: signature compared with `hash_equals`, then
      merchant identity.
   2. Every field is checked against the stored payment: our reference, the provider reference,
      the **amount we stored**, and the currency.
   3. When the provider supports it, the outcome is confirmed by `queryPaymentStatus()`, and that
      answer is checked the same way. **The query, not the notification, decides.**
   4. Without a status query, the authenticated notification is the confirmation. That is
      acceptable only if the provider's documentation says so.
8. **Settle** (`settle()`, one transaction, payment row locked first):
   - a verified success credits the wallet with **our stored amount** through
     `WalletService::credit` (type `deposit`, key `deposit:{reference}`, linked to the payment);
   - the payment is marked `succeeded` with `verified_at` and `ledger_transaction_id`.
9. **Failed / expired.** Failed: `failed` with a safe failure code. The provider expired it, or it
   is still unpaid after our deadline: `expired`. A later verified success still credits an
   expired payment, because the player may have paid late. `succeeded` and `failed` are final.
10. **Status page** `/account/deposits/{reference}` is also the provider's return URL.
    - It ignores every query-string parameter.
    - It reads our database and, while open, asks the provider (at most every 10 s).
    - It refreshes itself every 5 s until the payment is final.

**Never trusted:** success query strings, browser redirects, client-sent statuses or amounts,
screenshots, unverified callbacks.

## Exactly-once crediting

Duplicate notifications (sequential or simultaneous) credit at most once, enforced by:
- a `SELECT … FOR UPDATE` on the payment row, plus the final-status check;
- the unique ledger idempotency key `deposit:{reference}`, where a replay returns the original;
- the unique `payment_transactions.ledger_transaction_id`.

A database failure while crediting rolls back the credit **and** the status change. The error
propagates, so the provider retries; reconciliation would also retry.

## Callback log

`payment_callbacks` is append-only. Every notification is recorded, including rejected ones, with:
- the outcome (`applied`, `duplicate`, `pending`, `rejected`) and reason (for example
  `bad_signature`, `wrong_merchant`, `amount_mismatch`, `unknown_payment`,
  `verification_unavailable`);
- whether the signature was valid;
- the body hash, the IP address, and a sanitized payload. Signatures, hashes, tokens, card, OTP,
  MSISDN and CNIC fields are replaced with `[removed]`.

Rejections are also logged as warnings with the reference and reason only.

## Reconciliation

`php artisan payments:reconcile` runs every 5 minutes. For every unfinished deposit older than
2 minutes it calls `DepositService::refresh()`:
- query the provider and settle the verified outcome, so a lost callback is still credited;
- expire payments that are unpaid after their deadline, or unknown to the provider;
- leave the payment alone if the provider cannot be reached.

The command exits 1 if the provider's answer disagrees with a stored payment.

## Fake provider (local / testing only)

`FakePaymentGateway` simulates the following, with no network and no money:
- a provider-side record of each payment;
- an HMAC-SHA256 signed notification carrying a merchant id;
- a status query;
- timeouts.

The local checkout page `/fake-gateway/{providerReference}` lets you approve or decline. It then
delivers the signed notification to the real callback handler.

It is refused unless **all** of these hold:
- `APP_ENV` is `local` or `testing` (it is never resolved in production, whatever the settings);
- `FAKE_GATEWAY_ENABLED=true`;
- `FAKE_GATEWAY_SECRET` is set.

Its routes are registered only in local/testing.

**To try it locally:** set `PAYMENT_GATEWAY=fake`, `FAKE_GATEWAY_ENABLED=true` and
`DEPOSITS_ENABLED=true`, then run `php artisan config:clear`. `.env` already has a random local
secret.

## Configuration

| Variable | Default | Meaning |
|---|---|---|
| `PAYMENT_GATEWAY` | `disabled` | driver name from `competition.payments.drivers` |
| `DEPOSITS_ENABLED` | `false` | real-money deposits switch |
| `DEPOSIT_MIN_MINOR` / `DEPOSIT_MAX_MINOR` | 10000 / 2500000 | per-deposit limits (paisa) |
| `DEPOSIT_DAILY_MAX_MINOR` | 5000000 | per player per 24 hours |
| `DEPOSIT_EXPIRES_MINUTES` | 30 | payment deadline |
| `FAKE_GATEWAY_ENABLED` / `FAKE_GATEWAY_SECRET` / `FAKE_GATEWAY_MERCHANT_ID` / `FAKE_GATEWAY_CACHE_STORE` | off / empty / `FAKE-MERCHANT` / default | simulated provider (local/testing) |

**A real driver will need**, from environment variables only and never committed:
- the merchant id;
- the integrity or signing key (for example an HMAC salt or a public key);
- API credentials;
- sandbox or production base URLs taken from the provider's documentation.

Add them under `competition.payments.<provider>` and register the class in
`competition.payments.drivers`.

## Admin

Finance staff (`finance.view`) get:
- **Payments:** search by our reference, provider reference, or player email/name; filter by type
  and status. Columns: player, amount, status, failure code, created and verified times, provider.
- **Payment detail:** the sanitized notification log and the link to the ledger credit.

The payment pages are **read-only**. No action marks a payment as paid; only a verified provider
outcome can settle a payment, and a test asserts that no admin payment route accepts writes.

## Not in this phase

- Real provider driver.
- Refunds of deposits (the interface method exists; the status `refunded` is reserved).
- Withdrawals.
