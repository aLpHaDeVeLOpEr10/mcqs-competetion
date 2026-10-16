# Reports, Analytics and Audit Logs

## Access

| Page | Permission | Roles |
|---|---|---|
| Reports overview (activity) and the competition report | `reports.view` | super admin, admin, finance manager |
| Money figures on the overview; financial reports and reconciliation | `reports.view` + `finance.view` | super admin, admin, finance manager |
| User report (participation, wins, account state) | `users.view` | super admin, admin |
| …its financial activity / audit history sections | + `finance.view` / `audit.view` | |
| CSV exports | `reports.export` + the report's own permission | super admin, admin, finance manager |

## Periods

**Choices:** Today, Last 7 days, Last 30 days, or a custom range (at most 366 days). The default
is Last 30 days.
- **Whole days in Pakistan time.** Dates are interpreted as whole days in the display timezone
  (`Asia/Karachi`), then converted to a UTC half-open interval `[from, to)`. All database times
  are UTC.
- **Example:** a sign-up at 00:30 PKT on 5 October (19:30 UTC on 4 October) counts as
  **5 October**.

## Metric definitions (all SQL aggregates)

| Metric | Definition |
|---|---|
| Registered users | accounts created in the period |
| Active users | distinct users who took a seat in a competition **or** had money move in their wallet in the period |
| Rooms created | rooms created in the period |
| Competitions completed | sessions whose results were finalized (`scored_at`) in the period |
| Competition entries | seats confirmed in the period |
| Gross entry fees / refunds | ledger `entry_fee` debits and `entry_refund` credits on player wallets |
| Prize credits | ledger `prize_payout` credits on player wallets (net of any tax withheld) |
| Deposits | ledger `deposit` credits, plus the count of successful payments |
| Withdrawals | amount paid (withdrawals paid in the period), and amount requested |
| Failed transactions | failed or expired payments, plus failed payouts; rejected withdrawals are shown separately |
| Administrative adjustments | net ledger `adjustment` movement on player wallets |
| Platform revenue | net movement into the `platform_revenue` account (platform share of entry fees, plus disclosed withdrawal fees). **This is the only revenue figure.** |

**None of these is profit.** Volumes such as deposits and entry fees are labelled as money
movements.

## Competition report

One row per room whose competition started (else was scheduled, else was created) in the period:
- room and session ids;
- status and entry fee;
- confirmed participants and capacity;
- **gross collected** and **refunds**, from the ledger entries of that room's seats;
- prize pool, from the distribution;
- winners (rank-1 prize-eligible players);
- start and end times.

Per-row figures are correlated SQL subqueries on indexed columns, so there are no N+1 queries.
Results are paginated 25 per page, with totals for the whole period.

## Financial reports

- **Lists:** deposits, entry fees, refunds, prize credits, withdrawals and adjustments. Each is
  paginated (30 per page), with totals for the whole period.
- **Reconciliation:**
  - **player wallets:** opening balance + movements by type = closing balance;
  - **records vs ledger:** for deposits, withdrawal holds, prizes and entry-fee refunds, the
    business records are compared with the ledger. A non-zero difference flags a mismatch to
    investigate;
  - **platform account balances**, which are counterparties and not profit;
  - **the all-time ledger check** (`wallets:reconcile`).

## Exports (CSV)

1. "Export CSV" queues `GenerateReportExport`. Nothing large runs in a web request.
2. The job streams rows in chunks of 1,000 into a private file (`storage/app/private/exports`),
   then records the row count. The request is audited (`report.exported`).
3. Every cell goes through `CsvWriter`: text starting with `= + - @`, tab or carriage return is
   prefixed with `'`, so spreadsheets never evaluate it. Numbers stay numeric.
4. **Download rules:** only the requester can download, only while they still hold the report's
   permission, and only for 7 days. The permission is re-checked when the job runs.
   `retention:prune` deletes expired files.

## Audit log

**Who writes it.** Every sensitive administrative or business action writes an `audit_logs`
record in the same transaction as the change. Covered actions:
- **Accounts:** status and role changes.
- **Content:** question and paper create, update, publish, unpublish, archive and restore; CSV
  imports.
- **Rooms and competitions:** room create, update and status changes (including cancellation);
  competition lifecycle steps.
- **Money:** entry-fee refunds; wallet adjustments; prize payout states; withdrawal states;
  payout account changes.
- **Results:** result finalization, publication and corrections; disputes.
- **Other:** announcements, report exports, staff sign-ins.

Settings are read-only, from the environment, so there are no runtime settings changes to audit.

| Field | Content |
|---|---|
| `actor_id` | the user (null = system: scheduler, queue, provider callback) |
| `action`, `auditable_type/id` | what happened and to which record |
| `old_values` / `new_values` / `metadata` | before/after and context. Credential-like keys (`password`, `token`, `secret`, `card`, `account_number`, `iban`, `cnic`, …) are replaced with `[removed]` by `AuditLogger` before storage. |
| `ip_address`, `user_agent` | the request origin |
| `request_id` | the correlation id (see below) |
| `created_at` | millisecond precision, UTC |

**Correlation id.** `AssignRequestId` gives every request an id: it reuses a well-formed incoming
`X-Request-Id` or generates a UUID, and returns it in the response header. The id goes into
Laravel's Context, so it is:
- on every log line;
- carried into queued jobs dispatched by the request;
- stored on audit records.

Console commands get their own id. In Admin → Audit, clicking a request id lists everything that
request did.

**Immutability.** `audit_logs` is append-only, enforced at three levels:
- database triggers reject UPDATE and DELETE;
- the `Immutable` model guard does the same in code;
- there are no edit or delete routes.

No role, including super admin, can change it through the application.

## Retention

| Data | Kept | How |
|---|---|---|
| Audit logs, ledger, payments, payment callbacks, withdrawals, prize payouts, result versions | **permanently** (append-only or never deleted) | Archival, if ever required by policy, is a DBA operation: export to cold storage, then remove the partition or rows with the triggers disabled. It is never done through the application, and must itself be recorded. |
| Read in-app notifications | 180 days (`RETENTION_READ_NOTIFICATIONS_DAYS`) | `retention:prune`, daily |
| CSV exports | 7 days | `retention:prune`, daily |
| Unread notifications | until read | |

## Performance

- **Indexes added for date-range queries:** `users.created_at`, `rooms.created_at`,
  `room_participants.confirmed_at`, `competition_sessions.scored_at`,
  `payment_transactions(type, status, completed_at)`, `withdrawals.paid_at`, and
  `audit_logs.request_id`.
- **Existing indexes used:** ledger `(type, created_at)`, plus the morph and foreign-key indexes.
- **Query style:** every figure is an aggregate and every list is paginated. Exports stream with
  `lazy()`. No report loads the ledger into memory.
