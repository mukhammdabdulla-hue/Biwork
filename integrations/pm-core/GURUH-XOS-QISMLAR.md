# PM'ning guruh korxonalariga xos qismlari: mijoz paketiga nima tushadi

Savol: PM kodining qaysi qismlari Avtosanoat Invest guruhi korxonalariga (Avtosanoat Invest, OTP Avtosanoat Finance, Avtosanoat Agro Lizing, Green House) xos va uchinchi tomon mijoz paketiga tushmasligi kerak?

**Holat (paket yig'ish `build.sh` / `make-package.sh`, PM papkasiga tegilmagan):**

| Qism | Holat |
|---|---|
| **A** (hech bir mahsulot ishlatmaydi) | stublanadi: 10 PHP + 10 JS fayl, 508 KB → 30 KB. `--keep-group` bilan qaytariladi |
| **C** (ichki xususiyatlar) | **stublanmaydi**: sinovda guruhga xos emas, mahsulotlar ishlatadigan umumiy xususiyat chiqdi (quyida) |
| **B** (Moliya 1C shakllari) | **tegilmagan**, foydalanuvchi javobini kutamiz |
| **D** (nomga bog'langan mantiq) | faqat ro'yxat, PM kodiga tegilmagan |
| Qo'shimcha (sinov paytida topildi) | `php/seed/gh-biznes-reja.json` paketdan olib tashlanadi; fon rasmi `assets/bino.webp` neytral rasmga almashtirildi |

## Usul

Fayllarni o'chirib bo'lmaydi (`app.js` ularni statik `import` qiladi, `api.php` va `db.php` `require_once` qiladi, `sw.js` keshlaydi). Shuning uchun har fayl **stub**ga almashtiriladi (`tools/stub-group.php`, `build.sh` oxirgi qadami):
- PHP (`token_get_all` bilan): funksiya imzolari saqlanadi, tanasi tip bo'yicha neytral qiymat qaytaradi (`array` → `[]`, `string` → `''`, `?T` → `null`...); skalyar konstantalar o'z qiymati bilan, massiv konstantalar `[]`; natija `php -l` dan o'tmasa fayl o'zgarishsiz qoladi.
- JS: eksportlar saqlanadi (`export function X(){return '';}`, `export const X=[];`).
- `--keep-group` bilan bu qadam butunlay o'chadi (asl fayllar va seed qoladi). `make-package.sh` shu bayroqni o'tkazadi.

Nullable qaytaruvchi funksiyalar (`otp_bal_oqi`, `realizatsiya_oq`) stubda `null` beradi, ya'ni «shakl tanilmadi»: B qismidagi chaqiruvchilar (`balans.php`, `bp-moliya.php`) aynan shu tarmoqqa tushadi va oddiy yo'lda davom etadi.

## A: stublanadigan fayllar

| Qism | Fayllar |
|---|---|
| OTP Avtosanoat Finance | `php/otp-bp.php`, `otp-dds.php`, `otp-lizing.php`, `otp-savdo.php`, `otp-balans.php`; `assets/mod/otpbosh*.js`, `otpbp*.js`, `otplizing*.js`, `otpsavdo*.js` |
| Avtosanoat Agro Lizing | `php/lizing-bp.php`, `lizing-qarz.php`, `lizing-savdo.php`; `assets/mod/lizingbp*.js` |
| Realizatsiya, ДДС kitobi | `php/realizatsiya.php`, `php/dds.php` |

### A tekshiruvi (stublangan paket va stublanmagan baza solishtirildi)

- Besh mahsulot demosi portal orqali ochildi; bosh sahifa, barcha asosiy bo'limlar, ichki yorliqlar (Moliya: Biznes reja / Pul oqimi / Qarz / Balans; Savdo: Umumiy / Yig'im va yuk xati; HR: Ish vaqti / Kontaktlar; Loyiha: Loyihalar / G'oyalar) va Ma'lumot markazi (4 mahsulotda) ekran matni **stublanmagan nusxa bilan bir xil** (farq 0, faqat soat), **brauzer xatosi 0**.
- 15 PDF hisobot chiqdi (Moliya 4/4, Savdo 1/1, HR 2/2, Loyiha 8/8; Yuridik'da PDF yo'q), `/api/state` 5 mahsulotda 200.
- `tests/run.sh`: **81 tekshiruv o'tdi**, shu jumladan yangilari: stub fayllar kichik va `php -l` dan o'tadi, umumiy xususiyatlar va B stublanmagan, `--keep-group` asl fayllarni saqlaydi, stublangan marshrutlar (`/api/otpbp`, `/api/otpsavdo`, `/api/lizingbp`, `/api/realizatsiya`...) 4xx bilan rad etadi, o'rnatish tekshiruvi `/api/tekshir` xatosiz.
- **Tekshirilmagan:** haqiqiy 1C fayllari bilan import (bizda ular yo'q). Sintetik «Материальный отчет» bilan `balans_oqi()` stublangan va stublanmagan nusxada bir xil natija/xato berdi.

## C: nega stublanmaydi (sinov natijasi)

Vazifada C ham stublash so'ralgan edi. Stublangan paketni baza bilan solishtirganda C fayllari Biwork mahsulotlari ishlatadigan **umumiy xususiyat** ekani chiqdi:

| Fayl | Stublansa nima buziladi |
|---|---|
| `assets/mod/aktiv.js` | Moliya bosh sahifasidagi «Aktivlar / Majburiyat / Sof aktivlar / Majburiyat-aktivlar» bloki yo'qoladi, konsolda `TypeError … ghMajTana` |
| `assets/mod/manba.js`, `php/manba-holat.php` | Ma'lumot markazi (Moliya, Savdo, HR, Loyiha) holat o'rniga «undefined» chiqaradi, «Joyida», muddat, kim yuklagani yo'qoladi |
| `assets/mod/mavsumreja.js` | Biznes reja «Smeta ijrosi» va «Balans va majburiyatlar» paneli uni chaqiradi (`bzYig` → `smetaTahlil()`, `smetaBosqichlar()`); demo ekranida farq ko'rinmadi, stub esa `''` qaytaradi, shuning uchun smeta kiritilgan bazada panel buzilishi kutiladi (bu holat alohida sinalmagan) |
| `php/versiya.php` | `/api/tekshir` (administrator uchun o'rnatish tekshiruvi) bo'sh qaytadi; faqat ichidagi «Green House mavsumi» tekshiruvi guruhga xos |

Shuning uchun C ro'yxatdan chiqarildi. Ularda guruhga xos faqat nomlar va izohlar (izohlar app paketida olib tashlanadi). Agar PM egasi baribir olib tashlashni xohlasa, bu PM kodini o'zgartirishni talab qiladi (D bilan birga).

## Qo'shimcha topilmalar (paket yig'ishda tuzatildi)

1. **`php/seed/gh-biznes-reja.json`.** Kod uni «Green House biznes rejasi» deb ataydi (mavsumlar, reja raqamlari) va «Green House» nomli korxona bo'lsa avtomatik qo'shadi (`db.php`, `biznes-koch.php`). Mening ish nusxamda bu papka yo'q edi (PM'ni ko'chirganda `php/seed/` ni chiqarib qo'ygandim), shuning uchun oldingi hujjatdagi «haqiqiy ma'lumot yo'q» degani **shu fayl uchun tekshirilmagan edi**. Faylning o'zini ko'rmadim: PM egasi ichini tekshirsin. Endi `stub-group.php` uni paketdan olib tashlaydi va o'rnatish tekshiruvi (`versiya.php`) ro'yxatidan chiqaradi; fayl yo'q bo'lsa PM jim o'tadi (`bk_avto_koch` → `seed_yoq`). Soxta fayl bilan sinaldi (testda); `php/seed/` ichida boshqa fayl bo'lsa ogohlantiradi va papkani qoldiradi.
2. **`assets/bino.webp`.** Asl PM'da bu «UzAuto» yozuvli bino rasmi: har sahifa fonida (`#bgArt`, 16% shaffoflik, kartalar orasidan ko'rinadi) va kirish sahifasida. Uchinchi tomon belgisi bo'lgani uchun `overlay/assets/bino.webp` (neytral past-poligon to'r, ~39 KB) har doim uni almashtiradi (demo va app, `--keep-group` ta'sir qilmaydi, PDF logotipi va matnlardagi brend almashtirish kabi).

## B: qaror kutilmoqda (tegilmagan)

`php/bp-moliya.php` (Moliya: «Отчет выполнения БП» import), `php/balans.php`, `balans-xlsx.php` («Материальный отчет», «Ведомость амортизации ОС»), `php/gh-balans.php`, `gh-qarz.php` (Green House shablonlari). Moliya manifestida «1C'dan yuklash» deb aytilgan. Chiqarilsa Moliya'da qo'lda va CSV/Excel (umumiy `import.js`) kiritish qoladi. B bo'yicha foydalanuvchi javobi kelgach: stublash uchun `GRUH_PHP` ga qo'shish va manifest/FAQ'dan «1C fayllaridan yuklash» da'vosini olib tashlash yetadi.

## D: nomga bog'langan mantiq (faqat ro'yxat, PM kodi o'zgartirilmagan)

Yakuniy app paketidan sanalgan (kichik harfsiz moslik soni bilan):
- `assets/app.js`: `boshKorxonami()` (`nomi.includes("avtosanoat invest")`), `k_asi` (2), `uzavtosanoat` (7), «avtosanoat» (11), import tanish regexida `uzauto` (1). G'oyalar, nazoratdan yechish, davomatning tashqi manbasi shunga bog'langan.
- `assets/mod/til-lugat.js`: rus tili lug'atida «Avtosanoat Invest» (10).
- `php/api.php`: `bosh_korxona_id()`, xato matni «Nazorat qarorini faqat Avtosanoat Invest administratori…»; `php/hisobot-pdf.php`: `stripos(nomi,'avtosanoat invest')`, `uzavtosanoat` («O'zavtosanoat AJ (aloqa)»), «Korporativ ulushlar» PDF sarlavhasi; `php/db.php`: nom bo'yicha tanish (Green House 4, Avtosanoat Invest 3) va migratsiya kalitlari (`migr_gh_biznes_reja_v1` va h.k.).
- `php/versiya.php`: «Green House mavsumi» tekshiruvlari (6 ta), `php/biznes-koch.php`: Green House biznes reja ko'chirish; `php/totp.php`: `totp_uri()` standart tashkilot nomi «Avtosanoat Invest» (hozir hech qayerda chaqirilmaydi).
- Jadval nomlari (`otp_*`, `lizing_*`, `realizatsiya`) va stub fayllarining nomlari.
- Xom `pages/login.html`, `Main.html`, `admin.html`, `davomat-mobil.html`, `assets/manifest.webmanifest`, `.htaccess` izohi: «Avtosanoat Invest» matni bor, lekin foydalanuvchiga ko'ringanlari `php/biwork.php` bilan xizmat paytida Biwork'ga almashtiriladi (login sarlavhasi va PWA manifesti tekshirildi: «Biwork HR»).

Bularning hech biri ma'lumot emas, lekin guruhning ichki tuzilmasini ko'rsatadi. To'liq tozalash PM kodini o'zgartirishni va PM yangilanganda patchni qayta moslashni talab qiladi.

## Tavsiya

1. A, `php/seed`, `bino.webp` qadamlari standart yoqilgan, ishlatishga tayyor.
2. B bo'yicha PM egasi/foydalanuvchi qarori (qoldirish yoki stublash va Moliya'ni qo'lda/CSV import bilan sotish).
3. D va C ni olib tashlash PM kodini o'zgartirishni talab qiladi: PM egasi alohida rejalashtiradi.
4. Texnik tozalash shartnomani almashtirmaydi: baribir **mijoz shartnomasida** PM kodi (nomlari bilan) uchinchi tomonga berilmasligi yozilishi va PM egasi bilan kelishuv kerak. Paket PHP manba kodini yetkazadi, stub bilan ham guruh nomlari qoladi (D).
