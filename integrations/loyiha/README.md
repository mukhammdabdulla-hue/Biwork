# Biwork Loyiha (LOY)

PM-tizimning loyiha qismi alohida mahsulot sifatida: loyihalar (bosqich, vazifa, risk, hujjat, Gantt, ijro nazorati),
g'oyalar taxtasi va topshiriqlar zanjiri (berish → bajarish → qabul). Bosh sahifa — rahbar dashboardi.

## Modullar (`product.json`)
| Modul | Nima uchun |
|---|---|
| `pm` | Loyihalar (`pm.loyiha`) va G'oyalar (`pm.goya`) |
| `topshiriq` | Men berganlarim / Menga berilgan / Hammasi, «Ishlarim» |
| `kontakt.royxat:view` | Yordamchi: mas'ul va ijrochini xodimlar ro'yxatidan tanlash (faqat ko'rish) |
| `hisobot` | Loyihalar/g'oyalar/ishlar PDF hisobotlari (tugmalar `hisobot` huquqiga bog'liq) |

`kundalik` modullarda YO'Q: PM'da Ish kundaligi ekrandan olib tashlangan (`switchTab('kundalik')` bosh sahifaga qaytaradi).
`home` = `#/bolim/pm` (Loyihalar). `css`: G'oyalar yorlig'ini ochadi, pasportdagi «Byudjet — Moliya huquqi yo'q» plitkasini
va «Ma'lumot markazi» menyusini yashiradi.

## Namuna ma'lumot (`seed.php`, hammasi to'qilgan: «Yulduz Invest Group»)
1 korxona (logotip bilan), 24 xodim (kontakt + inert hisob), 11 loyiha (3 yashil, 3 sariq, 3 qizil, 2 yakunlangan; jami 95 vazifa,
bosqichlar, risklar, pasport, asos va jarayon hujjatlari — 52 ta haqiqiy PDF fayl, bazaviy reja), 13 g'oya (fikrlar va qarorlar bilan),
30 topshiriq (har holatda; 4 tasi takrorlanuvchi, 1 tasi qabul kutmoqda), 4 bildirishnoma. Ish kundaligi yozuvlari ham bor, lekin modul yo'qligi sababli ko'rinmaydi. Sanalar `bw_date()` bilan bugunga nisbatan.
Demo foydalanuvchi logini oldindan noma'lum (`bw-<mijoz>-<id>`): ma'lumotda `bw-demo-me` belgisi turadi, uni `users`ga birinchi `bw-%` hisob
qo'shilganda SQLite triggeri (`bw_demo_claim`) haqiqiy login bilan almashtiradi.

## Niqoblangan (ko'rinmaydi)
Moliya, Savdo, Yuridik, Xodimlar bo'limlari (ro'yxatdan tashqari), Kundalik, Hujjatlar, Ma'lumot markazi; `/api/state`da qarz/debitor/sud/korporativ
bo'sh, korxonaning kundalik/moliya qismlari qirqilgan, xodim kartasida INN/manzil/maosh yo'q.

## Ma'lum cheklovlar
- G'oyalar: PM faqat nomida «avtosanoat invest» bor korxonaga ochadi (`boshKorxonami`, `bosh_korxona_id`) — to'qilgan korxonada yorliq CSS bilan ochiladi;
  korxona cheklovi qo'yilgan foydalanuvchilarga server g'oyalarni bermaydi. Bosh sahifadagi g'oya vidjeti ham faqat bosh korxonada chiqadi.
- «Nazoratdan yechish» faqat bosh korxona adminiga (rahbar roli bilan demoda yo'q).
- Hisobotlar markazi (`#/bolim/hisobot`) PM'da menyuga ulanmagan; PDF hisobotlar Loyiha/G'oya/Xodimlar sahifalaridagi tugmalardan olinadi.
- Xodimlar ro'yxati faqat ko'rish: yangi xodim kartasi uchun HR mahsuloti (yoki `kontakt.royxat` tahrir huquqi) kerak.
- Loyiha vazifalari Excel'dan yuklanmaydi (qo'lda kiritiladi).

## Reklama
`manifest.json` → `marketing`; rasmlar `marketing/` (6 ta WEBP 1440×900 + `og.png`). Rasmlar namuna ma'lumotdan olingan; `#bwDemoBar` yashirilgan.
