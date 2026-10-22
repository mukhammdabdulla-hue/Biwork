#!/bin/sh
# Restore a backup made by deploy/backup.sh. This REPLACES the current database and files.
#   ./deploy/restore.sh backups/biwork-db-YYYYMMDD-HHMMSS.dump [backups/biwork-files-YYYYMMDD-HHMMSS.tar.gz]
set -eu
cd "$(dirname "$0")/.."
DB_DUMP="${1:?usage: deploy/restore.sh <db.dump> [files.tar.gz]}"
FILES_TAR="${2:-}"
[ -f "$DB_DUMP" ] || { echo "not found: $DB_DUMP" >&2; exit 1; }

printf 'This replaces the BIWORK database%s. Type "restore" to continue: ' "${FILES_TAR:+ and uploaded files}"
read -r answer
[ "$answer" = "restore" ] || { echo "cancelled"; exit 1; }

docker compose stop api worker beat web
docker compose exec -T postgres sh -c 'pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" --clean --if-exists --no-owner --role="$POSTGRES_USER"' < "$DB_DUMP"
if [ -n "$FILES_TAR" ]; then
  docker compose run --rm --no-deps -T api sh -c 'rm -rf /data/storage/* && tar -xzf - -C /data' < "$FILES_TAR"
fi
docker compose run --rm migrate
docker compose up -d
echo "restore finished"
