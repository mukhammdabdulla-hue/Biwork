# Biwork — korxonalar uchun avtomatlashtirish mahsulotlari platformasi

Biwork — korxonalar sotib oladigan **mahsulotlar** (har bir ish yo'nalishi uchun alohida dastur: birinchisi **Biwork Reja**; keyingilari — HR, Savdo,
Loyiha …) bitta eshikdan sotiladigan tizim. Uning vazifasi — mahsulotlarni internetda **reklama qilish** va sotish:

- **ochiq sayt** — har mahsulotga reklama sahifasi (nima qiladi, kimga kerak, ekran rasmlari, "Sinab ko'rish" / "So'rov qoldirish"), qidiruv va ijtimoiy tarmoq uchun teglar, sitemap, murojaatlar;
- korxona portalga kiradi va mahsulotni **brauzerda sinab ko'radi** (demo ma'lumot bilan);
- litsenziya sotib olgach mahsulotni **ilova sifatida yuklab** o'z kompyuteri/serveriga o'rnatadi;
- Biwork xodimi **litsenziyalarni boshqaradi**: kalit berish, muddat, o'rindiqlar (qurilmalar), to'xtatish, bekor qilish;
- yangi mahsulot — manifest + SDK bilan ulanadi, portalni o'zgartirish shart emas.

Reja: [docs/PLATFORM_PLAN.md](docs/PLATFORM_PLAN.md) · Ochiq sayt va reklama: [docs/SITE_VA_REKLAMA.md](docs/SITE_VA_REKLAMA.md) ·
Mahsulot ulash qoidalari: [docs/TOOL_CONTRACT.md](docs/TOOL_CONTRACT.md) · Biwork Rejani ulash: [integrations/reja/README.md](integrations/reja/README.md)

## Tezkor boshlash (kompyuterda)

PHP 8.1+ (`pdo_sqlite`, `sodium`, `mbstring`) kerak. Boshqa hech narsa shart emas.

```bash
bash start.sh --demo        # Windows: start.bat --demo
```

1. Brauzerda <http://localhost:8090/setup> — birinchi xodim (administrator) yaratiladi. O'rnatish kaliti `portal/data/setup_key.txt` da.
2. `--demo` Reja vositasini katalogga qo'shadi va "Namuna korxona" ochadi (parol terminalda chiqadi).
3. Boshqa oynada Biwork Reja panelini ishga tushiring (`ngd-panel`: `bash start.sh`, port 8080) va portal sozlamasiga qo'shing
   `portal/config.php`: `<?php return ['tool_urls' => ['reja' => 'http://localhost:8080/']];` — "Brauzerda ochish" paneldagi demoga olib boradi
   (panelga konnektor kerak: [integrations/reja/README.md](integrations/reja/README.md)).

## Tuzilma

```
portal/            Veb-portal + litsenziya serveri (PHP 8, SQLite, freymvorksiz — hosting uchun)
  public/            Hujjatlar ildizi: index.php (sayt), api.php (/api/v1), assets (rasmlar: assets/products/<slug>/), PWA
  src/               Kod (Auth, Licenses, Tokens, Catalog, Launch, Router, routes_*.php, views/)
  data/              Baza, imzolash kalitlari, relizlar, sessiyalar (veb orqali yopiq, yoziladigan bo'lishi kerak)
sdk/php/           BiworkConnect.php — vositaga ulanadigan kutubxona (bitta fayl)
sdk/python/        biwork_license.py — Python varianti (bitta fayl, bog'liqliksiz)
integrations/reja/ Reja uchun manifest, panel konnektori (patch) va qo'llanma
tools/cli.php      Buyruq qatori: xodim, mahsulot, reliz, korxona, litsenziya, kalitlar, demo
tools/product_assets.py  Mahsulot ekran rasmlari va ulashish rasmi (og.png) tayyorlash (Pillow)
tools/make_deploy.sh  Hosting uchun zip paket
tests/             run.sh — hamma sinovlar (birlik, brauzer oqimi, ochiq sayt, Reja panel konnektori, Python SDK)
docs/              Reja, mahsulot shartnomasi, ochiq sayt va reklama
```

## Qanday ishlaydi (qisqa)

| Bosqich | Nima bo'ladi |
|---|---|
| Ro'yxatdan o'tish | Korxona + egasi yaratiladi, 30 kunlik **sinov** (muddat: `config.php` dagi `trial_days`) (faqat brauzer demosi) |
| Sotib olish so'rovi | Mijoz tarif/o'rindiq yuboradi → xodim *So'rovlar* da ko'radi, hisob-faktura yuboradi |
| Litsenziya berish | Xodim *Litsenziyalar → Yangi*: kalit `BW-REJ-XXXX-XXXX-XXXX`, muddat, o'rindiq. Mijoz kalitni va **Yuklab olish** ni ko'radi |
| Faollashtirish | Ilova kalit + qurilma izini yuboradi → imzolangan (Ed25519) token oladi, internetsiz tekshiradi |
| Boshqaruv | Muddatni uzaytirish, to'xtatish/bekor qilish, o'rindiq qo'shish, qurilmani bo'shatish; hamma amal jurnalda |
| Brauzer | *Ochish* → 2 daqiqalik bir martalik imzolangan token → vosita sessiya ochadi |

Tokenlar, API xatolari, oflayn qoidalar: [docs/TOOL_CONTRACT.md](docs/TOOL_CONTRACT.md).

## Hostingga joylash (eskiz.uz / Plesk)

```bash
bash tools/make_deploy.sh        # -> ../biwork-dist/biwork-hosting.zip
```

1. Zipni hostingga yuklab oching → `biwork/` papkasi. PHP 8.1+; kengaytmalar: `pdo_sqlite`, `sodium`, `mbstring` (hostingda `sodium` yo'q bo'lsa — qo'llab-quvvatlashdan yoqishni so'rang).
2. **Hujjatlar ildizi (Document root) = `biwork/portal/public`**. Ildizni o'zgartirib bo'lmasa, `biwork/portal` ni ildiz qiling — `.htaccess` maxfiy papkalarni yopib `public/` ga yo'naltiradi.
3. `portal/data` papkasi yoziladigan bo'lsin (755/775). HTTPS yoqilgan bo'lsin (sessiya cookie `Secure` bilan ishlaydi).
4. `https://domeningiz/setup` — birinchi xodim. O'rnatish kaliti `portal/data/setup_key.txt` faylida (FTP/fayl menejeri bilan o'qiladi), yaratilgach fayl o'chadi.
5. *Admin → Mahsulotlar*: paketdagi tayyor mahsulotlarni (Reja, HR, Loyiha, Savdo, Moliya, Yuridik, Monitoring: u «tez orada» holatida) «Tayyor mahsulotlar» blokidan bir bosishda qo'shing; yangi mahsulotni «Yangi mahsulot» formasi bilan yozing (matn, rasm, tarif, brauzer manzili; «E'lon qilish» bosilgach `/<manzil>` sahifasi o'zi paydo bo'ladi) yoki dasturchi manifestini yuklang (`integrations/reja/manifest.json`); Reja uchun **brauzer (demo) manzili**ni kiriting; relizlar bo'limida Reja paketini (`ngd-panel-hosting.zip`) yuklang. Yuklangan rasmlar `portal/data/uploads` da saqlanadi: zaxira nusxaga `data/` ni kiriting.
6. Ixtiyoriy `portal/config.php`: `<?php return ['base_url' => 'https://biwork.uz', 'tool_urls' => ['reja' => 'https://reja.biwork.uz/']];` —
   reklama uchun yana `tracking_html` (Meta/Google/Yandex piksel), `contact`, `notify` (Telegram), `trial_days` (bepul sinov kunlari, standart 30): [docs/SITE_VA_REKLAMA.md](docs/SITE_VA_REKLAMA.md).

Zaxira: `portal/data/` (baza `biwork.sqlite`, **`keys/` — imzolash kalitlari**, `releases/`). Kalitlar yo'qolsa, ilovalardagi tokenlar tekshirilmay qoladi — nusxa saqlang.
Kalitni almashtirish: `php tools/cli.php keys:rotate` (eski ochiq kalitlar saqlanadi).

**Yangi zip bilan yangilash.** Zipni mavjud papka ustiga yoying: baza va kalitlar (`portal/data/`), `portal/config.php` va ochilgan demo paketlari (`portal/public/demo/`) zipda yo'q, shuning uchun o'zgarmaydi.
Mahsulot matnlari bazada turadi: paketdagi tayyor mahsulot matni yangilangan bo'lsa (masalan, Biwork Reja savol-javobi), uni `php tools/cli.php tool:register integrations/reja/manifest.json` bilan qayta yozing.
Diqqat: bu buyruq mahsulot formasida qo'lda o'zgartirilgan matn va brauzer manzilini manifestdagilar bilan almashtiradi.

## Buyruq qatori (`tools/cli.php`)

```bash
php tools/cli.php staff:add admin@biwork.uz "Ism" [parol]
php tools/cli.php tool:register integrations/reja/manifest.json
php tools/cli.php release:add reja 1.8 server paket.zip "izoh"
php tools/cli.php customer:add "Korxona MChJ" email@korxona.uz "Ega Ismi" [parol]
php tools/cli.php license:issue <korxona-id> reja standard [YYYY-MM-DD] [o'rindiqlar]
php tools/cli.php keys:show | keys:rotate
```

## Sinovlar

```bash
bash tests/run.sh                         # hammasi: 300 dan ortiq tekshiruv, vaqtinchalik server va baza bilan
PANEL_SRC=/yo'l/ngd-panel bash tests/run.sh   # Reja paneli boshqa joyda bo'lsa
```

Birlik (litsenziya yadrosi, vaqt sakrashi bilan) · brauzer oqimi (ro'yxatdan o'tish → sinov → so'rov → litsenziya → yuklab olish → faollashtirish →
to'xtatish) · ochiq sayt (mahsulot sahifasi, SEO/ulashish teglari, sitemap, murojaat oqimi, bot himoyasi, admin ro'yxati, Telegram) ·
Reja panel konnektori (SSO, litsenziya darvozasi) · Python SDK (PHP imzolagan tokenlar bilan o'zaro moslik).

## Xavfsizlik — qisqa

CSRF har POST'da · parol `password_hash` · kirishda 5 urinish/15 daqiqa qulf · API: IP bo'yicha 120 so'rov/daqiqa, 10 noto'g'ri kalit/10 daqiqa ·
"Ochish" tokeni 2 daqiqa, bir marta, kalitni o'z ichiga olmaydi · imzolash kaliti `data/keys` da (veb orqali yopiq) · sessiya cookie `HttpOnly/SameSite` ·
chiqish `htmlspecialchars` bilan · relizlarga faqat faol litsenziyali mijoz kiradi · murojaat shakli: CSRF, yashirin maydon, vaqt tuzog'i, IP chegarasi.

**Cheklov:** PHP vosita manba kod sifatida yetkaziladi — litsenziya tekshiruvi to'siq va nazorat, mutlaq himoya emas (tafsilot: [docs/PLATFORM_PLAN.md](docs/PLATFORM_PLAN.md) §8).

## Hozircha yo'q (2–3-bosqich)

Windows o'rnatuvchi (`.exe`) · oflayn faollashtirish kodi · e-pochta/SMS (parolni tiklash, muddat eslatmasi) · onlayn to'lov (Payme/Click) ·
boshqariladigan bulut nusxalari · hisob-faktura PDF · maxfiylik siyosati sahifasi va ko'p tillilik (sayt). Rejada: [docs/PLATFORM_PLAN.md](docs/PLATFORM_PLAN.md) §6.
