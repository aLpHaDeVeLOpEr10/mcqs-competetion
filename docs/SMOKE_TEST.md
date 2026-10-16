# Manual Smoke Test

Run this after every deployment.
- **Staging:** run every row.
- **Production:** run only the rows marked **P**.

**No real money is processed for a smoke test:**
- Payment, prize and withdrawal flows use the simulated gateway, **on staging only**
  (`PAYMENT_GATEWAY=fake`, `FAKE_GATEWAY_ENABLED=true`, a test secret, and the compliance gate
  approved with a test reference). The fake gateway refuses to run in production.
- In production, verify instead that those features are **unavailable** while the launch gate is
  locked.

**Setup:**
- Use dedicated test accounts (`smoke+player1@…`, `smoke+player2@…`) and one staff account per role.
- Record the date, release (commit), tester and result for every row.
- A failed row stops the release until it is explained.

| # | Area | Steps | Expected | P |
|---|---|---|---|---|
| 1 | Homepage | Open `/` on desktop and on a phone | Loads over HTTPS; no console errors; no CSP violations; featured rooms are correct | P |
| 2 | Health | `curl -sI https://<host>/` and `/up`; `php artisan ops:status` | 200; CSP, HSTS, `X-Frame-Options: DENY`; scheduler heartbeat < 30 s; no problems | P |
| 3 | Registration | Register `smoke+playerN`; open the verification email | Email arrives; the link verifies; a weak or breached password is refused | P |
| 4 | Login / logout | Log in, log out, log in with a wrong password ×6 | The session ends on logout; the 6th attempt is throttled; no account enumeration | P |
| 5 | Password reset | Request a reset; use the link; reuse the link | Reset works once; the second use is refused; other sessions are ended | P |
| 6 | Admin access | As a player, open `/admin`; as each staff role, open Admin | Player: 403. Each role sees only its own sections (ADMIN.md matrix) | P |
| 7 | Question creation | Content manager creates a question with an image and 4 options | Saved; the image is re-encoded; the answer key is visible only to content staff | |
| 8 | Quiz creation | Build a paper of 5 or more questions; publish | Publish validation passes; the paper is locked once used | |
| 9 | Room creation | Competition manager creates a free fill room (min = max = 2), publishes | It appears on `/competitions` with correct capacity and start rule | |
| 10 | User join | Player 1 and player 2 join | Seats are confirmed; the room fills, a countdown starts; a third user sees "Full" | |
| 11 | Wallet | Open Wallet and Transactions as a player | Balance and history are correct; no other user's data; amounts in Rs with paisa | P |
| 12 | Test-mode payment | **Staging:** deposit Rs 500 through the fake checkout; deliver the callback twice. **Production:** open Deposits | **Staging:** one credit, the payment shows Succeeded; `finance:reconcile` reports no differences. **Production:** deposits are unavailable | P (production check) |
| 13 | Live competition | Both players open the live screen, and answer from two tabs at once; one player reloads mid-question; stop Reverb for 30 s | One answer per question; "Your answer" shown; the deadline is unchanged after reload; the "Reconnecting" banner appears, and polling keeps the game going | |
| 14 | Result | Wait for the end | Results are published; ranks and tie-break match RESULTS.md; correct answers are revealed only now | |
| 15 | Prize | **Staging:** paid room with a prize tier (gate approved for testing) | The winner's wallet is credited once; the payout shows Paid; `prizes:distribute` twice changes nothing | |
| 16 | Refund | **Staging:** paid room, 1 player joins, staff cancel the room | The entry fee is refunded once; the room is Refunded; the notification arrives | |
| 17 | Notifications | Check the bell, the notifications page and email for rows 10–16 | Each event is notified once, with no duplicates | |
| 18 | Withdrawal workflow | **Staging:** add a payout account (password step-up), request a withdrawal, review and approve as finance (four-eyes), simulated payout | Funds are held at request; paid once; a rejection releases once; masked account everywhere. **Production:** withdrawals are unavailable | |
| 19 | Reports | Finance manager: Reports overview, Competition report, Reconciliation; export CSV | Figures match rows 12–18; reconciliation shows 0 differences; the export downloads (queued) | P (open pages) |
| 20 | Recovery | `php artisan competitions:recover --dry-run`; `php artisan finance:reconcile`; `php artisan wallets:reconcile` | Nothing to recover; reconciled; ledger reconciled | P |
| 21 | Security | `/.env`, `/storage/logs/laravel.log`, `/vendor/` | 404 | P |
| 22 | Launch gate | Admin → Settings → Real-money launch gate | Production: **LOCKED**, and lists the unconfirmed requirements (until the legal sign-off) | P |
