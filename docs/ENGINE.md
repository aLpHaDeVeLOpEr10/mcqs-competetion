# Live Competition Engine

## Running it locally (Windows / XAMPP)

The live engine needs **four** long-running processes, plus Vite while developing. Run each in
its own terminal from the project folder, with MySQL started in XAMPP:

| Process | Command | What breaks without it |
|---|---|---|
| Web server | `php artisan serve` (or Apache) | everything |
| WebSocket server | `php artisan reverb:start` (add `--debug` to see traffic) | instant updates. Browsers fall back to polling every 3 s and the competition still runs |
| Queue worker | `php artisan queue:work --queue=competition,default` (plus `php artisan queue:work database-long --queue=long --timeout=900` for exports and announcements) | precise timing. The scheduler still advances competitions within about 5 s. See [OPERATIONS.md](OPERATIONS.md) |
| Scheduler | `php artisan schedule:work` | recovery heartbeat, reservation expiry, scheduled starts and cancellations |
| Assets | `npm run dev` (or `npm run build` once) | page styling and scripts |

**Configuration.** Reverb was installed with `php artisan install:broadcasting --reverb`. Its keys
live in `.env` (`REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST=localhost`,
`REVERB_PORT=8080`, `REVERB_SCHEME=http`) and are mirrored into `VITE_REVERB_*` for the browser.
`BROADCAST_CONNECTION=reverb`. To create fresh keys elsewhere, run `php artisan reverb:install`.
Rebuild assets after changing any `VITE_*` value.

**Production.** Run `reverb:start` and `queue:work` under Supervisor (Linux), with
`* * * * * php artisan schedule:run` in cron. Put Reverb behind a TLS reverse proxy (wss) and set
`REVERB_HOST` / `REVERB_SCHEME=https` to the public host. Windows lacks `pcntl`, so production
should run on Linux.

## Timing policy: one shared global deadline per question

- When a question is scheduled, its planned `opens_at` is written to the snapshot row
  (`session_questions`). **Delivery is the real opening:** when the engine delivers it, it sets
  `opens_at = now`, `closes_at = now + time_limit_seconds` and `delivered_at = now`.
- Before delivery, nobody can see the question (the state endpoint requires `delivered_at`) or
  answer it. So if the engine runs late (worker down), the window starts when the question
  actually opens, and **every player keeps the full time limit**.
- Once delivered, the window **never moves**.
- Every participant has **the same window**. An answer is accepted **only if the server's receipt
  time** is within `[opens_at, closes_at + answer_grace_ms]`. The grace period (default 500 ms,
  `COMPETITION_ANSWER_GRACE_MS`) absorbs network latency for answers sent just before the deadline.
- Late delivery to one browser does **not** extend that player's time. The countdown in the
  browser is display only; it is computed from server timestamps plus a measured clock offset.
- Acceptance depends only on stored timestamps, never on when the engine processes the close.
  An answer arriving after the deadline is rejected even if the question hasn't been "closed" yet.

## The engine (`App\Services\Engine\CompetitionEngine::advance`)

`advance()` is the **only** thing that moves a competition. It takes row locks on the room and
then the session (the same order as `RoomEntryService`), performs every step that is due at
"now", and returns the next due moment. The steps are:

1. **Prepare** (room `starting`, session `pending` → `countdown`): verify the snapshot has
   questions, then record `starts_at`, `prepared_at` and the frozen `config` (timing policy, grace,
   gap, tie-break, fee, per-question limits and points). Broadcast `CompetitionStarting`.
2. **Start** (`countdown` → `in_progress` once `starts_at` arrives): expire unconfirmed
   reservations, turn every `joined` seat into `playing` (the participant list is locked from
   here), move the room to `in_progress`, and schedule question 1 to open now.
3. **Deliver** (open): once the planned `opens_at` arrives, fix the real window (now through
   now + limit), set `delivered_at`, and broadcast `QuestionOpened` (text and options only).
4. **Close**: once `closes_at + grace` passes, set `closed_at`, broadcast `QuestionClosed` and
   `CompetitionProgressUpdated` (aggregate counts only), then schedule the next question at
   `now + question_gap_seconds`.
5. **Finish** after the last question: session `scoring`, seats `finished`, broadcast
   `CompetitionCompleted`. The engine then runs `ResultsFinalizer`, which completes the session
   and room and publishes results (see RESULTS.md). The tick retries finalization if it fails.

**Two triggers call `advance()`:**
- the delayed `AdvanceCompetition` job, dispatched for the next due moment (precise timing);
- `competitions:tick`, scheduled every 5 seconds (recovery).

Both are idempotent, so duplicates, retries and overlapping runs are harmless. The `sync` queue
can't delay jobs, so no job is dispatched under it, and the tick alone drives competitions there.

## Answers (`AnswerService`, `POST /play/{room}/answers`)

The request carries only `question_id` and `option_id`. The server:

- records the receipt time **first**;
- requires a `playing` seat, a session `in_progress`, the question to be the **current** one, and
  the receipt time inside its window;
- requires the option to belong to that question (also enforced by a composite foreign key);
- derives `is_correct`, `points_awarded` and `response_time_ms` (receipt − `opens_at`) itself;
  anything the client sends for these is ignored;
- relies on the **unique (question, participant)** index for exactly one answer. Re-sending the
  same option returns `duplicate: true`; a different option returns 409 `already_answered`,
  because the first answer is final. This is verified with real parallel processes in
  `ConcurrentAnswerTest`.

Responses never contain correctness, points or timing.

**Rejection codes**

| Code | Status |
|---|---|
| `not_live` | 409 |
| `not_participant` | 403 |
| `unknown_question` | 422 |
| `out_of_sequence` | 409 |
| `not_open` | 409 |
| `closed` | 409 |
| `invalid_option` | 422 |
| `already_answered` | 409 |

## What players receive

**Channel:** `private-competition.{session}`. It is authorized by `App\Broadcasting\CompetitionChannel`
for active accounts holding a joined, playing or finished seat. It is a **private** channel, not a
presence channel, so members never see each other's data.

| Event | Payload (besides `session_id`, `server_now`) |
|---|---|
| `competition.starting` | `starts_at`, `question_count` |
| `question.opened` | id, position, total, text, options (id, label, text), image URL, points, limit, `opens_at`, `closes_at` |
| `question.closed` | question id, position, `closed_at`, `next_opens_at` |
| `competition.progress` | position, total, answered count, players count |
| `competition.completed` | status (`scoring` or `aborted`), end time |

**Never sent during the live competition:** correct answers, explanations, other players'
answers, scores, personal data or payment data. Images are served by `play.image` only to seated
players, and only after the question is delivered.

## Reconnects, refreshes and multiple tabs

`GET /play/{room}/state` returns the authoritative state:
- server time;
- session status and start time;
- the current question **only once it has opened** (or `next_opens_at` while waiting between
  questions);
- the player's own answer, if any.

The page loads with this state and re-reads it:
- after any event;
- on WebSocket reconnect;
- when the tab becomes visible or the browser comes back online;
- when a countdown reaches zero (throttled to once every 2 s);
- every 15 s while connected, and every 3 s while disconnected.

Deadlines come from the database, so a refresh or a second tab shows the same question, the same
remaining time and the same locked answer.

## Failure handling

| Failure | Behaviour |
|---|---|
| WebSocket server down | `EngineBroadcaster` logs a warning and the engine carries on. Browsers show "Reconnecting…" and poll every 3 s. |
| Browser disconnected or slow | The deadline is unchanged (global policy). On reconnect, the browser resyncs and sees the current question with its true remaining time. |
| Queue worker down or restarted | `competitions:tick` advances every live session within about 5 s. Jobs that run late or twice do nothing extra. |
| Application crash mid-step | Each `advance()` is one transaction, so state is either fully applied or not at all. The next tick or job continues from the stored state. |
| Long outage | The question that was open closes on the first run afterwards. The next question opens `gap` seconds after that, with its full time limit. **Questions are delayed, never skipped or shortened.** Answers that arrived during the outage were still judged by their receipt time. |
| No worker (tick only) | Steps run up to about 5 s late, so gaps between questions grow. Each window still starts at actual delivery, so answer time is never lost. **Verified live:** 5 × 5 s questions, no Reverb and no worker; every window was exactly 5 s from delivery, and the polling browser answered every question. |
| Room cancelled while live | The session is aborted, `competition.completed` is sent with status `aborted`, and answers are refused (`not_live`). |
| Snapshot missing questions, or no confirmed players at start | The room is cancelled and the session aborted. |
