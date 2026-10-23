#!/usr/bin/env bash
# Biwork portalini mahalliy ishga tushirish (idempotent).
#   ./start.sh              -> http://localhost:8090
#   PORT=9000 ./start.sh    -> boshqa port
#   ./start.sh --demo       -> avval Reja vositasi va namuna korxonani qo'shadi (ishlab chiqish uchun)
# Ma'lumotlar: portal/data (BIWORK_DATA bilan o'zgartiriladi). Birinchi xodim: brauzerda /setup sahifasi.
set -euo pipefail
cd "$(dirname "$0")"
export TZ="${TZ:-Asia/Tashkent}"
PORT="${PORT:-8090}"
HOST="${HOST:-127.0.0.1}"

command -v php >/dev/null 2>&1 || { echo "XATO: php topilmadi (PHP 8.1+ kerak)." >&2; exit 1; }
for ext in pdo_sqlite sodium mbstring; do
  php -m | grep -qi "^$ext\$" || { echo "XATO: PHP '$ext' kengaytmasi o'rnatilmagan." >&2; exit 1; }
done

mkdir -p portal/data
if [ "${1:-}" = "--demo" ]; then
  php tools/cli.php seed:demo
fi

php -r 'require "portal/src/bootstrap.php"; exit(Accounts::staffCount() > 0 ? 0 : 1);' 2>/dev/null || {
  KEYFILE="${BIWORK_DATA:-portal/data}/setup_key.txt"
  echo ""
  echo "Birinchi sozlash: brauzerda http://localhost:$PORT/setup ni oching."
  echo "O'rnatish kaliti ($KEYFILE) sahifa birinchi ochilganda yaratiladi: cat $KEYFILE"
  echo "(yoki terminalda:  php tools/cli.php staff:add admin@example.uz \"Ism\" \"Parol\")"
}

echo ""
echo "Biwork portali: http://localhost:$PORT   (To'xtatish: Ctrl+C)"
echo ""
cd portal
exec php -d upload_max_filesize=64M -d post_max_size=64M -S "$HOST:$PORT" -t public public/router.php
