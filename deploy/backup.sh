#!/bin/sh
# Back up the BIWORK database and uploaded files. Run from the repository root, e.g. daily from cron:
#   15 2 * * * cd /opt/biwork && ./deploy/backup.sh >> backups/backup.log 2>&1
# Keeps BACKUP_KEEP_DAYS days of backups (default 14) in BACKUP_DIR (default ./backups).
set -eu
cd "$(dirname "$0")/.."
BACKUP_DIR="${BACKUP_DIR:-./backups}"
KEEP_DAYS="${BACKUP_KEEP_DAYS:-14}"
STAMP="$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BACKUP_DIR"

docker compose exec -T postgres sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --format=custom' \
  > "$BACKUP_DIR/biwork-db-$STAMP.dump"
docker compose run --rm --no-deps -T api tar -czf - -C /data storage \
  > "$BACKUP_DIR/biwork-files-$STAMP.tar.gz"

find "$BACKUP_DIR" -name 'biwork-*' -type f -mtime +"$KEEP_DAYS" -delete
echo "$(date -Iseconds) backup written: biwork-db-$STAMP.dump, biwork-files-$STAMP.tar.gz"
