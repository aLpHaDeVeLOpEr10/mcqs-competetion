# Security, Anti-Cheat and Fraud Controls (Phase 17)

## 1. Findings report

The review covered:
- competition integrity;
- authorization and IDOR, across routes, controllers, broadcast channels, admin, wallet and payments;
- rate limits, CSRF, XSS, SQL injection and uploads;
- sessions and authentication;
- financial abuse;
- secrets, headers and logging.

| ID | Severity | Finding | Status |
|---|---|---|---|
| H1 | **High** | **Shared rate-limit counters.** Laravel's unnamed `throttle:N,1` form keys every route on the same user (or IP). State polling (`throttle:120,1`) therefore consumed the answer-submission budget (`throttle:60,1`). A player whose WebSocket dropped, or who had two tabs open, could have legitimate answers refused with 429. Login, contact and deposit-status limits shared counters the same way. | **Fixed.** Every limiter is now named, with its own counter: `answers` and `play-state` are per user and room, plus `login`, `contact`, `room-entry`, `disputes`, `status-checks` and `provider-callbacks`. Tests prove that heavy polling never blocks an answer and that no unnamed limiter remains. |
| M1 | Medium | **Notification "open" redirect check.** It used `str_starts_with($url, url('/'))`, so `https://app.example.com.evil.test` would pass. Notification URLs are server-generated today, so this was not reachable, but it was one bug away from an open redirect. | **Fixed.** The scheme, host and port must all equal the app's own; credentials in the URL are refused. Tested with lookalike hosts, `//host`, `user@host` and `javascript:` targets. |
| M2 | Medium | **No browser hardening headers** (CSP, nosniff, frame protection, referrer policy). | **Fixed.** `SecurityHeaders` middleware adds a nonce-based CSP and the other headers; HSTS is sent only in production over HTTPS. Verified in headless Chrome: zero CSP violations, CSS, fonts, scripts and Echo all load. The browser check also caught and fixed the Reverb `ws`→`wss` fallback. |
| M3 | Medium | **Session cookie not secure by default.** `secure` relied on an unset `SESSION_SECURE_COOKIE`. | **Fixed.** It now defaults to true when `APP_URL` is https; an explicit env value still wins. `security:check` fails production if the cookie is not secure. |
| M4 | Medium | **No fraud or abuse signals, and no review workflow.** | **Fixed.** Risk flags and Admin → Risk review (section 3). |
| M5 | Medium | **Application logs not redacted.** Callers were careful, but nothing enforced it. | **Fixed.** A Monolog tap (`RedactSensitiveData`) on every channel replaces credential-like context keys. `dontFlash` was extended to `account_number`, `iban` and `token`. |
| M6 | Medium | **Trusted proxies not configured.** Behind a load balancer or TLS terminator, `request->ip()` and `isSecure()` would be wrong; that affects rate-limit keys, audit IPs and HSTS. | **Open; deployment step.** Configure `$middleware->trustProxies(at: [...])` with the real proxy addresses when the hosting is known. Never trust `*` on a directly exposed server. |
| M7 | Medium | **No second factor for staff accounts.** | **Open; recommendation.** Add TOTP for finance and super-admin roles before real money is enabled. |
| L1 | Low | **The private `local` disk was registered for HTTP serving** (`/storage/{path}`). Signed URLs were required, and none are generated. | **Fixed.** `serve => false`; the route no longer exists. |
| L2 | Low | **Suspended account during live play.** Background (JSON) requests were answered with a 302 to the login page. | **Fixed.** They now get a 401 JSON response; the answer is never recorded either way. |
| L3 | Low | **Some admin content and account writes were unthrottled.** | **Fixed.** The `writes` limiter allows 60 form submissions per minute per user in the admin and account areas; reads are unlimited. |
| L4 | Low | `style-src` allows `'unsafe-inline'` because Blade components set `style` attributes (for example a progress-bar width). Scripts do **not** allow inline. | Accepted. |
| L5 | Low | Sessions are stored unencrypted in the database (`SESSION_ENCRYPT=false`). The payload holds no secrets beyond the CSRF token and auth id. | Accepted; can be enabled at no cost. |
| L6 | Low | The per-IP login limit (20/min) can affect many users behind one NAT. The per-account limit (5) is the main defence. | Accepted; revisit with traffic data. |
| L7 | Low | `/broadcasting/auth` has no dedicated limiter. It is authenticated, and every check is a single indexed query. | Accepted. |
| L8 | Low | On sensitive admin routes, the throttle runs before the permission check, so forbidden attempts also use up the budget. | Harmless; no change. |
| — | **Critical** | None found. | — |

**Reviewed and found sound (no change needed):**
- **Answers.** The client sends only question and option ids. Receipt time, correctness, response
  time and points are all computed on the server. The answer must be for the current, delivered,
  open question of the player's own room, and the option must belong to that question. A unique
  index makes the first answer final across tabs and duplicate requests. Late, early,
  out-of-sequence, future-question, cross-room and cross-user answers are rejected.
- **Channels.** Competition channels require a seated, active player. User channels admit only
  their owner.
- **Authorization.**
  - Every admin route has `can:` middleware. A test sweeps every admin route as a guest and as a
    player.
  - Player resources are user-scoped and use unguessable ULID references.
- **Money.**
  - Integer minor units.
  - The ledger is the only writer: row locks, idempotency keys and append-only triggers.
  - Callbacks are verified with a signature, reference, amount and currency check, plus a status
    query.
- **CSRF and XSS.** CSRF protection is on everywhere except provider callbacks, which are
  signature-verified. There is no `{!! !!}` and no `innerHTML` with user data.
- **SQL.** Raw SQL interpolates only code constants.
- **Uploads.** Images are decoded and re-encoded to WebP with random names and served only through
  authorized controllers with `nosniff`. CSV imports have type and size limits.
- **Authentication.** The session is regenerated on login and invalidated on logout. Passwords are
  bcrypt-hashed. Reset tokens are single-use and time-limited. Suspending an account ends its
  sessions.

## 2. Secrets audit

The project files were scanned, excluding `vendor` and `node_modules`. Patterns searched:
- private keys;
- AWS, Stripe, Slack, GitHub and Google keys;
- `base64:` application keys;
- hard-coded password, secret, key and token assignments;
- `.pem`, `.key`, `.p12`, `.sql` and `.sqlite` files.

**Result: no secrets found in project source.** Values are not reproduced here.
- The only file with real secret values is the local `.env`. It is listed in `.gitignore`, and the
  project is not yet under version control, so nothing has been committed.
- `.env.example` contains only empty placeholders.
- Test-only constants (the fake gateway's HMAC test secrets) are not credentials for any real
  service.
- The development seeder's `password` accounts refuse to run in production.

No rotation is required. If `.env` is ever shared or committed by mistake, rotate `APP_KEY`, the
database password, the Reverb app secret and any provider secrets. Note that rotating `APP_KEY`
requires re-encrypting the stored payout account numbers.

## 3. Risk signals (fraud and abuse)

`App\Services\Security\RiskMonitor` records **signals for administrative review**.

**Signals never block, penalize or accuse anyone.** Fast answers, reloads, a second device or a
shared network are often innocent, and **an IP address is never a signal on its own**; it is kept
only as context.

**How a flag counts.**
- There is one row per type, user, subject and day. Repeats increase `occurrences`.
- A flag is `watching` until it reaches its threshold, then becomes `open` in **Admin → Risk
  review**.
- Recording is failure-isolated, so it can never break the operation that produced it.
- Signals raised inside money transactions are recorded **after commit**, so a rolled-back
  operation leaves none.
- Detail fields are stripped of credentials.

| Signal | Severity / threshold | Source |
|---|---|---|
| Provider notifications for a payment or withdrawal failed verification | medium / 2 | deposit and withdrawal callbacks |
| Unauthenticated notification with no matching record | low / 5 | callbacks (IP kept as context) |
| Provider reported a different reference for an existing payment | high / 1 | callbacks |
| Withdrawal within 24 h of a deposit with no entry fee paid since | medium / 1 | withdrawal request |
| Several withdrawals cancelled in a day | low / 3 | withdrawal cancel |
| Many entry-fee refunds in a day (join/withdraw cycling) | low / 5 | entry refunds |
| Wallet balance disagrees with the ledger (impossible state) | high / 1 | `wallets:reconcile` |
| Repeatedly hit rate limits | low / 3 | any 429 for a signed-in user |
| Answer accepted faster than `SECURITY_RAPID_ANSWER_MS` (400 ms) | low / 3 | answers (accepted, never rejected) |
| Repeated attempts to change a final answer | low / 5 | answers |
| Many reloads of a live competition | low / 10 | play page |
| Signed in on several devices during a live competition | low / 1 | play page (database sessions) |

**Review.** Permission `risk.review` is granted to super admins, admins and finance managers.
- Staff filter by status, severity, signal or user.
- To close a flag, staff mark it **Reviewed** or **Dismissed** with a written note. The decision is
  row-locked, recorded once and audited (`risk.reviewed` / `risk.dismissed`).
- Reviewing never changes the account. Any action goes through the existing audited user and
  finance tools.

## 4. Rate limits

| Limiter | Limit | Key |
|---|---|---|
| `login` | 20/min, plus 5 attempts per account+IP in `LoginRequest` | IP |
| `register`, `password-reset`, `verification` | 5–6/min; resets also capped at 5/h per address | IP / address / user |
| `room-entry` (join, withdraw) | 20/min | user |
| `answers` | 60/min | user + room |
| `play-state` | 240/min | user + room |
| `account-sensitive` (deposits, withdrawals, payout accounts, password, admin money and role actions) | 10/min | user |
| `writes` (other admin and account form submissions) | 60/min, reads unlimited | user |
| `disputes`, `contact`, `status-checks`, `provider-callbacks` | 5, 5, 60 and 240/min | user or IP |

**Answer budget.** A question accepts one answer per player, so 60 answer requests per minute per
room only stops scripted floods. The answer budget is never shared with polling.

## 5. Headers

| Header | Value |
|---|---|
| `Content-Security-Policy` | see the directives below |
| `X-Content-Type-Options` | `nosniff` |
| `X-Frame-Options` | `DENY` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Permissions-Policy` | camera, microphone, geolocation, payment and usb all off |
| `Cross-Origin-Opener-Policy` | `same-origin` |
| `Strict-Transport-Security` | only when `APP_ENV=production` **and** the request is HTTPS |

CSP directives:
- `script-src 'self' 'nonce-…'`;
- `style-src 'self' 'unsafe-inline' fonts.bunny.net`;
- `font-src`: fonts.bunny.net;
- `connect-src 'self'` plus the Reverb origin, `SECURITY_CSP_CONNECT` and, in local development
  only, the Vite dev server;
- `form-action 'self'` plus `SECURITY_CSP_FORM_ACTIONS`, for a real provider's hosted checkout;
- `frame-ancestors 'none'`, `base-uri 'self'`, `object-src 'none'`.

**Nonces.** Vite tags get the nonce automatically. The one inline script (the hosted-checkout
auto-submit) carries it explicitly.

**Rolling out a change.** Use `SECURITY_CSP=report-only` to trial a policy change; `off` disables
the CSP only.

## 6. Production checklist (`php artisan security:check --production`)

The command prints pass, fail or warn per setting, **never values**, and exits non-zero when a
required check fails.

**Required checks:**
- `APP_KEY` set;
- `APP_DEBUG` off;
- https `APP_URL`;
- secure, HttpOnly, SameSite session cookie;
- headers on and CSP enforced;
- both simulated gateways off.

**Warnings:** Reverb without TLS, a debug log level, and a log/array mailer.

Also, by hand:
- configure trusted proxies (M6);
- serve only over HTTPS;
- keep `.env` out of the web root and version control;
- restrict database and Reverb ports to the application hosts.
