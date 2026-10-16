# Operations: Queues, Scheduler, Recovery and Deployment

The competition never depends on any of the following:
- a browser staying connected;
- a JavaScript timer;
- a single HTTP request;
- a worker never restarting;
- a WebSocket event being delivered.

All authoritative state lives in the database: statuses, `opens_at`, `closes_at`,
`delivered_at` and `closed_at`, answers with server receipt times, results and the ledger. Every
background step re-derives what is due from that state.

## 1. Processes

| Process | Command (production: under Supervisor / systemd) | If it stops |
|---|---|---|
| Web | PHP-FPM behind nginx/Apache | site down |
| Engine worker | `php artisan queue:work database --queue=competition --tries=5 --timeout=30 --max-time=3600` | timing falls back to the scheduler tick (≤ ~5 s late) |
| Default worker | `php artisan queue:work database --queue=default --tries=5 --timeout=60 --max-time=3600` | email, prize payment jobs and notifications wait; `prizes:distribute` still pays every 5 min |
| Long worker | `php artisan queue:work database-long --queue=long --timeout=900 --max-time=7200` | CSV exports and announcements wait |
| WebSocket | `php artisan reverb:start` | browsers poll `/play/{room}/state` every 3 s; the competition is unaffected |
| Scheduler | cron `* * * * * php artisan schedule:run` (locally `php artisan schedule:work`) | **recovery stops:** no tick, reminders, reconciliation or recovery |

**One worker for development.** A single worker can serve every queue on the database connection
in priority order: `php artisan queue:work --queue=competition,default`. Exports need the
long-connection worker as well.

## 2. Queue architecture

| Work | How it runs | Retries / backoff / timeout | Why it is safe to repeat |
|---|---|---|---|
| Competition steps (prepare, start, deliver, close, finish) | `AdvanceCompetition` on `competition`, delayed to the exact due moment. **Unique per (session, moment)**, so the engine and every tick queue *one* job per moment. | 5 tries; 1, 2, 5, 10 s; 30 s timeout. An impossible transition fails at once. | `CompetitionEngine::advance` locks room → session and performs only the steps due *now*. |
| Scheduled starts, reservation expiry, cancellations | `RoomScheduler` via `competitions:tick` (every 5 s) | next tick | each room in its own locked transaction; every step re-checks state |
| Finalization (scoring and results) | right after the live part ends, in the same job or tick; retried by tick and `competitions:recover` | next tick | the finalizer locks room → session; a completed session returns its existing results |
| Prize payments | `DistributePrizes` on `default`, after commit; unique per session | 5 tries; 10 s, 1 min, 5 min, 15 min; 60 s timeout | one ledger idempotency key per payout (`prize:{session}:{seat}`) |
| Notifications | mail queued on `default`; database and broadcast after commit | the Laravel defaults | sent after commit; reminders and announcements claim each recipient once in `notification_dispatches` |
| Payment and payout reconciliation | `payments:reconcile` (5 min), `withdrawals:reconcile` (10 min) | next run | exactly-once settlement under the row lock |
| CSV exports, announcements | `GenerateReportExport` and `SendAnnouncement` on the `database-long` connection, queue `long` | 2–3 tries with backoff; 900 s timeout | the export re-checks permission and rewrites its file; announcements claim each user once |

**Timeouts and `retry_after`.** Every job timeout is below its connection's `retry_after`:
- `database`: 90 s;
- `database-long`: 1000 s.

So a running job is never handed to a second worker.

**Permanent failures** fail immediately and are not retried: an impossible state transition, or
prizes that are not payable. They are logged and land in `failed_jobs`.

## 3. Scheduler

Every entry is safe to repeat. Each uses `withoutOverlapping()` and `onOneServer()`; on several
servers, the shared cache store must support locks (database or redis).

| Command | Every | Purpose |
|---|---|---|
| `competitions:tick` | 5 s | due room transitions; advance every live session; finalize ended sessions; heartbeat for `ops:status` |
| `competitions:recover` | 5 min | reconcile overdue or inconsistent competitions (section 5) |
| `payments:reconcile` | 5 min | verify pending deposits with the provider; expire abandoned ones |
| `prizes:distribute` | 5 min | pay prize entitlements whose conditions are now met |
| `notifications:reminders` | 1 min | starting-soon reminders, at most once each |
| `withdrawals:reconcile` | 10 min | confirm payouts in progress |
| `wallets:reconcile` | daily 03:00 | ledger and balance check; opens a risk flag on discrepancy |
| `retention:prune` | daily 03:30 | expired exports and old read notifications |
| `queue:monitor` | 1 min | logs a warning when a queue exceeds `QUEUE_MONITOR_MAX` |
| `queue:prune-failed --hours=720` | daily | keeps failed jobs for 30 days |

## 4. Observability

- **`php artisan ops:status`** (read-only) reports:
  - the age of the scheduler heartbeat;
  - waiting jobs and lag per queue;
  - the number of failed jobs;
  - live competitions;
  - sessions waiting more than a minute for results.

  It exits non-zero when unhealthy, so use it from an external monitor every minute.
- **Logs:**
  - every permanently failed job is logged as an error, with job, queue, attempts and error;
  - queue backlog is logged as a warning;
  - broadcast failures are logged as warnings;
  - all log lines carry the request or command correlation id.
- **`failed_jobs`:**
  - inspect with `php artisan queue:failed`;
  - retry with `php artisan queue:retry <id|all>`, which is safe for every job above;
  - discard with `php artisan queue:forget <id>`.

## 5. Recovery runbook

`php artisan competitions:recover [--dry-run]` works only from persisted timestamps and statuses.
It never creates or edits answers, scores or results.

| Check | Repair (same idempotent services as normal operation) |
|---|---|
| Room transitions due | applied through `RoomScheduler` |
| `session_overdue` (start, question open or close past due) | `CompetitionEngine::advance`. A late question opens with its **full** time; nothing is skipped. |
| `results_not_finalized` (session in scoring) | `ResultsFinalizer::finalize` (one result version) |
| `room_not_completed` (results stored, room still in progress) | room → completed |
| `entry_fee_not_refunded` (cancelled room, fee charged, no refund linked) | the refund path. An existing refund is **re-linked, never repeated**. |
| `aborted_session_room_active`, `live_room_without_session`, `seat_still_playing` | **reported only.** These cannot arise in normal operation (only from manual database edits), and an operator must decide. |

**Symptoms and fixes:**

| Symptom | Do |
|---|---|
| `ops:status`: scheduler "never ran" or stale | Check cron and `schedule:run`, then run `competitions:recover` once by hand. |
| A queue is lagging | Check that the workers are running (`supervisorctl status`). Competitions keep moving through the tick in the meantime. |
| Failed jobs | Read the error with `queue:failed`, fix the cause, then `queue:retry all`. |
| A player says the question "froze" | The browser polls; ask them to reload. `/play/{room}/state` returns the true question and deadline. Deadlines never reset. |

## 6. Lock ordering (deadlock avoidance)

Multi-row work always locks in this order, and the first statement inside a transaction is the
locking read (REPEATABLE READ snapshot rule):

1. **Competition work:** room → session → seats → prize distribution → payouts (by id) → wallets
   (by id). This covers the engine, finalizer, cancellation and refunds, prizes and recovery.
2. **Payments:** the payment row → wallets (by id).
3. **Withdrawals:** the withdrawal row → wallets (by id). Request pre-locks every wallet it touches
   in id order.
4. **Ledger postings** lock all their wallets in ascending id order.
5. **Answers take no row locks.** A unique index makes the first answer final across tabs and
   retries.

## 7. Deployment

**Live competitions.** Workers and the scheduler pause in maintenance mode (Laravel default).
- That is deliberate. If questions kept opening while players could not reach the site, players
  would lose answer time.
- After `up`, the engine catches up: an already-open question keeps its original deadline, and
  later questions open with their full time.

Still, **avoid maintenance mode while a competition is live**:
- check `ops:status` (live competitions);
- schedule deployments between competitions.

**Steps:**
1. Run `php artisan ops:status` and confirm there are no live competitions. If one is live, wait,
   or deploy without maintenance mode (code-only, backwards-compatible changes).
2. Upload the release next to the current one (atomic symlink switch), then:
   `composer install --no-dev --optimize-autoloader` and `npm ci && npm run build`.
3. If the release has migrations: `php artisan down --retry=60`. Migrations are additive and
   reversible by design.
4. Run `php artisan migrate --force`.
5. Switch the symlink, then run `php artisan optimize`. That rebuilds the config, route, view and
   event caches; run `optimize:clear` first if a cache is suspect.
6. Run `php artisan queue:restart`. Workers finish their current job, then exit; Supervisor starts
   them on the new code. Jobs are idempotent, so nothing is lost or doubled.
7. Run `php artisan reverb:restart` only when broadcasting code or config changed. Browsers
   reconnect, and poll meanwhile.
8. Run `php artisan up`.
9. **Verify:**
   - `php artisan schedule:list`;
   - `php artisan ops:status` within a minute (heartbeat fresh, queues draining);
   - `php artisan security:check --production`;
   - `php artisan competitions:recover --dry-run`.

**Supervisor example** (one program per worker type; the long worker is shown):

```ini
[program:mcq-worker-long]
command=php /var/www/mcq/current/artisan queue:work database-long --queue=long --timeout=900 --max-time=7200
numprocs=1
autostart=true
autorestart=true
stopwaitsecs=960        ; longer than the job timeout, so a restart never kills a running export
user=www-data
```

`stopwaitsecs` must exceed each worker's `--timeout`:
- **engine worker:** 30 s timeout, `stopwaitsecs=60`;
- **default worker:** 60 s timeout, `stopwaitsecs=90`.
