#!/usr/bin/env bash
#
# Release-based deployment (docs/DEPLOYMENT.md §9). Run on the server as the "deploy" user:
#   deploy/scripts/deploy.sh <git-ref> [--restart-reverb] [--allow-during-live]
#
# Layout:  $BASE/releases/<timestamp>   one directory per release (code, vendor, built assets)
#          $BASE/shared/.env            the only .env (0640 deploy:www-data)
#          $BASE/shared/storage         persistent storage (uploads, logs, sessions, maintenance file)
#          $BASE/current -> releases/<timestamp>   switched atomically
#
# Requires: git, php8.3, composer, node 20+/npm, curl, flock; sudo rights for exactly
#   systemctl reload php8.3-fpm   (see docs/DEPLOYMENT.md §3)
# Migration credentials: /etc/mcq/migrate.env (DB_USERNAME / DB_PASSWORD of mcq_migrate).
#
# This script has not been run against a real server from this repository (no server access was
# provided). Rehearse it on staging first.

set -Eeuo pipefail

REF="${1:?usage: deploy.sh <git-ref> [--restart-reverb] [--allow-during-live]}"
shift || true
RESTART_REVERB=0
ALLOW_DURING_LIVE=0
for arg in "$@"; do
    case "$arg" in
        --restart-reverb) RESTART_REVERB=1 ;;
        --allow-during-live) ALLOW_DURING_LIVE=1 ;;
        *) echo "unknown option: $arg" >&2; exit 2 ;;
    esac
done

BASE="${BASE:-/var/www/mcq}"
REPO="${REPO:?set REPO to the git remote URL}"
APP_URL="${APP_URL:-$(grep -E '^APP_URL=' "$BASE/shared/.env" | cut -d= -f2-)}"
KEEP_RELEASES="${KEEP_RELEASES:-5}"
TS="$(date -u +%Y%m%d%H%M%S)"
RELEASE="$BASE/releases/$TS"
MAINTENANCE=0

log() { printf '[%s] %s\n' "$(date -u +%H:%M:%S)" "$*"; }
fail() { log "FAILED: $*"; exit 1; }

exec 9>"$BASE/.deploy.lock"
flock -n 9 || fail "another deployment is running"

on_error() {
    local code=$?
    log "Deployment stopped (exit $code). The live site still runs the previous release unless the switch already happened."
    if [ "$MAINTENANCE" = 1 ]; then
        log "Maintenance mode is ON. Decide: fix and re-run, or roll back (deploy/scripts/rollback.sh), then: php $BASE/current/artisan up"
    fi
    log "Partial release left for inspection: $RELEASE (remove it when done)."
}
trap on_error ERR

artisan() { php "$RELEASE/artisan" --no-interaction "$@"; }

# 1. Code ---------------------------------------------------------------------------------------
log "Fetching $REF into $RELEASE"
git clone --quiet --depth 1 --branch "$REF" "$REPO" "$RELEASE"
log "Commit: $(git -C "$RELEASE" rev-parse --short HEAD)"

ln -sfn "$BASE/shared/.env" "$RELEASE/.env"
rm -rf "$RELEASE/storage"
ln -sfn "$BASE/shared/storage" "$RELEASE/storage"
# bootstrap/cache is per release; the web group may write the package manifest if it is missing.
chgrp -R www-data "$RELEASE/bootstrap/cache"
chmod -R g+rwX "$RELEASE/bootstrap/cache"

# 2. Dependencies and assets ----------------------------------------------------------------------
log "composer install (production)"
composer install --working-dir="$RELEASE" --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress

log "Frontend build (VITE_* values come from shared/.env)"
( cd "$RELEASE" && npm ci --no-audit --no-fund --silent && npm run build --silent )
rm -rf "$RELEASE/node_modules"

# 3. Live competitions and migrations --------------------------------------------------------------
LIVE=0
if [ -e "$BASE/current/artisan" ]; then
    LIVE="$(php "$BASE/current/artisan" ops:status --live --no-interaction || echo 0)"
fi
log "Competitions starting or in progress: $LIVE"

if artisan migrate:status --pending --no-ansi | grep -q 'Pending'; then
    log "Pending migrations:"
    artisan migrate:status --pending --no-ansi | grep 'Pending' || true

    if [ "$LIVE" != "0" ] && [ "$ALLOW_DURING_LIVE" != 1 ]; then
        fail "$LIVE competition(s) are live. Maintenance mode would freeze them. Deploy after they finish, or pass --allow-during-live."
    fi

    log "Database backup before migrating"
    "$RELEASE/deploy/scripts/backup.sh" database pre-migrate

    log "Maintenance mode ON (workers and the scheduler pause; competition timing resumes from stored deadlines)"
    php "$BASE/current/artisan" down --retry=60 --no-interaction 2>/dev/null || artisan down --retry=60
    MAINTENANCE=1

    log "Migrating with the migration account (config is not cached yet in the new release)"
    set -a; . /etc/mcq/migrate.env; set +a
    artisan migrate --force
    unset DB_USERNAME DB_PASSWORD
else
    log "No pending migrations: no maintenance window needed"
fi

# 4. Caches -----------------------------------------------------------------------------------------
log "Caching config, routes, events and views"
artisan optimize
artisan storage:link >/dev/null 2>&1 || true

# Refuse to go live with an unsafe configuration.
artisan security:check --production

# 5. Switch -----------------------------------------------------------------------------------------
log "Switching current -> $RELEASE"
ln -sfn "$RELEASE" "$BASE/current.next"
mv -Tf "$BASE/current.next" "$BASE/current"

log "Reloading PHP-FPM (OPcache does not re-check files)"
sudo systemctl reload php8.3-fpm

log "Restarting queue workers gracefully (each finishes its current job)"
php "$BASE/current/artisan" queue:restart --no-interaction
if [ "$RESTART_REVERB" = 1 ]; then
    log "Restarting Reverb (browsers reconnect; they poll meanwhile)"
    php "$BASE/current/artisan" reverb:restart --no-interaction
fi

if [ "$MAINTENANCE" = 1 ]; then
    php "$BASE/current/artisan" up --no-interaction
    MAINTENANCE=0
    log "Maintenance mode OFF"
fi

# 6. Smoke tests --------------------------------------------------------------------------------------
log "Smoke tests"
curl -fsS -o /dev/null -w 'GET /up %{http_code}\n' "$APP_URL/up"
curl -fsS -o /dev/null -w 'GET / %{http_code}\n' "$APP_URL/"
curl -fsS -o /dev/null -w 'GET /competitions %{http_code}\n' "$APP_URL/competitions"
php "$BASE/current/artisan" competitions:recover --dry-run --no-interaction || log "WARNING: competitions:recover reports issues (see above)"
sleep 65   # let the scheduler tick and the workers restart
php "$BASE/current/artisan" ops:status --no-interaction || log "WARNING: ops:status reports a problem (see above)"

# 7. Keep the last N releases -------------------------------------------------------------------------
ls -1dt "$BASE"/releases/*/ | tail -n +"$((KEEP_RELEASES + 1))" | xargs -r rm -rf

trap - ERR
log "Deployed $(git -C "$RELEASE" rev-parse --short HEAD) as $TS"
