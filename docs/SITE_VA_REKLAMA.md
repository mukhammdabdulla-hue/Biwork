# Ochiq sayt va reklama (Biwork vitrinasi)

Biwork — korxonalar sotib oladigan **mahsulotlar** (Biwork Reja, Biwork HR, Biwork Savdo, …) platformasi. Ochiq sayt (`/`) shu
mahsulotlarni internetda reklama qiladi: har mahsulotning o'z reklama sahifasi bor, qidiruv tizimi va ijtimoiy tarmoq uni to'g'ri ko'rsatadi,
tashrif buyuruvchi **"Sinab ko'rish"** yoki **"So'rov qoldirish"** ni bosadi.

## Manzillar

| Manzil | Nima | Qidiruvda |
|---|---|---|
| `/` | Bosh sahifa: Biwork nima ekani (bitta jumla), **barcha mahsulotlar kartalari** (qisqa tavsif, «Batafsil», «Sinab ko'rish»), har mahsulot qo'llanmasiga havola, so'rov shakli | ha |
| `/mahsulotlar` | Hamma mahsulotlar katalogi | ha |
| `/<slug>` (masalan `/reja`) | Mahsulotning reklama sahifasi | ha (`hidden` — yo'q) |
| `/sorov` (POST), `/rahmat` | Murojaat yuborish; "Rahmat" sahifasi — **reklama konversiyasi shu manzilda hisoblanadi** | yo'q |
| `/sitemap.xml`, `/robots.txt` | Qidiruv tizimlari uchun (mahsulot qo'shilsa sitemap o'zi yangilanadi) | — |
| `/tools`, `/tools/<slug>` | Eski manzillar: doimiy (301) yo'naltirish | — |
| `/login`, `/register`, `/app/*`, `/admin/*`, `/api/*` | Kabinet va admin | yopiq (noindex + robots) |

## Yangi mahsulot sahifasini chiqarish

### 0. Paketda tayyor mahsulotlar: Biwork HR, Loyiha, Savdo, Moliya, Yuridik, Monitoring («tez orada»)

Bu beshtasining matni, 6 tadan ekran rasmi, ulashish rasmi va 8–10 ta savol-javobi tayyor (`integrations/<slug>/manifest.json`, rasmlar
`portal/public/assets/products/<slug>/`). *Admin → Mahsulotlar* tepasida **«Tayyor mahsulotlar»** bloki chiqadi: **«Hammasini qo'shish»** (yoki bittadan «Qo'shish») bosilsa,
sahifalari darhol `/hr`, `/loyiha`, `/savdo`, `/moliya`, `/yuridik` da ochiladi, katalog va sitemap yangilanadi. Qo'shilgach ham har birini formada o'zgartirish mumkin.

Ular **narxsiz** va **demo manzilsiz** qo'shiladi (to'qib chiqarilmaydi): tariflarda «Narx so'rov bo'yicha», sahifada faqat «So'rov qoldirish».
Beshalasi bitta PM demo hostida ishlaydi; host tayyor bo'lgach, *Mahsulotlar* sahifasidagi «Bitta demo manzilni bir nechta mahsulotga qo'yish» bilan manzilni bir marta yozing:
shundan keyin ularda «Sinab ko'rish» tugmasi chiqadi (host ishlayotganiga ishonch hosil qilib qo'ying).

**Biwork Monitoring** ham shu blokda turadi, lekin manifestida holat **«Tez orada»** (`status: soon`): u «Qo'shish»dan keyin ham sahifada «Sinab ko'rish» va tariflarsiz chiqadi,
tepada «Tez orada» belgisi va «Chiqqanda xabar bering» shakli bo'ladi, katalogda kartasi xira ko'rinadi. Demo manzil unga qo'yilmaydi (u bitta PM demo hostiga kirmaydi:
«Bitta demo manzil» ro'yxatida yo'q). Haqiqiy ma'lumot ulash tayyor bo'lgach: mahsulot formasida demo manzil va tariflarni yozing va «Saqlash va e'lon qilish»ni bosing.

### A. Admin formasi (oddiy yo'l, dasturchi kerak emas)

*Admin → Mahsulotlar → «Yangi mahsulot»*. Faqat nomni yozib «Qoralama sifatida saqlash»ni bosing: mahsulot **yashirin** yaratiladi (saytda ko'rinmaydi,
xodimlar ko'radi). Keyin formani bo'limma-bo'lim to'ldirasiz:

1. **Asosiy ma'lumot** — nom, qisqa tavsif (katalogdagi karta), batafsil tavsif, bo'lim, belgi.
2. **Sahifaning tepa qismi** — katta sarlavha, izoh, qisqa belgilar (ixtiyoriy).
3. **Kimga kerak**, 4. **Qanday muammolarni hal qiladi**, 5. **Imkoniyatlar** — qator qo'shish, ↑ ↓ bilan tartiblash.
6. **Ekran rasmlari** — «Rasm qo'shish» (PNG/JPG/WEBP, bittasi 12 MB gacha; server o'zi 1600 px ga kichraytiradi), yozuv, ↑ ↓ tartib, «O'chirish».
   Birinchi rasm sahifa tepasida chiqadi va ijtimoiy tarmoqda ulashish rasmiga (avtomatik 1200×630) aylanadi.
7. **Savol-javob**, 8. **Qo'llanma: qanday ishlatiladi** (qadamma-qadam: nom, tushuntirish, qisqa bandlar, ekran rasmi, eslatmalar; mahsulot sahifasida `#qollanma`, bosh sahifada qisqa ko'rinishi chiqadi),
9. **Tariflar va narx** (narx yozilmasa «Narx so'rov bo'yicha»; xohlasangiz «1 000 000 so'mdan» kabi yozuv), 10. **Brauzerda sinab ko'rish** (manzil, sinov muddati; yangi mahsulotda standart 30 kun),
11. **Qo'shimcha** — sayt manzili va litsenziya kodi, tugma matnlari, Google ko'rinishi (SEO).

«Oldindan ko'rish» saqlamasdan sahifani yangi oynada ko'rsatadi (yangi yuklangan rasmlar saqlangandan keyin chiqadi). Tayyor bo'lgach **«Saqlash va e'lon qilish»**:
sahifa `/<manzil>` da ochiladi, katalog va sitemap o'zi yangilanadi. «Tez orada» — sahifa chiqadi, lekin sinash o'rniga mijoz xabar kutish uchun so'rov qoldiradi.
«Yashirish» — saytdan olib tashlaydi (sahifa 404, sitemap dan chiqadi). Yon paneldagi «E'lon qilishdan oldin» ro'yxati nima yetishmayotganini ko'rsatadi.

**30 kunlik bepul sinov.** Standart muddat 30 kun (`portal/config.php` dagi `trial_days`, 1–90; mahsulot formasida har mahsulotga alohida ham qo'yiladi).
Sayt «30 kun bepul sinov» yozuvini hamma joyda ko'rsatadi, lekin **tugma faqat mahsulotning brauzer manzili ulangan bo'lsa chiqadi**. Manzil bo'lmasa kartada «demo hali ulanmagan»,
mahsulot sahifasida esa nima yetishmayotgani yoziladi va mijoz «So'rov qoldirish»ni bosadi (ishlamaydigan narsa va'da qilinmaydi). Eski berilgan sinov litsenziyalari o'zgarmaydi.

**Qo'llanma matnlari paketda.** Paketdagi mahsulotlarning qo'llanmasi `portal/content/<slug>.json` da (`{"guide":{"intro","steps":[{"title","text","shot","points"}],"tips"}}`; `shot` — mahsulot rasm fayli nomi).
«Qo'shish»da (yoki `tool:register` da) mahsulot bilan birga o'qiladi; allaqachon qo'shilgan mahsulotda qo'llanmani admin formasidan tahrirlaysiz.

Eslatmalar:
- **Mahsulot nomi dasturda qattiq yozilmagan**: har bir nom shu formada o'zgartiriladi. Nom o'zgarsa, sayt manzili o'zgarmaydi (u alohida, «Qo'shimcha» bo'limida).
- **Sayt manzili va kod** litsenziya berilgunga yoki reliz yuklanguncha o'zgartirilishi mumkin; keyin qulflanadi (kalitlar kodga bog'liq). Manzil o'zgarsa, eski havola yangisiga 301 bilan o'tadi.
- Yuklangan rasmlar `data/uploads/tools/<id>/` da saqlanadi — **zaxira nusxaga `data/` ni ham kiriting** (baza ham shu yerda).
- **Brauzerda sinab ko'rish manzili** bo'sh bo'lsa, sahifada «Sinab ko'rish» ko'rinmaydi, faqat «So'rov qoldirish» (ishlamaydigan sinov va'da qilinmaydi).
- Server rasm chegarasi (`upload_max_filesize`/`post_max_size`, hostingda ko'pincha 2–8 MB) kichik bo'lsa, forma buni tushunarli xabar bilan aytadi; rasmlarni bittadan yoki kichikroq yuklang.

### B. Manifest orqali (dasturchilar uchun)

1. **Manifest** — `integrations/<slug>/manifest.json` (`name` — masalan `Biwork Reja`, `slug`, `code`, `tagline`, `description`, `plans`, `trial`) va
   `marketing` bo'limi: sarlavha, muammolar, imkoniyatlar, kimga kerak, savol-javob, SEO matni. Maydonlar: [TOOL_CONTRACT.md §1.1](TOOL_CONTRACT.md).
2. **Ekran rasmlari** — mahsulotning haqiqiy ekranlaridan (namuna ma'lumot bilan!):
   ```bash
   python3 tools/product_assets.py hr \
     --shot royxat=/yo'l/xodimlar.png --shot tahlil=/yo'l/tahlil.png \
     [--mask x,y,w,h]   # rasmdagi maxfiy yozuvni (korxona nomi) atrofi rangi bilan yopadi \
     --og-title "Biwork HR" --og-tagline "Qisqa tavsif" --og-shot royxat
   ```
   Natija `portal/public/assets/products/hr/` ga tushadi: `<nom>.webp` (1600 px gacha) va `og.png` (1200×630, ijtimoiy tarmoq ulashish rasmi).
   **Rasmda haqiqiy mijoz nomi, narx, shaxsiy ma'lumot bo'lmasin** — chiqarishdan oldin har rasmni ko'zdan kechiring.
3. **Brauzer manzili**: admin → *Mahsulotlar* → mahsulot → *Brauzerda sinab ko'rish* (yoki manifestda `web.url`). **Reklamani yoqishdan oldin har mahsulot uchun shuni to'ldiring.**
4. **Ro'yxatga olish**: admin → *Mahsulotlar* → pastdagi «manifest.json orqali qo'shish» (yoki `php tools/cli.php tool:register integrations/hr/manifest.json`).
   Sahifa darhol `/hr` da ochiladi. Hosting: rasmlar papkasini ham yuklang (`tools/make_deploy.sh` paketiga kiradi).
5. **Tekshirish**: sahifani telefonda oching; havolani Telegram/Facebook'ga tashlab ulashish ko'rinishini ko'ring.

**Ikki yo'lni aralashtirmang.** Forma bilan tahrirlangan mahsulotga manifest qayta yuklansa, sahifa matnlari, rasm nomlari, brauzer manzili va holat manifestdagilar bilan
almashadi (tariflar va narx o'zgarmaydi). Shuning uchun admin panelida mavjud mahsulotni almashtirish alohida belgi bilan tasdiqlanadi. Bir mahsulot uchun bitta yo'lni tanlang:
dasturchilar manifest bilan yuritsa, xodim formaga tegmasin, va aksincha.

**Halol da'vo qoidasi.** Sahifadagi raqam va va'dalar faqat haqiqatan tekshirilgan bo'lsin; namuna ma'lumotdagi natija "namuna ma'lumotda" deb yozilsin.
Reklama sahifasi va'da qilgan narsani mahsulot bermasa, murojaatdan mijoz chiqmaydi.

## Qidiruv va ijtimoiy tarmoq (avtomatik)

Har ochiq sahifada: `<title>` va `description` (manifestdagi `marketing.seo`), `canonical`, Open Graph (Facebook, Telegram, LinkedIn),
Twitter karta (katta rasm), `robots`, JSON-LD (Organization, WebSite, ItemList, SoftwareApplication, BreadcrumbList, FAQPage).
`sitemap.xml` — `lastmod` mahsulot yangilangan sanasi. Ulashish rasmi mahsulotniki (`og.png`), bo'lmasa umumiy (`assets/og-default.png`).

Siz qiladigan ish (bir marta, domen chiqqach):
- Google **Search Console** va Yandex **Webmaster** da saytni tasdiqlab, `https://<domen>/sitemap.xml` ni yuboring.
- `portal/config.php` da `'base_url' => 'https://biwork.uz'` ni qo'ying (kanonik va sitemap manzillari shundan olinadi; bo'lmasa so'rovdagi domen ishlatiladi).

## Reklama kampaniyasi

- **Havola**: reklamada mahsulot sahifasini UTM bilan bering:
  `https://<domen>/reja?utm_source=facebook&utm_medium=cpc&utm_campaign=bahor`. `utm_*` qiymatlari 30 kunlik cookie ga yoziladi va
  keyin **murojaatga** (*Admin → Murojaatlar*, "manba") hamda **ro'yxatdan o'tgan korxonaning izohiga** ("Manba: …") tushadi — qaysi reklama mijoz keltirganini ko'rasiz.
  UTM bo'lmasa, tashqi saytdan kelganda (`Referer`) shu sayt nomi yoziladi.
- **Konversiya**: murojaat yuborilgach brauzer `/rahmat?t=<slug>` ga o'tadi — Meta/Google/Yandex da shu manzilni "murojaat" hodisasi qilib belgilang.
- **Piksel / hisoblagich**: `portal/config.php` ga `'tracking_html' => '<script>…</script>'` — kod faqat ochiq sahifalar `<head>` iga qo'yiladi
  (kirish, kabinet va admin sahifalarida yo'q).
- **Aloqa** (pastki qism): `'contact' => ['phone' => '+998 71 200 00 00', 'telegram' => 'biwork_uz', 'email' => 'salom@biwork.uz']`.
- **Biwork AI (Biwork Reja ichida)** haqida yozganda: u ixtiyoriy va kalit kiritilmaguncha o'chiq; yoqilganda kerakli ma'lumot tashqi provayderga (Anthropic) yuboriladi, bazaga esa faqat foydalanuvchi tasdiqlagandan keyin yoziladi.
  «Ma'lumot faqat sizda qoladi» deb yozmang. «Ishonchli», «kafolatlangan» kabi so'zlar ham mos emas: Biwork AI haqiqiy Anthropic kaliti bilan hali sinalmagan (faqat soxta server bilan). Reja sahifasidagi savol-javob shunga moslangan.

## Murojaatlar

*Admin → Murojaatlar*: ism, korxona, telefon/email, izoh, qaysi mahsulot va qaysi reklama manbasidan; holat: Yangi → Bog'lanildi → Mijoz bo'ldi / Rad etdi + ichki izoh.
Boshqaruv panelida va menyuda yangi murojaatlar soni ko'rinadi.

**Telegram xabari** (ixtiyoriy): yangi murojaat kelganda botga xabar. `portal/config.php`:
```php
'notify' => ['telegram_token' => '123456:ABC…', 'telegram_chat' => '-100123456789'],
```
Bot yarating (@BotFather), uni guruhga/chatga qo'shing va chat raqamini kiriting. Telegram javob bermasa murojaat baribir saqlanadi (xato `data/error.log` ga yoziladi).

**Spamdan himoya**: yashirin maydon, juda tez yuborilgan shakl (<2 s), bir IP dan soatiga 5 ta va kuniga jami 300 ta murojaat. Bot "muvaffaqiyatli" ko'rinadi, lekin saqlanmaydi.

## Hozircha yo'q

- **Maxfiylik siyosati / shartlar sahifasi** — shakl telefon va email yig'adi; reklama platformalari (Meta, Google) piksel bilan ishlaganda ko'pincha shuni talab qiladi.
- Ko'p tillilik (rus/ingliz), blog/maqolalar, mijozlar fikri va logotiplari (haqiqiy fikr bo'lgach qo'shiladi). Tarif narxlari formada kiritiladi; kiritilmaguncha «Narx so'rov bo'yicha».
- Murojaatni e-pochta bilan yuborish (hozir faqat admin paneli va ixtiyoriy Telegram), A/B sinov, analitika paneli.
