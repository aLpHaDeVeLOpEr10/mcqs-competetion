#!/usr/bin/env bash
#
# Health check for cron (every minute) or an external monitor (docs/DEPLOYMENT.md §11).
# Exits non-zero and calls ALERT_COMMAND (from /etc/mcq/monitor.env) with a message when:
#   - GET /up fails (application or database down)
#   - ops:status reports a problem (scheduler heartbeat, queue backlog/lag, results stuck,
#     rejected payment callbacks, open high-severity risk flags)
#   - competitions:recover --dry-run finds an issue that needs an operator (checked every 5 min)
#   - Reverb or a queue worker is not running (Supervisor)
#   - disk usage is above DISK_MAX_PERCENT
# Example ALERT_COMMAND: 'curl -fsS -X POST -d @- https://hooks.example.com/alert'  (message on stdin)

set -uo pipefail
[ -f /etc/mcq/monitor.env ] && . /etc/mcq/monitor.env
BASE="${BASE:-/var/www/mcq}"
APP_URL="${APP_URL:-$(grep -E '^APP_URL=' "$BASE/shared/.env" | cut -d= -f2-)}"
DISK_MAX_PERCENT="${DISK_MAX_PERCENT:-85}"
STATE="${STATE_FILE:-/tmp/mcq-health.state}"
problems=()

code="$(curl -s -o /dev/null -m 10 -w '%{http_code}' "$APP_URL/up" || echo 000)"
[ "$code" = 200 ] || problems+=("GET /up returned $code (application or database down)")

if ! out="$(php "$BASE/current/artisan" ops:status --no-ansi --no-interaction 2>&1)"; then
    problems+=("ops:status: $(echo "$out" | grep -E 'PROBLEM' | sed 's/[|]/ /g' | tr -s ' ' | paste -sd ';' -)")
fi

if [ "$(( $(date +%-M) % 5 ))" = 0 ]; then
    if ! rec="$(php "$BASE/current/artisan" competitions:recover --dry-run --no-ansi --no-interaction 2>&1)"; then
        problems+=("competitions:recover needs an operator: $(echo "$rec" | grep -E 'reported|failed' | tr -s ' ' | paste -sd ';' -)")
    fi
fi

if command -v supervisorctl >/dev/null 2>&1; then
    down="$(supervisorctl status 'mcq:*' 2>/dev/null | grep -v RUNNING || true)"
    [ -z "$down" ] || problems+=("processes not running: $(echo "$down" | awk '{print $1" "$2}' | paste -sd ',' -)")
fi

for mount in / /var; do
    used="$(df -P "$mount" | awk 'NR==2 {gsub("%","",$5); print $5}')"
    [ "${used:-0}" -lt "$DISK_MAX_PERCENT" ] || problems+=("disk $mount at ${used}%")
done

if [ "${#problems[@]}" -eq 0 ]; then
    [ -f "$STATE" ] && { rm -f "$STATE"; [ -n "${ALERT_COMMAND:-}" ] && echo "RESOLVED: mcq health OK" | sh -c "$ALERT_COMMAND"; }
    exit 0
fi

summary="$(printf '%s | ' "${problems[@]}")"
message="mcq health ($(hostname), $(date -u +%FT%TZ)): $summary"
echo "$message"
# Alert once per distinct problem set (compared without the timestamp), not every minute.
if [ "$(cat "$STATE" 2>/dev/null)" != "$summary" ] && [ -n "${ALERT_COMMAND:-}" ]; then
    echo "$message" | sh -c "$ALERT_COMMAND"
fi
printf '%s' "$summary" > "$STATE.tmp" && mv "$STATE.tmp" "$STATE"
exit 1
