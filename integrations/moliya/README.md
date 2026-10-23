# Biwork Moliya (MOL)

PM ning moliya paneli mahsulot sifatida: Biznes reja, Pul oqimi, Kreditor-Debitor, Balans + rahbar bosh sahifasi.

## Modullar (`product.json`)
- `moliya` — hamma qismlari (biznes, oqim, qarz, balans). Boshqa modulga bog'liq tugma/ro'yxat qolmaydi, yordamchi modul kerak emas.
- `hisobot` — qismlardagi PDF hisobotlar uchun (moliya, qarz, balans, bpmoliya).
- `home` = `#/bolim/dashboard` (rahbar bosh sahifasi: kirim/chiqim/qoldiq/qarzdorlik/sof foyda + biznes reja).
- `css`: «Loyiha» maydoni (loyiha moduli yo'q) va ДДС yuklash tugmasi (u bitta egasining 1C kitobiga bog'langan) yashirilgan.

## Namuna ma'lumot (`seed.php`, butunlay xayoliy, sanalar `date()` ga nisbatan)
- Navro'z Savdo Group: 1102 ta kassa/bank yozuvi (13 oy), 5 hisob + depozit, tovar qoldig'i, kategoriyalar, inkassatsiya/depozit
  «ichki aylanma»; 14 debitor + 13 kreditor (yangi → muzokara → pretenziya → kelishuv → sud → ijro → umidsiz → yopilgan, kunlik harakat bilan);
  biznes reja (o'tgan yil fakti, joriy yil 12 oy reja, hisobot oyigacha fakt va izohlar, xarajat moddalari);
  balans 10 oy × 4 blok (29 tovar, 8 xom ashyo, 8 inventar, 14 asosiy vosita; zanjir uzluksiz).
- Yashil Vodiy Agro (issiqxona): 146 ta bank yozuvi (9 oy), 3 debitor + 2 kreditor, biznes reja va balans (10 oy).
- Shaxs, tashkilot, STIR, hisob raqami, bank — to'qilgan.

## Niqob nimani yopadi
Boshqa modul yorliqlari, `/api/state` dagi loyiha/kontakt/topshiriq/sud ma'lumoti, qidiruv, bildirishnoma, Hujjatlar tugmasi — tekshirilgan,
sizma yo'q (`/api/hisobotlar` faqat 4 ta moliya hisobotini beradi; `kundalik.pdf` — 404).

## Ma'lum cheklovlar (reklamada ham ochiq aytilgan)
- 1C bilan integratsiya yo'q. Fayl yuklash muayyan shakllarga sozlangan: biznes reja — «Отчет выполнения БП» (БП2026, oylik varaqlar),
  balans — «Материальный отчет» / «Ведомость амортизации ОС», qarzdorlik — «Дебитор»/«Кредитор» varaqli fayl, ДДС — egasining kitobi
  (demoda yashirin). Boshqa shaklga moslashtirish kerak. Pul oqimi uchun umumiy CSV/Excel import bor (Sana, Hisob, Turi, Kategoriya, Tavsif, Summa).
- Yangi debitor/kreditor yozuvi qo'lda qo'shilmaydi (faqat vedomost orqali); mavjudini tahrirlash mumkin.
- `kassaYoq`, `qoldiqYuritish`, `biznesAktiv`, `bpTur` — admin paneli sozlamalari; demo foydalanuvchida admin yo'q, seed ularni belgilaydi.
- «Bosh korxona» nomga bog'liq cheklovlar (Savdo realizatsiya) Moliya ekranlariga ta'sir qilmaydi.
- Mavsumli (issiqxona) biznes reja rejimi `biznesAktiv` bilan yoqiladi; demoda umumiy P&L biznes reja ko'rsatilgan.
- Dashboard «Masalalar» bloki: moliya ma'lumoti eskirmagan bo'lsa bo'sh turadi.

## Fayllar
`seed.php`, `product.json`, `manifest.json`, `marketing/` (6 ekran .webp 1440×900 + `og.png` 1200×630).
Rasmlarni portal `assets/products/moliya/` ga qo'yish (product_assets.py yoki deploy) alohida bajariladi.
