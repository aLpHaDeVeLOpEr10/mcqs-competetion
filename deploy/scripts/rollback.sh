#!/usr/bin/env bash
#
# Code rollback: point "current" back at the previous release (docs/DEPLOYMENT.md §10).
#   deploy/scripts/rollback.sh            → the release before the current one
#   deploy/scripts/rollback.sh <timestamp> → a specific release
#
# Code only. Migrations are additive (new tables/columns/indexes), so the previous release normally
# runs against the newer schema. If a release's migration must itself be undone, restore the
# pre-migration backup instead (DEPLOYMENT.md §10) — never hand-edit financial tables.

set -Eeuo pipefail
BASE="${BASE:-/var/www/mcq}"

current="$(readlink -f "$BASE/current")"
if [ -n "${1:-}" ]; then
    target="$BASE/releases/$1"
else
    target="$(ls -1dt "$BASE"/releases/*/ | sed 's:/*$::' | grep -vx "$current" | head -n 1)"
fi
[ -d "$target" ] || { echo "no such release: ${target:-<none>}" >&2; exit 1; }

echo "Rolling back: $current -> $target"
ln -sfn "$target" "$BASE/current.next"
mv -Tf "$BASE/current.next" "$BASE/current"
sudo systemctl reload php8.3-fpm
php "$BASE/current/artisan" queue:restart --no-interaction
php "$BASE/current/artisan" up --no-interaction 2>/dev/null || true
curl -fsS -o /dev/null -w 'GET /up %{http_code}\n' "$(grep -E '^APP_URL=' "$BASE/shared/.env" | cut -d= -f2-)/up"
php "$BASE/current/artisan" ops:status --no-interaction || true
echo "Rolled back to $(basename "$target"). If Reverb code changed, also run: php $BASE/current/artisan reverb:restart"
