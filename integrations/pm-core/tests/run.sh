#!/usr/bin/env bash
# PM ↔ Biwork ulanishi sinovlari: vaqtinchalik portal + patchlangan PM (demo va app rejimlari) ko'tarib, pm_connector.php ni yuritadi.
#   PM_SRC=/yo'l/PM bash integrations/pm-core/tests/run.sh
set -euo pipefail
CORE="$(cd "$(dirname "$0")/.." && pwd)"; BW="$(cd "$CORE/../.." && pwd)"
PM_SRC="${PM_SRC:?PM_SRC (PM manba papkasi) kerak}"
TMP="$(mktemp -d)"; PORT="${PORT:-8399}"; DEMO_PORT="${DEMO_PORT:-8391}"; APP_PORT="${APP_PORT:-8392}"
PIDS=""; trap 'for p in $PIDS; do kill $p 2>/dev/null || true; done; rm -rf "$TMP"' EXIT
export BIWORK_DATA="$TMP/portal-data" BIWORK_CONFIG="$TMP/portal-config.php"
SLUGS="hr loyiha savdo moliya yuridik yuridik"
{ echo "<?php return ['tool_urls' => ["; for s in hr loyiha savdo moliya yuridik; do echo "  '$s' => 'http://127.0.0.1:$DEMO_PORT/',"; done; echo "]];"; } > "$BIWORK_CONFIG"
for s in hr loyiha savdo moliya yuridik; do php "$BW/tools/cli.php" tool:register "$BW/integrations/$s/manifest.json" >/dev/null; done
php "$BW/tools/cli.php" staff:add admin@biwork.test "Biwork Admin" "Admin-Parol-2026" >/dev/null
( cd "$BW/portal"; php -S 127.0.0.1:$PORT -t public public/router.php >"$TMP/portal.log" 2>&1 </dev/null & echo $! > "$TMP/p1" ); PIDS="$PIDS $(cat "$TMP/p1")"
for i in $(seq 1 50); do curl -fs "http://127.0.0.1:$PORT/api/v1/health" >/dev/null 2>&1 && break; sleep 0.2; done
curl -fs "http://127.0.0.1:$PORT/api/v1/pubkey" > "$TMP/biwork-public.json"
PORTAL="http://127.0.0.1:$PORT"
bash "$CORE/tools/build.sh" "$PM_SRC" "$TMP/pm-demo" --mode demo --server "$PORTAL" --pubkey "$TMP/biwork-public.json" --templates >/dev/null
bash "$CORE/tools/build.sh" "$PM_SRC" "$TMP/pm-app" --mode app --server "$PORTAL" --pubkey "$TMP/biwork-public.json" >/dev/null
bash "$CORE/tools/build.sh" "$PM_SRC" "$TMP/pm-keep" --mode app --server "$PORTAL" --pubkey "$TMP/biwork-public.json" --keep-group >/dev/null   # --keep-group: guruhga xos fayllar asl holida qoladi
( cd "$TMP/pm-demo"; php -S 127.0.0.1:$DEMO_PORT index.php >"$TMP/pm-demo.log" 2>&1 </dev/null & echo $! > "$TMP/p2" ); PIDS="$PIDS $(cat "$TMP/p2")"
( cd "$TMP/pm-app"; php -S 127.0.0.1:$APP_PORT index.php >"$TMP/pm-app.log" 2>&1 </dev/null & echo $! > "$TMP/p3" ); PIDS="$PIDS $(cat "$TMP/p3")"
for i in $(seq 1 50); do curl -s -o /dev/null "http://127.0.0.1:$DEMO_PORT/" && curl -s -o /dev/null "http://127.0.0.1:$APP_PORT/login" && break; sleep 0.2; done
# portalda mijoz + HR va Loyiha litsenziyalari
CID="$(php "$BW/tools/cli.php" customer:add "Namuna korxona" demo@namuna.uz "Sinov Egasi" "Parol-Sinov-2026" | sed -n 's/.*id \([0-9]*\).*/\1/p')"
OUT_HR="$(php "$BW/tools/cli.php" license:issue "$CID" hr standard)"; OUT_LOY="$(php "$BW/tools/cli.php" license:issue "$CID" loyiha standard)"
KEY_HR="$(echo "$OUT_HR" | grep -o 'BW-HRM-[A-Z0-9]*-[A-Z0-9]*-[A-Z0-9]*')"; KEY_LOY="$(echo "$OUT_LOY" | grep -o 'BW-LOY-[A-Z0-9]*-[A-Z0-9]*-[A-Z0-9]*')"
LICENSE_ID_HR="$(echo "$OUT_HR" | sed -n 's/.*(id \([0-9]*\)).*/\1/p')"
php -r 'require $argv[1]; Db::exec("UPDATE users SET must_change_password = 0 WHERE email = ?", ["demo@namuna.uz"]);' "$BW/portal/src/bootstrap.php"
# PM app: birinchi so'rov administratorni yaratadi (ADMIN-PAROL.txt)
curl -s -o /dev/null "http://127.0.0.1:$APP_PORT/login"
PORTAL="$PORTAL" PM_DEMO="http://127.0.0.1:$DEMO_PORT" PM_APP="http://127.0.0.1:$APP_PORT" PM_DEMO_DIR="$TMP/pm-demo" PM_APP_DIR="$TMP/pm-app" PM_KEEP_DIR="$TMP/pm-keep" \
CUST_EMAIL=demo@namuna.uz CUST_PASS="Parol-Sinov-2026" STAFF_EMAIL=admin@biwork.test STAFF_PASS=Admin-Parol-2026 \
KEY_HR="$KEY_HR" KEY_LOY="$KEY_LOY" LICENSE_ID_HR="$LICENSE_ID_HR" php "$CORE/tests/pm_connector.php"
