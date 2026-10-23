#!/usr/bin/env bash
# Mijozga beriladigan reliz paketi (zip) — app rejimidagi PM + tanlangan mahsulotlar. Portalga `release:add <slug> <versiya> server <zip>` bilan yuklanadi.
#   bash tools/make-package.sh <PM_manba> <chiqish.zip> --products hr[,savdo,...] --server https://biwork.uz [--pubkey fayl|URL] [--version 1.0] [--keep-group]
# Paketda faqat shu mahsulotlarning product.json fayllari bo'ladi (boshqa mahsulot kaliti bu nusxada ochmaydi); data/ bo'sh (haqiqiy baza yo'q);
# namuna shablonlari kirmaydi. Mahsulot qo'shilganda mijozga yangi paket beriladi — data/ papkasi saqlanib qoladi (ustidan yoziladi).
set -euo pipefail
HERE="$(cd "$(dirname "$0")/.." && pwd)"
SRC="${1:?PM manba papkasi}"; ZIP="${2:?chiqish zip}"; shift 2
PRODUCTS=""; SERVER=""; PUBKEY=""; VER="1.0"; KEEP=0
while [ $# -gt 0 ]; do case "$1" in --products) PRODUCTS="$2"; shift 2;; --server) SERVER="$2"; shift 2;; --pubkey) PUBKEY="$2"; shift 2;; --version) VER="$2"; shift 2;; --keep-group) KEEP=1; shift;; *) echo "noma'lum: $1" >&2; exit 2;; esac; done
[ -n "$PRODUCTS" ] && [ -n "$SERVER" ] || { echo "--products va --server kerak" >&2; exit 2; }
T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
ARGS=(--mode app --server "$SERVER" --products "$PRODUCTS"); [ -n "$PUBKEY" ] && ARGS+=(--pubkey "$PUBKEY"); [ "$KEEP" = 1 ] && ARGS+=(--keep-group)
bash "$HERE/tools/build.sh" "$SRC" "$T/pm" "${ARGS[@]}" >/dev/null
php -r '$f=$argv[1]; $c=include $f; $c["app_version"]=$argv[2]; $c["products"]=explode(",", $argv[3]); file_put_contents($f, "<?php\n// Biwork ulanishi (o\x27rnatilgan nusxa)\nreturn " . var_export($c, true) . ";\n");' "$T/pm/biwork-config.php" "$VER" "$PRODUCTS"
[ -f "$T/pm/biwork-public.json" ] || echo "OGOHLANTIRISH: biwork-public.json yo'q (--pubkey berilmadi) — mijoz uni qo'lda qo'yishi kerak" >&2
cat > "$T/pm/BIWORK-ORNATISH.txt" <<TXT
Biwork — o'rnatish (PM asosidagi mahsulotlar: $PRODUCTS, versiya $VER)

1. Talab: PHP 8.1+ (pdo_sqlite, sodium, mbstring), veb-server (Apache/Nginx/IIS) yoki sinov uchun:  php -S 0.0.0.0:8090 index.php
2. Papkani hosting ildiziga joylang; data/ papkasi YOZILADIGAN bo'lsin. Batafsil: ORNATISH-PHP.md
3. Birinchi ochilishda administrator yaratiladi: login va parol data/ADMIN-PAROL.txt faylida (kirgach parolni almashtiring, faylni o'chiring).
4. Administrator sifatida kiring → $SERVER emas, o'z manzilingizdagi /licenziya sahifasi → Biwork litsenziya kalitini kiriting (BW-XXX-....).
   Litsenziyasiz tizim ochilmaydi; internet vaqtincha bo'lmasa ham imtiyoz muddati ichida ishlayveradi.
   Biwork Savdo / Moliya: ularning bo'limlari korxona sozlamalariga bog'liq — Administrator paneli (/admin) → Korxonalar → korxonani yarating →
   Sozlash: «Savdo bo'limi» (Savdo), «Mavsumli biznes reja» (Savdo: Yuk xatlari), Moliya uchun hisob/qoldiq sozlamalarini yoqing. Aks holda bo'lim bo'sh ko'rinadi.
5. Qo'shimcha mahsulot sotib olsangiz — yangi paketni shu papka ustidan yozing (data/ saqlanadi) va kalitni /licenziya da kiriting.
TXT
rm -f "$ZIP"; ( cd "$T/pm" && zip -qr "$ZIP" . ); sha256sum "$ZIP" | cut -d' ' -f1 | sed "s#^#sha256: #"
echo "paket tayyor: $ZIP ($(du -k "$ZIP" | cut -f1) KB)"
