#!/usr/bin/env bash
# Hosting (Plesk / eskiz.uz) uchun zip paket yaratish.
#   bash tools/make_deploy.sh [chiqish_papkasi]
# Natija: <chiqish_papkasi>/biwork-hosting.zip  (standart: ../biwork-dist). Ichida: biwork/ papkasi (portal + CLI + SDK + hujjat).
# Baza, imzolash kalitlari, relizlar, sessiyalar, setup kaliti, config.php va sinovlar paketga KIRMAYDI:
# kalitlar va baza hostingda birinchi ishga tushishda yaratiladi.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT_DIR="${1:-$(dirname "$ROOT")/biwork-dist}"
ZIP="$OUT_DIR/biwork-hosting.zip"

command -v zip >/dev/null || { echo "XATO: zip topilmadi" >&2; exit 1; }
command -v php >/dev/null || { echo "XATO: php topilmadi" >&2; exit 1; }
mkdir -p "$OUT_DIR"

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/biwork"

( cd "$ROOT" && tar \
    --exclude='./portal/data/*' --exclude='./portal/public/demo' --exclude='./tests' --exclude='./docs/screens' --exclude='./.git' --exclude='./node_modules' \
    --exclude='./config.php' --exclude='./portal/config.php' --exclude='*.sqlite*' --exclude='*.log' --exclude='*.tmp' \
    --exclude='__pycache__' --exclude='*.pyc' \
    -cf - . ) | ( cd "$STAGE/biwork" && tar -xf - )

# data/ papkasi: faqat himoya fayllari
mkdir -p "$STAGE/biwork/portal/data"
cp "$ROOT/portal/data/.htaccess" "$ROOT/portal/data/.gitignore" "$STAGE/biwork/portal/data/"

# public/demo/ papkasi: admin «Demo versiyalari» paketlarni shu yerga ochadi. Paketga bo'sh holda kiradi (ishlab chiqishda
# ochilgan demolar kirmaydi): faqat .gitkeep va portal qoidalarini o'chiradigan .htaccess (matni Demos::HTACCESS dan).
mkdir -p "$STAGE/biwork/portal/public/demo"
: > "$STAGE/biwork/portal/public/demo/.gitkeep"
php -r 'require $argv[1]; echo Demos::HTACCESS;' "$ROOT/portal/src/bootstrap.php" > "$STAGE/biwork/portal/public/demo/.htaccess"

# Mahsulot jamoalari ishlaydigan papkalar (ilova manbasi, seed, ichki hujjat, sinov) hostingga kerak emas:
# sayt faqat integrations/<slug>/manifest.json ni o'qiydi, rasmlar portal/public/assets/products/ da turadi.
# reja/ shu holicha qoladi (panel ulanish yo'riqnomasi shu yerda); manifest yo'q papkalar (pm-core) olib tashlanadi.
for d in "$STAGE"/biwork/integrations/*/; do
  [ "$(basename "$d")" = reja ] && continue
  if [ -f "$d/manifest.json" ]; then
    find "$d" -mindepth 1 -maxdepth 1 ! -name manifest.json -exec rm -rf {} +
  else
    rm -rf "$d"
  fi
done

# Tekshiruvlar
bad="$(cd "$STAGE" && find . \( -name 'config.php' -o -name '*.sqlite*' -o -name 'setup_key.txt' -o -name '*.sec' -o -name 'current.txt' \) -print)"
if [ -n "$bad" ]; then
  echo "XATO: paketga kirmasligi kerak bo'lgan fayllar topildi:" >&2
  echo "$bad" >&2
  exit 1
fi
for f in portal/.htaccess portal/public/.htaccess portal/src/.htaccess portal/data/.htaccess portal/public/index.php portal/public/api.php \
         portal/src/bootstrap.php portal/src/Licenses.php portal/src/Tokens.php tools/cli.php integrations/reja/manifest.json \
         sdk/php/BiworkConnect.php sdk/python/biwork_license.py docs/TOOL_CONTRACT.md docs/SITE_VA_REKLAMA.md README.md \
         portal/public/assets/og-default.png portal/public/assets/products/reja/og.png portal/src/views/product.php tools/product_assets.py \
         portal/src/ToolEditor.php portal/src/Media.php portal/src/ProductPage.php portal/src/views/admin_tool_edit.php portal/public/assets/editor.js \
         portal/src/Demos.php portal/src/routes_demos.php portal/public/demo/.htaccess portal/public/demo/.gitkeep; do
  [ -f "$STAGE/biwork/$f" ] || { echo "XATO: $f yo'q" >&2; exit 1; }
done
extra="$(cd "$STAGE/biwork/portal/public/demo" && find . -mindepth 1 ! -name .gitkeep ! -name .htaccess -print)"
if [ -n "$extra" ]; then
  echo "XATO: portal/public/demo ichida joylashtirilgan demo qolgan (paketga kirmasligi kerak):" >&2
  echo "$extra" >&2
  exit 1
fi
while IFS= read -r f; do
  php -l "$f" >/dev/null || { echo "XATO: sintaksis xatosi: $f" >&2; exit 1; }
done < <(find "$STAGE/biwork" -name '*.php')

# Huquqlar: papkalar 755, fayllar 644, data/ yoziladigan
find "$STAGE/biwork" -type d -exec chmod 755 {} +
find "$STAGE/biwork" -type f -exec chmod 644 {} +
chmod 775 "$STAGE/biwork/portal/data"
chmod 775 "$STAGE/biwork/portal/public/demo"

rm -f "$ZIP"
( cd "$STAGE" && zip -qr -X "$ZIP" biwork )
echo "Tayyor: $ZIP ($(du -h "$ZIP" | cut -f1), $(cd "$STAGE" && find biwork -type f | wc -l) fayl)"
