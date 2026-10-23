# Demo versiyalari (brauzerda sinab ko'rish)

Admin → **Mahsulotlar** → mahsulot kartasi → **«Demo versiyalari»**. Har versiyada: raqam (masalan 1.3), izoh («nima o'zgardi»)
va turi. **Joriy** versiya — saytdagi «Sinab ko'rish» va mijozning «Ochish» tugmasi ochadigan demo. Birinchi versiya joriy
qilingach, mahsulot formasidagi «Brauzer manzili» hisobga olinmaydi (`config.php` dagi `tool_urls` sinov sozlamasi esa ustun).
Kod: `portal/src/Demos.php`, `routes_demos.php`, `views/_admin_demos.php`; sinov: `tests/demos.php`.

## Ikki tur
- **Manzil (URL)** — tavsiya etiladi. Demo alohida subdomen/hostingda ishlaydi (`https://reja-demo.biwork.uz/`), versiya shu manzilni
  saqlaydi. Yangi versiya = yangi manzil (yoki o'sha manzil, yangi izoh bilan). Faqat `http://` / `https://`.
- **Paket (zip)** — portal o'zi joylashtiradi: zip `data/demos/<id>/` da saqlanadi, joriy qilinganda `portal/public/demo/<slug>/` ga
  ochiladi va `/demo/<slug>/` manzilida ishlaydi. Oddiy (bitta papkali) PHP yoki HTML demolar uchun.

## Zip qanday tayyorlanadi
```
reja-demo.zip
└── reja-demo/            ← bitta umumiy papka bo'lsa, o'zi tushirib qoldiriladi
    ├── index.php         ← yoki index.html — ildizda bo'lishi SHART
    ├── assets/app.css
    └── data/namuna.json
```
- Yo'llar nisbiy bo'lsin: demo `/demo/<slug>/` ostida ochiladi, `/assets/app.css` kabi mutlaq yo'l ishlamaydi (`assets/app.css`
  yoki `SCRIPT_NAME` dan olingan prefiks kerak).
- Rad etiladi: `..` yoki `/` bilan boshlanadigan yo'l, simvolik havola, `.user.ini`/`php.ini`, 5000 tadan ko'p fayl, ochilganda 300 MB dan katta,
  ildizda index yo'q. **Hujjatlar ildizi `public/` bo'lgan ilovalar** (`public/index.php` + `src/` + `data/`, masalan Reja paneli) ham rad
  etiladi: portal ichida ochilsa `src/`, `data/` veb orqali ochilib qolardi. Ularni subdomenga o'rnating va «Manzil (URL)» qo'shing.
- Demo o'z papkasiga yozishi mumkin (namuna baza va h.k.). «Qayta joylashtirish» papkani zipdan qayta ochib, boshlang'ich holatga qaytaradi.

## Admin'da qadamlar
1. Kartada «Demo versiyalari»ni oching. Versiya raqami avtomatik taklif qilinadi (1.2 → 1.3).
2. Turini tanlang: manzilni yozing yoki zipni tanlang; izohga nima o'zgarganini yozing.
3. «Darhol joriy qilish» belgisi turgan bo'lsa, «Saqlash»dan keyin saytda shu versiya ochiladi. Belgisiz — faqat saqlanadi.
4. Tekshirish: kartadagi havolani oching (paket: `/demo/<slug>/`), so'ng mahsulot sahifasidagi «Sinab ko'rish»ni bosing.

## Avvalgisiga qaytarish
- Eski versiya qatorida **«Shu versiyaga qaytarish»**. Manzil versiyasi darhol; paket versiyasi o'z zipidan qaytadan ochiladi
  (eski nusxa saqlanmaydi, almashtirish vaqtinchalik papka orqali — demo yarim ochilgan holda qolmaydi).
- Paketdan manzil versiyasiga o'tilsa, ochilgan paket papkasi o'chiriladi. Joriy versiyani o'chirib bo'lmaydi.
- Paket papkasi yo'qolsa (portal fayllari qayta yuklanganda), saytda «Sinab ko'rish» yashiriladi, kartada «Qayta joylashtirish» chiqadi.
- **Ilova relizlari** (yuklab olish paketlari): mijoz ro'yxatida «Oxirgi» belgili reliz birinchi turadi. Yangisida xato bo'lsa,
  eskisida **«Oxirgi qilish»**ni bosing; «Oxirgi» reliz yashirilsa, shu turdagi eng yangi ko'rinadigani o'zi «Oxirgi» bo'ladi. Izoh tahrirlanadi.

## Hostingga bog'liq jihatlar
- **Yuklash chegarasi**: zip `upload_max_filesize` va `post_max_size` dan oshmasin (formada ko'rsatiladi). Oshsa tushunarli xabar chiqadi
  (413 yoki «yuklash chegarasidan katta»). Chegarani hosting panelida oshiring yoki «Manzil (URL)» turini tanlang.
- **PHP `zip` kengaytmasi** (ZipArchive) kerak; bo'lmasa paket turi o'chiq va xabar chiqadi. URL turi kengaytmasiz ishlaydi.
- **`portal/public/demo` yoziladigan bo'lsin** (PHP ishlaydigan foydalanuvchiga, 755/775). `tools/make_deploy.sh` papkani bo'sh holda
  (`.gitkeep` + `.htaccess`) beradi; ishlab chiqishda ochilgan demolar paketga kirmaydi. Papka bo'lmasa, birinchi joylashtirishda yaratiladi.
- **PHP bajarilishi**: demo `/demo/<slug>/` so'rovlarini veb-server o'zi berishi kerak.
  - Apache: `public/demo/.htaccess` (`RewriteEngine On`) portalning «hammasi index.php ga» qoidasini shu papkada o'chiradi —
    *taxmin, Apache bilan sinalmagan*.
  - nginx + PHP-FPM: `try_files $uri $uri/ /index.php` odatda `/demo/<slug>/index.php` ni bajaradi; demo ichidagi «chiroyli» yo'llar
    (`/demo/x/api/...`) uchun alohida `location` kerak — *taxmin*.
  - `php -S` (start.sh): ishlaydi (tests/demos.php). `public/router.php` ochilgan `public/demo/<slug>/` papkasi uchun `return false`
    qiladi, ya'ni php -S demoni o'zi beradi; papka yo'q bo'lsa, portal «demo ochilmagan» deb javob beradi.
- **`.user.ini` / `open_basedir`**: joylashtirishda portal demo papkasiga `.user.ini` yozadi: demo kodi faqat o'z papkasini, php.ini dagi
  sessiya papkasini va vaqtinchalik papkani o'qiy oladi (portal papkasi va `data/` hech qachon qo'shilmaydi).
  - PHP-FPM / CGI da ishlaydi: `php-cgi 8.3` bilan sinaldi — portal `src/`, `data/`, qo'shni demo, `/etc/passwd` yopiq; o'z fayllari va
    sessiya ochiq. O'zgarish `user_ini.cache_ttl` (standart 300 s) ichida kuchga kiradi.
  - **`php -S` va Apache `mod_php` `.user.ini` ni o'qimaydi** (php -S da sinaldi: hammasi ochiq). mod_php da himoya yo'q.
- **Xavfsizlik — halol**: paket demo portal bilan **bir domen va bir PHP foydalanuvchisida** ishlaydi. `open_basedir` faqat fayl
  o'qishni cheklaydi: demo kodi o'z `.user.ini` sini qayta yozishi mumkin, demo JavaScript'i esa portal bilan bir manbada (origin)
  bo'lgani uchun admin sahifasini ochiq turgan xodim nomidan so'rov yubora oladi. Shuning uchun **faqat o'zingiz tayyorlagan,
  ishonchli paketni yuklang**. Begona yoki murakkab ilova uchun eng xavfsizi — **alohida subdomen/hosting va «Manzil (URL)» turi**.
