# Player Dashboard (Phase 13)

All pages are under `/account` and need a signed-in user. Every query starts from that user's own
seats, wallet, payments or notifications (`App\Services\Account\PlayerDashboard`). No page takes a
user, wallet or seat id from the request, so another player's data cannot be reached.

| Page | Route | Shows |
|---|---|---|
| Overview | `account.show` | See the list below. |
| My competitions | `account.competitions?tab=` | Cards in four tabs: upcoming, live, completed, cancelled (cancelled also covers rooms you left or whose reservation expired). Each card shows the room, entry fee, players and capacity, start, room status, your participation, and your result and prize once published. |
| History | `account.competitions.history` | A paginated table of finished competitions: date, entry fee, correct answers, score, correct-answer time (the first tie-breaker), rank, prize, and result status. |
| Competition detail | `account.competitions.show/{room}` | Your entry (fee charged, refund, references), your result, and your own answers. Returns 404 unless you hold a seat in that room. |
| Wallet | `account.wallet` | Balance, money in and out by kind (deposits, entry fees, refunds, prize credits, withdrawals), a withdrawal shell (not available yet), and recent transactions. |
| Transactions | `account.transactions?type=` | Your ledger history, filterable by type. |
| Deposits, profile, security | (earlier phases) | Unchanged. The profile edits only the name and display name; role, status and email are protected on the server. |
| Notifications | `account.notifications` | Your in-app notifications (for example prize credits), with "mark all as read". |

**The overview shows:**
- wallet balance;
- the live competition, if any, with a link to enter it;
- upcoming competitions;
- completed competitions and wins;
- prizes received;
- prize history;
- recent transactions;
- notifications.

There are no vanity metrics. Wins count only first places in **published** results. Prizes
received are **paid** payouts only.

**Results visibility.** Before results are published, a player sees only what they submitted
and the time taken ("Submitted"). Correctness, correct answers, explanations, rank, score and
prize appear only after publication.

The review is built by `App\Services\Results\AnswerReview`. It is also used by the public results
page, and reads only the given seat's answers.

**Responsive layout.** Checked at 390 px (phone), 820 px (tablet) and 1440 px (desktop) with no
horizontal overflow. The account menu scrolls sideways on small screens, and wide tables scroll
inside their card.
