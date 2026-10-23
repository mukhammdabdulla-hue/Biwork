#!/usr/bin/env bash
# Sinov muhitini ko'taradi: vaqtinchalik portal + PM (demo va app nusxalari). source qilib ishlatiladi.
#   PM_SRC=/yo'l/PM  source tests/env.sh   ->  $TMP, $PORTAL, $PM_DEMO_URL, $PM_APP_URL va papkalar
set -euo pipefail
CORE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BW="$(cd "$CORE/../.." && pwd)"
PM_SRC="${PM_SRC:?PM_SRC (PM manba papkasi) kerak}"
TMP="${TMP:-$(mktemp -d)}"; mkdir -p "$TMP"; : > "$TMP/pids"
PORT="${PORT:-8199}"; DEMO_PORT="${DEMO_PORT:-8191}"; APP_PORT="${APP_PORT:-8192}"
PORTAL="http://127.0.0.1:$PORT"; PM_DEMO_URL="http://127.0.0.1:$DEMO_PORT"; PM_APP_URL="http://127.0.0.1:$APP_PORT"
export BIWORK_DATA="$TMP/portal-data"; export BIWORK_CONFIG="$TMP/portal-config.php"
SLUGS="${SLUGS:-hr loyiha savdo moliya yuridik}"
{ echo "<?php return ['tool_urls' => ["; for s in $SLUGS; do echo "  '$s' => '$PM_DEMO_URL/',"; done; echo "]];"; } > "$BIWORK_CONFIG"
for s in $SLUGS; do php "$BW/tools/cli.php" tool:register "$BW/integrations/$s/manifest.json" >/dev/null; done
php "$BW/tools/cli.php" staff:add admin@biwork.test "Biwork Admin" "Admin-Parol-2026" >/dev/null
( cd "$BW/portal"; setsid nohup php -S 127.0.0.1:$PORT -t public public/router.php >"$TMP/portal.log" 2>&1 & echo $! >> "$TMP/pids" )
for i in $(seq 1 40); do curl -fs "$PORTAL/api/v1/health" >/dev/null 2>&1 && break; sleep 0.2; done
curl -fs "$PORTAL/api/v1/pubkey" > "$TMP/biwork-public.json"
bash "$CORE/tools/build.sh" "$PM_SRC" "$TMP/pm-demo" --mode demo --server "$PORTAL" --pubkey "$TMP/biwork-public.json" --templates >/dev/null
bash "$CORE/tools/build.sh" "$PM_SRC" "$TMP/pm-app" --mode app --server "$PORTAL" --pubkey "$TMP/biwork-public.json" >/dev/null
( cd "$TMP/pm-demo"; setsid nohup php -S 127.0.0.1:$DEMO_PORT index.php >"$TMP/pm-demo.log" 2>&1 & echo $! >> "$TMP/pids" )
( cd "$TMP/pm-app"; setsid nohup php -S 127.0.0.1:$APP_PORT index.php >"$TMP/pm-app.log" 2>&1 & echo $! >> "$TMP/pids" )
for i in $(seq 1 40); do curl -s -o /dev/null "$PM_DEMO_URL/login" && curl -s -o /dev/null "$PM_APP_URL/login" && break; sleep 0.2; done
export TMP PORTAL PM_DEMO_URL PM_APP_URL BW CORE
bw_stop() { [ -f "$TMP/pids" ] && xargs -r kill < "$TMP/pids" 2>/dev/null || true; }
