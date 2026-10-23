#!/usr/bin/env bash
# Biwork Monitoring sinovlari: birlik sinovi + HTTP/SSO sinovi (vaqtinchalik nusxa va serverlar).
#   bash tests/run.sh
# SSO sinovi uchun Biwork portalining manba kodi kerak (standart: ../../../portal/src); topilmasa shu qism o'tkazib yuboriladi:
#   PORTAL_SRC=/yo'l/biwork/portal/src bash tests/run.sh
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$(pwd)"
TMP="$(mktemp -d)"
P1="${PORT:-8191}"; P2=$((P1 + 1)); P3=$((P1 + 2))
export PORTAL_SRC="${PORTAL_SRC:-$ROOT/../../../portal/src}"
PIDS=""
trap 'for p in $PIDS; do kill $p 2>/dev/null || true; done; rm -rf "$TMP"' EXIT

echo "### Birlik sinovi (qoidalar, hayot sikli, namuna)"
php tests/check.php

echo "### HTTP sinovi"
copy_app() { mkdir -p "$1"; tar -C "$ROOT" --exclude=./data --exclude=./config.php --exclude='*.sqlite*' -cf - . | tar -C "$1" -xf -; mkdir -p "$1/data"; }
copy_app "$TMP/a1"; copy_app "$TMP/a2"; copy_app "$TMP/a3"

KEYS="[]"
if [ -f "$PORTAL_SRC/bootstrap.php" ]; then
  export BIWORK_DATA="$TMP/portal-data"
  KEYS="$(php -r 'require $argv[1]; echo var_export(Tokens::publicKeys(), true);' "$PORTAL_SRC/bootstrap.php")"
fi
cat > "$TMP/c1.php" <<PHP
<?php return ['biwork' => ['server' => 'http://127.0.0.1:1', 'tool' => 'monitoring', 'sso' => true, 'customer_id' => 5, 'public_keys' => $KEYS]];
PHP
cat > "$TMP/c2.php" <<PHP
<?php return ['biwork' => ['server' => 'http://127.0.0.1:1', 'tool' => 'monitoring', 'sso' => true, 'demo' => true, 'public_keys' => $KEYS]];
PHP
cat > "$TMP/c3.php" <<PHP
<?php return ['biwork' => ['server' => 'http://127.0.0.1:1', 'tool' => 'monitoring', 'sso' => false, 'enforce' => true, 'public_keys' => $KEYS]];
PHP

export ADMIN_LOGIN=admin ADMIN_PASS="Sinov-Parol-2026"
MON_CONFIG="$TMP/c1.php" MON_DB="$TMP/a1/data/m.sqlite" php "$TMP/a1/bin/set_password.php" "$ADMIN_LOGIN" "$ADMIN_PASS" admin "Administrator" >/dev/null
MON_CONFIG="$TMP/c3.php" MON_DB="$TMP/a3/data/m.sqlite" php "$TMP/a3/bin/set_password.php" "$ADMIN_LOGIN" "$ADMIN_PASS" admin "Administrator" >/dev/null

start() { # papka, port, config
  ( cd "$1" && MON_CONFIG="$3" MON_DB="$1/data/m.sqlite" exec php -S 127.0.0.1:"$2" -t public public/router.php >"$TMP/srv-$2.log" 2>&1 ) &
  PIDS="$PIDS $!"
  for i in $(seq 1 50); do curl -fs "http://127.0.0.1:$2/api/health" >/dev/null 2>&1 && return 0; sleep 0.2; done
  echo "server ko'tarilmadi (port $2)"; cat "$TMP/srv-$2.log"; exit 1
}
start "$TMP/a1" "$P1" "$TMP/c1.php"
start "$TMP/a2" "$P2" "$TMP/c2.php"
start "$TMP/a3" "$P3" "$TMP/c3.php"

BASE="http://127.0.0.1:$P1" BASE_DEMO="http://127.0.0.1:$P2" BASE_ENF="http://127.0.0.1:$P3" DEMO_DATA="$TMP/a2/data" php tests/http.php
