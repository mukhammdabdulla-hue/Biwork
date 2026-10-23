# Biwork platformasi — tuzilma rejasi (qisqa)

**Maqsad.** Biwork — korxonalar sotib oladigan avtomatlashtirish **mahsulotlari** platformasi. Har bir panel/dastur alohida mahsulot:
**Biwork Reja** (ishlab chiqarish rejasi, NGD paneli) — birinchisi; keyingilari (Biwork HR, Savdo, Loyiha …) xuddi shu eshikdan kiradi: bitta kirish,
bitta katalog, bitta litsenziya tizimi. Platformaning vazifasi — mahsulotlarni internetda **reklama qilish va sotish**: ochiq sayt har mahsulotga reklama
sahifasi beradi (qidiruv/ijtimoiy tarmoq teglari, sitemap, murojaat shakli), ko'rib chiqqan odam brauzerda sinab ko'radi yoki so'rov qoldiradi
([SITE_VA_REKLAMA.md](SITE_VA_REKLAMA.md)).

```
Mijoz (brauzer)                         Biwork xodimi (admin)
      │                                        │
      ▼                                        ▼
┌──────────────────── BIWORK PORTAL ────────────────────┐
│ kirish · katalog · "Mening vositalarim" · yuklab olish │
│ admin: mijozlar · litsenziyalar · relizlar · so'rovlar │
├────────────────────────────────────────────────────────┤
│ LITSENZIYA SERVERI  /api/v1  (kalit, faollashtirish,   │
│ muddat, o'rindiqlar, imzolangan token)                 │
└──────┬───────────────────────────────┬─────────────────┘
       │ "Ochish" (imzolangan launch    │ faollashtirish / tekshirish
       │  token, 2 daqiqa, bir marta)   │ (token Ed25519 bilan imzolangan)
       ▼                                ▼
  Vosita — veb (demo/sinov)       Vosita — ilova (korxona kompyuteri/serveri)
  masalan Reja paneli             oflayn ham ishlaydi (token ichida muddat)
```

## 1. Qismlar

| Qism | Nima qiladi | 1-bosqichda |
|---|---|---|
| **Portal** | Ro'yxatdan o'tish/kirish, mahsulotlar katalogi, "Mening mahsulotlarim" | ha |
| **Ochiq sayt (vitrina)** | Bosh sahifa, `/mahsulotlar`, har mahsulotga `/<slug>` reklama sahifasi (matn va ekran rasmlari manifestdan), SEO/ulashish teglari, `sitemap.xml`, murojaat (lead) shakli, reklama manbasi (utm), admin *Murojaatlar*, Telegram xabari | ha ([SITE_VA_REKLAMA.md](SITE_VA_REKLAMA.md)) |
| **Vositalar katalogi** | Har vosita: nom, tavsif, tariflar, holati (faol / tez orada), sinov bormi | ha (Reja paneli faol) |
| **Sinov rejimi** | Ro'yxatdan o'tgan korxona vositani brauzerda **demo ma'lumot** bilan ochadi; 30 kunlik sinov litsenziyasi avtomatik beriladi (bir korxonaga bir vositaga bir marta) | ha |
| **Ilova ko'rinishida yuklash** | Litsenziyasi bor mijoz vositaning reliz paketini portaldan yuklaydi (versiya, hajm, sha256); har yuklash yoziladi | ha (zip paket) — Windows o'rnatuvchi 2-bosqich |
| **Litsenziya serveri** | Kalit berish, faollashtirish, muddat, o'rindiqlar (qurilmalar), to'xtatish/bekor qilish, hodisalar jurnali | ha |
| **Oflayn tekshiruv** | Vosita imzolangan tokenni ochiq kalit bilan **internetsiz** tekshiradi; token ichida muddat va "qayta tekshirish" oynasi bor | ha (token) / oflayn faollashtirish kodi — 2-bosqich |
| **Vosita shartnomasi** | Har vosita platformaga qanday ulanishi: manifest, litsenziya tekshiruvi, "Ochish" tokeni, brend | ha ([TOOL_CONTRACT.md](TOOL_CONTRACT.md)) |
| **Admin** | Mijoz qo'shish, litsenziya berish/uzaytirish/to'xtatish, sotib olish so'rovlari, relizlarni ro'yxatga olish | ha |

## 2. Rollar

- **Biwork xodimi** (`staff`) — hamma narsani boshqaradi.
- **Korxona egasi** (`owner`) — o'z korxonasining litsenziyalari, qurilmalari, yuklashlari, xodimlari.
- **Korxona xodimi** (`member`) — vositani ochadi, yuklaydi; litsenziyani boshqarmaydi.

## 3. Mijoz yo'li (sotuv oqimi)

1. Korxona portalga kiradi (o'zi ro'yxatdan o'tadi yoki xodim ochib beradi) → katalogni ko'radi.
2. **Sinab ko'rish** → 30 kunlik sinov litsenziyasi → vosita brauzerda demo ma'lumot bilan ochiladi.
3. **Sotib olish so'rovi** (tarif, o'rindiqlar, izoh) → xodim so'rovni ko'radi, hisob-faktura yuboradi.
4. To'lov kelgach xodim **litsenziya beradi** (kalit, muddat, o'rindiq) → mijoz "Mening vositalarim" da kalitni va **Yuklab olish** tugmasini ko'radi.
5. Mijoz paketni o'z kompyuteriga o'rnatadi, kalitni kiritadi → vosita faollashadi (qurilma o'rindiqni egallaydi).
6. Muddat tugashidan oldin xodim va mijoz ogohlantiriladi; uzaytirilsa vosita keyingi tekshiruvda yangi muddatni oladi; to'xtatilsa yoki bekor qilinsa — tekshiruv oynasi tugagach vosita bloklanadi.

## 4. Litsenziya qanday ishlaydi (qisqa)

- **Kalit**: `BW-REJ-7K2Q-9XPM-4TND` (tool kodi + tasodifiy). Litsenziya = kalit + vosita + tarif + o'rindiqlar + muddat + holat.
- **Faollashtirish**: vosita kalit + qurilma izini yuboradi → server o'rindiq bo'sh bo'lsa **imzolangan token** qaytaradi (Ed25519).
- **Token** ichida: korxona, vosita, tarif, imkoniyatlar, o'rindiqlar, **muddat (`exp`)**, **keyingi tekshiruv muddati (`chk`)** va qo'shimcha **imtiyozli kunlar (`grace`)**.
- **Tekshiruv**: vosita ochiq kalit bilan imzoni internetsiz tekshiradi. `chk` o'tsa — onlayn yangilashga urinadi (14 kunda bir marta), serverga yetolmasa `grace` (7 kun) ichida ishlayveradi, undan keyin bloklanadi. Muddat tugasa — bloklanadi.
- **O'rindiq** = bir vaqtda faol qurilma/o'rnatish. Mijoz portalda eski qurilmani **bo'shatadi** (kompyuter almashsa).
- **Bekor qilish** oflayn vositaga darhol emas, keyingi tekshiruvda ta'sir qiladi (shuning uchun `chk` oynasi qisqa). Bu — oflayn ishlashning narxi.

## 5. Ma'lumotlar modeli

`customers` (korxona) · `users` (kirish) · `tools` (katalog) · `plans` (vosita tariflari) · `tool_releases` (versiyalar/paketlar) ·
`licenses` · `activations` (qurilmalar) · `license_events` (jurnal) · `purchase_requests` (so'rovlar) · `rate_limits` (urinishlar chegarasi).
"Ochish" tokeni bir martalik: portal uni saqlamaydi, vosita o'zi `jti` keshini yuritadi (shartnoma §4).

## 6. Bosqichlar

Kod va ishga tushirish: [../README.md](../README.md). Vosita ulash qoidalari: [TOOL_CONTRACT.md](TOOL_CONTRACT.md).

| Bosqich | Mazmuni |
|---|---|
| **1 (qurildi, ishlaydi)** | Ochiq sayt: mahsulot reklama sahifalari, SEO, sitemap, murojaatlar. Portal: kirish/ro'yxatdan o'tish, katalog, Mening vositalarim, sinov, so'rov. Admin: mijoz, litsenziya (berish/uzaytirish/to'xtatish/bekor), qurilmalar, relizlar. API: faollashtirish/tekshirish/bo'shatish/ochiq kalit. "Ochish" tokeni. PHP va Python uchun tekshiruv kutubxonasi. Reja paneliga ulash paketi (patch). Gated yuklash. PWA (portalni ilova sifatida o'rnatish). |
| **2** | Windows o'rnatuvchi (PHP+Python+vosita bitta `.exe`) va ishga tushirgich; oflayn faollashtirish kodi; parolni tiklash va muddat eslatmalari (e-pochta/SMS); hisob-faktura PDF. |
| **3** | Onlayn to'lov (Payme/Click/Uzum); bulutda har mijoz uchun alohida nusxa (boshqariladigan xosting); yangilanish kanali; foydalanish hisoboti; 2-, 3-vositalar. |

## 7. Sizdan kerak bo'ladigan qarorlar (tavsiya bilan)

1. **Ilova shakli.** (a) faqat brauzer, (b) faqat Windows ilova, (c) **ikkalasi: sinov — brauzerda demo ma'lumot bilan; sotib olingach — Windows ilova, ma'lumot korxonada qoladi** ← tavsiya. Sabab: zavodlar o'z ishlab chiqarish ma'lumotini tashqi serverga qo'yishni xohlamaydi, sinov esa tez bo'lishi kerak.
2. **Hosting.** (a) **hozirgi PHP hosting (eskiz.uz) da portal + litsenziya serveri; vosita demosi shu yerda** ← tavsiya (yuk kichik, PHP+SQLite yetadi, `sodium` kengaytmasi kerak), (b) darhol VPS. VPS faqat bulutda har mijozga alohida nusxa yoki Python/OR-Tools hisob-kitobi kerak bo'lganda zarur (3-bosqich).
3. **To'lov.** (a) **hisob-faktura/bank o'tkazmasi, xodim litsenziyani qo'lda beradi** ← tavsiya, (b) darhol onlayn (Payme/Click — merchant shartnomasi va integratsiya kerak). Qo'lda boshlash sotuvni kechiktirmaydi.
4. **Litsenziya turi.** (a) **yillik obuna: muddat + o'rindiqlar** ← tavsiya (muddat tugaganda tizim o'zi bloklaydi, doimiy daromad), (b) bir martalik "doimiy" + yillik yangilanish. Tizim ikkalasini ham qo'llaydi (doimiy = uzoq muddat); gap — qaysi biri standart bo'lishida.

## 8. Cheklovlar (ochiq aytamiz)

- PHP vosita **manba kod ko'rinishida** yetkaziladi: texnik jihatdan litsenziya tekshiruvini olib tashlash mumkin. Litsenziya tizimi — shartnoma bilan birga **to'siq va nazorat**, mutlaq himoya emas. Eng kuchli chora — vositaning qimmatli qismini (o'rgatilgan model, optimizator) kompilyatsiya qilingan holda (Windows ilova) yoki serverdan berish.
- Ochiq ro'yxatdan o'tishda sinovdan suiste'mol qilish mumkin (bir korxona ko'p hisob). 1-bosqichdagi to'siqlar: sinov faqat umumiy demo ma'lumot bilan, **yuklab olish va ilova faollashtirish yo'q**; IP bo'yicha tezlik chegarasi (soatiga 5 ro'yxatdan o'tish). E-pochta tasdig'i va xodim tasdig'i — 2-bosqichda (xohlasangiz, tezroq qo'shamiz).
- Sinov demosi **umumiy demo ma'lumot** bilan; mijozning haqiqiy ma'lumoti umumiy serverga yuklanmaydi.
