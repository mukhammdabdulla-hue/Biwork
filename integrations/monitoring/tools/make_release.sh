#!/usr/bin/env bash
# Biwork Monitoring reliz paketi (server uchun zip).  Portal: Admin -> Vositalar -> Reliz qo'shish (kind: server)
#   bash tools/make_release.sh [versiya] [chiqish_papkasi]
#   PUBKEY=/yo'l/biwork-public.json   (curl -s https://biwork.uz/api/v1/pubkey > biwork-public.json) paketga qo'shiladi
# Paketga baza, sessiya, config.php va sinovlar KIRMAYDI.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VER="${1:-$(php -r 'require $argv[1]; echo MON_VERSION;' "$ROOT/app/src/bootstrap.php")}"
OUT="${2:-$ROOT/dist}"
command -v zip >/dev/null || { echo "XATO: zip topilmadi" >&2; exit 1; }
mkdir -p "$OUT"
STAGE="$(mktemp -d)"; trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/monitoring"
( cd "$ROOT/app" && tar --exclude='./tests' --exclude='./config.php' --exclude='./data/*' --exclude='*.sqlite*' --exclude='*.log' -cf - . ) | ( cd "$STAGE/monitoring" && tar -xf - )
mkdir -p "$STAGE/monitoring/data"
cp "$ROOT/app/data/.htaccess" "$ROOT/app/data/.gitignore" "$STAGE/monitoring/data/"
cp "$ROOT/README.md" "$STAGE/monitoring/README.md"
if [ -n "${PUBKEY:-}" ]; then
  cp "$PUBKEY" "$STAGE/monitoring/biwork-public.json"
else
  echo "ESLATMA: PUBKEY berilmadi, paketda biwork-public.json yo'q (mijoz uni o'zi qo'yadi)" >&2
fi
ZIP="$OUT/biwork-monitoring-$VER.zip"
rm -f "$ZIP"
( cd "$STAGE" && zip -qr "$ZIP" monitoring )
echo "Tayyor: $ZIP ($(du -h "$ZIP" | cut -f1))"
