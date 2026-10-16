# Scoring, Rankings, Results and Prize Entitlements

## Scoring policy `correct_count_v1` (`App\Services\Results\ScoringPolicy`)

| Rule | Definition |
|---|---|
| Points | Each **correct** counted answer = 1 point. Incorrect, unanswered, late, early and voided answers = 0. |
| Counted answer | Server receipt time is within `[opens_at, closes_at + grace]`, where `opens_at` is the question's actual opening (delivery) time and `grace` is the value frozen in the session config. Answers to voided questions never count. |
| Answer time | `received_at − opens_at` in milliseconds, from server timestamps only. |
| Correct-answer time | Sum of answer times over **correct** counted answers. This is not the competition duration, and wrong answers add no time. |
| Last-correct time | Milliseconds from the competition start (`started_at`) to the receipt of the player's last counted correct answer. |
| Correctness | Read from the frozen snapshot options (`session_question_options.is_correct`), never from the flag stored at submission and never from the client. |

The policy version is frozen into the session config when the competition is prepared, and it is
stored on every result version.

## Ranking and tie-breaks

| Order | Rule |
|---|---|
| 1 | More correct answers |
| 2 | Lower correct-answer time |
| 3 | Earlier last correct answer (only under the `fastest_correct` policy) |
| 4 | Still equal → **shared rank** (standard competition ranking: 1, 2, 2, 4) |

- The tie-break policy is set per room before publishing and frozen at preparation.
  `fastest_correct` applies steps 1–4; `shared` applies step 1 only.
- **No winner is ever chosen arbitrarily.** The participant id only stabilises display order and
  never decides a rank.
- Disqualified players are scored but not ranked.
- Players with no correct answers are ranked (they tie each other), but they are **not
  prize-eligible** (`competition.prizes.min_correct_answers = 1`). `is_winner` is set only for an
  eligible rank-1 player.

## Prize entitlements (`App\Services\Results\PrizeCalculator`)

- **Source:** the prize tiers **frozen at preparation** (`session.config.prize_tiers`). Later room
  edits are ignored.
- **Places:** ranked players occupy places 1…n in rank order. A group tied at rank r with k players
  occupies places r…r+k−1.
- **Shared ranks:** the prizes of every place the group occupies are **pooled and split equally**.
- **Rounding:** each player gets `floor(pool ÷ k)` in paisa. The remainder (fewer than k paisa) is
  **unallocated**. It is reported and never given to an arbitrarily chosen player.
- **Unallocated money** also includes ineligible groups and prize places with no player. The
  invariant `awarded + unallocated = configured total` is checked on every calculation.
- **Percentages:** since Phase 12, rooms can also define prizes as percentages of a guaranteed
  pool or of net entry fees (see [PRIZES.md](PRIZES.md)).
- **Recording:** entitlements are stored as `prize_distributions` and `prize_payouts` rows, with an
  audit snapshot. Wallet credits are made by `PrizeDistributionService` (PRIZES.md). While
  `PRIZE_PAYOUTS_ENABLED=false`, every payout is `held` with a reason.

## Finalization (`ResultsFinalizer`)

Triggered when the engine finishes the last question; `competitions:tick` retries it.

In one transaction, holding row locks on the room and the session, it:
1. calculates results from the persisted records (`ResultsCalculator`);
2. writes `participant_results`, prize entitlements, and an **append-only** `result_versions`
   row (the inputs, their sha256 hash, the ranking and the allocation);
3. marks the session `completed` and the room `completed`;
4. publishes, if `RESULTS_AUTO_PUBLISH=true` (the default). Otherwise an admin publishes from
   Admin → Results.

**Idempotent and concurrency-safe.** A completed session returns its existing version.
Six parallel processes finalizing at once produce exactly one version (`ConcurrentFinalizeTest`).
If the calculation fails, nothing is marked complete.

**Publication** broadcasts `results.published` (a link only) on the competition channel. The live
page then shows "View results".

## Results page (`/competitions/{room}/results`)

- **Hidden until publication** (404 before then).
- **Everyone sees** the ranking: display names, correct answers, correct-answer time and prize
  entitlement.
- **Participants also see** their own result and **their answers next to the correct answers and
  explanations**. This is the first moment correct answers are revealed. Participants can also
  raise a dispute.

## Corrections and disputes

**Corrections** (Admin → Results → a competition) require `results.manage` (admin and super
admin), a reason and a confirmation:
- **Void a question**, for example when the answer key was wrong. It no longer counts for anyone.
- **Disqualify a player.** They are scored but no longer ranked, and prizes are re-allocated.

How a correction is applied:
- Results are never edited by hand. A correction changes an input and **recalculates the whole
  competition as a new version**.
- Every earlier version stays in `result_versions`, which rejects updates and deletes at the
  database level.
- `results.corrected` is audited with the before and after versions, actor, reason and the linked
  dispute.
- Entitlements that disappear are marked `cancelled`, never deleted.
- **Blocked once any prize has been paid**, and the whole change rolls back.

**Disputes:**
- A participant may raise one while results are published and within
  `competition.results.dispute_window_days` (7). Only one open dispute per player.
- Staff either reject it with a written reply, or uphold it by applying a correction that
  references it. That marks the dispute resolved with the before and after versions.

## Configuration

| Variable | Default | Meaning |
|---|---|---|
| `RESULTS_AUTO_PUBLISH` | `true` | publish as soon as results are finalized |
| `PRIZE_PAYOUTS_ENABLED` | `false` | while false, entitlements are recorded as `held`; when true, they are credited to wallets (PRIZES.md) |
| `competition.prizes.min_correct_answers` | 1 | prize eligibility threshold |
| `competition.results.dispute_window_days` | 7 | how long players can dispute |
