# Launch Readiness Report (Phase 23 final audit, 2026-10-05)

## Verdict

**Not production-ready.** The application is functionally complete and passes every automated
check: 657 tests, 0 failures, 0 skips, 0 warnings. But:
- **launch blockers remain:** no rehearsed deployment, draft legal pages, no email provider, and
  no tested restore;
- **real money must stay off:** no payment or payout provider, no legal sign-off, and the
  KYC/age/AML/geo/self-exclusion controls are not built.

The **real-money launch gate** (added in this phase) keeps paid entries, deposits, prize payouts
and withdrawals forced **off** until a recorded approval covers every requirement.

**Possible first step.** A free-entry, no-cash-prize pilot becomes possible once BLOCKERS 1–4
are resolved. Paid entry or cash prizes need everything in BLOCKERS and EXTERNAL REQUIREMENTS,
and explicit authorization.

No production deployment was performed, and no real-money feature was activated.

---

## Audit results (what was checked and how)

| Area | Result | Evidence |
|---|---|---|
| **1. Architecture** | Sound. Thin controllers; services own the rules (engine, finalizer, ledger, wallet, deposits, withdrawals, prizes, reports); Form Requests, policies and gates; one writer for money (`LedgerService`); idempotent jobs; scheduler with recovery; state persisted, WebSockets only notify. Fragile or duplicated logic found: the withdrawal-availability condition is repeated in two controllers, and the test-only `DeferredEntryPayment` is still in `app/` (both minor, see MEDIUM). | ARCHITECTURE, ENGINE, WALLET, OPERATIONS docs; `tests/Feature/*` |
| **2. Authentication and authorization** | Verified. Registration, login throttling (5 per account + 20 per IP), logout invalidation, password reset (single-use, revokes sessions), account restrictions, admin and finance permissions, policies, channel authorization. New escalation tests: mass assignment of role/status/verification, admin self or other promotion, another user's private channel, non-finance withdrawal decisions; all refused. | `Security/PrivilegeEscalationTest`, `LeastPrivilegeMatrixTest`, `AdminAccessMatrixTest`, `UserManagementTest` |
| **3. Competition lifecycle** | Every transition is server-side, row-locked and retry-safe: question → paper (locked) → room (snapshot) → join (fee in the same transaction) → start → questions → answers → completion → scoring → frozen versioned results → publish → prizes (one credit per payout). | Simulation A–J; `CompetitionEngineTest`, `ResultsFinalizationTest`, `PrizeDistributionTest` |
| **4. Timing** | Server timestamps only (UTC storage, PKT display); one shared deadline per question; late answers refused (1 ms after grace); reconnect restores the same deadline; the client timer is display-only. **Fixed:** the DB session time zone is pinned to UTC (`DB_TIMEZONE`), so TIMESTAMP columns can't shift with server daylight saving. | Simulation D, E; `AnswerSubmissionTest`; `config/database.php` |
| **5. Financial integrity** | Integer minor units only; no balance editing (only audited, limited, off-by-default adjustments); append-only ledger (DB triggers); idempotency keys and unique references; single-reversal rule; transactions with ordered locks; floor rounding with the remainder unallocated. **Added:** `finance:reconcile` (records vs ledger; non-zero exit; scheduled daily). | `AssertsFinancialInvariants` (self-tested), `WalletServiceTest`, concurrency tests, `FinanceReconcileCommandTest` |
| **6. Payment security** | Callbacks authenticated (signature, merchant), amount/currency/reference verified, plus an independent status query; duplicates are no-ops (also under real concurrency); no secrets committed; the fake provider is refused outside local/testing; `security:check` fails on fake gateways in production. | `DepositFlowTest`, `ConcurrentCallbackTest`, `PaymentVerificationTest`, SECURITY.md §2 |
| **7. Competition integrity** | Attempted and refused: answer after the deadline, duplicate or changed answer, forged question id, forged room id, another user's seat (not accepted as input), future question and image retrieval, answer key retrieval (never serialized before publication), reload/reconnect, multiple tabs (unique index), answer floods (rate-limited per room). Nothing exploitable found. | `Security/CompetitionIntegrityTest`, `PlayStateAndChannelTest`, `SnapshotIntegrityTest` |
| **8. Admin security** | Ordinary admins cannot edit balances (no route; adjustments are finance-only, audited, disabled by default); cannot silently change results (corrections need `results.manage`, a reason and confirmation, create a new version, and are blocked after payment); cannot delete financial or audit history (no routes; DB triggers reject UPDATE/DELETE); cannot act on finance without permission. | `AdminAccessMatrixTest`, `AppendOnlyRecordsTest`, `ResultsPagesAndCorrectionsTest`, `PrivilegeEscalationTest` |
| **9. Privacy** | Minimal collection (name, display name, email, password hash); payout numbers encrypted and masked; contact messages emailed, not stored; logs redacted and contain no player personal data. **Open:** IP addresses in audit and callback records are kept permanently; no self-service data export/deletion (MEDIUM, policy decision). | SECURITY.md, REPORTS.md (retention) |
| **10. Legal/compliance gate** | **Added.** `config/compliance.php` and `LaunchGate`: real-money switches are forced off unless `COMPLIANCE_REAL_MONEY_APPROVED`, an approval reference, and all 11 requirements (legal review, provider approval, age verification, KYC, AML, geo-restriction, responsible play, self-exclusion, tax, terms/rules/refunds, privacy policy) are confirmed. Shown in Admin → Settings and the dashboard; enforced in `security:check`. **No legal conclusion is made by the code.** | `Security/LaunchGateTest` |
| **11. Disaster recovery** | See the table below. No procedure fabricates answers or financial events. | OPERATIONS.md, DEPLOYMENT.md, Simulation J |
| **12. Backups** | Schedule, retention, encryption (`age`), off-site copy, restore procedure and a monthly restore test are documented and scripted. **The restore has not been tested** (no server). | DEPLOYMENT.md §9 |
| **13. Tests** | See below. | |

### Disaster recovery summary

| Event | What happens | Recovery |
|---|---|---|
| App crash during a competition | Each engine step is one transaction (all or nothing) | The next tick/job continues from stored state; `competitions:recover` finishes stuck steps (tested, Scenario J) |
| Database outage | Requests fail (`/up` = 500, alert); answers cannot be stored, so none are invented | On recovery the engine catches up: an open question keeps its original deadline, and later questions open with full time |
| Queue worker failure | Engine timing falls back to the 5-second tick; notices wait | Supervisor restarts workers; `ops:status` alerts on lag |
| Reverb failure | Browsers show "Reconnecting" and poll; the competition continues | Supervisor restarts it; deadlines are unchanged |
| Payment provider outage | Deposits stay pending; nothing is credited without verification; expiry after 30 min | `payments:reconcile` re-queries the status; idempotent settlement |
| Duplicate callback | No-op (exactly-once settlement) | Tested sequentially and under concurrency |
| Deployment interruption | Before the switch: the old release keeps serving. After: rollback script. Maintenance stays on if migration failed | `deploy.sh` error handler instructions; `rollback.sh`; pre-migration dump |

### Automated tests

```
vendor/bin/phpunit --display-warnings --display-deprecations --display-notices --display-skipped --display-incomplete --display-errors --display-phpunit-deprecations
```

| Result | Count |
|---|---|
| Tests | **657** (5,247 assertions) |
| Passed | **657** |
| Failed | **0** |
| Errors | **0** |
| Skipped / incomplete | **0** |
| Warnings / deprecations / notices | **0** |
| Duration | 4 min 02 s (PHP 8.2.12, MariaDB 10.4, Windows dev machine) |

**Also run:**
- `vendor/bin/pint --test`: passed.
- `npm run build`: OK.
- `php artisan view:cache`: OK.
- `composer audit`: no advisories.
- `npm audit --omit=dev`: 0 vulnerabilities.

**Load:** a 1,000-player answer burst passed with 0 errors (LOAD_TESTING.md).

**Not tested:**
- real payment, payout, email and SMS providers;
- Reverb in automated tests (checked manually in a browser);
- PHP 8.3 and MariaDB 10.11 (the production targets);
- MySQL 8;
- multi-server setups.

---

## READY (completed and verified)

- **Accounts:** registration with email verification, login/logout, throttling, password reset,
  profile, email change, sessions; account statuses (restricted, suspended, closed).
- **Administration:** least-privilege roles and permissions; an audited admin area; read-only
  settings; dashboard; risk review.
- **Content:** question bank (images re-encoded, CSV import with formula protection) and paper
  builder; immutable competition snapshots.
- **Rooms:** fill, scheduled and hybrid; capacity enforced under real concurrency; reservations;
  cancellation with exactly-once refunds.
- **Live engine:** server-timed, shared deadlines, WebSocket with polling fallback, reconnect
  restoration, recovery tick and recovery command, unique per-moment jobs.
- **Results:** deterministic scoring, tie-break policies, versioned results, corrections,
  disputes, publication.
- **Ledger and wallet:** double-entry ledger, wallets, entry fees, prizes (fixed and percentage
  pools, ties, remainders), the withdrawal workflow (holds, four-eyes, simulated payouts), the
  deposit flow (simulated gateway), reconciliation (`wallets:reconcile`, `finance:reconcile`,
  `payments:reconcile`, `withdrawals:reconcile`).
- **Notifications:** in-app, real-time and email (queued, chunked room notices); preferences;
  announcements.
- **Reports:** analytics, financial reports, queued CSV exports (formula-safe), an append-only
  audit log with correlation ids.
- **Security:** CSP and headers, named rate limiters, risk signals, log redaction, `security:check`.
- **UI:** responsive and accessible pages, a redesigned live screen, branded error pages.
- **Operations:** health/ops commands, queue architecture, deployment kit (nginx, PHP-FPM,
  Supervisor/systemd, cron, deploy/rollback/backup/restore/health scripts).
- **Launch gate** for real money.

## BLOCKERS (must be resolved before any public launch)

1. **No production environment and no rehearsed deployment.** `deploy/` has never run on Linux.
   Provision staging, run the full deployment and the test suite on PHP 8.3 / MariaDB 10.11, then
   production. (Hosting/DNS decision needed.)
2. **Legal pages are drafts.**
   - Terms and the privacy policy display "Draft, pending legal review".
   - The contest rules (How to play, including the refund rules) and the responsible-play page
     were written by the developer and have not been reviewed.

   All of them need qualified review, completion and publication before real users register,
   even for free play, because personal data is collected and prizes are advertised.
3. **No transactional email provider.** Email verification is required to join competitions, and
   password reset depends on email. Configure SMTP or an API provider, plus SPF/DKIM/DMARC.
4. **No restore has been tested.** Run `restore-test.sh` on staging. Until it succeeds, the backup
   strategy is unproven.

**Additional blockers before any real money** (paid entry, deposits, cash prizes or withdrawals):

5. **No real payment or payout provider integrated.** Only the `disabled` and local-only `fake`
   drivers exist. Integration requires the provider's official documentation, credentials, a
   sandbox, and their approval of this business model.
6. **Legal and compliance sign-off not recorded.** The launch gate is locked. All 11 requirements
   must be confirmed by qualified review (each "in place" or "not required").
7. **Compliance controls not implemented:** age verification, identity verification (KYC), AML
   monitoring/reporting, geo-restriction to permitted jurisdictions, and self-exclusion. These
   must be built or provided by a specialist provider, if the review requires them.
8. **Tax treatment undecided.** Prize withholding is configurable (`PRIZE_TAX_WITHHOLDING_BP`,
   currently 0). The rate and reporting obligations must come from a tax adviser.

## HIGH PRIORITY (before accepting real users or money)

- **Two-factor authentication for staff,** at least super admin and finance manager (SECURITY.md M7).
- **Independent security review / penetration test** of the deployed staging site, including
  WebSockets.
- **Monitoring wired and tested end to end:** external uptime check on `/up`, `ALERT_COMMAND` for
  `healthcheck.sh`, log shipping, a test alert received.
- **Staging load test** over HTTP and WebSockets with realistic concurrency (LOAD_TESTING.md §2):
  so far only an in-process burst on one machine has been run.
- **Trusted-proxy configuration** if a load balancer or CDN is ever placed in front of nginx
  (SECURITY.md M6).
- **Operational ownership:** who reviews risk flags, disputes, failed jobs and reconciliation
  differences, and how fast.

## MEDIUM PRIORITY (non-blocking)

- **Privacy:**
  - define a retention period for IP addresses in audit and callback records (they are
    append-only, so pseudonymize going forward or archive);
  - self-service data export and deletion requests, per the privacy policy.
- **Code tidy-ups:**
  - move the test-only `DeferredEntryPayment` out of `app/`;
  - centralize the withdrawal-availability condition repeated in two controllers.
- **Scale items (with measurements in PERFORMANCE.md):**
  - a leaderboard index once completed competitions number in the thousands;
  - reconciliation opening-balance snapshots;
  - Redis when running more than one app or Reverb server.
- **UI:**
  - admin tables stack on phones;
  - `sm` buttons reach 44 px tap targets;
  - self-hosted fonts.
- **Rate limiting:** the per-IP login limit can affect shared networks; review with real traffic.
- **Database portability:** MySQL 8 compatibility (only MariaDB has been tested).

## EXTERNAL REQUIREMENTS

| Area | Needed |
|---|---|
| **Payment provider** | Merchant account and approval of the paid-entry/prize model (e.g. JazzCash / Easypaisa / a bank PSP); official API docs, sandbox, credentials; callback IP/signature details; refund and chargeback terms |
| **Payout provider** | Approved disbursement channel for prizes and withdrawals (bank / mobile wallet); limits; KYC expectations |
| **Hosting / DNS** | Linux server(s) per DEPLOYMENT.md; domain and DNS; TLS (Let's Encrypt); off-site backup storage; log/monitoring service |
| **Email / SMS** | Transactional email provider (SPF/DKIM/DMARC); SMS only if OTP or alerts are required later (not implemented) |
| **Legal / compliance** | Classification of paid entry + prizes in each target jurisdiction; terms, privacy, rules, refund and responsible-play texts; age limit; AML/CTF obligations; consumer-protection and data-protection obligations |
| **Identity / KYC** | Provider for identity and age verification (and sanctions/PEP screening if AML applies) |
| **Tax** | Prize withholding rate and reporting; platform revenue treatment |
| **Business decisions** | Entry fees and prize structures; platform fee; deposit/withdrawal limits and fees; free-pilot scope; support hours and escalation; staff roles and who holds super admin |

## REQUIRED PRODUCTION ENVIRONMENT VARIABLES (names only)

**Core:** `APP_NAME` `APP_ENV` `APP_KEY` `APP_DEBUG` `APP_URL` `APP_LOCALE` `APP_FALLBACK_LOCALE`
`APP_MAINTENANCE_DRIVER` `LOG_CHANNEL` `LOG_STACK` `LOG_LEVEL` `LOG_DAILY_DAYS`

**Database:** `DB_CONNECTION` `DB_HOST` `DB_PORT` `DB_DATABASE` `DB_USERNAME` `DB_PASSWORD`
`DB_TIMEZONE`. Migration credentials go in `/etc/mcq/migrate.env`, not `.env`.

**Sessions, cache, queues:** `SESSION_DRIVER` `SESSION_LIFETIME` `SESSION_SECURE_COOKIE`
`SESSION_DOMAIN` `CACHE_STORE` `QUEUE_CONNECTION` `QUEUE_ENGINE` `QUEUE_DEFAULT`
`QUEUE_LONG_CONNECTION` `DB_LONG_QUEUE_RETRY_AFTER` `QUEUE_MONITOR_MAX` `OPS_TICK_STALE_SECONDS`
`CACHE_DASHBOARD_SECONDS`

**Mail and contact:** `MAIL_MAILER` `MAIL_HOST` `MAIL_PORT` `MAIL_USERNAME` `MAIL_PASSWORD`
`MAIL_SCHEME` `MAIL_FROM_ADDRESS` `MAIL_FROM_NAME` `CONTACT_RECIPIENT_EMAIL`

**Broadcasting / Reverb:** `BROADCAST_CONNECTION` `REVERB_APP_ID` `REVERB_APP_KEY`
`REVERB_APP_SECRET` `REVERB_HOST` `REVERB_PORT` `REVERB_SCHEME` `REVERB_SERVER_HOST`
`REVERB_SERVER_PORT` `REVERB_ALLOWED_ORIGINS` `REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM`
`VITE_APP_NAME` `VITE_REVERB_APP_KEY` `VITE_REVERB_HOST` `VITE_REVERB_PORT` `VITE_REVERB_SCHEME`

**Security:** `SECURITY_HEADERS` `SECURITY_CSP` `SECURITY_CSP_FORM_ACTIONS` `SECURITY_CSP_CONNECT`
`SECURITY_HSTS_MAX_AGE`

**Real money (keep off):** `PAYMENT_GATEWAY` `DEPOSITS_ENABLED` `COMPETITION_PAID_ENTRIES_ENABLED`
`PRIZE_PAYOUTS_ENABLED` `WITHDRAWALS_ENABLED` `WITHDRAWAL_PAYOUT_GATEWAY` `FAKE_GATEWAY_ENABLED`
`FAKE_PAYOUT_ENABLED` `PRIZE_TAX_WITHHOLDING_BP`

**Launch gate:** `COMPLIANCE_REAL_MONEY_APPROVED` `COMPLIANCE_APPROVAL_REFERENCE`
`COMPLIANCE_PERMITTED_JURISDICTIONS` `COMPLIANCE_MINIMUM_AGE` `COMPLIANCE_CONFIRMED_LEGAL_REVIEW`
`COMPLIANCE_CONFIRMED_PAYMENT_PROVIDER_APPROVAL` `COMPLIANCE_CONFIRMED_AGE_VERIFICATION`
`COMPLIANCE_CONFIRMED_KYC` `COMPLIANCE_CONFIRMED_AML` `COMPLIANCE_CONFIRMED_GEO_RESTRICTION`
`COMPLIANCE_CONFIRMED_RESPONSIBLE_PLAY` `COMPLIANCE_CONFIRMED_SELF_EXCLUSION`
`COMPLIANCE_CONFIRMED_TAX_REPORTING` `COMPLIANCE_CONFIRMED_TERMS_RULES_REFUNDS`
`COMPLIANCE_CONFIRMED_PRIVACY_POLICY`

**Optional with defaults:**
- **Competition:** `RESULTS_AUTO_PUBLISH` `COMPETITION_ANSWER_GRACE_MS` `ROOM_RESERVATION_MINUTES`
- **Deposits:** `DEPOSIT_*`
- **Withdrawals:** `WITHDRAWAL_*`
- **Wallet:** `WALLET_ADJUSTMENTS_ENABLED` `WALLET_MAX_ADJUSTMENT_MINOR`
- **Notifications and retention:** `NOTIFICATIONS_MAIL` `NOTIFICATIONS_BROADCAST`
  `RETENTION_READ_NOTIFICATIONS_DAYS`
- **Security:** `SECURITY_RAPID_ANSWER_MS`
- **Redis, if chosen:** `REDIS_*`

The template with comments is `deploy/env/production.env.template`.

## FINAL DEPLOYMENT CHECKLIST (operator sequence)

Do not start until BLOCKERS 1–4 are resolved for the chosen scope, and you have **explicitly
authorized** the deployment.

1. **Repository:** commit and tag the release that passed `vendor/bin/phpunit` and
   `vendor/bin/pint --test`. Mark the scripts executable
   (`git update-index --chmod=+x deploy/scripts/*.sh`).
2. **Staging rehearsal:** provision staging exactly per DEPLOYMENT.md §2–§8; run `deploy.sh`; run
   the test suite on staging; run SMOKE_TEST.md fully (simulated payments, gate approved with a
   *test* reference on staging only); run `restore-test.sh`; run the staging load test.
3. **Production server:** follow DEPLOYMENT.md §2–§4.
   - Packages, firewall, users and permissions.
   - Database: create users from the template with generated passwords. Set
     `log_bin_trust_function_creators=1` if binlog is on.
4. **Secrets:**
   - generate `APP_KEY`, the Reverb keys and the DB passwords on the server;
   - write `/var/www/mcq/shared/.env` from the template and `/etc/mcq/migrate.env`;
   - store copies in the secret manager.
   Real-money switches are `false`, and all `COMPLIANCE_*` stay `false`.
5. **TLS and nginx** (§6), **PHP-FPM and OPcache** (§7), **Supervisor and cron** (§8).
6. **Deploy:** `REPO=<url> bash deploy/scripts/deploy.sh <tag>` as `deploy`. It refuses to switch
   if `security:check --production` fails.
7. **First super admin:** `sudo -u www-data php artisan users:create-super-admin`. Create the
   other staff in Admin → Users.
8. **Verify:** DEPLOYMENT.md §14 checklist; SMOKE_TEST.md rows marked **P**; Admin → Settings
   shows the launch gate **LOCKED**.
9. **Backups:** confirm the first database and files backups exist off-site; schedule the monthly
   restore test.
10. **Monitoring:** confirm the external uptime check and a test alert from `healthcheck.sh`.
11. **Go / no-go:** sign off by the business owner, the security reviewer and the operator. Then
    open registration.
12. **Real money (later, separately authorized):**
    - integrate and certify the provider;
    - complete the compliance controls;
    - record the legal approval in the `COMPLIANCE_*` variables, change the switches, and
      redeploy;
    - confirm `security:check`;
    - run a controlled low-limit pilot.
