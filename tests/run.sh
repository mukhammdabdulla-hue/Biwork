#!/usr/bin/env bash
# Vaqtinchalik portal serverini ko'tarib, barcha sinovlarni ishga tushiradi.
#   bash tests/run.sh
# Reja paneli konnektori sinovi uchun panelning manba nusxasi kerak (standart: ../ngd-panel). Unga
# integrations/reja/panel-connector.patch qo'llanadi (agar panelda konnektor allaqachon bo'lsa — qo'llanmaydi).
# Panel topilmasa shu bosqich o'tkazib yuboriladi:  PANEL_SRC=/yo'l/ngd-panel bash tests/run.sh
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$(pwd)"
TMP="$(mktemp -d)"
PORT="${PORT:-8099}"
PANEL_OPEN_PORT="${PANEL_OPEN_PORT:-8081}"
PANEL_ENF_PORT="${PANEL_ENF_PORT:-8082}"
TG_PORT="${TG_PORT:-8083}"
DEMO_PORT="${DEMO_PORT:-8084}"
PANEL_SRC="${PANEL_SRC:-$ROOT/../ngd-panel}"
PIDS=""
trap 'for p in $PIDS; do kill $p 2>/dev/null || true; done; rm -rf "$TMP"' EXIT

echo "### Birlik sinovlari (litsenziya yadrosi + SDK)"
php tests/license_test.php

export BIWORK_DATA="$TMP/data"
export BIWORK_CONFIG="$TMP/config.php"
cat > "$BIWORK_CONFIG" <<PHP
<?php return [
  'tool_urls' => ['reja' => 'http://127.0.0.1:${PANEL_OPEN_PORT}/'],
  // ochiq sayt sozlamalari sinovi (tests/showcase.php)
  'tracking_html' => '<script>/* bw-track */</script>',
  'contact' => ['phone' => '+998 71 200 00 00', 'telegram' => 'biwork_uz', 'email' => 'salom@biwork.uz'],
  'notify' => ['telegram_token' => '123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ-abcdef', 'telegram_chat' => '-1001234567', 'telegram_api' => 'http://127.0.0.1:${TG_PORT}'],
];
PHP
php tools/cli.php tool:register integrations/reja/manifest.json >/dev/null
head -c 4096 /dev/urandom > "$TMP/paket.zip"
php tools/cli.php release:add reja 1.8 server "$TMP/paket.zip" "sinov paketi" >/dev/null
php tools/cli.php staff:add admin@biwork.test "Biwork Admin" "Admin-Parol-2026" >/dev/null

echo "### Brauzer oqimi (HTTP, port $PORT)"
( cd portal && exec php -S 127.0.0.1:$PORT -t public public/router.php >"$TMP/server.log" 2>&1 ) &
PIDS="$PIDS $!"
for i in $(seq 1 30); do curl -fs "http://127.0.0.1:$PORT/api/v1/health" >/dev/null 2>&1 && break; sleep 0.2; done
BASE="http://127.0.0.1:$PORT" RELEASE_ID=1 php tests/e2e.php

# brauzer sinovi ataylab 429 ga yetkazgan IP cheklovlarini tozalaymiz
clear_limits() { php -r 'require $argv[1]; Db::exec("DELETE FROM rate_limits");' "$ROOT/portal/src/bootstrap.php"; }

echo "### Ochiq sayt: mahsulot sahifalari, SEO, murojaatlar (soxta Telegram port $TG_PORT)"
clear_limits
export TG_LOG="$TMP/telegram.log"
( exec php -S 127.0.0.1:$TG_PORT tests/fake_telegram.php >"$TMP/telegram-server.log" 2>&1 ) &
PIDS="$PIDS $!"
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$TG_PORT/" && break; sleep 0.2; done
BASE="http://127.0.0.1:$PORT" php tests/showcase.php
clear_limits
echo "### Admin mahsulot formasi: qo'shish, tahrirlash, rasmlar, e'lon qilish, oldindan ko'rish"
BASE="http://127.0.0.1:$PORT" php tests/editor.php
clear_limits
echo "### Paketdagi tayyor mahsulotlar (HR, Loyiha, Savdo, Moliya, Yuridik): ta'riflar, rasmlar, qo'shish, sahifalar"
BASE="http://127.0.0.1:$PORT" php tests/bundled.php
clear_limits
echo "### Sayt yangilanishi: bosh sahifa, 30 kunlik sinov, qo'llanma (admin va ochiq sahifa), logotip"
BASE="http://127.0.0.1:$PORT" php tests/site30.php
clear_limits
new_license() {  # $1 = korxona nomi, $2 = email -> LICENSE_KEY, LICENSE_ID, CID
  CID="$(php tools/cli.php customer:add "$1" "$2" "Sinov Egasi" "Parol-Sinov-2026" | sed -n 's/.*id \([0-9]*\).*/\1/p')"
  LIC_OUT="$(php tools/cli.php license:issue "$CID" reja standard)"
  LICENSE_KEY="$(echo "$LIC_OUT" | grep -o 'BW-REJ-[A-Z0-9]*-[A-Z0-9]*-[A-Z0-9]*')"
  LICENSE_ID="$(echo "$LIC_OUT" | sed -n 's/.*(id \([0-9]*\)).*/\1/p')"
}

if command -v python3 >/dev/null 2>&1; then
  echo "### Python SDK (haqiqiy portal bilan)"
  clear_limits
  new_license "Python korxona" py@korxona.uz
  PORTAL="http://127.0.0.1:$PORT" LICENSE_KEY="$LICENSE_KEY" LICENSE_ID="$LICENSE_ID" python3 tests/python_sdk_test.py
fi

echo "### Demo versiyalari (manzil va zip paket, qaytarish) va ilova relizlari: «Oxirgi qilish» (port $DEMO_PORT — routersiz php -S)"
clear_limits
BASE="http://127.0.0.1:$PORT" DEMO_PORT="$DEMO_PORT" php tests/demos.php

if [ ! -d "$PANEL_SRC/src" ]; then
  echo "### Reja paneli konnektori: panel topilmadi ($PANEL_SRC) — o'tkazib yuborildi"
  exit 0
fi

echo "### Reja paneli konnektori (portal ↔ panel, ikki nusxa: ochiq va majburiy litsenziya)"
prep_panel() {  # $1 = papka, $2 = enforce (true|false)
  mkdir -p "$1"
  tar -C "$PANEL_SRC" --exclude=./data --exclude=./uploads --exclude='__pycache__' --exclude=./engine/backtest_data -cf - . | tar -C "$1" -xf -
  if [ ! -f "$1/src/Biwork.php" ]; then
    patch -s -p1 -d "$1" < "$ROOT/integrations/reja/panel-connector.patch"
  fi
  cmp -s "$1/src/BiworkConnect.php" "$ROOT/sdk/php/BiworkConnect.php" || echo "OGOHLANTIRISH: paneldagi BiworkConnect.php nusxasi sdk/php/BiworkConnect.php dan farq qiladi — yangilang"
  curl -fs "http://127.0.0.1:$PORT/api/v1/pubkey" > "$1/biwork-public.json"
  cat > "$1/config.php" <<PHP
<?php return ['biwork' => ['server' => 'http://127.0.0.1:$PORT', 'tool' => 'reja', 'sso' => true, 'enforce' => $2, 'app_version' => '1.8']];
PHP
  mkdir -p "$1/data/exports"
  ( cd "$1" && TZ=Asia/Tashkent php src/migrate.php >/dev/null )
}
prep_panel "$TMP/panel-open" false
prep_panel "$TMP/panel-enf" true
( cd "$TMP/panel-enf" && php src/set_password.php admin "Panel-Admin-2026" >/dev/null )
( cd "$TMP/panel-open" && TZ=Asia/Tashkent exec php -S 127.0.0.1:$PANEL_OPEN_PORT -t public public/router.php >"$TMP/panel-open.log" 2>&1 ) &
PIDS="$PIDS $!"
( cd "$TMP/panel-enf" && TZ=Asia/Tashkent exec php -S 127.0.0.1:$PANEL_ENF_PORT -t public public/router.php >"$TMP/panel-enf.log" 2>&1 ) &
PIDS="$PIDS $!"

CUST_PASS="Parol-Sinov-2026"
clear_limits
new_license "Namuna korxona" demo@namuna.uz
for i in $(seq 1 30); do
  curl -s -o /dev/null "http://127.0.0.1:$PANEL_OPEN_PORT/login.php" && curl -s -o /dev/null "http://127.0.0.1:$PANEL_ENF_PORT/login.php" && break
  sleep 0.2
done

# demo@namuna.uz parolini o'zgartirish talab qilinmasligi uchun (customer:add vaqtinchalik parol beradi)
php -r 'require $argv[1]; Db::exec("UPDATE users SET must_change_password = 0 WHERE email = ?", ["demo@namuna.uz"]);' "$ROOT/portal/src/bootstrap.php"

PORTAL="http://127.0.0.1:$PORT" PANEL_OPEN="http://127.0.0.1:$PANEL_OPEN_PORT" PANEL_ENF="http://127.0.0.1:$PANEL_ENF_PORT" \
PANEL_OPEN_DB="$TMP/panel-open/data/ngd.sqlite" PANEL_ENF_DIR="$TMP/panel-enf" \
CUST_EMAIL=demo@namuna.uz CUST_PASS="$CUST_PASS" STAFF_EMAIL=admin@biwork.test STAFF_PASS=Admin-Parol-2026 \
PANEL_ADMIN_LOGIN=admin PANEL_ADMIN_PASS="Panel-Admin-2026" LICENSE_KEY="$LICENSE_KEY" LICENSE_ID="$LICENSE_ID" \
  php tests/panel_connector.php
