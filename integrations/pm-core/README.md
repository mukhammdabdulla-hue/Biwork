# PM ni Biwork mahsulotlari sifatida ulash (`pm-core`)

PM (boshqaruv tizimi: PHP 8 + SQLite, bitta sahifali ilova) beshta alohida Biwork mahsulotiga bo'lingan:
**Biwork HR · Biwork Loyiha · Biwork Savdo · Biwork Moliya · Biwork Yuridik**. Har biri portalda o'z sahifasi, tarifi va litsenziyasiga ega;
mijoz kerakligini sotib oladi, qolgan bo'limlar ko'rinmaydi va ishlamaydi.

PM'ning asl papkasiga **tegilmaydi**. Biwork PM nusxasi ustida quriladi: kichik patch (4 fayl) + yangi fayllar (`overlay/`) + mahsulot ta'riflari.
`biwork-config.php` bo'lmasa PM avvalgidek mustaqil ishlayveradi.

## Mahsulotlar

| Mahsulot (slug) | Ochiladigan bo'limlar (`product.json` → `modules`) | Bosh sahifa | Manifest |
|---|---|---|---|
| Biwork HR (`hr`) | `kontakt` (xodimlar, ish vaqti, tabel, davomat, ro'yxat), `hujjat`, `hisobot` | `#/bolim/dashboard` | [`hr/manifest.json`](../hr/manifest.json) |
| Biwork Loyiha (`loyiha`) | `pm` (loyihalar), `topshiriq`, `kontakt.royxat:view`, `hisobot` | `#/bolim/pm` | [`loyiha/manifest.json`](../loyiha/manifest.json) |
| Biwork Savdo (`savdo`) | `savdo.umumiy`, `savdo.yigim` (demoda "Yuk xatlari" deb ataladi), `hisobot` | `#/bolim/savdo` | [`savdo/manifest.json`](../savdo/manifest.json) |
| Biwork Moliya (`moliya`) | `moliya`, `hisobot` | `#/bolim/dashboard` | [`moliya/manifest.json`](../moliya/manifest.json) |
| Biwork Yuridik (`yuridik`) | `yuridik` | `#/bolim/yuridik` | [`yuridik/manifest.json`](../yuridik/manifest.json) |

Modul kaliti — PM'dagi `SECTIONS` va ularning qism-bo'limlari (`savdo.umumiy`, `kontakt.royxat`…). `:view` — faqat ko'rish huquqi.
Bir mijozda bir nechta mahsulot faol bo'lsa, ochiq modullar **birlashmasi** olinadi (kuchli huquq qoladi: masalan `kontakt.royxat` HR'da `edit`, Loyihada `view` → `edit`).

### Qaysi biri alohida sotiladi, qaysi biri bog'liq

| Mahsulot | Alohida sotiladimi | Bog'liqlik / eslatma |
|---|---|---|
| HR | Ha, to'liq | Hisobotlar katalogi umumiy (`hisobot`), lekin faqat shu mahsulotning hisobotlari ko'rinadi. Ish vaqti PM'da `ishvaqt.uz` integratsiyasidan keladi; demoda qo'lda kiritilgan namuna. |
| Loyiha | Ha, lekin torroq | "G'oyalar" bo'limi PM'da korxona-nomiga bog'langan (`bosh_korxona_id`, "Avtosanoat Invest" mantig'i) — umumiy mahsulotga kiritilmagan. Ish kundaligi uchun ekran yo'q (hisoboti ham yashirilgan). Byudjet bloki Moliya huquqini talab qiladi — Loyihada bo'sh qutiga o'xshab qoladi (CSS bilan yashirilgan joylar bor). |
| Savdo | Ha, torroq | Realizatsiya, lizing, dilerlar bo'limlari bosh korxona mantig'iga bog'langan — kiritilmagan. 1C bilan ulanish yo'q; Excel'dan import bor. |
| Moliya | Ha, torroq | DDS va vedomost importlari faqat "bosh korxona" uchun ishlaydi (PM mantig'i); qolgan korxonalarda qo'lda kiritiladi. |
| Yuridik | Ha | PDF hisobot yo'q, hisobotlar katalogi yopiq. |

Ya'ni **beshovi ham sotilishi mumkin**, lekin Loyiha, Savdo va Moliya PM'ning to'liq imkoniyatidan torroq to'plamni beradi —
qolgan qismi PM'ning bitta korxonasiga (bosh korxona) bog'langan bo'lib, umumiy mahsulotga ajratib bo'lmadi (kodni o'zgartirish kerak, PM'ga tegmaslik sharti bilan qilinmagan).

## Tuzilishi

```
pm-core/
  pm-connector.patch      PM'ga 4 fayl: index.php, php/auth.php, php/hisobot-pdf.php, assets/app.js (tools/make-patch.py bilan yasaladi)
  overlay/                yangi fayllar: php/biwork.php (konnektor), php/BiworkConnect.php (SDK nusxasi), biwork/brand/* (logotip, ikonka)
  tools/                  build.sh, make-package.sh, dev.sh, make-patch.py, scrub.php, brand-text.php, stub-group.php, seed.php (+seed_lib.php), strip-js-comments.js, bwshot.js
  tests/                  run.sh + pm_connector.php (61 tekshiruv)
../<slug>/                product.json, manifest.json, seed.php (xayoliy namuna ma'lumot), marketing/ (sayt rasmlari), README.md
```

**Modul niqobi.** `php/auth.php` dagi `user_perms()` — PM'da huquqlar hisoblanadigan yagona nuqta. Konnektor uning natijasini faol
mahsulotlarning `modules` ro'yxatiga kesadi; server tomoni ham (`state_for_user`, endpointlar), brauzer tomoni ham (`me.bolimlar` → yorliqlar) shundan oladi.
Mahsulotga kirmagan bo'limga yozish `403` qaytaradi (testlarda tekshirilgan).

> **Chegara.** Bu litsenziya darvozasi va ko'rinish chegarasi, mutlaq himoya emas: PHP manba kodi mijozga yetkaziladi, administrator uchun
> server holati filtrlanmaydi (mijoz tomoni niqobga amal qiladi). Mijoz kodni o'zgartirsa, yopiq bo'limlarni ochishi mumkin — bu shartnoma bilan
> yopiladi, texnik jihatdan emas.

## Ikki rejim (`biwork-config.php`)

| | Demo (`mode: demo`) | App (`mode: app`) |
|---|---|---|
| Kim ishlatadi | Sayt mehmoni / mijoz portaldan "Ochish" bilan | Mijoz o'z serverida |
| Kirish | Portal tokeni (`bwl1`) → cookie `bw_sbx` | Litsenziya kaliti (`/licenziya`) + SSO (portaldan) |
| Ma'lumot | Har (mijoz × mahsulot) uchun alohida sandbox `data/demo/c<id>-<slug>/`, shablon `data/templates/<slug>/` dan; 30 kundan keyin tozalanadi | Mijozning o'z bazasi (`data/`), bo'sh boshlanadi |
| Litsenziyasiz | Portalga qaytariladi | Tizim yopiq, API `402 license_required` |
| Mahsulotlar | Barcha beshtasi bitta hostda | Faqat sotib olingani (paketda boshqalarning `product.json`i yo'q) |

Demo uchun **bitta** PM nusxasi yetadi: portalda beshala mahsulotning `tool_urls` manzili shu PM demo manziliga ko'rsatiladi
(token ichidagi mahsulot kaliti qaysi shablon ochilishini belgilaydi).

## Qurish va sinash

```bash
# 1) sinov (vaqtinchalik portal + demo + app + --keep-group nusxasi, 81 tekshiruv)
PM_SRC=/yo'l/PM_nusxasi bash integrations/pm-core/tests/run.sh      # NODE_PATH=/opt/node-tools/node_modules kerak

# 2) demo host (Biwork serverida; barcha mahsulotlar + xayoliy shablonlar)
bash integrations/pm-core/tools/build.sh /yo'l/PM_nusxasi /var/www/pm-demo --mode demo --server https://biwork.uz --pubkey https://biwork.uz/api/v1/pubkey --templates

# 3) mijoz paketi (zip): faqat sotib olingan mahsulotlar
bash integrations/pm-core/tools/make-package.sh /yo'l/PM_nusxasi hr-savdo.zip --products hr,savdo --server https://biwork.uz --pubkey https://biwork.uz/api/v1/pubkey --version 1.0
php tools/cli.php release:add hr 1.0 server hr-savdo.zip       # (har mahsulot sahifasiga; paketda ikkalasi ham bor)

# 4) bitta mahsulot uchun ishlab chiqish muhiti (portal + demo, fonda)
bash integrations/pm-core/tools/dev.sh up <slug> /yo'l/PM_nusxasi <ish_papka> <port>
```

`build.sh` nima qiladi: PM nusxalanadi (haqiqiy `data/` OLINMAYDI) → patch → `overlay/` → JS izohlari olib tashlanadi
(ichki izohlarda haqiqiy tashkilot nomlari bor) → PDF'dagi eski brend (`Avtosanoat Invest`, `AI-`) Biwork'ga almashtiriladi va logotip qo'yiladi →
app rejimida PHP/HTML izohlari va ichki hujjatlar (`DIZAYN.md`, `MALUMOTNOMA.md`, `QOLLANMA.md`, `server-sozlama/`) ham olib tashlanadi (`tools/scrub.php`).
Oxirida guruhga xos A qismi bo'shatiladi, `php/seed/gh-biznes-reja.json` olib tashlanadi (`tools/stub-group.php`; `--keep-group` bilan bu qadam o'chadi, `make-package.sh` ham shu bayroqni o'tkazadi). Fon rasmi `assets/bino.webp` har doim `overlay/` dagi neytral rasm bilan almashadi.

## Portal tomonida kerak bo'ladigan ishlar (bu papka portalga tegmaydi)

1. Har mahsulot uchun manifestni ro'yxatga olish: `php tools/cli.php tool:register integrations/<slug>/manifest.json`.
2. Admin → *Mahsulotlar va relizlar*: beshala mahsulotning **brauzer (demo) manzili** — bitta PM demo hosti.
3. Sayt rasmlarini `integrations/<slug>/marketing/*` dan `portal/public/assets/products/<slug>/` ga ko'chirish (manifestdagi `shots[].file` shu nomlar).
4. Har mahsulot uchun reliz paketi (`make-package.sh`) va `release:add`.

## Yangi mahsulot qo'shish (PM bo'limlaridan)

1. `integrations/<slug>/product.json` — `slug`, `code`, `name`, `sub`, `home`, `modules`, ixtiyoriy `css` (ortiqcha tugmalarni yashirish).
2. `integrations/<slug>/seed.php` — xayoliy namuna ma'lumot (`tools/seed_lib.php` yordamchilari bilan). Haqiqiy nom/ism ishlatilmaydi.
3. `manifest.json` + `marketing/` (qoidalar: [`docs/SITE_VA_REKLAMA.md`](../../docs/SITE_VA_REKLAMA.md)).
4. `tests/run.sh` dagi mahsulot ro'yxatiga qo'shing va yuriting.

## PM yangilanganda

`pm-connector.patch` PM'ning 4 faylidagi kichik joylarga yozilgan. PM o'zgarsa: `python3 tools/make-patch.py <yangi_PM_papka>` bilan
patchni qayta yasang (har almashtirish aniq bir marta uchrashi shart — aks holda to'xtaydi, shunda `EDITS` ni moslang), so'ng `tests/run.sh` ni yuriting.
PM'da yangi bo'lim qo'shilgan bo'lsa u hech bir mahsulotga kirmaydi (niqob uni yopadi) — kerakli `product.json` ga qo'shing.

## Ma'lum kamchiliklar

- Kodda `'avtosanoat invest'` mantiq literallari qoldi (PM bosh korxona tushunchasi); `api.php` da xato matni ("Nazorat qarorini faqat Avtosanoat Invest administratori…") va rus tili lug'atida (`til-lugat.js`) eski nom bor. Ular foydalanuvchiga kamdan-kam ko'rinadi, lekin Biwork'ga to'liq moslash uchun PM kodini o'zgartirish kerak.
- Mijoz paketida PM'ning PHP kodi to'liq bor, **guruhga xos A qismi bundan mustasno**: OTP/lizing/realizatsiya/ДДС fayllari (`otp-*.php`, `lizing-*.php`, `realizatsiya.php`, `dds.php` va `otp*/lizing*.js`) paket yig'ishda bo'shatiladi (stub, `tools/stub-group.php`), `php/seed/gh-biznes-reja.json` (Green House reja raqamlari) olib tashlanadi, fon rasmi `assets/bino.webp` (asl PM'da «UzAuto» yozuvli bino) neytral rasmga almashtirilgan. Qaytarish: `build.sh ... --keep-group` (stub va seed olib tashlash o'chadi). Qolgani va sababi: `GURUH-XOS-QISMLAR.md`. **Qaror kutilmoqda:** B (Moliya 1C shakllari: `bp-moliya.php`, `balans*.php`, `gh-*.php`) hozircha asl holida; D (nomga bog'langan mantiq) PM kodini o'zgartirishni talab qiladi. Uchinchi tomonga sotishdan oldin PM egasi bilan kelishish baribir kerak.
- Demo ma'lumotdagi sanalar shablon qurilgan kunga nisbatan; ko'p kundan keyin eskirib ko'rinadi — `build.sh --templates` ni qayta yuritib yangilang.
- Demo ma'lumot to'liq xayoliy (korxona, ism, raqam), lekin PDF/Excel eksportlar PM'ning o'z andozasi bo'yicha chiqadi.
- **Mijozda birinchi ishga tushirish (app).** Savdo va Moliya yorliqlari PM'da korxona kartasidagi bayroqlarga bog'liq (`savdoAktiv`, `biznesAktiv`, `kassaYoq`, `qoldiqYuritish`, `bpTur`) — ular faqat PM **administrator paneli**da yoqiladi (admin paneli niqoblanmagan). Demo ma'lumot bayroqlarni o'zi qo'yadi, lekin mijozning toza bazasida administrator avval korxona yaratib, bayroqlarni yoqishi kerak. Hozir bu o'rnatish yo'riqnomasida (`BIWORK-ORNATISH.txt`) yozilmagan va avtomatlashtirilmagan.
- PM nuqsonlari (PM'ga tegilmadi; PM egasiga uzating): `php/yukxati-pdf.php` — `rasmOlcham()` `['w','h']` qaytaradi, kod esa `$ol[0]`, `$ol[1]` o'qiydi (korxona logotipi hech qachon chizilmaydi); global qidiruv savdo va sud ishlarini qidirmaydi; import ko'rinishida «Miqdor» ustunida «500 so'm» chiqadi; Hisobotlar markazi menyuga ulanmagan (faqat `#/bolim/hisobot`); yangi debitor/kreditor qo'lda qo'shilmaydi (faqat vedomost orqali); loyiha/topshiriq/g'oya, savdo realizatsiyasi va nazoratdan yechish korxona **nomiga** (`avtosanoat invest`) bog'langan — bayroq bo'lishi kerak.
- Mahsulotlarning har birining kamchiliklari `integrations/<slug>/README.md` da.
