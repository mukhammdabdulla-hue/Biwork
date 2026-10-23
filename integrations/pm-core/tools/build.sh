#!/usr/bin/env bash
# PM nusxasidan Biwork-ga ulangan PM yig'adi (PM asl papkasiga TEGILMAYDI).
#
#   bash tools/build.sh <PM_manba_papka> <chiqish_papka> [--mode demo|app] [--server URL] [--pubkey fayl|URL] [--products hr,savdo] [--templates] [--keep-comments] [--keep-group]
#
# 1) PM nusxalanadi (data/ ichidagi haqiqiy baza/zaxiralar OLINMAYDI — faqat data/.htaccess va OQING.txt)
# 2) pm-connector.patch qo'llanadi (index.php, php/auth.php)  3) overlay/ (php/biwork.php, SDK, brend) ko'chiriladi
# 4) integrations/<mahsulot>/product.json -> biwork/products/  5) biwork-config.php yoziladi
#    brauzerga beriladigan JS dan izohlar olib tashlanadi (ichki izohlarda haqiqiy tashkilot nomlari bor; --keep-comments bilan saqlanadi);
#    --mode app da PHP/HTML izohlari va ichki hujjatlar ham olib tashlanadi (tools/scrub.php)
#    guruh korxonalariga xos qismlar (OTP, lizing, realizatsiya, ДДС, ichki xususiyatlar) bo'shatiladi (tools/stub-group.php; --keep-group bilan saqlanadi)
# 6) --templates: har mahsulot uchun xayoliy namuna bazasi (data/templates/<slug>/) yasaladi (demo rejimi uchun)
set -euo pipefail
HERE="$(cd "$(dirname "$0")/.." && pwd)"           # integrations/pm-core
INTEG="$(cd "$HERE/.." && pwd)"                    # integrations
SRC="${1:?PM manba papkasi kerak}"; OUT="${2:?chiqish papkasi kerak}"; shift 2
MODE=app; SERVER=""; PUBKEY=""; ONLY=""; TPL=0; STRIP=1; KEEPGROUP=0
while [ $# -gt 0 ]; do case "$1" in
  --mode) MODE="$2"; shift 2;; --server) SERVER="$2"; shift 2;; --pubkey) PUBKEY="$2"; shift 2;;
  --products) ONLY="$2"; shift 2;; --templates) TPL=1; shift;; --keep-comments) STRIP=0; shift;; --keep-group) KEEPGROUP=1; shift;; *) echo "noma'lum: $1" >&2; exit 2;; esac; done
[ -f "$SRC/index.php" ] && [ -f "$SRC/php/auth.php" ] || { echo "PM topilmadi: $SRC" >&2; exit 1; }
rm -rf "$OUT"; mkdir -p "$OUT"
tar -C "$SRC" --exclude='./data/*' --exclude='./.git' --exclude='./sinov' --exclude='./reliz' -cf - . | tar -C "$OUT" -xf -
mkdir -p "$OUT/data"; for f in .htaccess OQING.txt; do [ -f "$SRC/data/$f" ] && cp "$SRC/data/$f" "$OUT/data/$f" || true; done
chmod -R u+w "$OUT"
patch -s -p1 -d "$OUT" < "$HERE/pm-connector.patch"
cp -r "$HERE/overlay/." "$OUT/"
if [ "$STRIP" = 1 ]; then
  NODE_PATH="${NODE_PATH:-/opt/node-tools/node_modules}" node "$HERE/tools/strip-js-comments.js" "$OUT/assets" "$OUT/sw.js" \
    || echo "OGOHLANTIRISH: JS izohlari olib tashlanmadi (node/acorn yo'q) — ochiq nashr oldidan bajaring" >&2
fi
# ekranga chiqadigan matnlardagi haqiqiy tashkilot nomlari (import izohlari, KPI maslahati) — neytral matnga
php "$HERE/tools/brand-text.php" "$OUT"
# PDF hujjatlar (php/pdf.php): tashkilot nomi va logotip qattiq yozilgan — Biwork nomi/logotipiga almashtiriladi
if [ -f "$OUT/php/pdf.php" ]; then
  sed -i -e 's/AVTOSANOAT INVEST/BIWORK/g' -e 's/Avtosanoat Invest/Biwork/g' -e "s/'AI-'/'BW-'/" "$OUT/php/pdf.php"
  cp "$OUT/biwork/brand/logo.png" "$OUT/assets/logo.png"
  if grep -qi 'avtosanoat' "$OUT/php/pdf.php"; then echo "OGOHLANTIRISH: php/pdf.php da eski tashkilot nomi qoldi" >&2; fi
fi
# app (mijozga beriladigan) nusxa: PHP/HTML izohlari va ichki hujjatlar olib tashlanadi (--keep-comments bilan saqlanadi)
if [ "$MODE" = app ] && [ "$STRIP" = 1 ]; then php "$HERE/tools/scrub.php" "$OUT"; fi
mkdir -p "$OUT/biwork/products"
for d in "$INTEG"/*/; do
  s="$(basename "$d")"; [ -f "$d/product.json" ] || continue
  if [ -n "$ONLY" ] && ! echo ",$ONLY," | grep -q ",$s,"; then continue; fi
  cp "$d/product.json" "$OUT/biwork/products/$s.json"
done
if [ -n "$PUBKEY" ]; then case "$PUBKEY" in http*) curl -fsS "$PUBKEY" > "$OUT/biwork-public.json";; *) cp "$PUBKEY" "$OUT/biwork-public.json";; esac; fi
php -r '
$cfg = ["server" => $argv[2], "mode" => $argv[1], "app_version" => "1.0"];
if ($argv[1] === "demo") { $cfg["demo_ttl_days"] = 30; } else { $cfg["enforce"] = true; $cfg["sso"] = true; }
file_put_contents($argv[3], "<?php\n// Biwork ulanishi (integrations/pm-core/README.md)\nreturn " . var_export($cfg, true) . ";\n");
' "$MODE" "$SERVER" "$OUT/biwork-config.php"
php -l "$OUT/php/biwork.php" >/dev/null && php -l "$OUT/index.php" >/dev/null && php -l "$OUT/php/auth.php" >/dev/null
if [ "$TPL" = 1 ]; then
  for f in "$OUT"/biwork/products/*.json; do
    s="$(basename "$f" .json)"
    php "$HERE/tools/seed.php" "$OUT" "$s" "$INTEG/$s/seed.php"
  done
fi
# guruh korxonalariga xos qismlar: shablonlar TO'LIQ kod bilan yasalgach bo'shatiladi (tools/stub-group.php; GURUH-XOS-QISMLAR.md)
if [ "$KEEPGROUP" != 1 ]; then php "$HERE/tools/stub-group.php" "$OUT"; fi
echo "tayyor: $OUT (rejim: $MODE, mahsulotlar: $(ls "$OUT/biwork/products" | sed 's/\.json//' | tr '\n' ' '))"
