# Biwork Yuridik (YUR)

Sud ishlari reyestri, majlislar kalendari va eslatmalar, majlis natijalari tarixi, sud hujjatlari arxivi.
PM (PHP 8 + SQLite) ustiga `pm-core` qatlami orqali yig'iladi; alohida kod yo'q — faqat quyidagi fayllar.

## Fayllar
- `product.json` — modullar, bosh ekran (`#/bolim/yuridik`), kichik CSS (bosh sahifadagi «Ish yuritish» kartasi bo'sh cho'zilmasin).
- `manifest.json` — portal uchun: tarif, reklama matni (`marketing`), savol-javob.
- `seed.php` — namuna (demo) ma'lumot; to'liq xayoliy. `marketing/` — 6 ta ekran rasmi (WEBP, 1440x900) + `og.png`.

## Modullar
`["yuridik"]` — bitta bo'lim, qismlari yo'q. `hisobot` olib tashlandi: PM da sud ishlari hisoboti yo'q, Hisobotlar
markazida faqat boshqa bo'limga tegishli «Ish kundaligi» chiqardi. `hujjat` (kutubxona) qo'shilmadi: u kadrlar/me'yoriy
hujjatlar uchun; sud hujjatlari ish kartasining o'zida. Mas'ul yurist — erkin matn, shuning uchun `kontakt` kerak emas.

## Namuna ma'lumot (`dev.sh reseed` bilan yasaladi)
3 korxona (Bahor Savdo Group, Orzu Qurilish Invest, Safo Agro Plyus), 26 sud ishi (12 / 8 / 4 + 2 «umumiy»),
73 majlis (45 tasida natija yozilgan, kelajakda ham bor), 109 hujjat (har biriga kichik PDF/DOCX fayl), 4 yurist.
Holatlar: 16 jarayonda, 5 yutildi, 2 yutqazildi, 2 kelishuv, 1 to'xtatildi. Sanalar bugunga nisbatan.
Barcha korxona, shaxs, ish raqami, telefon va summalar to'qilgan.

## Niqob (boshqa modullar yopiq)
`/api/state` da faqat korxonalar va sud ishlari; moliya, savdo, kontakt, topshiriq, kundalik, hujjat, hisobot
endpointlari 403 yoki bo'sh. Tekshirilgan: ~30 endpoint, dashboard, qo'ng'iroqcha, bildirishnomalar.

## Ma'lum cheklovlar
- Summa, instansiya va ish turi alohida maydon emas — `izoh` va sud nomida matn sifatida.
- Sud ishlari bo'yicha hisobot/eksport va umumiy qidiruvda sud ishlari yo'q; eslatmalar faqat dastur ichida.
- Jadvalning «Ish» ustuni tor (PM ning avtomatik jadvali): ish nomlari qisqa qilib yozilgan.
- `#/bolim/hisobot` ni qo'lda ochsa bo'sh sahifa chiqadi (navigatsiyadan unga yo'l yo'q).
- Excel/boshqa tizimdan import yo'q.

## Ishga tushirish
`bash integrations/pm-core/tools/dev.sh up yuridik <PM_manba> <ish_papka> <port>`; seeddan keyin `dev.sh reseed yuridik <ish_papka>`;
manifestdan keyin `dev.sh manifest yuridik <ish_papka>`. Demo: `demo@namuna.uz` / `Parol-Sinov-2026` (faqat dev portal).
