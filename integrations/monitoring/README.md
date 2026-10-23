# Biwork Monitoring

Korxona jarayonlarini o'zi kuzatadigan alohida Biwork mahsuloti. Uskunalar, buyurtmalar va reja-fakt ma'lumotini qoidalar bo'yicha tekshiradi
va muammoni *kechikmasdan* mas'ul odamga ko'rsatadi: **nima bo'ldi · nima uchun muhim · nima qilish kerak**. Interfeys o'zbek tilida, yorug' va qorong'i ko'rinishli.

Biwork Reja bilan birga ishlashga mo'ljallangan (Reja «qanday bo'lishi kerak»ni, Monitoring «aslida qanday bo'layotgani»ni beradi), lekin alohida mahsulot:
har bir korxona uni alohida sotib oladi. Reja panelidagi texnologiya va ko'rinish aynan shu yerda ham: PHP 8, SQLite, framework yo'q, tashqi saytlarsiz.

## Hozirgi holat (v0.1)

| Tayyor | Tayyor emas (keyingi bosqich) |
|---|---|
| 10 ta qoida, chegaralari sozlanadi; ogohlantirish hayoti: ochiladi → ko'rdim → mas'ul → yopiladi (shart yo'qolsa o'zi yopiladi) | Haqiqiy ma'lumot manbalari: ERP hisobotini (Excel) papkadan o'qish, ERP bazasiga ulanish |
| Bosh sahifa, ogohlantirishlar, uskunalar, buyurtmalar, reja va fakt, qoidalar, ma'lumot manbalari | Biwork Rejadan rejani avtomatik olish va «qayta tuzish» havolasi |
| 14 uskunali to'qilgan namuna korxona, soatni surib ko'rish (+1 soat va boshqa qadamlar) | Telegramga ogohlantirish, haftalik hisobot |
| Biwork shartnomasi: manifest, «Ochish» tokeni (SSO), litsenziya tekshiruvi, umumiy namuna rejimi | Loyiha vazifalari va Savdo kuzatuvi |

Mahsulot portalda `status: soon` («tez orada») bilan turadi: namuna ishlaydi, lekin mijoz ma'lumotini ulash hali umumiy ko'rinishda tayyor emas.
Reja va qaror hujjati: [`docs/REJA.md`](docs/REJA.md). Portal tomoni uchun ro'yxat: [`docs/PORTAL-UCHUN.md`](docs/PORTAL-UCHUN.md).

## Qoidalar

| Qoida (kod) | Nimani topadi | Standart chegara (sozlanadi) |
|---|---|---|
| `stop_long` Uskuna uzoq to'xtab turibdi | to'xtash uzoq cho'zildi (tanaffus va «ish yo'q» hisoblanmaydi) | 60 daqiqa; 3 baravar uzoq bo'lsa «jiddiy» |
| `stop_repeat` Uskuna tez-tez buziladi | bir uskuna qisqa vaqtda bir necha marta ta'mirga to'xtadi | 7 kunda 3 marta; 5 marta bo'lsa «jiddiy» |
| `idle_ready` Uskuna bo'sh, ish tayyor | uskuna ishlamayapti, navbatda esa tayyor buyurtma bor | 30 daqiqa; 4 baravar uzoq bo'lsa «jiddiy» |
| `start_late` Ish rejadagi vaqtda boshlanmadi | rejadagi ish kechikdi; sababi: uskuna to'xtagan, oldingi ish cho'zilgan yoki oldingi bosqich tugamagan | 45 daqiqa; 180 daqiqa bo'lsa «jiddiy» |
| `late_risk` Buyurtma muddatga ulgurmasligi mumkin | qolgan ishlar va haqiqiy kechikishdan tugash vaqti taxmin qilinadi va muddat bilan solishtiriladi | muddatgacha kamida 4 soat zaxira |
| `setup_long` Sozlash me'yordan ko'p ketyapti | sozlash me'yordagi vaqtdan oshdi | 1,5 baravar, kamida 20 daqiqa |
| `overrun` Ish me'yor vaqtidan oshib ketdi | buyurtma uskunada me'yordan ko'p ishlagan, lekin tugamagan | 1,3 baravar, kamida 30 daqiqa |
| `queue_overload` Uskuna oldida ish yig'ilib qoldi | uskuna oldidagi ish hajmi (soatda) tor joyga aylandi | 48 soat; 1,5 baravar ko'p bo'lsa «jiddiy» |
| `no_reason` To'xtash sababi yozilmagan | sababsiz to'xtashlar ko'payganda eslatadi | kamida 20 daqiqalik to'xtash, oxirgi 24 soatda 2 ta |
| `source_stale` Ma'lumot yangilanmagan | hisobot yoki fayl odatdagidan uzoq yangilanmadi | 1,5 baravar; 3 baravar bo'lsa «jiddiy» |

Chegaralar *Qoidalar* sahifasida o'zgartiriladi (yoki `src/Rules.php` dagi standartlar). Bir muammo uchun faqat bitta ochiq ogohlantirish bo'ladi; jiddiylik pasaysa,
oxirgi kuchayishdan keyin 3 soat kutiladi (ogohlantirish «miltillamasligi» uchun); qo'lda yopilgan ogohlantirish 4 soat qayta chiqmaydi.

## Ishga tushirish

Talab: PHP 8.1+ (`pdo_sqlite`, `mbstring`, `json`, `sodium`).

```bash
cd app
bash start.sh                         # http://localhost:8090  (Windows: start.bat)
php bin/set_password.php admin 'kamida-10-belgi'   # kirish uchun foydalanuvchi (namuna ham parol bilan ochiladi)
cp config.php.sample config.php       # kerak bo'lsa sozlamalar
```

Hosting (Plesk va boshqalar): hujjatlar ildizi = `app/public`. `data/` papkasi yoziladigan bo'lishi kerak (baza, sessiya, litsenziya fayllari); u tashqariga yopiq.

- Birinchi so'rovda baza va namuna korxona o'zi yaratiladi (`php bin/seed.php` bilan qo'lda ham mumkin).
- Namuna soati qo'lda suriladi (yuqoridagi «+1 soat»). Haqiqiy ma'lumotga o'tilganda `demo_data => false`, qoidalar esa cron bilan ishlaydi: `*/5 * * * * php /yo'l/app/bin/tick.php`.
- Tuzatish uchun: `php bin/status.php` (uskunalar, ochiq ogohlantirishlar, xavfdagi buyurtmalar).

## Biwork bilan ulanish

Shartnoma: [`../../docs/TOOL_CONTRACT.md`](../../docs/TOOL_CONTRACT.md). Barchasi `config.php` dagi `biwork` bo'limi bilan yoqiladi (bo'lim yo'q bo'lsa mahsulot mustaqil ishlaydi);
sozlamalar namunasi: [`app/config.php.sample`](app/config.php.sample).

| Qism | Qayerda |
|---|---|
| `manifest.json` (nom, tariflar, reklama sahifasi matnlari; `status: soon`) | [`manifest.json`](manifest.json) |
| «Ochish» tokeni (`?bw_launch=`): imzo, muddat, bir martalik `jti`, `aud=monitoring`; demo rejimda cheklangan rol, `live` rejimda portal administratori — panel administratori | `app/src/Biwork.php`, `app/public/index.php` |
| Litsenziya tekshiruvi (`BiworkConnect` SDK, Ed25519): `enforce` bilan faol litsenziyasiz ochilmaydi, API `402 license_required`, holat sahifasi `/license.php` | `app/src/BiworkConnect.php` (SDK nusxasi), `app/public/license.php` |
| Umumiy namuna (`demo => true`): har tashrifchiga alohida baza nusxasi, qoidalarni o'zgartirish yopiq | `Biwork::visitorCopy()` |
| Portal ochiq kalitlari | `app/biwork-public.json` (`curl -s https://biwork.uz/api/v1/pubkey > app/biwork-public.json`) |

Reliz paketi: `PUBKEY=biwork-public.json bash tools/make_release.sh 0.1` → `dist/biwork-monitoring-0.1.zip` (baza, sessiya, `config.php` va sinovlarsiz).

## Sinovlar

```bash
cd app
bash tests/run.sh                    # birlik (44) + HTTP/SSO/litsenziya (57) sinovlari, vaqtinchalik serverlar ko'taradi
PORTAL_SRC=/yo'l/biwork/portal/src bash tests/run.sh   # portal manbasi boshqa joyda bo'lsa (SSO uchun)
```

Sinovlar portalning haqiqiy `Tokens::sign` bilan imzolangan tokenlarni tekshiradi: to'g'ri token, qayta ishlatilgan, boshqa vosita uchun, muddati o'tgan,
buzilgan, boshqa korxona tokeni; shuningdek CSRF, rollar, umumiy namunaning izolyatsiyasi va litsenziya darvozasi.

## Kod tuzilishi

```
app/public/        index.php (qobiq), login.php, license.php, api.php, router.php, assets/ (app.js, app.css, shriftlar)
app/src/           Rules (qoidalar) · Engine (holat → topilmalar → hayot sikli) · Metrics (ko'rsatkichlar) · Alerts (foydalanuvchi amallari)
                   Feeder (ma'lumot kiritish) · Demo (namuna korxona) · Api · Auth · Biwork + BiworkConnect · Db · Schema · Clock
app/bin/           seed.php, tick.php, status.php, set_password.php
app/tests/         check.php (birlik), http.php + run.sh (HTTP/SSO)
tools/             shots.js, marketing_shots.js (ekran suratlari), make_release.sh
marketing/         reklama sahifasi rasmlari (portalga ko'chiriladi)
docs/              REJA.md, PORTAL-UCHUN.md
```

Hisob-kitob: `Engine::snapshot` (uskunalar, navbatlar, buyurtmalar prognozi) → `Rules` har qoida bo'yicha topilmalar → `Engine::run` ularni ochiq ogohlantirishlar bilan
solishtiradi (yangisini ochadi, kuchayganini yangilaydi, yo'qolganini o'zi yopadi). Vaqt `Asia/Tashkent` (UTC+5), ishlab chiqarish kuni 08:00 dan boshlanadi.

## Reklama rasmlari

```bash
cd app && MON_DB=/tmp/mon.sqlite php bin/seed.php && MON_DB=/tmp/mon.sqlite php -S 127.0.0.1:8090 -t public public/router.php &
NODE_PATH=<playwright> PW_CHROMIUM=<chromium> node tools/marketing_shots.js http://127.0.0.1:8090 marketing-src
python3 ../tools/product_assets.py monitoring --shot bosh=marketing-src/bosh.png … --og-title "Biwork Monitoring" --og-pill "Tez orada"
```

`product_assets.py` natijani portal papkasiga yozadi; ushbu mahsulot uchun tayyor rasmlar `marketing/` da turadi, portalga ko'chirish [`docs/PORTAL-UCHUN.md`](docs/PORTAL-UCHUN.md) da.
Rasmlardagi uskuna, buyurtma va odam nomlari to'liq to'qilgan; haqiqiy mijoz ma'lumoti yo'q.
