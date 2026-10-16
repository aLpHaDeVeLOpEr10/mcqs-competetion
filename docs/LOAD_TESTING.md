# Load Testing (local / staging only)

**Never run any of this against production, a shared database or a third-party service.** The
tools below create their own users and rooms and never delete data. Use a disposable database.

## 1. Answer burst (included, runnable now)

`tests/Load/answer-burst.php` has N players answer the same live question at the same instant,
from W parallel PHP processes, through the real `AnswerService`, engine and database. It does not
use HTTP.

**Safety:**
- It refuses to run unless the database name ends in `_load` and `APP_ENV` is local or testing.
- It uses the database queue, but no worker runs during the burst; notices queue exactly as they
  would in production.
- It uses null broadcasting and array mail.

**Setup and run:**

```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS mcq_competition_load CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
DB_DATABASE=mcq_competition_load php artisan migrate --force
php tests/Load/answer-burst.php run 1000 16        # players, worker processes
```

**What it checks:**
- every player ends with exactly one stored answer;
- every 10th player double-submits, and the duplicates are idempotent;
- zero errors;
- the engine can still close the question promptly afterwards.

**It reports:**
- join throughput;
- engine step times;
- answer latency (p50, p95, p99 and max);
- close time.

**Measured on the development machine** (Windows 10, XAMPP MariaDB 10.4, PHP 8.2, one host,
2026-10-05):

| Players / processes | Joins | Engine start step | Burst | Answer latency p50 / p95 / p99 / max | Errors | Stored answers | Question close |
|---|---|---|---|---|---|---|---|
| 200 / 8 | 25.8 ms each | (not split out) | 0.57 s | 15.8 / 32.4 / 48.9 / 59.9 ms | 0 | 200 / 200 | 21 ms |
| 1000 / 16, notices inline (before the fix) | 26.2 ms each | **≈ 13 s** | 4.64 s | 35.1 / 218 / 479 / 570 ms | 0 | 1000 / 1000 | 22 ms |
| 1000 / 16, notices queued (after the fix) | 32.6 ms each | **8 ms** | ≈ 2 s | 25.9 / 74.2 / 107 / 177 ms | 0 | 1000 / 1000 | 37 ms |

**Notices afterwards.** Draining the queued notices afterwards (1,000 join emails plus one chunked
"competition started" notice) took 52 s on one worker, with 0 failures.

**The fix.** The 1,000-player run exposed that room-wide notices ("competition started",
"results ready") were sent inside the engine process: about 13 ms per player. They now go through
`NotifyRoomPlayers`:
- queued after commit;
- chunked 500 per job;
- each player claimed once.

**What the numbers don't show.** These are single-machine numbers with the database, PHP and load
generator competing for the same CPU. They show correctness under contention and relative cost,
not production capacity.

## 2. HTTP and WebSocket plan for staging

Run against a **staging** copy that matches production: the same web server, PHP-FPM workers,
database class, Reverb behind TLS, queue workers and scheduler.

1. **Seed.** Create K test accounts and one fill room with K seats in the staging database
   (`answer-burst.php` shows how), and log each account in once to collect session cookies and
   CSRF tokens.
2. **Ramp.** Use a load tool you control on your own machine, such as k6 or Locust (not installed
   in this project). Script, per virtual user:
   - `GET /play/{room}` once;
   - open the Reverb WebSocket and subscribe to `private-competition.{session}` via
     `POST /broadcasting/auth`;
   - `GET /play/{room}/state` every 3 s **only when the socket is down**;
   - one `POST /play/{room}/answers` per question, at a random 0.5–10 s after `question.opened`.
3. **Stages.** 100 → 500 → 1,000 → 2,000 concurrent players, holding each stage for a full
   10-question competition.
4. **Watch:**
   - p95/p99 for the answer and state endpoints;
   - 429s (there should be none at one answer per question);
   - database CPU and lock waits;
   - `php artisan ops:status` (queue lag, heartbeat);
   - Reverb connection count and memory;
   - worker job times.
5. **Pass criteria:**
   - 0 answer errors other than late answers;
   - p95 answer latency < 300 ms;
   - every question closes within 1 s of its deadline;
   - results finalize in < 10 s;
   - financial invariants hold (`php artisan wallets:reconcile`);
   - `competitions:recover --dry-run` finds nothing.
6. **Failure drills during a run:**
   - restart the queue workers;
   - stop Reverb for 60 s;
   - kill one PHP-FPM pool.

   The competition must continue and stay correct (docs/OPERATIONS.md).

**Rate limits.** The answer limit (60/min per player and room) and the state-polling limit
(240/min) are per user. A load generator that reuses one account for many virtual users will be
throttled; use distinct accounts.
