# Phase 00 Audit and Phased Implementation Plan

## Audit findings (2026-10-04)

| Item | Finding |
|---|---|
| Existing project | None. The folder was empty: no Laravel app, no git repository. |
| Action taken | Fresh **Laravel 12.69.3** skeleton (`laravel/laravel:^12.0`). Laravel 13 needs PHP 8.3, and this machine has PHP 8.2.12. |
| Database | XAMPP MariaDB 10.4.32. Databases `mcq_competition` (dev) and `mcq_competition_test` (tests) created. Default migrations only: users, password_reset_tokens, sessions, cache, cache_locks, jobs, job_batches, failed_jobs. |
| Authentication / authorization | None yet beyond the skeleton `User` model. No starter kit installed. |
| Frontend | Skeleton defaults: Vite 7, Tailwind CSS 4, axios. One view (`welcome.blade.php`). No layouts or components yet. |
| Routes / controllers / services / jobs / events | Skeleton only: `GET /` → welcome. |
| Tests | PHPUnit 11. Skeleton example tests + `FoundationConfigTest`. |
| Deployment assumptions | Local: Windows + XAMPP. Production target: Linux with Supervisor (see ARCHITECTURE §6). |

## Phased plan (each phase stops for approval)

| Phase | Scope | Main deliverables |
|---|---|---|
| **01 Database & application foundation** ✅ | Full schema, enums with transition rules, models, factories, safe seeders, constraint tests. See [DATABASE.md](DATABASE.md). | Done 2026-10-04. |
| **02 Public website & frontend design** ✅ | Blade layout and components, home, listing with filters, details, leaderboard, rules, contact, legal drafts, server-side join eligibility, error pages. | Done 2026-10-04. Login/register are placeholder shells. |
| **03 Authentication & user accounts** ✅ | Registration, login/logout, verification, password reset, profile, email change with confirmation, password change, session management, account status (active/restricted/suspended/closed), Gates, account pages. | Done 2026-10-04. Built on Laravel's own auth facilities, no new packages. |
| **04 Admin dashboard & access control** ✅ | Permission system, admin layout and components, dashboard metrics and alerts, user status/role management (audited), read-only sections for content, rooms, results, payments, ledger, audit log, settings. | Done 2026-10-04. See [ADMIN.md](ADMIN.md). |
| **05 Question bank & paper builder** ✅ | Question CRUD with options/answer key, tags, private re-encoded images, archive/restore; CSV import with preview, row errors, duplicate & formula detection; paper builder with ordering, overrides, target count, preview, publish validation, locking and duplication; immutable competition snapshots. | Done 2026-10-04. See [CONTENT.md](CONTENT.md). |
| **06 Competition rooms & scheduling** ✅ | Room configuration (policies, prizes, schedule), publish with validation + snapshot, locked settings, atomic join/withdraw with reservations and expiry, state machine to `starting`, `rooms:advance` scheduler, cancellation. | Done 2026-10-04. See [ROOMS.md](ROOMS.md). |
| **07 Real-time competition engine** ✅ | Laravel Reverb + Echo, idempotent engine (prepare/start/deliver/close/finish), shared global deadlines, answer service, state endpoint with reconnect, private channels, delayed jobs + 5 s tick recovery. | Done 2026-10-05. See [ENGINE.md](ENGINE.md). |
| **08 Scoring, rankings & winner selection** ✅ | Deterministic scoring policy, explicit tie-breaks with shared ranks, prize entitlements (pooled splits, floor rounding, unallocated remainder), idempotent concurrent-safe finalization, append-only result versions, results page with answer reveal, corrections and disputes. | Done 2026-10-05. See [RESULTS.md](RESULTS.md). |
| **09 Wallet & transaction ledger** ✅ | `LedgerService` (sole writer, ordered row locks, idempotency, currency/restriction/funds checks) and `WalletService` (credit, debit, refund, entry-fee charge/refund, audited adjustments), public ULID references, report-only reconciliation, player wallet and transaction pages, admin wallets/ledger, `finance.adjust`. | Done 2026-10-05. See [WALLET.md](WALLET.md). No gateway, deposits, withdrawals or payouts. |
| **10 Payment gateway & deposits** ✅ | `PaymentGateway` interface, `disabled` and local/testing-only `fake` drivers, deposit lifecycle with verified callbacks (signature, merchant, reference, amount, currency, server-side status query), exactly-once crediting, callback log, `payments:reconcile`, deposit pages, read-only admin payments. | Done 2026-10-05. See [PAYMENTS.md](PAYMENTS.md). Deposits off; no real provider. |
| **11 Entry fees & refunds** ✅ | `WalletEntryPayment` debits the frozen fee inside the locked join transaction, idempotent joins, refund policy (cancellation, minimum not reached, abort, withdrawal), exactly-once refunds with audit, rooms move to `refunded`, live seat counts, entry details for players and admins. | Done 2026-10-05. See [ROOMS.md](ROOMS.md#entry-fees-and-refunds-phase-11). Paid entries still off by default. |
| **12 Prize distribution & payouts** ✅ | Fixed and percentage prize structures (guaranteed or entry-fee pool with platform fee), pool accounting, `PrizeDistributionService` (eligibility, holds, review, idempotent per-payout credits, platform share, tax withholding hook), immutable paid payouts, reversal, admin payout management, player payout status, notifications. | Done 2026-10-05. See [PRIZES.md](PRIZES.md). Payouts off by default. |
| **13 Player dashboard** ✅ | Server-derived overview, My competitions tabs and cards, paginated history, own-answer review gated by publication, wallet breakdown and withdrawal shell, transaction filters, notifications, user-scoped queries and IDOR tests, responsive checks. | Done 2026-10-05. See [DASHBOARD.md](DASHBOARD.md). |
| **14 Notifications** ✅ | Central `AppNotification` + `Notifier` (after-commit, failure-isolated), database + private real-time + queued mail channels with SMS/push extension point, 12 event notifications, deduplicated reminders, header bell, notifications page, preferences with locked security notices, admin announcements. | Done 2026-10-05. See [NOTIFICATIONS.md](NOTIFICATIONS.md). |
| **15 Withdrawals & finance administration** ✅ | Encrypted, masked payout destinations with step-up and cooling-off, eligibility rules, ledger-based fund hold, explicit state machine, finance review queue with four-eyes, provider-agnostic `PayoutGateway` (disabled + local fake), idempotent payouts, verified callbacks, reconciliation, release-once on rejection/failure, audit and notifications. | Done 2026-10-05. See [WITHDRAWALS.md](WITHDRAWALS.md). Off by default; no real provider. |
| **16 Reports, analytics & audit** ✅ | Period analytics in PKT, competition/financial/user reports from SQL aggregates, reconciliation with records-vs-ledger checks, queued formula-safe CSV exports, audit correlation ids and credential stripping, retention policy and pruning. | Done 2026-10-05. See [REPORTS.md](REPORTS.md). |
| **17 Anti-cheat, fraud prevention & hardening** ✅ | Security review with classified findings, named rate limiters (answers never throttled by polling), CSP with nonces + hardening headers + production-only HSTS, risk signals (payments, withdrawals, refunds, wallet integrity, answers, reloads, devices, rate limits) for staff review only, admin risk review, log redaction, open-redirect and private-storage fixes, `security:check`. | Done 2026-10-05. See [SECURITY.md](SECURITY.md). |
| **18 Real-time reliability, queues & recovery** ✅ | Unique per-moment engine jobs with backoff/timeouts, queued retry-safe prize payments, separate long-job connection (timeouts below `retry_after`), failure and backlog logging, `competitions:recover` (overdue/inconsistent states from persisted timestamps, report-only where not derivable), `ops:status` heartbeat, `onOneServer` scheduling, refund re-link repair, deployment and recovery runbook, failure-simulation tests. | Done 2026-10-05. See [OPERATIONS.md](OPERATIONS.md). |
| **19 UI/UX polish & mobile** ✅ | Live-screen redesign (sticky timer with urgency text, locked state, reconnect banner, announcements), stacked mobile tables, error pages, a11y fixes, smaller bundles. | Done 2026-10-05. See [UI.md](UI.md). |
| **20 Automated testing & simulation** ✅ | Simulations A–J with whole-database financial invariants (self-tested), least-privilege matrix, unit tests for money/ledger/payment verification/refund policy, last-seat race, local load burst (1,000 players); fixed room-wide notices blocking the engine (now queued, chunked). | Done 2026-10-05. 641 tests passing. See [TESTING.md](TESTING.md), [LOAD_TESTING.md](LOAD_TESTING.md). |
| **21 Performance & database** ✅ | Profiled 28 pages/operations on a seeded dataset (OPcache on); dashboard totals cached 60 s (58→21 ms), competition report deferred join, per-request notification memo, hidden-tab polling pause; rejected candidates documented with measurements; Redis assessed (not required). | Done 2026-10-05. See [PERFORMANCE.md](PERFORMANCE.md). |
| **22 Production deployment** (prepared, not performed) | `deploy/`: nginx vhost (Reverb proxied on the same host), PHP-FPM pool + OPcache ini, Supervisor and systemd units, cron, least-privilege DB accounts (app / migrate / backup), production `.env` template, release-based `deploy.sh` (live-competition guard, pre-migration backup, atomic switch, smoke tests), rollback, backup + restore test, health check; Reverb origins restricted, client events refused, `/up` checks the DB, `security:check` deployment checks, `users:create-super-admin`. | Prepared 2026-10-05; **not executed** (no server access). See [DEPLOYMENT.md](DEPLOYMENT.md). |
| **23 Final audit & launch readiness** ✅ | End-to-end audit; real-money launch gate (`config/compliance.php`, `LaunchGate`); `finance:reconcile`; DB session pinned to UTC; privilege-escalation tests; smoke-test checklist; launch-readiness report. Verdict: **not production-ready** (blockers listed). | Done 2026-10-05. See [LAUNCH_READINESS.md](LAUNCH_READINESS.md), [SMOKE_TEST.md](SMOKE_TEST.md). |
| **Real payment & payout providers** (pending approval) | Real deposit and payout drivers from official docs. | Needs the compliance decision on real money. |

### Original Phase 00 proposal (kept for reference; numbering superseded by the phases above)

| Phase | Scope | Main deliverables |
|---|---|---|
| **02 MCQ bank & paper builder** | Admin CRUD for categories, questions, options. Quiz papers with ordering. Validation (exactly one correct option, ≥ 2 options). Bulk CSV import (optional). | Policies, Form Requests, tests that answer keys never appear in player-facing serialization. |
| **03 Wallet ledger & payment abstraction** | `wallets`, `ledger_transactions`, `ledger_entries`, `payments`. `LedgerService`, `Money` value object. `PaymentGateway` interface with `disabled` and `fake` drivers. Transaction history page. | Concurrency tests (parallel debits, duplicate idempotency keys). No real gateway. |
| **04 Rooms & competition lifecycle** | Admin room configuration (type, fee, capacity, schedule, prize tiers), publish snapshotting, `CompetitionStateMachine`, public listings, join/leave, cancellation refunds. Paid rooms blocked while the flag is off. | Transition tests, duplicate-join tests, capacity race test. |
| **05 Real-time gameplay** | Reverb + Echo install, channel authorization, `OpenQuestion`/`CloseQuestion` jobs, `competitions:tick` recovery command, answer submission endpoint, game screen (Blade + plain JS). | Timing-window tests, duplicate-answer tests, channel auth tests. |
| **06 Scoring, rankings & payouts** | Scoring, tie-breakers, ranking, winner publication, `PrizeDistributor` (idempotent, audited). Results pages. | Deterministic tie and prize-split tests, double-finalize test. |
| **07 Notifications, reports, account history** | In-app and email notifications, admin dashboard metrics, financial and competition reports, user history pages. | |
| **08 Hardening & deployment** | Rate limits, security headers, security review, load test of a full room, backup plan, Linux deployment guide, production env validation. | |

## Open decisions for upcoming phases

1. **Phase 01:** Laravel Fortify vs. hand-written auth controllers (recommend Fortify).
2. **Phase 01:** login identifier: email only, or also Pakistani mobile number (needs SMS OTP later)?
3. **Compliance:** are *free-entry* competitions with cash prizes allowed? Cash payouts may need a
   separate switch from paid entries.
4. **Phase 06:** confirm the tie-breaker and prize-split rule proposed in ARCHITECTURE §5.
5. **Production hosting:** is Redis available? It affects queue, cache and lock choices.
