#!/bin/bash
# Daily backup of the RTRroom database and uploaded files.
# Keeps every daily copy for 30 days, and the copy made on the 1st of each month for 12 months.
set -euo pipefail
export PATH="/usr/local/bin:/opt/homebrew/bin:$PATH"
cd "$(dirname "$0")/.."
DEST="${RTR_BACKUP_DIR:-$HOME/RTRroom-backups}"
STAMP=$(date +%Y%m%d-%H%M)
mkdir -p "$DEST/monthly"

docker compose exec -T mysql sh -c 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction "$MYSQL_DATABASE"' 2>/dev/null \
  | gzip > "$DEST/db-$STAMP.sql.gz"
TABLES=$(gzip -dc "$DEST/db-$STAMP.sql.gz" | grep -c "CREATE TABLE" || true)
if [ "$TABLES" -lt 10 ]; then
  echo "$(date) FAILED: only $TABLES tables in the dump" >&2
  exit 1
fi
tar -czf "$DEST/files-$STAMP.tar.gz" -C laravel-app/storage app

if [ "$(date +%d)" = "01" ]; then
  cp "$DEST/db-$STAMP.sql.gz" "$DEST/files-$STAMP.tar.gz" "$DEST/monthly/"
fi

find "$DEST" -maxdepth 1 -name 'db-*.sql.gz' -mtime +30 -delete
find "$DEST" -maxdepth 1 -name 'files-*.tar.gz' -mtime +30 -delete
find "$DEST/monthly" -name '*-*.gz' -mtime +366 -delete
echo "$(date) OK: $TABLES tables, $(du -h "$DEST/db-$STAMP.sql.gz" | cut -f1) db, $(du -h "$DEST/files-$STAMP.tar.gz" | cut -f1) files"
