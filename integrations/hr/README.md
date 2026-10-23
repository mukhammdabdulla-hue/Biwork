# Biwork HR (`hr`, kod HRM)

PM tizimidan kesib olingan "panel": xodimlar bazasi, QR bilan ish vaqti, oylik tabel va hujjatlar bilan tanishuv nazorati.

## Tarkib
- `product.json` — `modules`: `kontakt` (Xodimlar/Kontaktlar, **Ish vaqti** = `kontakt.davomat`, **Tabel** = `kontakt.tabel`),
  `hujjat` (Hujjatlar kutubxonasi), `hisobot` (bosh sahifa va PDF hisobotlar). Yordamchi qismlar kerak emas.
  `home` = `#/bolim/dashboard`; `css` — "Ishlarim" blokini yashiradi, qidiruv maydoni matnini HR ga moslaydi, bosh sahifa kartasini tekislaydi.
- `manifest.json` — portal uchun (tagline, tavsif, `marketing{}`: `Marketing::clean()` hech narsani kesmaydi). Narx yo'q.
- `marketing/` — 6 ta ekran (WEBP 1440x900) + `og.png`. Chiqarishda `portal/public/assets/products/hr/` ga ko'chiring.
- `seed.php` + `seed/` (xodimlar, hujjatlar) + `seed-assets/` (logo, selfilar) — demo ma'lumot.

## Demo ma'lumot (hammasi xayoliy: "Zamin Plast MChJ")
- 35 xodim (har biriga hisob), 6 bo'lim, 3 ish joyi (ofis, sex, ombor), ish jadvali 09:00-18:00.
- Joriy oydan 3 oy oldingi 1-sanadan bugungacha davomat (kechikish, kelmaslik, ta'tillar, sabablar), oxirgi 3 kunda QR hodisalari va selfilar.
- Tabel tuzatishlari va imzolar, 9 hujjat (14 PDF fayl, v1/v2) tanishuv holati bilan. Demo foydalanuvchi: rahbar.
- Sanalar `bw_date()` ga nisbiy — bir necha haftadan keyin `dev.sh reseed` bilan yangilang.

## Ish vaqti manbai (muhim)
PM da davomat ma'lumoti nomi bo'yicha aniqlanadigan "bosh korxona"ga bog'langan (`boshKorxonami()`/`bosh_korxona_id()`) va tashqi ishvaqt.uz dan sinxronlanadi.
Biz PM kodiga tegmadik: seed korxonaga `davomatIchki:true` belgisini qo'yadi — ichki QR+GPS+selfi tizimi yoqiladi, tashqi tarmoqqa chiqilmaydi.
Yangi mijoz o'rnatilganda ham korxona yozuvi shu belgi bilan yaratilishi kerak.

## Niqob orqali yopiq
Moliya, savdo, yuridik, loyiha/vazifa, kundalik va boshqa PM modullari. Interfeysda ular yo'q; seed ham ularni to'ldirmaydi.

## Ma'lum cheklovlar
- Telefon kamerasi/joylashuv faqat HTTPS manzilda ishlaydi; QR domeni `davomat_domen` meta orqali sozlanadi.
- Xodimga avto-hisob (login = telefon, parol "1", birinchi kirishda almashtiriladi) faqat korxona ro'yxatda bo'lsa ochiladi.
- Rus tiliga tarjima qisman. Tabel/PDF shakli bitta andoza; ish haqi hisoblanmaydi; turniket/terminal ulanishi yo'q.
- Ketgan (`ketdi`) xodimlar demoda yo'q: PM kun xulosasi sonlari mos kelmay qolardi.
- Qidiruv maydonining asl matni boshqa modullarni eslatadi — CSS bilan almashtirilgan (PM kodi o'zgarmagan).
