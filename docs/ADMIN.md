# Administration and Access Control

## Roles and permissions

Roles are the `users.role` column. Permissions are defined in code, so every environment has the
same rules:

- `App\Enums\Permission`: the list of permissions.
- `App\Enums\UserRole::permissions()`: which role grants which permission.

Each permission is registered as a Gate with the same name (`AppServiceProvider::defineGates`),
so routes use `can:<permission>` middleware.

| Permission | Super admin | Admin | Content mgr | Competition mgr | Finance mgr |
|---|:-:|:-:|:-:|:-:|:-:|
| `admin.access` (dashboard) | ✓ | ✓ | ✓ | ✓ | ✓ |
| `users.view` | ✓ | ✓ | | | |
| `users.manage_status` | ✓ | ✓ | | | |
| `users.manage_roles` | ✓ | | | | |
| `content.view` (includes answer keys) | ✓ | ✓ | ✓ | ✓ | |
| `content.manage` | ✓ | ✓ | ✓ | | |
| `rooms.view` / `rooms.manage` | ✓ | ✓ | | ✓ | |
| `results.view` | ✓ | ✓ | | ✓ | ✓ |
| `results.manage` (finalize, publish, corrections, disputes) | ✓ | ✓ | | | |
| `finance.view` (payments, ledger, balances) | ✓ | ✓ | | | ✓ |
| `finance.adjust` (audited wallet adjustments; off unless `WALLET_ADJUSTMENTS_ENABLED`) | ✓ | | | | ✓ |
| `prizes.manage` (approve, hold, release, retry, reverse prize payouts) | ✓ | | | | ✓ |
| `announcements.manage` (send platform announcements) | ✓ | ✓ | | | |
| `withdrawals.review` / `withdrawals.approve` (withdrawal queue, decisions, payouts) | ✓ | | | | ✓ |
| `reports.view` / `reports.export` (analytics, competition and user reports, CSV exports) | ✓ | ✓ | | | ✓ |
| `risk.review` (fraud/abuse signals; review only, never automatic action) | ✓ | ✓ | | | ✓ |
| `audit.view` / `settings.view` | ✓ | ✓ | | | |

The same matrix is rendered on **Admin → Settings**. `content.manage` and `rooms.manage` are
defined, but no screens use them yet.

### Rules enforced on the server

- **Every admin route** checks its permission with middleware. Navigation hides links as a
  convenience only. `AdminAccessMatrixTest` checks every role against every page.
- **Permissions apply only to active accounts** (and verified ones, while email verification is
  on). Restricting a staff member removes their admin access; suspending them ends their sessions.
- **Status changes** (`UserPolicy::changeStatus`): never your own account, and only for accounts
  ranked strictly below yours (`UserRole::rank()`). An admin cannot suspend another admin or a
  super admin.
- **Role changes** (`UserPolicy::changeRole`): super admin only, never your own role. The last
  super admin cannot be demoted. This is checked under row locks in `UserAdministrationService`.
- **Both actions** require a reason and an explicit confirmation checkbox. They run in a
  transaction with an audit record. Suspending or closing an account revokes all of its sessions.

## Audit trail

`App\Services\Audit\AuditLogger` writes to `audit_logs`. Database triggers reject every UPDATE and
DELETE on that table. Recorded actions so far:

| Action | When |
|---|---|
| `user.status_changed` | status change (old/new status, reason) |
| `user.role_changed` | role change (old/new role, reason) |
| `auth.staff_login` | any staff member signs in |
| `category.*`, `question.*`, `questions.imported`, `paper.*`, `room.snapshot_created` | question bank and paper builder changes (see CONTENT.md) |

## What admins cannot do

- **Edit or delete ledger entries or audit records.** There are no routes for it, and the
  database refuses it.
- **Edit a wallet balance.** No such field exists. The only finance write is an audited ledger
  adjustment (`finance.adjust`, reason + confirmation, off by default). See [WALLET.md](WALLET.md).
- **Edit competition results.** The results section is read-only. Later corrections must be new,
  audited records that keep the original.
- **Change settings from the UI.** Settings are read-only and come from the environment. The
  contact address is masked.
- **See gateway payloads.** They are never displayed.

## Dashboard figures

All figures come from database queries (`App\Services\Admin\DashboardMetrics`). Money figures are
shown only to `finance.view` holders, as separate, non-overlapping amounts:

- gross deposits received
- withdrawals paid out
- gateway refunds
- entry fees charged
- entry fees refunded
- prizes paid
- platform revenue wallet balance
- outstanding (unpaid) prize payouts

None of these is labelled or treated as profit.

## Local staff accounts (DevelopmentSeeder, password `password`, local only)

| Email | Role |
|---|---|
| admin@mcq.test | Super admin |
| staff.admin@mcq.test | Admin |
| content@mcq.test | Content manager |
| competitions@mcq.test | Competition manager |
| finance@mcq.test | Finance manager |
| moderator@mcq.test | Content manager (converted from the former "moderator" role) |

## Not yet available (marked in the UI)

- payment operations
- editing settings
- password reset on behalf of users
- account deletion and data export
