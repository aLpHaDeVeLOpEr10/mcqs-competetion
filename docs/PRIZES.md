# Prize Distribution and Winner Payouts

Prizes are paid as **internal wallet credits** through the ledger. External cash withdrawal is
not part of this system. Payouts are **off by default** (`PRIZE_PAYOUTS_ENABLED=false`): while
off, every entitlement is recorded and kept `held`.

## Prize structures (set per room, locked when the room is published)

| Mode | Defined by | Prize per position |
|---|---|---|
| `fixed` | tiers of rupee amounts, for example #1 Rs 1,000, #2–3 Rs 250 | the configured amount |
| `percentage` + `guaranteed` pool | a fixed pool amount, plus a percentage per position | `floor(pool × % )` |
| `percentage` + `entry_fees` pool | a platform fee %, plus a percentage per position | `floor(pool × % )`, where pool = net entry fees − platform fee |

**The pool is never "all entry fees".** It is modelled separately, from ledger records, when
results are finalized:

| Figure | Source |
|---|---|
| Gross entry fees | `entry_fee` ledger debits for the room's seats |
| Refunds | `entry_refund` ledger credits for those seats |
| Net entry fees | gross − refunds |
| Platform amount | `floor(net × platform_fee %)`. Only for the `entry_fees` basis; rounded down in the players' favour. |
| Prize pool | as in the table above |
| Tax withheld | per payout, `floor(prize × PRIZE_TAX_WITHHOLDING_BP / 10000)`. **0 until a legal requirement is confirmed by finance.** |

**Frozen rules.** The rules are frozen into the competition session when it starts
(`config.prize_rules`). The finalized calculation (pool figures, resolved tiers, tie lines) is
stored in `prize_distributions.calculation` and in the result version.

## Ties and rounding

These follow the Phase 08 policy (see RESULTS.md):
- Players sharing a rank occupy consecutive places. The prizes of those places are **pooled and
  split equally**, with each player receiving `floor(pool ÷ players)`.
- The remainder, percentage rounding, ineligible players and unfilled places are **unallocated**.
  This money stays in the prize pool and is never given to an arbitrarily chosen player.
- Each payout stores its rule: places covered, the amount per place, tied players, the group pool,
  each player's share and the remainder.

## Payout states

| State | Meaning |
|---|---|
| `pending` | entitled, waiting for the conditions below |
| `approved` | finance approved (rooms that require review) |
| `processing` | claimed for payment; a crash leaves it here and the next run finishes it |
| `paid` | credited; linked to its ledger transaction. **Immutable** (database trigger). |
| `held` | blocked by a stated reason: payouts disabled, open dispute, awaiting review, winner's account suspended or closed, or held by staff |
| `failed` | the ledger refused the credit; retried only by staff |
| `reversed` | a paid prize taken back by a ledger reversal (final) |
| `cancelled` | the entitlement disappeared after a results correction (final) |

## PrizeDistributionService

`distribute(session)` runs:
- after finalization and publication (`ResultsFinalizer`);
- every 5 minutes from `prizes:distribute`;
- on staff actions.

It is idempotent: calling it any number of times, including from 6 processes at once
(`ConcurrentDistributionTest`), credits each winner once.

1. **Claim.** One transaction. Lock the room, then the session, then the distribution; then lock
   the payouts by primary key (range locks would deadlock with step 2). Then check eligibility
   (`blocker()`):
   - payouts are enabled;
   - the room is **completed** (cancelled or aborted competitions have no distribution);
   - the session is completed and its results are **published**;
   - the distribution matches the latest result version (rankings frozen);
   - no **open dispute** (if `PRIZE_HOLD_WHILE_DISPUTED=true`);
   - finance **approval** if the room requires review.

   If blocked, payouts become `held` with the reason. Otherwise, eligible payouts become
   `processing`, and the platform share is moved once (`platform_fee`, prize pool → platform
   revenue, key `platform_fee:{session}`).
2. **Pay.** One transaction per payout. Lock order: distribution → payout → wallets.
   - Post one balanced ledger transaction of type `prize_payout`, keyed by the payout's unique
     `idempotency_key` (`prize:{session}:{seat}`):
     - prize pool −gross;
     - winner +net;
     - tax withholding +tax (when configured).
   - Link it, mark `paid`, and audit `prize.paid`.
   - A failure rolls that payout back (it stays `processing`). A ledger refusal marks it `failed`.
3. **Settle.** The distribution becomes `paid` when nothing is outstanding.
4. **Notify.** After the commits, `PrizeCredited` notifications (database and mail) go to the
   winners. A notification failure never affects the credit.

**Exactly once:** this relies on the unique ledger key, the unique `room_participant_id` per
payout, the unique `ledger_transaction_id`, the payout row lock with a status check, and the
immutability trigger.

**Corrections.** Results corrections are refused while any payout is `processing` or `paid`. To
correct after payment, reverse the paid prizes first. A reversed payout is never re-created
automatically; re-awarding requires a wallet adjustment.

## Screens

- **Player results page:** rank, correct answers, correct-answer time, prize entitlement, and the
  payout state. A paid prize links to its wallet transaction, which appears as "Prize" in the
  wallet history.
- **Admin → Prize payouts** (`finance.view`):
  - a list with competition, winner, rank, prize, credited amount, state, ledger reference and
    time;
  - a page per competition with the pool figures and conditions, plus per-payout score, timing,
    rule, tax, ledger links and timestamps.
- **Actions** (`prizes.manage`: finance manager and super admin, not admin) each need a reason
  and a confirmation, and are audited:
  - approve a review;
  - run payment now;
  - hold, release or retry a payout;
  - reverse a paid prize. This is a ledger reversal and needs the winner's balance.

  Amounts can never be edited.

## Configuration

| Variable | Default | Meaning |
|---|---|---|
| `PRIZE_PAYOUTS_ENABLED` | `false` | credit prizes to wallets |
| `PRIZE_HOLD_WHILE_DISPUTED` | `true` | hold a competition's payouts while a dispute is open |
| `PRIZE_TAX_WITHHOLDING_BP` | `0` | tax withheld per prize, in basis points (only when legally required) |
| `competition.prizes.min_correct_answers` | 1 | prize eligibility |
