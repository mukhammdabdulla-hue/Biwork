# Biwork Monitoring — reja (1 sahifa)

**Nima bu.** Korxona jarayonlarini o'zi kuzatadigan alohida Biwork mahsuloti: ma'lumot kelishi bilan qoidalar bo'yicha tekshiradi va muammoni
*kechikib qolmasdan* mas'ul odamga ko'rsatadi ("nima bo'ldi · nima uchun muhim · nima qilish kerak"). Biwork Reja bilan birga ishlaydi: Reja "qanday bo'lishi kerak"ni,
Monitoring "aslida qanday bo'layotgani"ni beradi.

## Ma'lumotda nimani kuzatish mumkin (NGD fayllarida o'lchandi)

| Jarayon | Ko'rsatadigan ogohlantirish | Ma'lumot manbai | Ma'lumotda kuzatish mumkinmi |
|---|---|---|---|
| Uskuna to'xtashi | "Tigel-05 1 soat 50 daqiqadan beri turibdi"; bir uskuna haftada 3+ marta buzilsa | ERP "Незапланированные простои" | **tayyor** |
| Reja va fakt farqi | ish boshlanishi kerak edi, boshlanmadi; operatsiya me'yordan uzoq cho'zildi | Biwork Reja + ERP fakt | **tayyor** (Reja bilan) |
| Buyurtma muddati | muddatga ulgurmaydigan buyurtma, kechikish soati va sababi | muddat + qolgan ishlar | muddat manbasi hal bo'lsin |
| Sozlash vaqti | sozlash me'yordan 1,5 baravar ko'p | ERP "приладка" | qisman (faqat oktyabr hisoboti) |
| Navbat / tor joy | uskuna oldida 2 sutkalik ish yig'ilib qoldi | kunlik reja fayllari, ERP navbat | **tayyor** |
| Ma'lumot intizomi | to'xtash sababi yozilmagan; manba yangilanmagan | ERP, kunlik fayllar | **tayyor** |
| Vazifalar muddati | muddati o'tayotgan vazifa | Biwork Loyiha | 2-bosqich |

**Topilmalar (tigel uchastkasi, 01.07–12.10):**
- 1 240 ta rejasiz to'xtash = 3 737 soat; 148 ta ta'mir (o'rtacha 55 daqiqa, har o'ninchisi 3,7 soatdan uzoq). To'xtashlarning 56% i 1 soatdan uzoq va soatlarning 95% ini beradi — shuning uchun faqat uzoq to'xtashlarni ko'rsatish yetarli.
- ERPdagi reja eskirgan: 1 020 operatsiyaning 62% i rejadagi vaqtdan 24 soatdan keyin boshlangan (o'rtacha +67 soat). Haqiqiy reja-fakt uchun ERP rejasi emas, **Biwork Reja** kerak.
- 237 buyurtmaning 190 tasida ERP "vaqt zaxirasi" 0: topshirish muddati ERPda aniq saqlanmaydi.
- To'xtash soatlarining ~62% i sababsiz ("Boshqa"/erkin matn); ish yozuvlarining 10,8% i 2 daqiqadan qisqa — yozuv intizomi o'zi alohida nazorat obyekti.
- Kunlik reja fayllarining 22 varag'idan 10 tasi 33 faylda o'zgarmay qolgan; rejadagi qatorlarning faqat 28–49% i o'sha kuni boshlangan.

## Birinchi qism (MVP) — tayyor

10 ta tayyor qoida (to'xtash, takroriy ta'mir, ishsiz turish, kech boshlash, me'yordan oshish, sozlash, muddat xavfi, navbat, sababsiz to'xtash, eskirgan manba);
har qoidaning chegarasi sozlanadi. Ogohlantirish hayoti: ochiladi → "ko'rdim" → mas'ul → yopiladi (shart yo'qolsa o'zi yopiladi). 14 uskunali to'qilgan namuna korxona
(soatni surib ko'rish mumkin), o'zbekcha interfeys, yorug'/qorong'i mavzu. Biwork shartnomasi bo'yicha ulangan: manifest, "Ochish" tokeni, litsenziya tekshiruvi
(portalning haqiqiy tokenlari bilan sinalgan: 44 birlik + 57 HTTP/SSO tekshiruvi o'tdi).
**Hali yo'q:** haqiqiy ma'lumot manbalari (ERP hisoboti, Biwork Reja) — ular yuqoridagi 3 qarorga bog'liq, shuning uchun mahsulot portalda "tez orada" holatida.
Tafsilot: [`../README.md`](../README.md).

## Sizdan kerak bo'ladigan qarorlar (tavsiya bilan)

1. **Birinchi bosqich doirasi.** Tavsiya: *faqat ishlab chiqarish* (uskuna, reja-fakt, buyurtma muddati) — ma'lumoti bor va foydasi darrov ko'rinadi; Loyiha vazifalari va Savdo 2-bosqichda.
2. **Topshirish muddati qayerdan olinadi.** Tavsiya: *Biwork Rejada kiritiladigan muddat* (ERPda yo'q); Rejasiz mijozda — o'xshash buyurtmalarning odatiy oqim vaqti (taxminiy deb belgilanadi).
3. **Ma'lumot qanday keladi.** Tavsiya: boshida *ERP hisobotini (Excel) papkaga tashlash — o'zi o'qiladi* (kuniga 2–4 marta); keyin ERP bazasiga to'g'ridan-to'g'ri ulanish (korxona IT bilan kelishib). Zavod stanogidan bevosita signal — undan keyin.

## Keyingi bosqichlar
Telegramga ogohlantirish (jiddiylari mas'ulga) · ERP Excel'ni avtomatik o'qish · Vazifalar (Loyiha) va Savdo kuzatuvi · haftalik hisobot · Rejadan "qayta tuzish" tugmasi.

## Boshqa threadlarga eslatma (men tegmadim)
Portal: `manifest.json` ni `tool:register` qilish va `marketing/` rasmlarini `assets/products/monitoring/` ga ko'chirish; to'liq ro'yxat: [`PORTAL-UCHUN.md`](PORTAL-UCHUN.md).
Reja paneli: o'zgarish shart emas (keyingi bosqichda "qayta tuzish" havolasi).
