# Performance and Database Optimization (Phase 21)

Every change below was made only after measuring it. Rejected candidates are listed with their
numbers.

## Test environment

| | |
|---|---|
| Machine | one Windows 10 development PC (database, PHP and the load generator share the CPU) |
| Software | PHP 8.2.12 CLI (XAMPP) with **OPcache enabled** for all reported numbers (`-d zend_extension=opcache -d opcache.enable_cli=1`, matching production PHP-FPM); MariaDB 10.4.32, default XAMPP settings |
| Dataset | local `mcq_competition_load` database. Built by `tests/Load/seed-profile.php` through the real services, plus the Phase 20 load runs. See below. |
| Method | `tests/Load/profile.php` sends real HTTP requests in-process (full middleware stack) as a guest, a player, a seated player and a super admin. Per page: 1 warm-up plus 5 runs, **median** wall time, query count and SQL time. Answer submission is the median over 60 different players. The engine close step is a single run on a 500-player room. |

**Dataset:**
- 5,500 users and 8,600 seats;
- 40 completed paid competitions, with results, entry fees and prizes, spread over 30 days;
- 80 open or upcoming rooms, plus 10 large load-test rooms (500–1,000 players);
- 4,150 ledger transactions and 8,300 entries; 10,600 notifications; 5,350 audit records;
- one player with a 1,500-transaction history.

**Two notes on the measurements:**
- **OPcache:** this matters. Without it (XAMPP's CLI default), page rendering was up to about 45% slower (home 25.7 vs 14.9 ms, admin users 46.5 vs 27.4 ms), while SQL-bound operations such as answer submission barely changed. **Production must run PHP-FPM with OPcache on.**
- **Category data:** the first baseline used the question factory's one-category-per-question data (666 categories), which inflated the listing's filter dropdown. That is a test-data artifact, so the data was normalized to 12 categories and the baseline retaken. The numbers below all use the realistic data.

Reproduce:

```bash
php tests/Load/seed-profile.php 40 50 80          # once
php -d zend_extension=opcache -d opcache.enable_cli=1 tests/Load/profile.php <label> --live=500
# PROFILE_ONLY=<page> PROFILE_REPEAT_MIN=2 and --slow print duplicate and slowest queries
```

## Baseline findings

**No N+1 queries.** Every page runs 2–21 queries, and no query shape repeated more than 5 times.

**Index review:**
- Every hot query plan uses an index: ledger `(type, created_at)`, entries `(ledger_transaction_id, wallet_id)`, seats `(room_id, status)`, the session's `room_id`, and the wallet primary key.
- No missing index was demonstrated by EXPLAIN or ANALYZE on this data.

**The live hot paths are already cheap:**
- answer submission: 12 ms, 8 queries;
- state poll: 6.5 ms, 6 queries;
- closing a question for 500 players: 24 ms.

They take no session-wide locks (answers rely on a unique index), so nothing was changed there.

**Where the time went:**
- **Admin dashboard:** 21 queries, 37.5 ms of SQL. The all-time money and user totals scan whole tables, so their **cost grows without limit** as history accumulates (about 5 µs per ledger row).
- **Competition report:** the per-room aggregate subqueries ran for **every** room in the period, not just the 25 shown (ORDER BY on an expression causes a filesort over fully computed rows).
- **Reports overview and reconciliation:** period aggregates of about 1–13 ms each, proportional to activity in the period (at most 366 days). The plans are optimal.
- **Duplicate queries:** the header bell and the account overview each fetched the unread count and the latest notifications (2 × 2 queries). The dashboard counted outstanding prize payouts twice.

## Changes made (with evidence)

| Change | Evidence | Result |
|---|---|---|
| **Dashboard all-time totals cached** (`DashboardMetrics`): users, participation and money totals reused for `CACHE_DASHBOARD_SECONDS` (default 60; 0 = live), shown with "Totals as of …" | 21 queries / 37.5 ms of SQL, growing linearly with history | 58.4 → **20.5 ms**; 21 → **6** queries on a cache hit. A miss (first request each minute) costs the original ≈ 60 ms. |
| **Competition report deferred join** (`ReportCatalog::page`): page ids first, then aggregates for those rows only. Totals are one query. | per-room subqueries computed for every room in the period | Page query 36.5 → **26.2 ms**. Whole page 78.1 → **68.7 ms**. Totals unchanged (27.6 → 27.3 ms; inherently proportional to every seat in the period). Page cost no longer grows with the number of competitions. |
| **Notifications read once per request** (`User::unreadNotificationCount` / `recentNotifications`, memoized on the request object, so never across requests or users) | the same 2 queries ran twice per page | Account overview 21 → **19** queries; notifications page 5 → **4** |
| **Live screen pauses polling in hidden tabs** and resyncs the moment the tab is shown | hidden tabs polled every 3 s (WebSocket down) or 15 s (up). Each state poll costs ≈ 7 ms server-side. | No polling from tabs nobody is looking at; a player with two tabs makes half the requests |
| Phase 20: **room-wide notices queued and chunked** | engine start step ≈ 13 s at 1,000 players | 8 ms (see LOAD_TESTING.md) |
| Phase 19: **removed unused axios; CSS no longer scans the compiled-view cache** | 53 KB `app.js`; CSS size depended on the cache | `app.js` 2.3 KB; CSS a deterministic ≈ 50 KB (9.5 KB gzipped) |

### Before and after, all pages (OPcache on, medians)

Pages with no code change differ by about ±10% between runs. That is measurement noise on a
shared machine, not an effect: for example, the play page, reconciliation and the state poll.

| Area | Page / operation | Wall ms before → after | Queries | SQL ms |
|---|---|---|---|---|
| public | home | 12.2 → 12.7 | 3 → 3 | 2.1 → 2.1 |
| public | competition listing | 30.2 → 29.4 | 5 → 5 | 5.1 → 3.9 |
| public | competition listing (filtered) | 32.9 → 32.4 | 5 → 5 | 2.8 → 3.1 |
| public | competition details | 11.5 → 11.6 | 5 → 5 | 2.0 → 2.5 |
| public | results (50 players) | 15.4 → 15.7 | 6 → 6 | 3.1 → 3.1 |
| public | leaderboard | 29.1 → 27.5 | 6 → 6 | 11.1 → 9.8 |
| player | account overview | 38.9 → 40.0 | 21 → 19 | 14.7 → 14.5 |
| player | my competitions | 25.1 → 25.8 | 12 → 12 | 6.8 → 5.3 |
| player | competition history | 17.8 → 18.9 | 8 → 8 | 4.2 → 5.0 |
| player | wallet | 20.4 → 21.9 | 6 → 6 | 7.0 → 7.3 |
| player | transactions (1,500+ entries) | 24.0 → 24.5 | 5 → 5 | 2.7 → 2.9 |
| player | notifications | 17.9 → 17.2 | 5 → 4 | 2.7 → 2.0 |
| play | play page (500-player room) | 29.6 → 23.5 | 12 → 12 | 16.4 → 10.0 |
| play | state poll | 6.5 → 7.8 | 6 → 6 | 2.1 → 2.6 |
| admin | dashboard (cache hit) | 58.4 → 20.5 | 21 → 6 | 37.5 → 3.2 |
| admin | rooms | 25.5 → 25.7 | 3 → 3 | 1.5 → 1.7 |
| admin | users | 26.2 → 26.4 | 2 → 2 | 2.6 → 2.7 |
| admin | results | 16.3 → 18.0 | 3 → 3 | 2.2 → 2.4 |
| admin | wallets | 26.4 → 21.6 | 3 → 3 | 5.4 → 4.3 |
| admin | ledger | 20.4 → 18.7 | 2 → 2 | 2.2 → 2.2 |
| admin | payouts | 35.3 → 35.8 | 6 → 6 | 3.3 → 2.6 |
| admin | audit | 31.8 → 33.0 | 4 → 4 | 8.8 → 8.3 |
| admin | reports overview (30 days) | 61.0 → 62.3 | 18 → 18 | 44.4 → 42.8 |
| admin | competition report | 78.1 → 68.7 | 5 → 4 | 57.7 → 50.1 |
| admin | finance: entry fees | 26.8 → 25.8 | 4 → 4 | 9.9 → 9.7 |
| admin | finance: reconciliation | 88.8 → 81.0 | 15 → 15 | 73.7 → 66.8 |
| play | answer submission (HTTP) | 12.1 → 12.0 | 8 → 8 | 5.6 → 4.8 |
| engine | close question (500 players) | 24.2 → 26.6 | 13 → 13 | 15.1 → 14.9 |

## Candidates measured and rejected

| Candidate | Measurement | Decision |
|---|---|---|
| One grouped query for the five per-type ledger sums (reports overview) | 12.0 ms grouped vs ≈ 12.3 ms for the five separate queries | no gain; not done |
| Covering index `ledger_entries(ledger_transaction_id, wallet_id, amount_minor)` | deposit sum 10.2 → 8.2 ms | duplicates the unique index's prefix for about 20%; not added |
| Competition-report totals via direct joins | gross 22 ms + refunds 11 ms vs 24.8 ms (a variant with `IN` on two columns took 3.3 s) | slower; not done |
| Seat-link columns instead of the polymorphic reference in report subqueries | page 30 → 24 ms, identical results | the deferred join (which also bounds growth) was chosen instead |
| Index on `competition_sessions(results_published_at)` for the leaderboard | the optimizer did not use it at 40 completed rooms (it scans 40 rows and sorts) | not added. **Revisit at scale.** The query scans every completed room, so cost grows with history. |
| Caching the leaderboard | 27.5 ms page, 9.8 ms of SQL | not worth the invalidation complexity at this size. Revisit with real traffic data. |
| Caching the public listing | shows live seat counts and per-user join eligibility | **must not be cached** (stale seats and eligibility) |

## Cache policy

- **Cached:** only the admin dashboard's all-time totals, for 60 s, shown with their time. No
  decision, balance, permission or result ever reads a cache: entry checks, answers, scoring,
  prizes, the ledger and reconciliation are always live.
- **Invalidation:** time-based (TTL). Alerts and recent audit actions are always live.
- **Request memoization:** the notification count and list are stored on the request object. They
  are never shared across requests or users.
- **Safe stores:** the `database` store (default) or `redis`; `array` in tests. The figures are
  plain integers.

## Redis assessment

Redis is **not available** on this machine: no `phpredis` extension, no server, and no `predis`
package. Nothing requires it; every role has a working database-backed default.

| Role | Current default | With Redis |
|---|---|---|
| Cache (dashboard totals, rate limiters) | `CACHE_STORE=database` | `CACHE_STORE=redis`: faster, and atomic locks for `onOneServer`/`withoutOverlapping` across servers |
| Queues | `QUEUE_CONNECTION=database` (with the `database-long` connection) | `redis` queues: lower latency for the engine's delayed jobs at high volume. Keep `retry_after` above job timeouts (OPERATIONS.md). |
| Atomic locks (unique jobs, scheduler) | database cache locks | redis locks |
| Reverb horizontal scaling | single Reverb server | **required** for more than one Reverb server (`REVERB_SCALING_ENABLED=true` with a shared Redis) |

**Recommendation:** add Redis when running more than one app server or Reverb server, or when
queue latency shows up in `ops:status`. Switching is configuration only, and the tests use array
and sync drivers either way.

## Frontend

- **Bundles:** `app.js` 2.3 KB; `play.js` 9.5 KB; Echo 74 KB, loaded only where live updates are
  used.
- **CSS:** ≈ 50 KB (9.5 KB gzipped).
- **Images:** question images are WebP, re-encoded on upload, in a fixed-height box (no layout
  shift).
- **Real-time:** WebSockets first. Polling is a fallback (every 3 s while disconnected; a 15 s
  safety net while connected), and it is paused in hidden tabs.

## Reports and exports

- CSV exports and announcements were already queued jobs on the long connection (900 s timeout).
- Report pages are paginated, and every figure is a SQL aggregate.
- Period reports are proportional to the period's activity, capped at 366 days.

**For much larger volumes:**
- daily closing-balance snapshots for reconciliation's opening balance (currently an all-history
  sum, 12 ms here);
- the leaderboard index above, once completed competitions number in the thousands.

## Integrity

No change touches transactions, locking, authorization, scoring, the ledger or audit logging.

**Equivalence tests** (`tests/Feature/Reports/PerformanceEquivalenceTest`) prove that:
- the deferred report pages and totals equal the previous calculation exactly, page by page,
  including refunds;
- cached dashboard figures stay permission-gated, refresh after the window, and are live when
  disabled;
- notifications are queried once per request but never reused across requests.

The full suite (644 tests) passes.
