#!/usr/bin/env bash
#
# Backups (docs/DEPLOYMENT.md §8).
#   backup.sh database [label]   consistent dump of the database (InnoDB, no locks on the app)
#   backup.sh files              question images and other private uploads
#
# Credentials: /etc/mcq/backup.cnf (owner mcqbackup, 0600):
#   [client]
#   user=mcq_backup
#   password=<BACKUP_DB_PASSWORD>
# Optional encryption: set AGE_RECIPIENT (an `age` public key) in /etc/mcq/backup.env.
# Optional off-site copy: set RCLONE_REMOTE (e.g. "b2:mcq-backups") in /etc/mcq/backup.env.

set -Eeuo pipefail
[ -f /etc/mcq/backup.env ] && . /etc/mcq/backup.env

KIND="${1:?usage: backup.sh database|files [label]}"
LABEL="${2:-scheduled}"
BASE="${BASE:-/var/www/mcq}"
DEST="${BACKUP_DIR:-/var/backups/mcq}"
DB_NAME="${DB_NAME:-mcq_competition}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
umask 077

encrypt() {
    if [ -n "${AGE_RECIPIENT:-}" ]; then age -r "$AGE_RECIPIENT"; else cat; fi
}
suffix() { if [ -n "${AGE_RECIPIENT:-}" ]; then echo ".age"; fi; }

case "$KIND" in
    database)
        mkdir -p "$DEST/db"
        out="$DEST/db/$DB_NAME-$STAMP-$LABEL.sql.gz$(suffix)"
        # --single-transaction: consistent snapshot without blocking players.
        # --triggers/--routines: the append-only triggers are part of the schema.
        mariadb-dump --defaults-extra-file=/etc/mcq/backup.cnf --single-transaction --quick \
            --routines --triggers --events --hex-blob --default-character-set=utf8mb4 "$DB_NAME" \
            | gzip -9 | encrypt > "$out.partial"
        mv "$out.partial" "$out"
        # Retention: 6-hourly dumps kept 14 days; pre-migration dumps kept 90 days.
        find "$DEST/db" -name "*-scheduled.sql.gz*" -mtime +14 -delete
        find "$DEST/db" -name "*-pre-migrate.sql.gz*" -mtime +90 -delete
        ;;
    files)
        mkdir -p "$DEST/files"
        out="$DEST/files/storage-private-$STAMP.tar.gz$(suffix)"
        # Question images and other private uploads. Exports are temporary (7 days) and excluded.
        tar -C "$BASE/shared/storage/app" --exclude='private/exports' -czf - private | encrypt > "$out.partial"
        mv "$out.partial" "$out"
        find "$DEST/files" -name "storage-private-*" -mtime +30 -delete
        ;;
    *)
        echo "unknown kind: $KIND" >&2; exit 2 ;;
esac

size="$(du -h "$out" | cut -f1)"
echo "[$(date -u +%FT%TZ)] $KIND backup written: $out ($size)"

if [ -n "${RCLONE_REMOTE:-}" ]; then
    rclone copy "$out" "$RCLONE_REMOTE/$KIND/" --quiet
    echo "[$(date -u +%FT%TZ)] copied off-site to $RCLONE_REMOTE/$KIND/"
fi
