# Development Workflow

## Prerequisites (verified on this machine, 2026-10-04)

| Tool | Version | Notes |
|---|---|---|
| PHP | 8.2.12 (XAMPP, ZTS x64) | Laravel 12 is the newest line supporting PHP 8.2 |
| Composer | 2.10.1 | |
| MariaDB | 10.4.32 (XAMPP) | `C:\xampp\mysql\bin\mysql.exe`, not on PATH |
| Node / npm | 24.16.0 / 11.13.0 | Vite 7, Tailwind 4 |
| Required PHP extensions | pdo_mysql, mbstring, openssl, bcmath, sodium, fileinfo, curl, zip | all present. `pcntl` is unavailable on Windows |

## First-time setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
# Start MySQL in the XAMPP control panel, then:
mysql -uroot -e "CREATE DATABASE mcq_competition CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                 CREATE DATABASE mcq_competition_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php artisan migrate
npm run build      # or: npm run dev
php artisan serve
```

`composer dev` runs the server, queue worker, log tail (Pail) and Vite together.

**Live competitions** need the WebSocket server, a queue worker and the scheduler running alongside the
web server: `php artisan reverb:start`, `php artisan queue:work`, `php artisan schedule:work`. See
[ENGINE.md](ENGINE.md) for what each one does and what happens if one is down.

## Migration workflow

- Schema changes go **only** through new migration files. Never edit a migration that
  has been run outside a developer's machine.
- Each migration must have a working `down()`, unless it's a destructive data
  migration. Those are documented in the file and need approval.
- Add FKs, unique indexes and NOT NULL in the same migration as the column.
  Integrity lives in the database, not only in validation.
- Never run `migrate:fresh`, `migrate:reset`, `db:wipe` or `migrate:rollback` against a
  shared or production database. Production deploys run `php artisan migrate --force` only.
- Review with `php artisan migrate --pretend` before running new migrations.

## Testing workflow

- Tests use the **MariaDB** database `mcq_competition_test` (see `phpunit.xml`). SQLite was
  rejected because it ignores `lockForUpdate()` and differs on constraints, so it can't
  prove the concurrency guarantees.
- `tests/TestCase.php` refuses to boot if the database name does not end in `_test`.
  This keeps `RefreshDatabase` away from the dev database.
- Commands:

```bash
php artisan test                     # full suite
php artisan test --filter=Wallet     # a subset
vendor/bin/pint --test               # code style check (vendor/bin/pint to fix)
npm run build                        # asset build check
```

- Definition of done for each phase: suite green, Pint clean, assets build, migrations
  run cleanly on a fresh `mcq_competition_test` and on the existing dev database.

## Mail configuration (accounts)

Account emails are: email verification, password reset, "account already exists"
(sent instead of revealing that an address is registered), welcome (when verification is
off), email-change confirmation and notice, and password-changed notice. They are sent
synchronously; consider queueing them in production (a queue worker must then run).

- **Local:** `MAIL_MAILER=log`. Emails, including verification and reset links, are written to
  `storage/logs/laravel.log`. Use the same host and port as `APP_URL` / `artisan serve`,
  because links are signed for the URL they were generated on.
- **Production:** set `MAIL_MAILER=smtp` (or a provider transport) with `MAIL_HOST`, `MAIL_PORT`,
  `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_SCHEME`, and a real `MAIL_FROM_ADDRESS` on a domain with
  SPF/DKIM configured. `APP_URL` must be the public HTTPS URL, because it is used in emailed links.
- **No mail delivery available:** set `ACCOUNT_EMAIL_VERIFICATION=false`. Registration still
  works, but password reset and email-change links cannot reach users until mail is configured.
- Reset links expire after `auth.passwords.users.expire` (60 minutes). Email-change links expire
  after `competition.accounts.email_change_link_minutes` (60).

## Environment-variable checklist

| Variable | Local value | Production note |
|---|---|---|
| `APP_NAME` | `"MCQ Competition"` | |
| `APP_ENV` | `local` | `production` |
| `APP_KEY` | generated | generate per environment, never commit |
| `APP_DEBUG` | `true` | **must be `false`** |
| `APP_URL` | `http://localhost:8000` (if using `artisan serve`) | real HTTPS URL |
| `DB_CONNECTION` | `mariadb` | `mariadb` or `mysql` |
| `DB_HOST` / `DB_PORT` | `127.0.0.1` / `3306` | |
| `DB_DATABASE` | `mcq_competition` | |
| `DB_USERNAME` / `DB_PASSWORD` | `root` / empty (**local only**) | dedicated least-privilege user with a strong password |
| `SESSION_DRIVER` | `database` | `database` or `redis`. Set `SESSION_SECURE_COOKIE=true` |
| `QUEUE_CONNECTION` | `database` | `redis` recommended |
| `CACHE_STORE` | `database` | `redis` recommended (atomic locks) |
| `MAIL_MAILER` | `log` | real SMTP/API provider |
| `COMPETITION_CURRENCY` | `PKR` | |
| `COMPETITION_CURRENCY_MINOR_UNITS` | `100` | |
| `COMPETITION_DISPLAY_TIMEZONE` | `Asia/Karachi` | storage stays UTC |
| `COMPETITION_PAID_ENTRIES_ENABLED` | `false` | enable only after legal sign-off |
| `PAYMENT_GATEWAY` | `disabled` | `fake` is refused in production |
| `BROADCAST_CONNECTION` | `reverb` | `reverb` |
| `COMPETITION_ANSWER_GRACE_MS` | `500` | latency allowance after each question deadline |
| `REVERB_APP_ID` / `REVERB_APP_KEY` / `REVERB_APP_SECRET` | generated locally by `install:broadcasting` / `reverb:install` | unique secrets per environment |
| `REVERB_HOST` / `REVERB_PORT` / `REVERB_SCHEME` | `localhost` / `8080` / `http` | public host behind a TLS proxy, `https` |
| `VITE_REVERB_*` | mirrors the above for the browser (rebuild assets after changes) | contains no secret |
| `ACCOUNT_EMAIL_VERIFICATION` | `true` (links in the log) | `true` with working mail; `false` only if mail is unavailable |
| `MAIL_MAILER` / `MAIL_*` | `log` | real SMTP or provider credentials (see Mail configuration) |
| `SESSION_SECURE_COOKIE` | unset | `true` (HTTPS only cookies) |
| `ROOM_RESERVATION_MINUTES` | `10` | how long a pending-payment seat is held |
| `CONTACT_RECIPIENT_EMAIL` | `support@mcq.test` (log mailer) | real support inbox. Empty = contact form shown as unavailable |
| Gateway credentials (JazzCash / Easypaisa) | not used | future phase. Stored only in env or a secret manager |
