#!/usr/bin/env bash
#
# Monthly restore test (docs/DEPLOYMENT.md §8): restores the newest database dump into a scratch
# database and checks it with the application's own integrity tools. Run as root on a staging or
# backup host (it needs CREATE DATABASE). It never touches the production database.
#   deploy/scripts/restore-test.sh [dump-file]

set -Eeuo pipefail
[ -f /etc/mcq/backup.env ] && . /etc/mcq/backup.env
BASE="${BASE:-/var/www/mcq}"
DEST="${BACKUP_DIR:-/var/backups/mcq}"
SCRATCH="${SCRATCH_DB:-mcq_restore_test}"
DUMP="${1:-$(ls -1t "$DEST"/db/*.sql.gz* | head -n 1)}"

[ "$SCRATCH" != "mcq_competition" ] || { echo "refusing to restore over the production database" >&2; exit 2; }
echo "Restoring $DUMP into $SCRATCH"

decrypt() { case "$DUMP" in *.age) age -d -i "${AGE_IDENTITY:?set AGE_IDENTITY to the private key file}" "$DUMP" ;; *) cat "$DUMP" ;; esac; }

mariadb -e "DROP DATABASE IF EXISTS \`$SCRATCH\`; CREATE DATABASE \`$SCRATCH\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
# Triggers in the dump name mcq_migrate as DEFINER; that account must exist on the restore host.
decrypt | gunzip | mariadb "$SCRATCH"

echo "Row counts:"
mariadb "$SCRATCH" -e "SELECT 'users', COUNT(*) FROM users UNION ALL SELECT 'ledger_transactions', COUNT(*) FROM ledger_transactions
    UNION ALL SELECT 'audit_logs', COUNT(*) FROM audit_logs UNION ALL SELECT 'triggers', COUNT(*) FROM information_schema.triggers WHERE trigger_schema = '$SCRATCH';"

# Run the application's checks against the restored copy. APP_CONFIG_CACHE points at a missing
# file so the cached production config (with the live database name) is not used.
export APP_CONFIG_CACHE=/tmp/mcq-restore-test-config.php DB_DATABASE="$SCRATCH" DB_USERNAME="${RESTORE_DB_USER:-root}" DB_PASSWORD="${RESTORE_DB_PASSWORD:-}"
php "$BASE/current/artisan" wallets:reconcile --no-interaction
php "$BASE/current/artisan" competitions:recover --dry-run --no-interaction || true

echo "Restore test passed: dump restored, ledger reconciles. Drop it with: mariadb -e 'DROP DATABASE $SCRATCH'"
