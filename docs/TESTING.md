<!-- Test-suite guide: commands, where each area is covered, and what is not tested. -->
# Testing

## Commands

| What | Command |
|---|---|
| Everything (about 5 min locally; run it on its own) | `php artisan test` |
| Unit tests only | `php artisan test tests/Unit` |
| Competition simulations A–J and the invariant checker | `php artisan test tests/Feature/Simulation` |
| A single area | `php artisan test tests/Feature/Withdrawals` (any folder or file) |
| Code style | `vendor/bin/pint --test` |
| Load burst (local `_load` database only) | `php tests/Load/answer-burst.php run 1000 16` (see LOAD_TESTING.md) |

**Test database.** Tests use the MariaDB database `mcq_competition_test`. `Tests\TestCase`
refuses to run against any database not ending in `_test`.

**Two kinds of database isolation:**
- **Most tests** run inside a rolled-back transaction (`RefreshDatabase`).
- **The concurrency tests** use `DatabaseTruncation`. They start real parallel PHP processes on
  their own connections (`tests/Support/concurrent-*.php`), which need committed data.

## Coverage map

| Area | Where |
|---|---|
| Money arithmetic and parsing | `Unit/Support/MoneyTest` |
| Ledger validation; ledger behaviour | `Unit/Wallet/LedgerPostingValidationTest`; `Feature/Wallet/*` (including real concurrent debits) |
| Scoring, ranking, tie-breaks | `Unit/Results/ScoringPolicyTest`, `Feature/Results/*`, Simulation B and C |
| Prize calculations | `Unit/Results/PrizeCalculatorTest`, `Unit/Prizes/PrizePoolCalculatorTest`, `Feature/Prizes/*` |
| Room and competition state transitions | `Unit/Enums/StateTransitionTest`, `Feature/Rooms/RoomLifecycleTest`, `Feature/Engine/CompetitionEngineTest` |
| Payment verification; refund eligibility | `Unit/Payments/PaymentVerificationTest`; `Unit/Payments/EntryRefundPolicyTest` |
| Registration and login | `Feature/Auth/*` |
| Questions, papers, rooms | `Feature/Content/*`, `Feature/Rooms/*` |
| Joining, entry fees, refunds | `Feature/Rooms/RoomEntryTest`, `PaidEntryTest`, `ConcurrentJoinTest`, Simulation F and G |
| Answers and live play | `Feature/Engine/*`, `Feature/Security/CompetitionIntegrityTest`, Simulation A, D, E |
| Results publication, prize credit | `Feature/Results/*`, `Feature/Prizes/*`, Simulation A and H |
| Deposits, withdrawals | `Feature/Payments/*`, `Feature/Withdrawals/*`, Simulation I |
| Notifications, reports | `Feature/Notifications/*`, `Feature/Reliability/RoomNoticeTest`, `Feature/Reports/*` |
| Authorization and least privilege | `Feature/Security/LeastPrivilegeMatrixTest`: guest, player, suspended, admin, finance and super admin across pages and actions. Also `Feature/Admin/AdminAccessMatrixTest`, `Feature/Security/HardeningTest` (an every-admin-route sweep) and `Feature/Account/AccessControlTest`. |
| Reliability and recovery | `Feature/Reliability/*`, Simulation J |
| Database integrity | `Feature/Database/*`: unique keys, foreign keys, append-only triggers, enum alignment, retention |
| Financial invariants | `Support/AssertsFinancialInvariants` (used after every simulation). Its own `InvariantCheckerTest` proves it catches corrupted data. |

## Simulations (`tests/Feature/Simulation`)

| | Scenario | What it proves |
|---|---|---|
| A | 10 players × 10 questions, all through HTTP | Same question, options and deadline for everyone; exact windows and gaps; 100 stored answers; correct scores, times, ranks 1–10 and prizes |
| B | equal correct counts | the faster total time on correct answers ranks higher |
| C | exact tie (fastest-correct policy) and equal scores (shared policy) | shared rank; tied prizes pooled and split; remainder unallocated |
| D | disconnect in Q4, reconnect in Q5 | lands on Q5 with the original deadline (no reset); earlier answers restored; Q4 cannot be answered late; Q4 counts as unanswered |
| E | answer after the deadline | inside the grace period accepted; 1 ms later refused and not scored |
| F | two users race for the last seat (separate processes, 3 rounds) | exactly one wins, capacity never exceeded, the loser is not charged |
| G | cancellation before and during play | each fee refunded exactly once, through every retry path |
| H | prize job runs twice, plus the scheduler | each prize credited once |
| I | payment callback delivered 5 times, plus reconciliation | one deposit credit |
| J | crash mid-transition, then while storing results | rolled back; the tick and recovery complete it; no invented answers |

## Not tested (external integrations)

- **Real payment providers** (JazzCash / Easypaisa / bank): no drivers exist yet; only the
  simulated gateway contract is tested.
- **Real payout providers:** none integrated.
- **Real email delivery** (SMTP / provider): mail is tested with Laravel's fakes. **SMS and push:**
  not implemented.
- **Reverb in tests:** the server process isn't exercised in automated tests (broadcasting is
  `null`, the auth endpoint is tested). It was checked manually in a browser in earlier phases.
- **Breached-password lookups** (`uncompromised()`, production only): an external API, not
  called in tests.
- **Browser behaviour:** checked with headless Chrome scripts in Phases 13, 17 and 19, not in
  the automated suite.
- **Multi-server behaviour:** `onOneServer`, a shared cache and load balancers.
