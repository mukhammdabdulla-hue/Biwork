#!/usr/bin/env bash
# Bitta mahsulot uchun ishlab chiqish muhiti (shaxsiy portal + demo PM, ikkalasi fonda).
#   bash tools/dev.sh up <slug> <PM_manba> <ish_papka> <port_boshi>   # portal=<port>, demo PM=<port+1>
#   bash tools/dev.sh reseed <slug> <ish_papka>                        # product.json + shablonni qayta yasaydi, sandboxlarni o'chiradi
#   bash tools/dev.sh manifest <slug> <ish_papka>                      # manifest.json ni portalga qayta yuklaydi
#   bash tools/dev.sh shot <slug> <ish_papka> <chiqish.png> [hash]     # namunani portal orqali ochib bitta rasm oladi
#   bash tools/dev.sh down <ish_papka>
set -euo pipefail
CORE="$(cd "$(dirname "$0")/.." && pwd)"; BW="$(cd "$CORE/../.." && pwd)"; INTEG="$BW/integrations"
cmd="${1:?up|reseed|manifest|shot|down}"; shift
export BIWORK_CONFIG; export BIWORK_DATA
env_of() { W="$1"; BIWORK_DATA="$W/portal-data"; BIWORK_CONFIG="$W/portal-config.php"; }
case "$cmd" in
up)
  slug="$1"; SRC="$2"; W="$3"; P="$4"; env_of "$W"; mkdir -p "$W"; : > "$W/pids"
  PORTAL="http://127.0.0.1:$P"; DEMO="http://127.0.0.1:$((P+1))"
  printf "<?php return ['tool_urls' => ['%s' => '%s/']];\n" "$slug" "$DEMO" > "$BIWORK_CONFIG"
  php "$BW/tools/cli.php" tool:register "$INTEG/$slug/manifest.json" >/dev/null
  php "$BW/tools/cli.php" staff:add admin@biwork.test "Biwork Admin" "Admin-Parol-2026" >/dev/null || true
  out="$(php "$BW/tools/cli.php" customer:add "Namuna korxona" demo@namuna.uz "Sinov Egasi" "Parol-Sinov-2026")"; cid="$(echo "$out" | sed -n 's/.*id \([0-9]*\).*/\1/p')"
  php "$BW/tools/cli.php" license:issue "$cid" "$slug" standard >/dev/null
  php -r 'require $argv[1]; Db::exec("UPDATE users SET must_change_password = 0 WHERE email = ?", ["demo@namuna.uz"]);' "$BW/portal/src/bootstrap.php"
  ( cd "$BW/portal"; setsid nohup php -S "127.0.0.1:$P" -t public public/router.php >"$W/portal.log" 2>&1 </dev/null & echo $! >> "$W/pids" )
  for i in $(seq 1 50); do curl -fs "$PORTAL/api/v1/health" >/dev/null 2>&1 && break; sleep 0.2; done
  curl -fs "$PORTAL/api/v1/pubkey" > "$W/biwork-public.json"
  bash "$CORE/tools/build.sh" "$SRC" "$W/pm-demo" --mode demo --server "$PORTAL" --pubkey "$W/biwork-public.json" --products "$slug" --templates
  ( cd "$W/pm-demo"; setsid nohup php -S "127.0.0.1:$((P+1))" index.php >"$W/pm-demo.log" 2>&1 </dev/null & echo $! >> "$W/pids" )
  for i in $(seq 1 50); do curl -s -o /dev/null -m 2 "$DEMO/" && break; sleep 0.2; done
  echo "tayyor. portal: $PORTAL (demo@namuna.uz / Parol-Sinov-2026), PM demo: $DEMO, ish papka: $W"
  ;;
reseed)
  slug="$1"; W="$2"
  cp "$INTEG/$slug/product.json" "$W/pm-demo/biwork/products/$slug.json"
  php "$CORE/tools/seed.php" "$W/pm-demo" "$slug" "$INTEG/$slug/seed.php"
  rm -rf "$W/pm-demo/data/demo"
  ;;
manifest)
  slug="$1"; env_of "$2"; php "$BW/tools/cli.php" tool:register "$INTEG/$slug/manifest.json"
  ;;
shot)
  slug="$1"; W="$2"; out="$3"; hash="${4:-}"
  P="$(grep -o '127.0.0.1:[0-9]*' "$W/portal-config.php" | head -1 | cut -d: -f2)"; P=$((P-1))
  NODE_PATH="${NODE_PATH:-/opt/node-tools/node_modules}" PW_CHROMIUM="${PW_CHROMIUM:-/opt/pw-browsers/chromium-1194/chrome-linux/chrome}" \
  node -e '
    const { openDemo } = require(process.argv[1] + "/bwshot.js");
    (async () => { const d = await openDemo({ portal: process.argv[2], email: "demo@namuna.uz", pass: "Parol-Sinov-2026", slug: process.argv[3] });
      if (process.argv[5]) { await d.page.evaluate(h => { location.hash = h; }, process.argv[5]); await d.page.waitForTimeout(1500); }
      await d.page.screenshot({ path: process.argv[4] }); console.log("xatolar:", JSON.stringify(d.errors.slice(0, 10))); await d.close(); })();
  ' "$CORE/tools" "http://127.0.0.1:$P" "$slug" "$out" "$hash"
  ;;
down)
  W="$1"; [ -f "$W/pids" ] && xargs -r kill < "$W/pids" 2>/dev/null || true
  ;;
esac
