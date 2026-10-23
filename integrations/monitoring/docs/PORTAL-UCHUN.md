# Portal tomoni uchun: Biwork Monitoring'ni ulash

Monitoring mahsuloti `biwork/integrations/monitoring/` ichida tayyor. `biwork/portal/` va `ngd-panel/` ga **tegilmagan**; portalda kerak bo'ladigan ishlar quyida.
Bu ro'yxat portal bilan shug'ullanuvchi threadga mo'ljallangan.

## 1. Hozir qilish mumkin (kod yozish shart emas)

1. **Mahsulotni katalogga qo'shish** (status `soon`: sahifa «Tez orada», «Sinab ko'rish» o'rniga murojaat shakli chiqadi):
   ```bash
   php tools/cli.php tool:register integrations/monitoring/manifest.json
   ```
   Tekshirildi: manifest portalning `Catalog::registerManifest` orqali vaqtinchalik bazaga xatosiz yozildi (slug `monitoring`, kod `MON`, 2 ta tarif),
   `Marketing::clean` hech narsani kesmadi va tashlamadi. Narx manifestda yo'q («so'rov bo'yicha»); tariflarni (Standart/Korxona) narxi admin panelida kiritiladi.
2. **Reklama rasmlari**: `integrations/monitoring/marketing/` dagi 8 ta fayl (7 ta `.webp` ekran + `og.png`) ni
   `portal/public/assets/products/monitoring/` ga ko'chirish. Rasmlar `tools/product_assets.py` bilan tayyorlangan, ularda to'qilgan «Namuna korxona» ma'lumoti
   (haqiqiy mijoz nomi, narx yoki shaxsiy ma'lumot yo'q). `og.png` dagi yashil yozuv «Tez orada».
3. Hech narsa o'zgartirilmasa ham ishlaydi: `status: soon` bo'lgani uchun mahsulot «Ochish» tugmasisiz ko'rinadi.

## 2. Mahsulot ishga tushganda (status `active`)

- Brauzer demosi: Monitoring'ni Biwork serverida ko'tarib (`app/public` hujjatlar ildizi, `config.php` dagi `biwork` bo'limi — namunasi `app/config.php.sample`),
  portalda `web.url` ni shu manzilga qo'yish (admin → Mahsulotlar → *Brauzer (demo) manzili*). Sinov (`trial`) manifestda hozir o'chirilgan (`enabled: false`): demo manzili bo'lgach yoqiladi.
- Portal «Ochish» tokenini `aud = monitoring` bilan beradi; Monitoring shu tokenni `app/biwork-public.json` dagi ochiq kalitlar bilan tekshiradi
  (`curl -s https://biwork.uz/api/v1/pubkey > app/biwork-public.json`). Kalit almashtirilsa (`keys:rotate`) faylni qayta yuklab olish kerak.
- Umumiy namuna uchun `config.php` da `'demo' => true`: har tashrifchiga alohida baza nusxasi, qoidalarni o'zgartirish yopiq. Shaxsiy nusxa uchun `'customer_id' => <korxona>`.
- Ilova sifatida yetkazish: `PUBKEY=... bash integrations/monitoring/tools/make_release.sh 0.1` → zip → *Vositalar → Reliz qo'shish* (`kind: server`). Mijozda `enforce` bilan litsenziyasiz ochilmaydi.

## 3. Portalda o'zgartirish shart bo'lmagan, lekin foydali narsalar (keyingi bosqich)

- Katalogdagi mahsulotlar ro'yxatida Monitoring'ni Reja yonida ko'rsatish (`sort: 15` berilgan).
- Reja sahifasida «Monitoring bilan birga» havolasi, shartnomada bunday maydon yo'q — kerak bo'lsa `marketing` ga yangi bo'lim qo'shish alohida qaror.

## 4. Ngd-panel (Reja)

O'zgarish shart emas. Keyingi bosqichda Monitoring ogohlantirishidan Rejaga «Qayta tuzish» havolasi (`reja_url`) yoki Reja ma'lumotini Monitoringga o'qish qo'shiladi:
bu paytda panelga kichik API/havola kerak bo'ladi, bu alohida kelishiladi.

## 5. Tekshiruv

`cd integrations/monitoring/app && bash tests/run.sh` — birlik va HTTP sinovlari; portalning haqiqiy `Tokens::sign` va `Tokens::publicKeys` bilan SSO oqimini ham tekshiradi
(portal manbasi `PORTAL_SRC` da, standart `../../../portal/src`).
