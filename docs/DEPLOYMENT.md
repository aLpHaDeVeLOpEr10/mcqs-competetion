# Production Deployment

**Status: prepared, not performed.** No server access was provided, so nothing here has been run
against a real server. The configuration files and scripts in `deploy/` were written for the
target below and checked for syntax only. Rehearse the whole procedure on a staging server first,
and run the full test suite on the staging PHP and database versions.

## 1. Architecture

```
           Internet ── 443/80 ──▶ nginx (TLS, static files, request limits)
                                   │  /index.php ──▶ PHP-FPM 8.3 pool "mcq" (www-data, OPcache)
                                   │  /app, /apps ──▶ Reverb 127.0.0.1:8080 (WebSockets)  [Supervisor]
                                   ▼
   Supervisor: Reverb · 2 engine workers (queue "competition") · 2 default workers · 1 long worker
   cron: schedule:run every minute (competitions:tick every 5 s, recovery, reconciliation, …),
         backups, health check
                                   │
                 MariaDB 10.11 (127.0.0.1 only) ── nightly/6-hourly dumps ──▶ /var/backups/mcq ──▶ off-site
```

| Component | Choice | Notes |
|---|---|---|
| OS | Ubuntu 24.04 LTS | packages below are its defaults |
| Web server | nginx | `deploy/nginx/mcq.conf` |
| PHP | **8.3** FPM and CLI | Laravel 12 supports 8.2–8.4. Development and tests ran on 8.2.12, so run the suite on 8.3 in staging. |
| Database | **MariaDB 10.11 LTS** | Development and tests use MariaDB 10.4 with the `mariadb` driver. **MySQL 8 has not been tested** (it would need `DB_CONNECTION=mysql` and a full test run). |
| Cache, sessions, queues | database (default) | Redis is optional; see PERFORMANCE.md. It's required only for more than one Reverb server. |
| WebSockets | Laravel Reverb behind nginx | listens on localhost only |
| Processes | Supervisor (`deploy/supervisor/mcq.conf`) | or systemd (`deploy/systemd/`), not both |
| TLS | Let's Encrypt (certbot) | |
| Backups | `deploy/scripts/backup.sh` + `restore-test.sh` | §8 |
| Monitoring | `deploy/scripts/healthcheck.sh` + an external uptime check on `/up` | §11 |

**Sizing for a start:** 2–4 vCPU, 4–8 GB RAM, SSD. The engine is light; the load test with 1,000
simultaneous answers ran on one development PC (LOAD_TESTING.md). Database and PHP-FPM are the
first things to separate when scaling.

## 2. Server preparation

```bash
sudo apt update && sudo apt -y upgrade
sudo apt -y install nginx mariadb-server supervisor git unzip curl acl certbot python3-certbot-nginx \
    php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-gd php8.3-zip php8.3-bcmath php8.3-opcache
# Composer (verify the installer signature as documented on getcomposer.org), Node.js 20 LTS (NodeSource or nvm) for the asset build.
php -r 'var_dump(function_exists("imagewebp"), extension_loaded("pcntl"));'   # both must be true

# Firewall: only SSH and HTTP(S). Reverb (8080) and MariaDB (3306) are never exposed.
sudo ufw allow OpenSSH && sudo ufw allow 'Nginx Full' && sudo ufw enable
```

**PHP extensions used by the app:**
- **gd with WebP:** question images are re-encoded.
- **mbstring, pdo_mysql, openssl, curl, xml, fileinfo, zip** (for Composer).
- **pcntl** (CLI): queue job timeouts, and stopping Reverb and the workers gracefully.

## 3. Users, directories and permissions

| Account | Purpose |
|---|---|
| `deploy` | owns the code and runs `deploy.sh`. A member of the `www-data` group. Login by SSH key only. |
| `www-data` | PHP-FPM, queue workers, scheduler and Reverb. Reads code; writes only `storage/` and `bootstrap/cache/`. |
| `mcqbackup` | runs backups; reads `/etc/mcq/backup.cnf` and `shared/storage`. |

```bash
sudo adduser --disabled-password deploy && sudo usermod -aG www-data deploy
sudo adduser --system --group mcqbackup && sudo usermod -aG www-data mcqbackup
sudo mkdir -p /var/www/mcq/{releases,shared} /var/log/mcq /var/backups/mcq /etc/mcq /var/www/certbot
sudo chown -R deploy:www-data /var/www/mcq && sudo chmod 750 /var/www/mcq
sudo chown www-data:www-data /var/log/mcq && sudo chown mcqbackup:mcqbackup /var/backups/mcq && sudo chmod 700 /var/backups/mcq

# Persistent storage, writable by the web group only (setgid keeps the group on new files).
sudo -u deploy mkdir -p /var/www/mcq/shared/storage/{app/private,app/public,framework/{cache,sessions,views},logs}
sudo chown -R deploy:www-data /var/www/mcq/shared/storage
sudo find /var/www/mcq/shared/storage -type d -exec chmod 2775 {} +
sudo setfacl -R -d -m g:www-data:rwX /var/www/mcq/shared/storage    # new files stay group-writable

# deploy may reload PHP-FPM and nothing else as root:
echo 'deploy ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.3-fpm' | sudo tee /etc/sudoers.d/mcq-deploy && sudo chmod 440 /etc/sudoers.d/mcq-deploy
```

**Permission rules:**
- **Code** (each release): owned by `deploy`. Files 644, directories 755; readable by `www-data`, not writable by it.
- **Writable by `www-data`:** only `shared/storage` and each release's `bootstrap/cache`.
- **Never `chmod 777`.**
- **`.env`:** `deploy:www-data`, mode **0640**.
- **`/etc/mcq/*`** (credentials for migrations, backups and alerts): root-owned, 0640. Group `deploy` for `migrate.env`; owner `mcqbackup` and mode 0600 for `backup.cnf`.

**What nginx can never serve:** its root is `current/public`, so `.env`, `storage/`, `vendor/`,
`config/`, `database/`, `deploy/` and the source live outside the web root. Dotfiles and any
`.php` other than `index.php` are also refused.

## 4. Database

1. `/etc/mysql/mariadb.conf.d/60-mcq.cnf`:
   ```ini
   [mysqld]
   bind-address = 127.0.0.1
   character-set-server = utf8mb4
   collation-server = utf8mb4_unicode_ci
   innodb_buffer_pool_size = 1G          # about 50–60% of RAM on a dedicated DB host
   log_bin_trust_function_creators = 1   # only if binary logging is enabled (needed to create the append-only triggers)
   ```
2. `sudo mysql_secure_installation`.
3. Create the database and the three accounts from **`deploy/mysql/create-users.sql`**. Edit a
   private copy with generated passwords (`openssl rand -base64 33`), then shred it.

| Account | Rights |
|---|---|
| `mcq_app` | data only (SELECT/INSERT/UPDATE/DELETE…); no DDL |
| `mcq_migrate` | DDL and triggers; used only by `artisan migrate` during deployments; its credentials live in `/etc/mcq/migrate.env`, not in `.env` |
| `mcq_backup` | read-only, plus what dumps need |

**Safe migrations:**
- Every migration in this project is additive or reversible, and was run forward, back and
  forward again in development.
- `deploy.sh` takes a database dump before any pending migration, and runs migrations only in a
  maintenance window that it refuses to open while a competition is live.
- Append-only tables (ledger, audit, payment callbacks, result versions, snapshots) are protected
  by triggers. Never disable them to "fix" data; corrections are new records.

## 5. Environment

Start from **`deploy/env/production.env.template`** and place it at `/var/www/mcq/shared/.env`
(0640 `deploy:www-data`). **Never commit it or paste secrets into tickets or chat.** Keep a copy
of `APP_KEY` and the other secrets in your password manager or secret store:
- **`APP_KEY`** decrypts the stored payout account numbers;
- **if `APP_KEY` is lost**, those numbers are unrecoverable;
- **if `APP_KEY` is rotated**, they must be re-encrypted.

| Variable | Production value | How |
|---|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` | |
| `APP_KEY` | generated | `php artisan key:generate --show`, pasted into `.env` once |
| `APP_URL` | `https://<host>` | |
| `DB_*` | `mcq_app` account | §4 |
| `SESSION_SECURE_COOKIE` | `true` | |
| `REVERB_APP_ID/KEY/SECRET` | generated | `openssl rand -hex 16` / `-hex 32` |
| `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` | `<host>`, `443`, `https` | the public address, used by browsers and the app |
| `REVERB_SERVER_HOST`, `REVERB_SERVER_PORT` | `127.0.0.1`, `8080` | where the process listens |
| `REVERB_ALLOWED_ORIGINS` | `<host>` | **never `*` in production** |
| `MAIL_*` | your provider's SMTP | verification and password-reset emails depend on it |
| Real-money switches | all off | until the legal and provider decisions (PAYMENTS.md) |
| `LOG_LEVEL` | `info` | |

**Check before going live:** `php artisan security:check --production`. It fails on debug mode,
http, insecure cookies, simulated gateways, the `sync` queue, `array` stores, unrestricted Reverb
origins and unwritable storage. It prints statuses only, never values. `deploy.sh` runs it before
switching releases.

## 6. TLS and nginx

1. Install `deploy/nginx/mcq.conf` with the host name replaced. **For the first certificate,
   temporarily keep only the port-80 server block, without the redirect.**
2. `sudo certbot certonly --webroot -w /var/www/certbot -d <host>`.
3. Enable the full file (HTTPS, plus the redirect from HTTP), then `sudo nginx -t && sudo systemctl reload nginx`.
4. Certbot renews automatically (`systemctl list-timers | grep certbot`). After renewal: `--deploy-hook "systemctl reload nginx"`.

**What the vhost does:**
- serves `public/` only, with `index.php` as the single PHP entry point;
- caches hashed `/build` assets for a year, and other static files for a week;
- limits bodies to 4 MB (uploads are capped at 2 MB);
- applies generous per-IP flood limits (whole classrooms can share one IP; the app enforces the
  fine-grained limits);
- proxies `/app` and `/apps` to Reverb with WebSocket upgrade;
- passes `HTTPS=on` to PHP, so Laravel sends HSTS and secure cookies.

The application sends the CSP and the other security headers itself (SECURITY.md).

**Load balancer.** If one is ever placed in front of nginx, configure trusted proxies
(SECURITY.md M6). Not needed with nginx and PHP-FPM on the same host.

## 7. PHP-FPM and OPcache

1. `deploy/php/mcq-fpm-pool.conf` → `/etc/php/8.3/fpm/pool.d/mcq.conf`.
2. `deploy/php/99-mcq.ini` → both `/etc/php/8.3/fpm/conf.d/` and `/etc/php/8.3/cli/conf.d/`.
3. `sudo systemctl restart php8.3-fpm`.

**What these set:**
- OPcache is on, with timestamp checks off, so `deploy.sh` reloads PHP-FPM after every switch.
- `display_errors` is off.
- `open_basedir` is limited to the app.
- Upload limits match nginx.

## 8. Queue workers, Reverb and the scheduler

**Supervisor.**
1. `deploy/supervisor/mcq.conf` → `/etc/supervisor/conf.d/`, then `sudo supervisorctl reread && sudo supervisorctl update`.
2. Check with `sudo supervisorctl status 'mcq:*'`.

| Program | Command | Retries / timeout | Stop wait |
|---|---|---|---|
| `mcq-reverb` | `reverb:start` | restarts on exit | 15 s |
| `mcq-worker-engine` ×2 | `queue:work database --queue=competition` | 5 tries, 30 s | 60 s |
| `mcq-worker-default` ×2 | `queue:work database --queue=default` | 5 tries, 60 s | 90 s |
| `mcq-worker-long` ×1 | `queue:work database-long --queue=long` | 3 tries, 900 s | 960 s |

**Worker behaviour:**
- Each job class also sets its own `tries`, `backoff` and `timeout`. Permanent failures fail
  immediately into `failed_jobs`. Failed jobs are logged and pruned after 30 days.
- Workers recycle themselves (`--max-time`, `--memory`) to avoid leaks.
- After a deploy, `queue:restart` makes each worker finish its current job and exit; Supervisor
  starts it on the new code.

The systemd units in `deploy/systemd/` are equivalent; use one approach, not both.

**Scheduler.** Install `deploy/cron/mcq` → `/etc/cron.d/mcq` (owner root, mode 0644). Then
verify:

```bash
sudo -u www-data php /var/www/mcq/current/artisan schedule:list    # competitions:tick (5 s), competitions:recover (5 min), …
sudo -u www-data php /var/www/mcq/current/artisan ops:status       # "Scheduler … last ran Ns ago" must be < 30 s
sudo -u www-data php /var/www/mcq/current/artisan competitions:recover --dry-run
```

**Reverb security:**
- It listens on `127.0.0.1:8080` only.
- Browsers connect to `wss://<host>/app/...` through nginx.
- Only `REVERB_ALLOWED_ORIGINS` may connect.
- Private competition and user channels are authorized by `/broadcasting/auth` (seat and owner
  checks).
- Client events (whispers) are refused.
- After changing Reverb code or configuration: `php artisan reverb:restart`.

**Builds.** `VITE_REVERB_*` values are compiled into the JavaScript, so rebuild assets whenever
they change.

## 9. Backups

| What | How | Frequency | Retention |
|---|---|---|---|
| Database | `backup.sh database`: `mariadb-dump --single-transaction --triggers --routines` (no locks on players), gzip, optional `age` encryption | every 6 hours, plus before every migration | 6-hourly: 14 days. Pre-migration: 90 days. **Off-site** (`RCLONE_REMOTE`): keep 30 daily + 12 monthly, using the remote's lifecycle rules. |
| Uploads | `backup.sh files`: `storage/app/private` (question images). Exports are temporary and excluded. | nightly | 30 days local; off-site as above |
| Secrets | `.env` values, `APP_KEY`, DB/Reverb/mail credentials, the `age` private key | on every change | in a password manager or secret store, **not** in the backup files and **not** in git |
| Server configuration | `deploy/` in the repository plus `/etc/mcq` (without secrets) | on change | with the code |

**Restore test, monthly and after any change to backups.** Run `deploy/scripts/restore-test.sh`
on a staging or backup host. It:
- restores the newest dump into a scratch database;
- checks row and trigger counts;
- runs `wallets:reconcile` (the ledger must balance) and `competitions:recover --dry-run`
  against it.

Record the date and duration; the duration is your real recovery time. A backup that has never
been restored is not a backup.

**Restore for real:**
1. Maintenance mode on.
2. Restore the chosen dump into the production database, as `mcq_migrate`, with the `mcq_migrate`
   definer account existing.
3. Restore `storage/app/private` from the files archive.
4. `php artisan competitions:recover`, then `wallets:reconcile`.
5. `up`.
6. Payments and withdrawals made after the dump must be reconciled with the provider
   (`payments:reconcile`, `withdrawals:reconcile`, the provider's own reports).

## 10. Monitoring

| Signal | Source | Alert |
|---|---|---|
| Site or database down | external uptime monitor on `https://<host>/up` (it also queries the database) plus `healthcheck.sh` | immediately |
| HTTP 5xx / error spikes | `/var/log/nginx/mcq.error.log`, `storage/logs/laravel-*.log` (daily, 30 days) | forward to central logging (journald/rsyslog → your log service); alert on error rate |
| Queue failures, backlog, lag | `ops:status` (queue sizes and lag), the `JobFailed` log line, `queue:monitor` (`QueueBusy` warnings) | `healthcheck.sh` |
| Scheduler stopped | `ops:status`: `competitions:tick` heartbeat older than 30 s | `healthcheck.sh` |
| Reverb or workers stopped | `supervisorctl status 'mcq:*'` | `healthcheck.sh` (the `www-data` user needs read access to the Supervisor socket: set `chown=root:www-data` and `chmod=0770` in `[unix_http_server]`) |
| Competition recovery | `competitions:recover --dry-run`: anything "reported" or "failed"; results stuck in scoring for more than 1 minute | `healthcheck.sh` (every 5 min) |
| Payment callback failures | `ops:status`: rejected callbacks in the last hour (10 or more is a problem); risk flags | `healthcheck.sh`; Admin → Risk review |
| Ledger integrity | `wallets:reconcile` daily; a discrepancy opens a high-severity risk flag | `ops:status` (open high-severity flags) |
| Disk space | `healthcheck.sh` (85% default) | |
| Database health | `/up`; MariaDB error log; slow query log (`long_query_time = 1`) | |
| TLS expiry | the certbot timer; most uptime monitors check certificates | 14 days before |

**Wiring alerts.** Set `ALERT_COMMAND` in `/etc/mcq/monitor.env`, for example a webhook to your
chat or e-mail. The health check alerts once per distinct problem, and again when resolved.

## 11. First deployment

1. Complete §2–§8 (no `current` release yet).
2. As `deploy`, with `REPO=<git url>` set: `bash deploy/scripts/deploy.sh <tag>`. On the very
   first run it:
   - creates all tables, using the migration account;
   - caches everything;
   - runs `security:check --production`;
   - switches `current`.

   The first run has no previous release, so there is no live-competition check.
3. Create the first super admin (production seeds no accounts):
   ```bash
   sudo -u www-data php /var/www/mcq/current/artisan users:create-super-admin
   ```
   - It prompts for the details. The password is entered without echo and checked against the
     production password policy, which includes the breached-password lookup.
   - The account is audited.
   - The command refuses once a super admin exists. Further staff roles are granted in Admin →
     Users.
4. Start the processes: `sudo supervisorctl start 'mcq:*'`. Install the cron file.
5. Run the verification checklist (§14).

## 12. Repeatable deployment checklist

`deploy/scripts/deploy.sh <git-tag> [--restart-reverb] [--allow-during-live]` automates steps 2–9.

1. **Release:** tag a commit that passed the full test suite and `vendor/bin/pint --test`. Read
   its migrations.
2. **Code:** a fresh clone into `releases/<timestamp>`; link the shared `.env` and `storage`.
3. **Dependencies:** `composer install --no-dev --prefer-dist --optimize-autoloader`.
4. **Frontend:** `npm ci && npm run build` (uses the production `VITE_*` values); `node_modules`
   is removed afterwards.
5. **Live competitions:** `ops:status --live` counts competitions starting or in progress.
   - **No migrations pending:** no maintenance window is needed; the code switch is atomic.
   - **Migrations pending and a competition live:** the script **stops**. Workers and the
     scheduler pause in maintenance mode, and players couldn't answer. Deploy afterwards, or
     deliberately pass `--allow-during-live`; timing resumes from stored deadlines (OPERATIONS.md).
6. **Migration (only if pending):** database backup → `artisan down --retry=60` → `artisan migrate
   --force`, run as `mcq_migrate`.
7. **Caches:** `artisan optimize` (config, routes, events, views), then
   `security:check --production`, which must pass.
8. **Switch:** atomic symlink `current → new release`; `systemctl reload php8.3-fpm`.
9. **Restart:** `queue:restart` (graceful); `reverb:restart` only with `--restart-reverb` (when
   broadcasting code or Reverb configuration changed); `artisan up` if maintenance was on.
10. **Smoke tests** (in the script):
    - `GET /up`, `/` and `/competitions` return 200;
    - `competitions:recover --dry-run`;
    - after 65 s, `ops:status` shows a fresh heartbeat, draining queues and no problems.
11. **Manually:**
    - log in as staff, open Admin → Dashboard and Risk review;
    - open a competition page;
    - check a live competition if one is running;
    - watch `storage/logs` and the nginx error log for 10 minutes.
12. **Old releases:** the last 5 are kept.

## 13. Rollback

**Code rollback** (seconds): `deploy/scripts/rollback.sh` points `current` at the previous release,
reloads PHP-FPM, restarts workers and checks `/up`.
- Migrations are additive, so the previous code normally runs on the newer schema.
- Run `reverb:restart` if the Reverb code differed.

**Schema rollback** (rare, and only if a migration itself is wrong):
1. Maintenance mode on.
2. `php artisan migrate:rollback --step=<n>`, as `mcq_migrate`, **only if** the migration's
   `down()` is safe for the data written since.
3. Otherwise, restore the pre-migration dump (§9). Then reconcile with payment and payout
   providers for anything after the dump.
4. Roll back the code. `up`.

**Never edit append-only or financial tables to undo a deployment.** Corrections are new,
audited records.

## 14. Post-deployment verification

- [ ] `php artisan security:check --production`: all required checks `ok`
- [ ] `php artisan about`: environment production, debug off, config/routes/views/events cached
- [ ] `https://<host>/up` returns 200; HTTP redirects to HTTPS; the certificate is valid
- [ ] response headers include CSP, HSTS, `X-Frame-Options: DENY` and `X-Content-Type-Options` (`curl -sI https://<host>/`)
- [ ] `https://<host>/.env`, `/storage/logs/laravel.log` and `/index.php.bak` all return 404
- [ ] `supervisorctl status 'mcq:*'`: all RUNNING
- [ ] `ops:status`: scheduler heartbeat < 30 s, queues draining, no problems
- [ ] a browser on the live screen shows "Live" (a WebSocket through `wss://<host>/app/…`), with no CSP errors in the console
- [ ] email works: a password reset arrives
- [ ] the first backup exists and **a restore test passed**
- [ ] the external uptime monitor and `ALERT_COMMAND` deliver a test alert
- [ ] `ufw status`: only 22, 80 and 443 open; `ss -tlnp` shows 8080 and 3306 on 127.0.0.1 only

## 15. Known gaps before a real launch

- **Untested on Linux:** the deployment scripts and server configuration have not been executed
  (no server access). Rehearse on staging.
- **Version mismatch:** tested on PHP 8.2 and MariaDB 10.4 (Windows/XAMPP). Production targets
  PHP 8.3 and MariaDB 10.11, so run `php artisan test` on staging.
- **Open security items:** trusted proxies (if a load balancer is ever added) and 2FA for staff
  remain open (SECURITY.md M6, M7).
- **Real money:** no real payment or payout provider is integrated. Real money must stay disabled
  until the legal and provider decisions are made.
