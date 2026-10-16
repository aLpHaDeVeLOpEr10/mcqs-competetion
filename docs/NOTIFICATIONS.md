# Notifications

## Design

- **Base class.** Every user-facing notification extends `App\Notifications\AppNotification`.
  It declares a category, a title, a body and a link.
- **`Notifier` service.** Services send notifications only through
  `App\Services\Notifications\Notifier`, which:
  - **sends after the database commit** (`afterCommit()`), so nobody is told about work that was
    rolled back;
  - **catches and logs every delivery failure**, so a notification can never undo a join, a
    payment, a result or a prize.

| Channel | When | How |
|---|---|---|
| `database` (in-app) | unless the user switched it off (locked on for security notices, prizes and wallet) | immediately (sync connection) |
| `broadcast` (real time) | with in-app, when `NOTIFICATIONS_BROADCAST=true` and a broadcaster is configured | immediately, on the **private** channel `App.Models.User.{id}` (authorised in `routes/channels.php`). Payload: **id, category, title, link only**. Never the body, amounts, balances, answers or personal data. |
| `mail` | verified address, `NOTIFICATIONS_MAIL=true`, and the user's preference for the category | **queued** on the default connection (needs `php artisan queue:work`), 3 tries with backoff |
| SMS / push (extension point) | add the channel name to `competition.notifications.extra_channels` and implement `to{Channel}()` on the notifications that should use it | not integrated |

## Events

| Notification | Category | Sent by |
|---|---|---|
| `RoomJoined` | competition | `RoomEntryService` (join confirmed, or reservation confirmed) |
| `CompetitionReminder` | competition | `notifications:reminders` (every minute) |
| `CompetitionStarted` | competition | `CompetitionEngine` (players who are playing) |
| `CompetitionCompleted` | results | `ResultsFinalizer`, when results are finalized but not auto-published |
| `ResultsReady` | results | `ResultsFinalizer` (auto-publish or manual publish) |
| `PrizeCredited` | prizes | `PrizeDistributionService` |
| `EntryFeeRefunded` | wallet | `WalletService::refundEntryFee` |
| `DepositConfirmed` / `DepositFailed` | wallet | `DepositService::settle` |
| `AccountStatusChanged`, `PasswordChanged` | security | `UserAdministrationService`, `SecurityController` |
| `AnnouncementPublished` | announcements | the `SendAnnouncement` job |
| `WithdrawalUpdated` | wallet | `WithdrawalService` (every state change) |
| `PayoutDestinationChanged` | security | `PayoutDestinationService` |

## Reminders

`notifications:reminders` runs every minute. Offsets come from `competition.notifications.reminder_minutes` (15 and 5).

- Only players with a confirmed seat are reminded.
- If the scheduler runs late, only the nearest due reminder is sent. For example, a player 3
  minutes before the start gets one "starts in 3 minutes" notice, not two.
- Each send claims the unique key `reminder:{room}:{start}:{offset}:{user}` in
  `notification_dispatches` first. A reminder can therefore never be sent twice, even when two
  scheduler runs overlap. A changed start time produces new keys.

## In-app interface

- **Header bell:** unread count and the 5 latest notifications. It updates live through
  `resources/js/notifications.js`, which listens on the private channel and inserts titles as text
  only.
- **`/account/notifications`:** all or unread, paginated (20 per page); open (marks it read and
  follows the link, but only links to this site); mark one; mark all.
- **`/account/notifications/preferences`:** in-app and email per category.
  - Security notices cannot be switched off, by any channel.
  - The in-app record of prizes, deposits and refunds cannot be switched off either.
- **Scoping:** every lookup goes through the signed-in user's own notifications. Another user's
  notification id returns 404.

## Announcements

- **Who:** Admin → Announcements requires `announcements.manage` (admin and super admin).
- **Validation:**
  - title of 5–120 characters and a body of 10–2000 characters, plain text (always escaped);
  - an audience: everyone who can sign in, players, staff, or the players of one published
    competition;
  - an explicit confirmation.
- **Recording:** the author is recorded and `announcement.created` is audited.
- **Delivery:** the queued `SendAnnouncement` job delivers it in chunks of 500. It claims
  `announcement:{id}:{user}` per recipient, so a retried job never notifies anyone twice.
  Suspended and closed accounts are skipped.

## Configuration

| Variable | Default |
|---|---|
| `NOTIFICATIONS_MAIL` | `true` (queued) |
| `NOTIFICATIONS_BROADCAST` | `true` (only when `BROADCAST_CONNECTION` is set) |
| `competition.notifications.reminder_minutes` | `[15, 5]` |
| `competition.notifications.extra_channels` | `[]` |
