# Competition Rooms, Entry and Scheduling

## Configuration (Admin → Rooms, requires `rooms.manage`)

| Setting | Notes |
|---|---|
| Title, description, category | Always editable (presentation only). If no category is set, the categories come from the paper. |
| Quiz paper | Must be **published**. When the room is published, the paper is frozen into an immutable snapshot (`PaperSnapshotService`). |
| Entry fee, currency | Entered in rupees and stored in paisa; parsing uses integer arithmetic only. A paid room can only be published while `COMPETITION_PAID_ENTRIES_ENABLED=true`. |
| Max / min players | Max 2–10,000. Min 1–max. |
| Start policy (`type`) | `scheduled`: start at the scheduled time. `fill`: start when full. `hybrid`: start when full **or** at the scheduled time, whichever comes first. |
| Registration window | Opens at `registration_opens_at` (empty = on publish). Closes at `registration_closes_at`, or the scheduled start if that is empty. Fill rooms **require** a closing time (their deadline). |
| Countdown | Seconds between the room filling and the start (fill and hybrid rooms). |
| Under-capacity policy | When the start time or deadline arrives before the room is full: `start_if_minimum` (start if at least `min` players are confirmed, otherwise cancel) or `cancel` (cancel unless every seat is filled). |
| Tie-break policy | `fastest_correct` (lower total response time on correct answers, then shared rank) or `shared`. Applied by the scoring phase. |
| Withdrawals | If allowed, players can leave until registration closes. The entry fee is refunded in full unless `ENTRY_FEE_REFUND_ON_WITHDRAWAL=false`. |
| Prize tiers | Rank ranges with an amount each. Ranks must start at 1 and continue without gaps, stay within capacity, and have positive amounts. |
| Cancellation | Cancelling releases every seat through `EntryPayment::release()`, which refunds every captured fee in full. Paid rooms then move to `refunded`. Seat rows are kept as history. |

**Publishing** (`RoomLifecycle::publish`) runs `RoomConfigValidator` first. The scheduled start or
deadline must be at least 5 minutes away. Publishing then takes the snapshot and moves the room to
`open`, or to `scheduled` if registration opens later.

**Locking:** after publishing, every critical setting (`Room::CRITICAL_ATTRIBUTES`) and the prize
tiers are locked. Edits to them are refused, and only the title, description and category can change.

## Room states

```
draft ─► scheduled ─► open ◄──► full ─► starting ─► in_progress ─► completed
  │          │          │        │         │             │
  └──────────┴──────────┴────────┴─────────┴─────────────┴─► cancelled ─► refunded
```

Every transition goes through `RoomLifecycle::transition()`. It checks `RoomStatus::allowedTransitions()`,
increments `lock_version` and writes a `room.status_changed` audit record.

**Not yet implemented:** moving a room from `starting` to `in_progress` (and on to `completed`)
belongs to the competition-engine phase. Until then, triggered rooms wait in `starting` with
`starts_at` set. A cancelled room whose seats had fees moves to `refunded` in the same transaction, once every fee is returned.

## Seats (`room_participants`)

| Status | Meaning | Counts toward capacity |
|---|---|---|
| `reserved` | seat held while an entry payment is pending; `reserved_until` set | yes |
| `joined` | confirmed seat (`confirmed_at` set) | yes |
| `withdrawn` | player left before registration closed | no |
| `expired` | reservation lapsed, or was released when the room started or was cancelled | no |
| `playing` / `finished` / `disqualified` | set by the engine and scoring phases | yes |
| `refunded` | the room was cancelled and the fee was returned (`refund_ledger_transaction_id`, `refunded_at`) | no |

There is one row per (room, user), enforced by a unique index. Rejoining after withdrawing or
expiring reuses the same row, and rows are never deleted.

## Atomic entry (`RoomEntryService`)

`join()` runs in a single transaction:

1. `SELECT … FOR UPDATE` on the room row. Every seat change for that room is serialized here.
2. Expire this room's lapsed reservations (lazy expiry), so abandoned holds never block a seat.
3. Re-run `JoinEligibilityService` against the locked state: room status, registration window,
   capacity, account status, verified email, paid-entry switch, existing seat.
4. **Idempotent:** if the user already holds a reserved or joined seat, that seat is returned
   unchanged.
5. Check capacity, write the seat, and call `EntryPayment::begin()`:
   - `Confirmed` → `joined`;
   - `Pending` → `reserved` for `ROOM_RESERVATION_MINUTES`.
6. Increment `participants_count` (the database CHECK `participants_count <= max_participants` is
   the last line of defence). Move the room to `full` when its last seat is taken, then evaluate
   the start policy.

`withdraw()`, `confirmReservation()` (for an asynchronous payment flow) and reservation expiry take the same
room lock. All timestamps come from the server.

**Entry payment interface** (`App\Contracts\Payments\EntryPayment`): bound to `WalletEntryPayment`
since Phase 11. See "Entry fees and refunds" below. `DeferredEntryPayment` (paid seats become
uncharged reservations) is kept for a possible asynchronous flow and for the reservation tests.

**Concurrency is tested with real parallel processes** (`ConcurrentJoinTest`):
- 14 PHP processes join a 5-seat room at the same instant: exactly 5 seats, 9 "full" refusals.
- 6 simultaneous joins by one user: exactly 1 seat.

## Scheduler (`rooms:advance`, every minute)

`RoomScheduler::advance()` finds candidate rooms and processes each one in its own locked
transaction. It is idempotent and safe if runs overlap (`withoutOverlapping`). For each room it:

1. **Expires lapsed reservations.** If the room was full, it reopens.
2. **Opens registration** for `scheduled` rooms whose opening time has passed.
3. **Applies the start policy** (`RoomLifecycle::evaluateStart`):
   - Fill and hybrid rooms with every seat **confirmed** → `starting`, with `starts_at` = now + countdown.
   - Scheduled and hybrid rooms at their start time, or fill rooms at their deadline:
     - if full, or the minimum is met under `start_if_minimum` → `starting`, after releasing any
       still-pending reservations (they don't count);
     - otherwise → `cancelled`, with the reason, e.g. "Not enough players: 2 confirmed, 3 required".

One failing room is reported and retried next minute without stopping the others.

## Entry fees and refunds (Phase 11)

**Paid join.** One transaction, holding the room row lock, then the seat lock, then the wallet locks:

1. Authenticate the user, then check account, room state, registration window, capacity and any
   existing seat. All of these run against the locked room; the page's counts are never used.
2. Write the seat as `reserved`, with the room's fee **frozen onto the seat** (`entry_fee_minor`).
3. `WalletEntryPayment::begin()` calls `WalletService::chargeEntryFee()`, which debits exactly the
   frozen fee to `prize_pool`:
   - balance, frozen-wallet and restricted-account checks run under the wallet row lock;
   - ledger key `entry_fee:{seat}:{joined_at}`;
   - the transaction is linked to the seat (`related`) with `room_id` in its metadata;
   - `entry_ledger_transaction_id` is written on the seat.
4. The seat becomes `joined` with `payment_reference` = the ledger reference, and the room count
   is incremented. The join is audited with the entry reference.
5. After commit, `RoomOccupancyChanged` is broadcast on the public channel `rooms.{id}`. It carries
   the count, capacity and status only.

If anything fails (insufficient balance, frozen wallet, a database error after the debit), the
whole transaction rolls back: no seat, no count change, no ledger rows. The player sees a plain
message ("balance is lower than the entry fee", "entry could not be completed… you were not
charged").

**Duplicate requests.** These are blocked at several levels:
- the room lock serializes joins, and a user who already holds a seat gets it back
  (`already`, no charge);
- `room_participants` is unique on (`room_id`, `user_id`);
- the ledger idempotency key is unique per seat and join;
- `entry_ledger_transaction_id` is unique.

A real-process test shows 6 simultaneous joins by one user charge once. A race of 6 players for the
final paid seat seats and charges exactly one.

**Free rooms** create no ledger rows at all (no misleading zero-value entries).

**Refund policy** (`EntryRefundPolicy`; always the full amount charged, by reversing the original
ledger transaction):

| Trigger | Refund |
|---|---|
| Room cancelled by staff | yes |
| Not enough players when the start policy runs | yes (the scheduler cancels the room) |
| Competition cancelled or aborted before valid completion (also while in progress) | yes |
| Player withdraws before the deadline (if the room allows withdrawal) | yes, unless `ENTRY_FEE_REFUND_ON_WITHDRAWAL=false` |
| Completed competition | never (completed rooms cannot be cancelled) |

Each refund:
- reverses exactly the original entry transaction (type `entry_refund`,
  `reverses_transaction_id` = the entry);
- sets `refund_ledger_transaction_id` and `refunded_at` on the seat;
- writes a `room.entry_refunded` audit record.

A fee can never be refunded twice: the reversal key `reversal:{entry id}` and the unique
`reverses_transaction_id` both prevent it, and repeated calls return the same refund.

A cancelled paid room's refunded seats move to `refunded`, and the room to `refunded`.

**Admin:** the room page lists each participant with the amount charged, the entry transaction
reference, the join time, the seat state and the refund state with its reference. There are no
routes that delete seats or ledger rows.
