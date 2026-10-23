<?php
/**
 * Hujjatlar kutubxonasi namunasi: xayoliy "Zamin Plast MChJ" ichki hujjatlari (matn).
 * Har hujjat: nomi, yonalish (kutubxona yo'nalish id), raqam, versiya, kuch (necha kun oldin kuchga kirgan),
 * majburiy, bolimlar (bo'sh = hammaga), tanish (tanishgan ulush 0..1), bolimlar, v1 (oldingi versiya bo'lsa: [versiya, kunOldin]), bandlar.
 * bandlar: [bo'lim sarlavhasi => [bandlar...]]
 */
function hr_hujjatlar_royxati(): array
{
    return [
        [
            'nomi' => "Ichki mehnat tartib qoidalari", 'yonalish' => 'qoida', 'raqam' => 'IMQ-01/2026', 'versiya' => '3.1',
            'kuch' => 140, 'yaratilgan' => 150, 'majburiy' => true, 'bolimlar' => [], 'tanish' => 0.88, 'v1' => ['2.0', 640],
            'tavsif' => "Ish vaqti, dam olish, kechikish va sababli kelmaslik tartibi, xodim va ish beruvchining majburiyatlari.",
            'bandlar' => [
                '1. Umumiy qoidalar' => [
                    "1.1. Ushbu qoidalar Zamin Plast MChJ (keyingi o'rinlarda — Jamiyat) xodimlari uchun ish tartibini belgilaydi va O'zbekiston Respublikasi mehnat qonunchiligiga muvofiq tuzilgan.",
                    "1.2. Qoidalar Jamiyatning barcha xodimlariga, shu jumladan sinov muddatidagi va vaqtinchalik xodimlarga tatbiq etiladi.",
                    "1.3. Xodim ishga qabul qilinganda ushbu qoidalar bilan imzo qarshi tanishtiriladi; tanishuv belgisi tizimda saqlanadi.",
                ],
                '2. Ish vaqti' => [
                    "2.1. Ish vaqti dushanbadan jumagacha soat 09:00 dan 18:00 gacha; tushlik tanaffusi 60 daqiqa (13:00–14:00).",
                    "2.2. Shanba va yakshanba dam olish kunlari. Bayram oldi kunlari ish vaqti 1 soatga qisqartiriladi.",
                    "2.3. Ishga kelish va ketish vaqti QR-kod orqali belgilanadi: xodim ish joyidagi QR-kodni telefon bilan skaner qiladi, joylashuvi tekshiriladi va suratga tushadi.",
                    "2.4. Boshlanish vaqtidan 5 daqiqagacha kechikish kechikish sifatida hisoblanmaydi.",
                ],
                '3. Kechikish va sababli kelmaslik' => [
                    "3.1. Kechikkan xodim shu kuni bevosita rahbarini xabardor qiladi; sabab tizimda yozib qo'yiladi.",
                    "3.2. Kasallik, ta'til, xizmat safari va rahbar ruxsati bilan kelmaslik sababli hisoblanadi; sababli kunlar intizom ko'rsatkichini pasaytirmaydi.",
                    "3.3. Kasallik varaqasi xodim ishga qaytgan kuni kadrlar bo'limiga topshiriladi.",
                    "3.4. Sababsiz kelmagan kun tabelda «NB» deb belgilanadi va mehnat intizomi buzilishi sifatida ko'riladi.",
                ],
                '4. Xodimning majburiyatlari' => [
                    "4.1. Mehnat vazifalarini o'z vaqtida va sifatli bajarish, ish joyida tartib va tozalikni saqlash.",
                    "4.2. Jamiyat mol-mulkini asrash, texnika va uskunadan faqat belgilangan tartibda foydalanish.",
                    "4.3. Tijorat va xizmat sirini saqlash, mijozlar va hamkasblar bilan hurmat bilan munosabatda bo'lish.",
                ],
            ],
        ],
        [
            'nomi' => "Mehnat ta'tillari berish tartibi to'g'risidagi nizom", 'yonalish' => 'mehnat', 'raqam' => 'NZ-07/2026', 'versiya' => '2.0',
            'kuch' => 95, 'yaratilgan' => 100, 'majburiy' => true, 'bolimlar' => [], 'tanish' => 0.74, 'v1' => ['1.2', 420],
            'tavsif' => "Yillik mehnat ta'tilini rejalashtirish, ariza berish va ta'til to'lovlari tartibi.",
            'bandlar' => [
                '1. Ta\'til jadvali' => [
                    "1.1. Yillik ta'til jadvali har yilning 15 dekabrigacha kadrlar bo'limi tomonidan bo'lim boshliqlari bilan kelishilib tuziladi va bosh direktor tomonidan tasdiqlanadi.",
                    "1.2. Ta'til jadvali Jamiyatning barcha xodimlari uchun majburiydir; xodim ta'til sanalari haqida kamida 2 hafta oldin xabardor qilinadi.",
                ],
                '2. Ariza va ruxsat' => [
                    "2.1. Xodim ta'tilga chiqishdan kamida 10 kalendar kun oldin ariza beradi.",
                    "2.2. Ariza bevosita rahbar va xodimlar bo'yicha menejer tomonidan ko'rib chiqiladi; ruxsat berilgach ta'til davri tizimdagi davomat va tabelga belgilanadi.",
                    "2.3. O'z hisobidan ta'til faqat ish beruvchi roziligi bilan, ariza asosida beriladi.",
                ],
                '3. To\'lovlar' => [
                    "3.1. Ta'til haqi ta'til boshlanishidan kamida 3 ish kuni oldin to'lanadi.",
                    "3.2. Ta'til davomida kasallik varaqasi ochilsa, ta'til shu kunlarga uzaytiriladi yoki boshqa muddatga ko'chiriladi.",
                ],
            ],
        ],
        [
            'nomi' => "Mehnat muhofazasi va xavfsizlik texnikasi yo'riqnomasi (ishlab chiqarish)", 'yonalish' => 'xavfsizlik', 'raqam' => 'YR-03/2026', 'versiya' => '4.0',
            'kuch' => 30, 'yaratilgan' => 34, 'majburiy' => true, 'bolimlar' => ['Ishlab chiqarish', 'Ombor va logistika'], 'tanish' => 0.54, 'v1' => ['3.2', 365],
            'tavsif' => "Ekstruder va qadoqlash liniyalarida, omborda ishlash xavfsizligi. Ishlab chiqarish va ombor xodimlari uchun majburiy.",
            'bandlar' => [
                '1. Umumiy talablar' => [
                    "1.1. Ishlab chiqarish hududiga kirishdan oldin xodim himoya kiyimi va poyabzalini kiyadi, uzun sochni yig'adi.",
                    "1.2. Ichkilik ichgan yoki dori ta'sirida bo'lgan xodim ishlab chiqarish hududiga kiritilmaydi.",
                    "1.3. Har chorakda xodimlar bilan takroriy yo'riqnoma o'tkaziladi va jurnalga imzo qo'yiladi.",
                ],
                '2. Uskunalar bilan ishlash' => [
                    "2.1. Ekstruderni ishga tushirishdan oldin himoya g'ilofi, favqulodda to'xtatish tugmasi va isitish zonalari tekshiriladi.",
                    "2.2. Ishlayotgan uskunani tozalash, moylash va ta'mirlash mutlaqo taqiqlanadi; buning uchun uskuna o'chirilib, «Tegilmasin» belgisi osiladi.",
                    "2.3. Elektr jihozlarini faqat malakali elektrik ulaydi va ta'mirlaydi.",
                ],
                '3. Omborda ishlash' => [
                    "3.1. Forklift faqat guvohnomasi bor operator tomonidan boshqariladi; yuk ostida turish taqiqlanadi.",
                    "3.2. Yuklarni taxlash balandligi 3 metrdan oshmaydi; o'tish yo'laklari doimo bo'sh bo'lishi shart.",
                ],
                '4. Baxtsiz hodisa yuz bersa' => [
                    "4.1. Jabrlanuvchiga birinchi yordam ko'rsatiladi va 103 xizmati chaqiriladi.",
                    "4.2. Voqea haqida darhol sex ustasi va ishlab chiqarish boshlig'iga xabar beriladi, voqea joyi o'zgartirilmaydi.",
                ],
            ],
        ],
        [
            'nomi' => "Yong'in xavfsizligi qoidalari", 'yonalish' => 'xavfsizlik', 'raqam' => 'YQ-02/2025', 'versiya' => '2.0',
            'kuch' => 260, 'yaratilgan' => 265, 'majburiy' => true, 'bolimlar' => [], 'tanish' => 0.94, 'v1' => null,
            'tavsif' => "Binolarda va ishlab chiqarish hududida yong'in xavfsizligi, evakuatsiya tartibi.",
            'bandlar' => [
                '1. Profilaktika' => [
                    "1.1. Binolarda chekish faqat maxsus ajratilgan joyda ruxsat etiladi.",
                    "1.2. Evakuatsiya yo'llari va o't o'chirgichlar oldi hech qachon to'sib qo'yilmaydi.",
                    "1.3. Ish kuni oxirida elektr asboblari o'chirilganligi tekshiriladi.",
                ],
                '2. Yong\'in yuz bersa' => [
                    "2.1. Har bir xodim yong'inni sezsa, darhol 101 xizmatiga qo'ng'iroq qiladi va rahbarni xabardor qiladi.",
                    "2.2. Kichik o't o'chirgich bilan o'chirish faqat xavfsiz bo'lsa urinib ko'riladi; aks holda binoni evakuatsiya qilish boshlanadi.",
                    "2.3. Yig'ilish joyi — bosh darvoza yonidagi maydon; xodimlar ro'yxati bo'yicha tekshiruv sex ustalari tomonidan o'tkaziladi.",
                ],
            ],
        ],
        [
            'nomi' => "Axborot xavfsizligi va kompyuterdan foydalanish siyosati", 'yonalish' => 'xavfsizlik', 'raqam' => 'AX-01/2026', 'versiya' => '1.4',
            'kuch' => 75, 'yaratilgan' => 80, 'majburiy' => true, 'bolimlar' => ["Boshqaruv", "Ma'muriyat va kadrlar", "Buxgalteriya", "Savdo va marketing"], 'tanish' => 0.69, 'v1' => null,
            'tavsif' => "Parollar, elektron pochta, shaxsiy ma'lumotlar va xizmat kompyuteridan foydalanish qoidalari (ofis xodimlari uchun).",
            'bandlar' => [
                '1. Parollar va kirish' => [
                    "1.1. Parol kamida 8 belgidan iborat bo'lib, harf va raqamni o'z ichiga oladi; parolni boshqa kishiga berish taqiqlanadi.",
                    "1.2. Tizimga kirish uchun ikki bosqichli tasdiqlashdan foydalanish tavsiya etiladi.",
                ],
                '2. Ma\'lumotlar bilan ishlash' => [
                    "2.1. Xodimlar va mijozlarning shaxsiy ma'lumotlari faqat ishga zarur doirada ishlatiladi va uchinchi shaxslarga berilmaydi.",
                    "2.2. Xizmat hujjatlari shaxsiy elektron pochta va ijtimoiy tarmoqlar orqali yuborilmaydi.",
                    "2.3. Shubhali xat yoki havola kelsa, ochilmasdan tizim administratoriga yuboriladi.",
                ],
            ],
        ],
        [
            'nomi' => "Odob-axloq kodeksi", 'yonalish' => 'axloq', 'raqam' => 'OK-01/2025', 'versiya' => '1.0',
            'kuch' => 320, 'yaratilgan' => 325, 'majburiy' => true, 'bolimlar' => [], 'tanish' => 0.97, 'v1' => null,
            'tavsif' => "Hamkasblar, mijozlar va hamkorlar bilan munosabat qoidalari, manfaatlar to'qnashuvi.",
            'bandlar' => [
                '1. Asosiy tamoyillar' => [
                    "1.1. Halollik, ochiqlik, hurmat va mas'uliyat Jamiyat faoliyatining asosiy tamoyillaridir.",
                    "1.2. Xodim irqi, jinsi, yoshi, dini yoki ijtimoiy kelib chiqishi sababli kamsitilmasligi shart; bunday holatlar qat'iy taqiqlanadi.",
                ],
                '2. Manfaatlar to\'qnashuvi' => [
                    "2.1. Xodim shaxsiy manfaati xizmat vazifasiga ta'sir qilishi mumkin bo'lgan holatda bu haqda rahbarni yozma ravishda xabardor qiladi.",
                    "2.2. Hamkor va yetkazib beruvchilardan 500 000 so'mdan ortiq qiymatdagi sovg'a qabul qilinmaydi.",
                ],
            ],
        ],
        [
            'nomi' => "Ish haqi va mukofotlash to'g'risidagi nizom", 'yonalish' => 'mehnat', 'raqam' => 'NZ-04/2026', 'versiya' => '2.2',
            'kuch' => 55, 'yaratilgan' => 60, 'majburiy' => false, 'bolimlar' => [], 'tanish' => 0.0, 'v1' => ['2.1', 330],
            'tavsif' => "Ish haqi tarkibi, oylik mukofotlar, ish vaqti intizomi koeffitsienti va ish haqini to'lash muddatlari.",
            'bandlar' => [
                '1. Ish haqi tarkibi' => [
                    "1.1. Xodim ish haqi lavozim maoshi, ish vaqti tabeli bo'yicha hisoblangan summa va mukofotlardan iborat.",
                    "1.2. Ish haqi oyiga ikki marta: avans — 25-sanada, yakuniy hisob — keyingi oyning 10-sanasigacha to'lanadi.",
                ],
                '2. Intizom koeffitsienti' => [
                    "2.1. Har chorak yakunida xodimning ish vaqti intizomi koeffitsienti tabel ma'lumotlari asosida hisoblanadi.",
                    "2.2. Mehnat ta'tili va xizmat safari koeffitsientni pasaytirmaydi; kasallik, o'z hisobidan ta'til va sababsiz kelmaslik pasaytiradi.",
                    "2.3. Chorak mukofoti koeffitsientga mutanosib ravishda to'lanadi.",
                ],
            ],
        ],
        [
            'nomi' => "Ish vaqtini QR-kod orqali belgilash tartibi", 'yonalish' => 'kadrlar', 'raqam' => 'TR-05/2026', 'versiya' => '1.1',
            'kuch' => 48, 'yaratilgan' => 52, 'majburiy' => true, 'bolimlar' => [], 'tanish' => 0.82, 'v1' => ['1.0', 120],
            'tavsif' => "Xodim ishga kelganda va ketganda QR-kod, joylashuv va selfi bilan qanday belgilanishi haqida qisqa yo'riqnoma.",
            'bandlar' => [
                '1. Belgilash' => [
                    "1.1. Har bir ish joyida (bosh ofis, ishlab chiqarish sexi, ombor) QR-kod osib qo'yilgan.",
                    "1.2. Xodim telefoni bilan QR-kodni skaner qiladi, tizimga telefon raqami va parol bilan kiradi.",
                    "1.3. Telefon joylashuvi ish joyi hududida (radius 100 metr) ekanligi tekshiriladi, shundan keyingina old kamera orqali selfi olinadi.",
                    "1.4. Kelish va ketish vaqti serverning soati bo'yicha qayd etiladi; telefon soati hisobga olinmaydi.",
                ],
                '2. Maxfiylik' => [
                    "2.1. Selfi suratlari faqat tasdiqlash uchun olinadi va 3 kundan keyin avtomatik o'chiriladi.",
                    "2.2. Belgilash tarixini faqat xodimning o'zi va xodimlar bo'yicha menejer ko'ra oladi.",
                ],
                '3. Muammo bo\'lsa' => [
                    "3.1. Telefon ishlamasa yoki GPS noto'g'ri bo'lsa, xodim bevosita rahbariga xabar beradi; xodimlar bo'yicha menejer davomatga qo'lda tuzatish kiritadi.",
                ],
            ],
        ],
        [
            'nomi' => "Xizmat safarlari to'g'risidagi nizom", 'yonalish' => 'kadrlar', 'raqam' => 'NZ-09/2026', 'versiya' => '1.0',
            'kuch' => 12, 'yaratilgan' => 14, 'majburiy' => false, 'bolimlar' => ["Boshqaruv", "Savdo va marketing", "Ma'muriyat va kadrlar"], 'tanish' => 0.0, 'v1' => null,
            'tavsif' => "Xizmat safariga yuborish, safar xarajatlari va hisobot topshirish tartibi.",
            'bandlar' => [
                '1. Safarga yuborish' => [
                    "1.1. Xizmat safari bosh direktor buyrug'i bilan rasmiylashtiriladi; buyruq asosida davomat va tabelda «x/s» belgisi qo'yiladi.",
                    "1.2. Safar muddati 30 kundan oshmaydi.",
                ],
                '2. Xarajatlar va hisobot' => [
                    "2.1. Safar xarajatlari avans orqali qoplanadi; xodim qaytgach 3 ish kuni ichida avans hisobotini topshiradi.",
                ],
            ],
        ],
    ];
}
