# Biwork Savdo (`savdo`, kod SAV)

PM (Project Manager) ning Savdo bo'limi Biwork mahsuloti sifatida: savdo yozuvlari, yuk xatlari, savdo dinamikasi, PDF savdo hisoboti.

| Fayl | Nima |
|---|---|
| `product.json` | Modullar: `savdo.umumiy`, `savdo.yigim` (ekranda «Yuk xatlari»), `hisobot`; `css` — yorliq nomi va jadval tuzatmasi |
| `manifest.json` | Katalog matni, reklama sahifasi (`marketing`), tariflar. Narx yo'q |
| `seed.php` | Xayoliy namuna: 2 korxona, 12 va 10 oylik tirik savdo tarixi (sanalar bugunga nisbatan) |
| `seed-assets/` | Ikki xayoliy korxona belgisi (`brend/<id>.png` ga ko'chadi) |
| `marketing/` | 6 ekran rasmi (1440x900 WEBP) + `og.png` (1200x630) -> `portal/public/assets/products/savdo/` |

## Modullar nega shunday
- Umumiy ko'rinish + savdolar ro'yxati + yuk xati — har qanday savdo qiladigan korxonaga kerak (umumiy).
- `hisobot` — PDF savdo hisoboti va yuk xati PDF (pm-core PDF brendi Biwork bo'lgach qo'shildi).
- **Kiritilmadi:** `savdo.realizatsiya` (faqat «bosh korxona» — nomi bo'yicha, oddiy mijozda ochilmaydi), `savdo.lizing`,
  `savdo.dilerlar`, `savdo.shartlar` (ularga `savdoTur` = lizing/otp kerak, bitta tashkilotga xos), agro yig'im jurnali.
  Keyingi mahsulot nomzodi: «Biwork Lizing» (lizing + dilerlar + shartnomalar).

## Namuna ma'lumot (demo)
- «Bahor Ulgurji Savdo» (oziq-ovqat, 12 oy, 14 mahsulot, 18 xaridor, 640 savdo yozuvi, 148 yuk xati, ~7,7 mlrd so'm) va
  «Zamin Qurilish Mollari» (10 oy, 10 mahsulot, 10 xaridor, 283 yozuv, 58 yuk xati, ~3,2 mlrd so'm). Oylar bo'yicha mavsumiy
  tebranish, 2 ta muddati o'tgan nasiya, yangi yozuvlar yuk xatiga bog'langan. Hammasi to'qilgan.
- Demo foydalanuvchi roli `rahbar`; niqob boshqa bo'limlarni (Moliya, Loyiha, ...) yopadi.

## Cheklovlar (halol)
- 1C bilan integratsiya yo'q; import — Excel/Google Sheets jadvalini nusxalab qo'yish (sarlavha o'zbekcha/ruscha taniladi).
- «Yuk xatlari» yorlig'i PM'da `biznesAktiv` bayrog'iga bog'liq: haqiqiy o'rnatishda administrator panelida korxona kartasida
  «Savdo bo'limi» va «Mavsumli biznes reja» yoqiladi (yorliq nomi chalkash, qarang: pm-core nuqsonlari).
- Qarzdorlik (debitorka) va ombor qoldig'i yo'q — to'lov holati faqat izoh.
- Global qidiruv savdo yozuvlarini qidirmaydi; yuk xati PDF'ida korxona logotipi o'rniga standart barg belgisi chiqadi.

## Ishlab chiqish
```bash
W=<ish_papka>; bash ../pm-core/tools/dev.sh up savdo <PM_manba> $W 8230   # portal 8230, demo PM 8231
bash ../pm-core/tools/dev.sh reseed savdo $W      # seed.php/product.json o'zgarsa
bash ../pm-core/tools/dev.sh manifest savdo $W    # manifest o'zgarsa
```
