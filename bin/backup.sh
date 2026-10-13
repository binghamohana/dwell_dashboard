#!/bin/sh
# Nightly backup of the Dwell Central database and staff photos, kept for 14 days.
# The backups contain personnel data: they are written with owner-only permissions.
#   e.g. in /etc/cron.d/dwell-central:
#   30 2 * * * www-data /var/www/dwell-central/bin/backup.sh >> /var/www/dwell-central/storage/logs/backup.log 2>&1
set -eu
APP="$(cd "$(dirname "$0")/.." && pwd)"
DEST="$APP/storage/backups"
STAMP="$(date +%Y-%m-%d)"
umask 077
mkdir -p "$DEST"
chmod 700 "$DEST"
CNF="$(mktemp)"
trap 'rm -f "$CNF"' EXIT
# Database name and login come from config/config.php (never typed on the command line).
DB_NAME="$(php -r '$c = require $argv[1]; printf("[client]\nuser=%s\npassword=\"%s\"\n", $c["db"]["user"], addcslashes((string) $c["db"]["password"], "\\\"")); fwrite(STDERR, $c["db"]["name"]);' "$APP/config/config.php" 2>&1 >"$CNF")"
mysqldump --defaults-extra-file="$CNF" --single-transaction --routines --no-tablespaces "$DB_NAME" | gzip > "$DEST/db-$STAMP.sql.gz"
tar -czf "$DEST/photos-$STAMP.tar.gz" -C "$APP/storage" photos
find "$DEST" -name '*.gz' -mtime +14 -delete
echo "$(date '+%F %T') backup ok: db-$STAMP.sql.gz photos-$STAMP.tar.gz"
