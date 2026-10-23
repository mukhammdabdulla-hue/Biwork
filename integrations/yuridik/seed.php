<?php
/**
 * Biwork Yuridik — namuna (demo) ma'lumoti. BUTUNLAY XAYOLIY: korxonalar, taraflar, yuristlar, ish raqamlari,
 * summalar va hujjat matnlari uydirma; haqiqiy tashkilot yoki shaxslarga aloqasi yo'q.
 *
 * Nima yoziladi (PM ning o'z shakli bilan — mijoz yaratadigan obyektlarning aynan o'zi):
 *   - 3 ta korxona (tepadagi korxona tanlagichi ishlasin; sud ishi korxona kesimida) + «Umumiy» (korxonasiz) ishlar;
 *   - 26 ta sud ishi (entities `sud`): iqtisodiy / fuqarolik / ma'muriy, 1-instansiya / apellyatsiya / kassatsiya,
 *     holatlar: jarayonda · yutildi · yutqazildi · kelishuv · to'xtatildi; majlislar (o'tgan natijalari bilan + KELAJAKDAGI);
 *   - har ishga sudga doir hujjatlar: kichik xayoliy PDF/DOCX fayllar DATA_DIR/uploads/sud/<ish id>/ da
 *     (uploads reyestri: ega_tur='sud'), hujjat yozuvi ish ichida (`hujjatlar`), ba'zilari majlisga biriktirilgan.
 * Sanalar bugunga nisbatan (namuna "jonli" turadi); tasodif yo'q — har safar bir xil shablon.
 *
 * Sud ishida summa / ish turi / instansiya uchun alohida maydon PM da yo'q — ular `sud` (nom) va `izoh` matnida yuritiladi.
 */

/* ------------------------------------------------------------------ yordamchilar */

/** Bugundan $n kun keyingi (musbat) yoki oldingi (manfiy) ish kuni (shanba/yakshanba surilib ketadi), Y-m-d. */
function yur_kun(int $n): string {
    $t = strtotime(($n >= 0 ? '+' : '') . $n . ' days', strtotime(date('Y-m-d')));
    $w = (int)date('N', $t);
    if ($w >= 6) $t += ($n >= 0 ? (8 - $w) : -($w - 5)) * 86400;
    return date('Y-m-d', $t);
}
function yur_dmy(string $iso): string { return date('d.m.Y', strtotime($iso)); }
function yur_som(int $n): string { return number_format($n, 0, '', ' '); }
function yur_id(string $p, string $kalit): string { return $p . '_' . substr(md5('biwork-yuridik|' . $kalit), 0, 8); }
function yur_ascii(string $s): string {
    $s = strtr($s, ["\u{2019}" => "'", "\u{2018}" => "'", "\u{02BB}" => "'", "\u{02BC}" => "'", "\u{2014}" => '-', "\u{2013}" => '-', "\u{2116}" => 'N']);
    return (string)preg_replace('/[^\x20-\x7E]/', '?', $s);
}

/** Juda sodda, lekin yaroqli PDF (Helvetica, A4, bir necha sahifa). */
function yur_pdf(string $sarlavha, array $abzaclar): string {
    $qator = [];
    $qator[] = ['NAMUNA HUJJAT  |  Biwork Yuridik  |  xayoliy ma\'lumot', 's'];
    $qator[] = ['', 'b'];
    foreach (explode("\n", wordwrap(yur_ascii($sarlavha), 52, "\n", true)) as $q) $qator[] = [$q, 'h'];
    $qator[] = ['', 'b'];
    foreach ($abzaclar as $a) {
        $a = yur_ascii((string)$a);
        if ($a === '') { $qator[] = ['', 'b']; continue; }
        foreach (explode("\n", wordwrap($a, 86, "\n", true)) as $q) $qator[] = [$q, 'b'];
        $qator[] = ['', 'b'];
    }
    $sahifalar = array_chunk($qator, 48);
    $obj = [];
    $obj[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $kids = [];
    $n = 5;
    $sahObj = [];
    foreach ($sahifalar as $sq) {
        $pId = $n++; $cId = $n++;
        $kids[] = "$pId 0 R";
        $y = 800;
        $s = "BT\n";
        foreach ($sq as [$m, $t]) {
            $esc = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $m);
            if ($t === 'h') { $s .= "/F2 14 Tf\n1 0 0 1 56 $y Tm\n($esc) Tj\n"; $y -= 20; }
            elseif ($t === 's') { $s .= "/F1 8 Tf\n1 0 0 1 56 $y Tm\n($esc) Tj\n"; $y -= 14; }
            else { $s .= "/F1 10.5 Tf\n1 0 0 1 56 $y Tm\n($esc) Tj\n"; $y -= 14; }
        }
        $s .= "ET\n";
        $sahObj[$pId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents $cId 0 R >>";
        $sahObj[$cId] = "<< /Length " . strlen($s) . " >>\nstream\n$s" . "endstream";
    }
    $obj[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
    $obj[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
    $obj[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
    foreach ($sahObj as $k => $v) $obj[$k] = $v;
    ksort($obj);
    $out = "%PDF-1.4\n";
    $off = [];
    foreach ($obj as $k => $v) { $off[$k] = strlen($out); $out .= "$k 0 obj\n$v\nendobj\n"; }
    $xref = strlen($out);
    $out .= "xref\n0 " . (count($obj) + 1) . "\n0000000000 65535 f \n";
    foreach ($obj as $k => $_) $out .= sprintf("%010d 00000 n \n", $off[$k]);
    $out .= "trailer\n<< /Size " . (count($obj) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    return $out;
}

/** Eng sodda DOCX (ZipArchive): sarlavha + abzaclar. */
function yur_docx(string $sarlavha, array $abzaclar): string {
    $x = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $p = '<w:p><w:r><w:rPr><w:sz w:val="18"/><w:color w:val="888888"/></w:rPr><w:t>NAMUNA HUJJAT - Biwork Yuridik - xayoliy ma\'lumot</w:t></w:r></w:p>';
    $p .= '<w:p><w:r><w:rPr><w:b/><w:sz w:val="30"/></w:rPr><w:t>' . $x($sarlavha) . '</w:t></w:r></w:p>';
    foreach ($abzaclar as $a) $p .= '<w:p><w:r><w:t xml:space="preserve">' . $x($a) . '</w:t></w:r></w:p>';
    $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $p . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134"/></w:sectPr></w:body></w:document>';
    $tmp = tempnam(sys_get_temp_dir(), 'yurdocx');
    $z = new ZipArchive();
    $z->open($tmp, ZipArchive::OVERWRITE);
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
    $z->addFromString('word/document.xml', $doc);
    $z->close();
    $b = (string)file_get_contents($tmp);
    @unlink($tmp);
    return $b;
}

/** Faylni DATA_DIR/uploads/sud/<ish>/ ga yozadi va `uploads` reyestriga qayd etadi; ish ichiga yoziladigan fayl obyektini qaytaradi. */
function yur_fayl(string $ishId, string $nom, string $bayt, string $kim, string $sana): array {
    $ext = strtolower(pathinfo($nom, PATHINFO_EXTENSION));
    $id = md5('biwork-yuridik-fayl|' . $ishId . '|' . $nom);
    $stored = $id . '.' . $ext;
    $dir = DATA_DIR . '/uploads/sud/' . $ishId;
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    file_put_contents($dir . '/' . $stored, $bayt);
    $at = $sana . 'T09:' . sprintf('%02d', hexdec(substr($id, 0, 2)) % 50 + 5) . ':00Z';
    db()->prepare('INSERT OR REPLACE INTO uploads(id, stored_name, rel_path, original_name, ext, size, uploaded_at, uploaded_by, ega_tur, ega_kod) VALUES(?,?,?,?,?,?,?,?,?,?)')
        ->execute([$id, $stored, 'sud/' . $ishId . '/' . $stored, $nom, $ext, strlen($bayt), $at, 'namuna-admin', 'sud', $ishId]);
    return ['id' => $id, 'storedName' => $stored, 'originalName' => $nom, 'size' => strlen($bayt), 'uploadedAt' => $at, 'uploadedBy' => 'namuna-admin'];
}

/* ------------------------------------------------------------------ hujjat turlari (katalog) */
/* kod => [tur (SUD_TUR kaliti), nomi, fayl nomi boshi, kengaytma, standart izoh] */
const YUR_HJ = [
    'davo'      => ['davo',      "Da'vo arizasi",                                   'Davo_arizasi',          'pdf', "Sudga topshirilgan ariza (nusxa)."],
    'qdavo'     => ['davo',      "Qarshi tarafning da'vo arizasi",                  'Qarshi_taraf_davosi',   'pdf', "Sud orqali olingan nusxa; javob muddati kuzatiladi."],
    'ilova'     => ['dalil',     "Da'vo ilovalari (shartnoma, hisob-faktura, dalolatnomalar)", 'Davo_ilovalari', 'pdf', "Dalillar to'plami, sahifalangan."],
    'pretenz'   => ['yozishma',  "Pretenziya xati (da'vodan oldingi talab)",        'Pretenziya_xati',       'pdf', "Da'vo tartibiga rioya: pretenziya yuborilgan, javob olinmagan."],
    'boj'       => ['boj',       "Davlat boji to'langanligi to'g'risida to'lov topshirig'i", 'Davlat_boji_tolov', 'pdf', "To'lov topshirig'i nusxasi."],
    'vakolat'   => ['vakolat',   "Vakolatnoma (yurist nomiga)",                     'Vakolatnoma',           'pdf', "Sudda vakillik uchun."],
    'javob'     => ['javob',     "Javob xati (e'tirozlarimiz)",                     'Javob_xati',            'pdf', "Da'voga yozma e'tiroz."],
    'etiroz'    => ['javob',     "Qarshi tarafning javob xati",                     'Qarshi_taraf_javobi',   'pdf', "Qarshi taraf e'tirozi; qayta javob tayyorlanadi."],
    'bayon'     => ['bayonnoma', "Majlis bayonnomasi",                              'Majlis_bayonnomasi',    'pdf', "Majlis jarayoni yozuvi."],
    'qaror'     => ['qaror',     "Sud qarori",                                      'Sud_qarori',            'pdf', "Ish bo'yicha qaror nusxasi."],
    'ajrim'     => ['qaror',     "Sud ajrimi",                                      'Sud_ajrimi',            'pdf', "Protsessual ajrim nusxasi."],
    'shikoyat'  => ['shikoyat',  "Shikoyat",                                        'Shikoyat',              'pdf', "Yuqori instansiyaga shikoyat."],
    'ijro'      => ['ijro',      "Ijro varaqasi",                                   'Ijro_varaqasi',         'pdf', "Majburiy ijro uchun."],
    'ekspert'   => ['dalil',     "Ekspertiza xulosasi",                             'Ekspertiza_xulosasi',   'pdf', "Sud tayinlagan ekspertiza natijasi."],
    'kelishuv'  => ['boshqa',    "Kelishuv bitimi",                                 'Kelishuv_bitimi',       'docx', "Taraflar imzolagan matn (tahrir qilinadigan nusxa)."],
    'hisob'     => ['dalil',     "Da'vo summasi hisob-kitobi",                      'Hisob_kitob',           'docx', "Asosiy qarz va penya hisobi."],
    'yozish'    => ['yozishma',  "Yozishma",                                        'Yozishma',              'pdf', ""],
    'dalil'     => ['dalil',     "Qo'shimcha dalillar",                             'Dalillar',              'pdf', ""],
];

/** Hujjat PDF/DOCX matni: [sarlavha, abzaclar[]] */
function yur_matn(string $kod, array $c, string $nom, string $sana): array {
    $sud = $c['sud']; $rq = $c['raqam']; $kx = $c['kx']; $tf = $c['taraf'];
    $dav = $c['rol'] === 'dav' ? $kx : $tf;
    $jav = $c['rol'] === 'dav' ? $tf : $kx;
    $summa = yur_som($c['summa']) . " so'm";
    $bosh = ["Sud: $sud", "Ish raqami: $rq (namuna raqam)", "Da'vogar: $dav", "Javobgar: $jav", "Ish mavzusi: " . $c['mavzu'], "Sana: " . yur_dmy($sana)];
    switch ($kod) {
        case 'davo': case 'qdavo':
            return ["DA'VO ARIZASI", array_merge($bosh, ["",
                "Talab: yuqorida ko'rsatilgan nizo bo'yicha $summa miqdoridagi summani undirish.",
                "Asos: taraflar o'rtasida tuzilgan shartnoma va uning ijrosi bo'yicha hujjatlar. Majburiyat o'z vaqtida bajarilmagan, pretenziyaga javob berilmagan.",
                "Hisob-kitob: asosiy qarz, kechiktirilgan har kun uchun penya va davlat boji.",
                "Ilova: shartnoma nusxasi, hisob-faktura va dalolatnomalar, to'lov topshirig'i, vakolatnoma.",
                "", "Ushbu hujjat namuna sifatida yaratilgan; barcha nomlar, raqamlar va summalar xayoliy."])];
        case 'ilova': case 'dalil':
            return [mb_strtoupper($nom, 'UTF-8'), array_merge($bosh, ["",
                "1. Shartnoma va qo'shimcha kelishuvlar - 6 bet.", "2. Hisob-faktura va dalolatnomalar - 14 bet.", "3. Solishtirma dalolatnoma - 2 bet.",
                "4. Yozishmalar (elektron pochta va rasmiy xatlar) - 9 bet.", "", "Ro'yxat namunaviy; haqiqiy hujjatlar bilan bog'liq emas."])];
        case 'pretenz':
            return ["PRETENZIYA XATI", array_merge($bosh, ["",
                "Shartnoma bo'yicha majburiyatlar bajarilmaganligi sababli $summa miqdoridagi talab 10 kalendar kun ichida qondirilishi so'raladi.",
                "Aks holda masala sudda ko'riladi; sud xarajatlari va penya javobgar zimmasiga yuklatiladi.", "", "Namuna matn (xayoliy)."])];
        case 'boj':
            return ["TO'LOV TOPSHIRIG'I (DAVLAT BOJI)", array_merge($bosh, ["", "To'lovchi: $kx", "Maqsad: davlat boji, ish raqami $rq.", "Summa: " . yur_som((int)round($c['summa'] * 0.03)) . " so'm.", "", "Namuna hujjat (xayoliy rekvizitlar)."])];
        case 'vakolat':
            return ["VAKOLATNOMA", array_merge($bosh, ["", "$kx rahbari quyidagi yuristga sudda manfaatlarni himoya qilish, ariza va shikoyat berish, kelishuv tuzish vakolatini beradi: " . $c['masul'] . ".", "Muddat: 1 yil.", "", "Namuna hujjat (xayoliy)."])];
        case 'javob': case 'etiroz':
            return ["JAVOB XATI (E'TIROZ)", array_merge($bosh, ["",
                "Da'vo talabiga e'tiroz bildiriladi: talab qisman asossiz, hisoblangan penya miqdori shartnomadagi chegaradan oshgan, qarzning bir qismi to'langan.",
                "So'raymiz: da'voni rad etish yoki penya miqdorini kamaytirish.", "", "Namuna matn (xayoliy)."])];
        case 'bayon':
            return ["MAJLIS BAYONNOMASI", array_merge($bosh, ["",
                "Majlis ochildi. Taraflar vakillari ishtirok etdi: " . $c['masul'] . " ($kx nomidan).",
                "Ko'rilgan masala: " . ($c['_mj'] ?? "ish bo'yicha taraflarning tushuntirishlari") . ".",
                "Natija: " . ($c['_natija'] ?? 'qayd etildi') . ".", "", "Namuna bayonnoma (xayoliy)."])];
        case 'qaror': case 'ajrim':
            return [$kod === 'qaror' ? 'SUD QARORI' : 'SUD AJRIMI', array_merge($bosh, ["",
                "Ish materiallari va taraflarning tushuntirishlarini ko'rib chiqib, sud quyidagicha qaror qildi: " . ($c['_natija'] ?? "ish bo'yicha qaror qabul qilindi") . ".",
                "Qaror qabul qilingan kundan e'tiboran 1 oy ichida shikoyat qilinishi mumkin.", "", "Namuna hujjat (xayoliy)."])];
        case 'shikoyat':
            return ["SHIKOYAT", array_merge($bosh, ["", "Quyi instansiya qaroridan norozilik bildiriladi: huquq normalari noto'g'ri qo'llangan, dalillarga baho berilmagan.",
                "Talab: qarorni o'zgartirish yoki bekor qilish.", "", "Namuna matn (xayoliy)."])];
        case 'ijro':
            return ["IJRO VARAQASI", array_merge($bosh, ["", "Undiriladigan summa: $summa.", "Ijro muddati: 3 yil.", "", "Namuna hujjat (xayoliy)."])];
        case 'ekspert':
            return ["EKSPERTIZA XULOSASI", array_merge($bosh, ["", "Ekspert tekshiruvi natijasi: namunalar qisman talablarga mos kelmaydi; farq 4-7 foiz oralig'ida.", "Xulosa sudga taqdim etildi.", "", "Namuna hujjat (xayoliy)."])];
        case 'kelishuv':
            return ["KELISHUV BITIMI", ["Taraflar: $dav va $jav.", "1. Javobgar $summa dan kelishilgan qismini 6 oy ichida teng ulushlarda to'laydi.", "2. Da'vogar penya talabidan voz kechadi.", "3. Sud xarajatlari taraflar o'rtasida teng taqsimlanadi.", "", "Namuna matn (xayoliy)."]];
        case 'hisob':
            return ["DA'VO SUMMASI HISOB-KITOBI", ["Asosiy qarz: " . yur_som((int)round($c['summa'] * 0.85)) . " so'm.", "Penya: " . yur_som((int)round($c['summa'] * 0.15)) . " so'm.", "Jami: $summa.", "", "Namuna hisob (xayoliy)."]];
        default:
            return [mb_strtoupper($nom, 'UTF-8'), array_merge($bosh, ["", "Hujjat mazmuni qisqacha: $nom. Ish bo'yicha ish yuritish yozuvi.", "", "Namuna matn (xayoliy)."])];
    }
}

/* ------------------------------------------------------------------ asosiy */

function biwork_seed(array $ctx): void {
    bw_seed_rng(20261020);
    $kim = $ctx['kim'];

    /* ---- Korxonalar (sud ishi korxona kesimida; tepada tanlagich bo'ladi) ---- */
    $KX = [
        'A' => ['id' => 'kx_bahor',  'nomi' => 'Bahor Savdo Group MChJ',      'rahbar' => 'Karimov Aziz Rustamovich',    'tavsif' => "Ulgurji savdo va logistika"],
        'B' => ['id' => 'kx_orzu',   'nomi' => 'Orzu Qurilish Servis MChJ',   'rahbar' => 'Nazarov Timur Baxtiyorovich', 'tavsif' => "Qurilish va ta'mirlash ishlari"],
        'C' => ['id' => 'kx_safo',   'nomi' => 'Safo Agro Plyus MChJ',        'rahbar' => 'Hasanova Nigora Karimovna',   'tavsif' => "Qishloq xo'jaligi mahsulotlarini qayta ishlash"],
    ];
    $i = 1;
    foreach ($KX as $r) {
        bw_put('korxona', $r['id'], [
            'id' => $r['id'], 'nomi' => $r['nomi'], 'rasmiyNomi' => $r['nomi'], 'rahbar' => $r['rahbar'], 'tavsif' => $r['tavsif'],
            'tartib' => $i++,
        ]);
    }
    $kxNom = fn($k) => $k === 'U' ? 'Bahor Savdo Group MChJ' : $KX[$k]['nomi'];

    $YU = ['sob' => 'Sobirov Behzod', 'kar' => 'Karimova Dilnoza', 'yus' => 'Yusupov Otabek', 'rah' => 'Rahimova Madina'];
    $I1 = 'Iqtisodiy sud (1-instansiya)'; $IA = 'Iqtisodiy sud (apellyatsiya)'; $IK = 'Iqtisodiy sud (kassatsiya)';
    $FS = "Fuqarolik ishlari bo'yicha sud"; $MS = "Ma'muriy sud";

    /* ---- Sud ishlari ----
       rol: dav = biz da'vogar, jav = biz javobgar. summa — so'mda. mj: [kun, vaqt, nima ko'riladi, natija|null, natija izohi|null]
       hj: [kod, kun|null, majlis tartibi|null, {nomi?, izoh?}] — kun bo'sh bo'lsa majlis sanasi olinadi. */
    $ISHLAR = [
        /* ======================= A: Bahor Savdo Group ======================= */
        ['k' => 'A', 'mavzu' => "Tovar uchun qarzni undirish", 'sud' => $I1, 'taraf' => "Kamolot Pak MChJ", 'raqam' => "E-26/0142",
         'masul' => $YU['sob'], 'holat' => 'jarayonda', 'rol' => 'dav', 'tur' => 'Iqtisodiy ish', 'inst' => '1-instansiya', 'summa' => 486000000,
         'extra' => "Asosiy qarz 412 mln so'm, qolgani penya. Javobgar solishtirma dalolatnomani imzolamagan.",
         'mj' => [
            [-96, '10:00', "Ishni dastlabki ko'rish", "Dastlabki ko'rish o'tkazildi", "Ish mohiyati bo'yicha ko'rishga tayinlandi, javobgarga javob xati taqdim etish muddati berildi."],
            [-52, '11:30', "Taraflarning tushuntirishlari", "Keyinga qoldirildi", "Javobgar vakili qo'shimcha muddat so'radi; sud so'rovni qanoatlantirdi."],
            [-19, '10:30', "Solishtirma dalolatnomani tekshirish", "Keyinga qoldirildi", "Taraflar hisob-kitob bo'yicha kelisha olmadi; sud javobgardan buxgalteriya ma'lumotlarini talab qildi."],
            [-8,  '11:00', "Buxgalteriya ma'lumotlarini ishga qo'shish", "Qo'shimcha dalillar ishga qo'shildi", "Javobgar hisob-kitob ma'lumotlarini taqdim etdi; taraflarga o'rganish uchun vaqt berildi."],
            [3,   '10:30', "Ishni mohiyati bo'yicha ko'rish", null, null],
            [34,  '11:00', "Sud munozaralari", null, null]],
         'hj' => [['pretenz', -138], ['davo', -121], ['ilova', -121], ['boj', -122], ['vakolat', -119], ['etiroz', -70], ['bayon', null, 1], ['bayon', null, 2],
                  ['dalil', -2, 4, ['nomi' => "Majlisga qo'shimcha dalillar (solishtirma hisob-kitob)", 'izoh' => "Keyingi majlisda taqdim etiladi."]]]],

        ['k' => 'A', 'mavzu' => "Ijara zararini undirish", 'sud' => $I1, 'taraf' => "Sifat-Invest Servis MChJ", 'raqam' => "E-26/0098",
         'masul' => $YU['kar'], 'holat' => 'kelishuv', 'rol' => 'dav', 'tur' => 'Iqtisodiy ish', 'inst' => '1-instansiya', 'summa' => 212000000,
         'extra' => "Kelishuv bitimi sud tomonidan tasdiqlangan; to'lov jadvali 6 oyga, ijro nazorat qilinadi.",
         'mj' => [
            [-150, '14:00', "Ishni dastlabki ko'rish", "Keyinga qoldirildi", "Taraflar yarashuv imkoniyatini bildirdi."],
            [-88,  '10:00', "Kelishuv bitimini tasdiqlash", "Kelishuv tuzildi", "Taraflar kelishuv bitimini sudga taqdim etdi, sud uni tasdiqladi va ishni tugatdi."],
            [28,   '11:30', "Kelishuv bitimi ijrosi bo'yicha hisobot", null, null]],
         'hj' => [['davo', -171], ['ilova', -171], ['boj', -172], ['kelishuv', -91], ['ajrim', null, 1, ['nomi' => "Kelishuv bitimini tasdiqlash to'g'risida ajrim"]], ['bayon', null, 1]]],

        ['k' => 'A', 'mavzu' => "Omborxona ijarasi qarzi (apellyatsiya)", 'sud' => $IA, 'taraf' => "Baraka Plast MChJ", 'raqam' => "E-26/0067",
         'masul' => $YU['yus'], 'holat' => 'jarayonda', 'rol' => 'dav', 'tur' => 'Iqtisodiy ish', 'inst' => 'apellyatsiya', 'summa' => 94500000,
         'extra' => "Birinchi instansiya da'voni qisman qanoatlantirgan; biz shikoyat berdik (bahs summasi 94,5 mln so'm).",
         'mj' => [
            [-60, '10:00', "Apellyatsiya shikoyatini qabul qilish", "Ish apellyatsiya tartibida ko'rishga qabul qilindi", null],
            [-21, '15:00', "Apellyatsiya instansiyasida ko'rish", "Keyinga qoldirildi", "Qo'shimcha dalillar taqdim etish uchun muddat berildi."],
            [-5,  '15:00', "Qo'shimcha dalillarni tekshirish", "Keyinga qoldirildi", "Sud birinchi instansiya ish materiallarini talab qildi."],
            [6,   '11:00', "Apellyatsiya ko'rishning davomi", null, null]],
         'hj' => [['qaror', -112, null, ['nomi' => "Birinchi instansiya sudining qarori", 'izoh' => "Apellyatsiya shikoyati shu qarorga qaratilgan."]], ['shikoyat', -76, null, ['nomi' => "Apellyatsiya shikoyati"]], ['boj', -77], ['etiroz', -35, null, ['nomi' => "Javobgarning apellyatsiya e'tirozi"]]]],

        ['k' => 'A', 'mavzu' => "Tovar sifati bo'yicha da'voga javob", 'sud' => $I1, 'taraf' => "Texno-Don Ta'minot MChJ", 'raqam' => "E-26/0171",
         'masul' => $YU['sob'], 'holat' => 'jarayonda', 'rol' => 'jav', 'tur' => 'Iqtisodiy ish', 'inst' => '1-instansiya', 'summa' => 138000000,
         'extra' => "Yetkazilgan mahsulot sifatsizligi da'vo qilinmoqda; sud ekspertiza tayinlagan.",
         'mj' => [
            [-48, '10:00', "Ishni dastlabki ko'rish", "Ekspertiza tayinlandi", "Ekspertiza o'tkazish muddati 30 kun belgilandi."],
            [-6,  '11:00', "Ekspertiza natijalari bo'yicha", null, null],
            [11,  '10:30', "Ekspertiza xulosasini muhokama qilish", null, null]],
         'hj' => [['qdavo', -64], ['javob', -50], ['vakolat', -65], ['dalil', -49, null, ['nomi' => "Sifat sertifikatlari va qabul dalolatnomalari"]], ['ekspert', -9]]],

        ['k' => 'A', 'mavzu' => "Muddat buzilgani: neustoyka undirish", 'sud' => $I1, 'taraf' => "Mehnat-Servis MChJ", 'raqam' => "E-25/0911",
         'masul' => $YU['sob'], 'holat' => 'yutildi', 'rol' => 'dav', 'tur' => 'Iqtisodiy ish', 'inst' => '1-instansiya', 'summa' => 63400000,
         'extra' => "Da'vo to'liq qanoatlantirilgan. Ijro varaqasi berilgan, ijro ishlari olib borilmoqda (21 mln so'm undirilgan).",
         'mj' => [
            [-212, '10:00', "Ishni dastlabki ko'rish", "Keyinga qoldirildi", null],
            [-170, '11:00', "Ishni mohiyati bo'yicha ko'rish", "Da'vo qanoatlantirildi", "Neustoyka 63,4 mln so'm undirilishi to'g'risida qaror chiqarildi."]],
         'hj' => [['davo', -232], ['boj', -233], ['qaror', null, 1], ['ijro', -140], ['yozish', -96, null, ['nomi' => "Ijrochiga ariza (ijro ishini ochish)", 'izoh' => "Ijro jarayoni nazorati."]]]],

        ['k' => 'A', 'mavzu' => "Ma'muriy jarimani bekor qilish", 'sud' => $MS, 'taraf' => "Davlat soliq xizmati tuman boshqarmasi", 'raqam' => "M-26/0021",
         'masul' => $YU['kar'], 'holat' => 'yutildi', 'rol' => 'dav', 'tur' => "Ma'muriy ish", 'inst' => '1-instansiya', 'summa' => 18900000,
         'extra' => "Jarima qarori sud tomonidan bekor qilingan; qaror qonuniy kuchga kirgan.",
         'mj' => [
            [-160, '10:00', "Ishni dastlabki ko'rish", "Keyinga qoldirildi", "Ma'muriy organdan ish materiallari talab qilindi."],
            [-128, '10:30', "Ishni mohiyati bo'yicha ko'rish", "Da'vo qanoatlantirildi", "Jarima to'g'risidagi qaror bekor qilindi."]],
         'hj' => [['davo', -176], ['boj', -177], ['vakolat', -176], ['qaror', null, 1]]],

        ['k' => 'A', 'mavzu' => "Sobiq xodim bilan mehnat nizosi", 'sud' => $FS, 'taraf' => "Tursunov Dilshod Akmalovich", 'raqam' => "F-26/0204",
         'masul' => $YU['rah'], 'holat' => 'jarayonda', 'rol' => 'jav', 'tur' => 'Fuqarolik ish', 'inst' => '1-instansiya', 'summa' => 27800000,
         'extra' => "Sobiq xodim majburiy progul uchun to'lov talab qilmoqda. Guvohlar so'roqqa chaqirilgan.",
         'mj' => [
            [-35, '09:30', "Ishni dastlabki ko'rish", "Keyinga qoldirildi", "Da'vogar qo'shimcha hujjatlar taqdim etdi."],
            [-17, '09:30', "Taraflarning yarashuvi bo'yicha muzokara", "Keyinga qoldirildi", "Taraflar yarashmadi; sud guvohlarni chaqirishni tayinladi."],
            [1,   '09:30', "Guvohlarni so'roq qilish", null, null],
            [30,  '10:00', "Ishni mohiyati bo'yicha hal qilish", null, null]],
         'hj' => [['qdavo', -52], ['javob', -34], ['dalil', -33, null, ['nomi' => "Mehnat shartnomasi va buyruqlar nusxalari"]], ['vakolat', -36]]],

        ['k' => 'A', 'mavzu' => "Bojxona to'lovlarini qaytarish", 'sud' => $MS, 'taraf' => "Bojxona boshqarmasi (hududiy)", 'raqam' => "M-26/0017",
         'masul' => $YU['yus'], 'holat' => 'yutqazildi', 'rol' => 'dav', 'tur' => "Ma'muriy ish", 'inst' => '1-instansiya', 'summa' => 52100000,
         'extra' => "Da'vo rad etilgan. Kassatsiya shikoyati muddati ichida - shikoyat loyihasi tayyorlanmoqda.",
         'mj' => [
            [-190, '10:00', "Ishni dastlabki ko'rish", "Keyinga qoldirildi", null],
            [-105, '11:00', "Ishni mohiyati bo'yicha ko'rish", "Da'vo rad etildi", "Sud bojxona organining qarorini qonuniy deb topdi."]],
         'hj' => [['davo', -204], ['qaror', null, 1], ['shikoyat', -12, null, ['nomi' => "Kassatsiya shikoyati (loyiha)", 'izoh' => "Imzolashga tayyorlanmoqda."]]]],

        ['k' => 'A', 'mavzu' => "Kafil qarzini undirish", 'sud' => $FS, 'taraf' => "Ergashev Farrux Karimovich", 'raqam' => "F-26/0188",
         'masul' => $YU['yus'], 'holat' => 'jarayonda', 'rol' => 'dav', 'tur' => 'Fuqarolik ish', 'inst' => '1-instansiya', 'summa' => 76300000,
         'extra' => "Kafillik shartnomasi bo'yicha. Javobgar manzili bo'yicha sud xabarnomasi qaytgan, qayta chaqirilgan.",
         'mj' => [
            [-44, '14:00', "Ishni dastlabki ko'rish", "Keyinga qoldirildi", "Javobgarga sud xabarnomasi yetib bormadi, qayta chaqirildi."],
            [-16, '14:00', "Javobgarni qayta chaqirish", "Keyinga qoldirildi", "Javobgar yana kelmadi; sud uni e'lon orqali chaqirishni tayinladi."],
            [1,   '14:00', "Ishni ko'rish", null, null],
            [38,  '10:00', "Ishni mohiyati bo'yicha hal qilish", null, null]],
         'hj' => [['davo', -64], ['boj', -65], ['dalil', -64, null, ['nomi' => "Kafillik shartnomasi nusxasi"]], ['yozish', -41, null, ['nomi' => "Sud xabarnomasi (qaytgan konvert)"]]]],

        ['k' => 'A', 'mavzu' => "Transport xizmati qarzi", 'sud' => $I1, 'taraf' => "Yo'l-Tranzit Logistik MChJ", 'raqam' => "E-26/0120",
         'masul' => $YU['kar'], 'holat' => 'toxtatildi', 'rol' => 'dav', 'tur' => 'Iqtisodiy ish', 'inst' => '1-instansiya', 'summa' => 158200000,
         'extra' => "Javobgar bankrotlik jarayoniga kirgani sababli ish to'xtatilgan; kreditor talabini reyestrga kiritish kutilmoqda.",
         'mj' => [
            [-172, '10:00', "Ishni dastlabki ko'rish", "Keyinga qoldirildi", null],
            [-95,  '11:00', "Ishni ko'rish", "Ish to'xtatildi", "Javobgar bankrot deb e'lon qilinishi munosabati bilan ish to'xtatildi."]],
         'hj' => [['davo', -192], ['ajrim', null, 1, ['nomi' => "Ish to'xtatilishi to'g'risida ajrim"]], ['yozish', -60, null, ['nomi' => "Bankrotlik boshqaruvchisiga kreditor talabi", 'izoh' => "Talab reyestrga kiritish uchun yuborilgan."]]]],

        ['k' => 'A', 'mavzu' => "Shartnomani bekor qilish talabiga javob", 'sud' => $I1, 'taraf' => "Vodiy Metall Trade MChJ", 'raqam' => "E-26/0186",
         'masul' => $YU['sob'], 'holat' => 'jarayonda', 'rol' => 'jav', 'tur' => 'Iqtisodiy ish', 'inst' => '1-instansiya', 'summa' => 340000000,
         'extra' => "Da'vogar shartnomani bekor qilish va zararni qoplashni talab qilmoqda; shartnoma ijrosi dalillari yig'ilmoqda.",
         'mj' => [
            [-27, '15:00', "Ishni dastlabki ko'rish", "Dastlabki ko'rish o'tkazildi", "Ish mohiyati bo'yicha ko'rishga tayinlandi."],
            [8,   '15:00', "Ishni mohiyati bo'yicha ko'rish", null, null],
            [14,  '15:00', "Guvohlarni so'roq qilish", null, null],
            [45,  '10:30', "Sud munozaralari", null, null]],
         'hj' => [['qdavo', -40], ['javob', -28], ['dalil', -27, null, ['nomi' => "Shartnoma ijrosi bo'yicha hujjatlar"]], ['vakolat', -30]]],

        ['k' => 'A', 'mavzu' => "Garov ta'minoti (kassatsiya)", 'sud' => $IK, 'taraf' => "Tijorat banki filiali", 'raqam' => "E-26/0155",
         'masul' => $YU['kar'], 'holat' => 'jarayonda', 'rol' => 'dav', 'tur' => 'Iqtisodiy ish', 'inst' => 'kassatsiya', 'summa' => 520000000,
         'extra' => "Apellyatsiya qaroriga qarshi kassatsiya shikoyati berilgan; garov mulki bahosi 520 mln so'm.",
         'mj' => [
            [-80, '10:00', "Kassatsiya shikoyatini qabul qilish", "Shikoyat ko'rishga qabul qilindi", null],
            [9,   '10:00', "Kassatsiya: ish materiallarini tayyorlash majlisi", null, null],
            [21,  '11:00', "Kassatsiya instansiyasida ko'rish", null, null]],
         'hj' => [['qaror', -118, null, ['nomi' => "Apellyatsiya instansiyasining qarori"]], ['shikoyat', -86, null, ['nomi' => "Kassatsiya shikoyati"]], ['boj', -87]]],

        /* ======================= B: Orzu Qurilish Servis ======================= */
        ['k' => 'B', 'mavzu' => "Pudrat ishlari qarzini undirish", 'sud' => $I1, 'taraf' => "Yangi Uy Invest MChJ", 'raqam' => "E-26/0133",
         'masul' => $YU['rah'], 'holat' => 'jarayonda', 'rol' => 'dav', 'tur' => 'Iqtisodiy ish', 'inst' => '1-instansiya', 'summa' => 1240000000,
         'extra' => "Bajarilgan va qabul qilingan qurilish-montaj ishlari uchun to'lanmagan qoldiq. Eng yirik ish.",
         'mj' => [
            [-141, '10:00', "Ishni dastlabki ko'rish", "Dastlabki ko'rish o'tkazildi", "Javobgarga javob xati berish muddati belgilandi."],
            [-58,  '11:00', "Ishni mohiyati bo'yicha ko'rish", "Keyinga qoldirildi", "Javobgar bajarilgan ishlar hajmiga e'tiroz bildirdi; qurilish ekspertizasi so'raldi."],
            [-3,   '10:30', "Ekspertiza tayinlash masalasi", null, null],
            [9,    '10:30', "Ishni ko'rishning davomi", null, null]],
         'hj' => [['pretenz', -162], ['davo', -143], ['ilova', -143, null, ['nomi' => "Bajarilgan ishlar dalolatnomalari"]], ['hisob', -143], ['boj', -144], ['vakolat', -142], ['etiroz', -75], ['bayon', null, 1]]],

        ['k' => 'B', 'mavzu' => "Nuqsonli beton uchun zararni undirish", 'sud' => $I1, 'taraf' => "Beton-Master Zavodi MChJ", 'raqam' => "E-26/0179",
         'masul' => $YU['sob'], 'holat' => 'jarayonda', 'rol' => 'dav', 'tur' => 'Iqtisodiy ish', 'inst' => '1-instansiya', 'summa' => 168000000,
         'extra' => "Yetkazilgan beton markasi shartnomaga mos kelmagan; sinov laboratoriyasi xulosasi dalil sifatida taqdim etilgan.",
         'mj' => [
            [-36, '10:00', "Ishni dastlabki ko'rish", "Keyinga qoldirildi", "Javobgar laboratoriya xulosasiga e'tiroz bildirdi."],
            [-10, '11:30', "Laboratoriya xulosasini ko'rib chiqish", "Ekspertiza tayinlandi", "Sud mustaqil ekspertiza tayinladi."],
            [4,   '11:30', "Ekspertiza bo'yicha tushuntirishlar", null, null]],
         'hj' => [['davo', -57], ['ilova', -57], ['boj', -58], ['dalil', -50, null, ['nomi' => "Sinov laboratoriyasi xulosasi"]], ['bayon', null, 1]]],

        ['k' => 'B', 'mavzu' => "Subpudratchi bilan hisob-kitob nizosi", 'sud' => $I1, 'taraf' => "Mustahkam Qurilish Montaj MChJ", 'raqam' => "E-25/0877",
         'masul' => $YU['kar'], 'holat' => 'kelishuv', 'rol' => 'jav', 'tur' => 'Iqtisodiy ish', 'inst' => '1-instansiya', 'summa' => 305000000,
         'extra' => "Kelishuv bitimi tuzilgan: 305 mln o'rniga 270 mln so'm to'lanadi (3 bosqichda).",
         'mj' => [
            [-205, '10:00', "Ishni dastlabki ko'rish", "Keyinga qoldirildi", null],
            [-134, '14:30', "Taraflarning yarashuvi", "Kelishuv tuzildi", "Kelishuv bitimi tasdiqlandi, ish tugatildi."],
            [24,   '10:00', "Kelishuv ijrosi bo'yicha holatni tekshirish", null, null]],
         'hj' => [['qdavo', -225], ['javob', -206], ['kelishuv', -136], ['ajrim', null, 1, ['nomi' => "Kelishuv bitimini tasdiqlash to'g'risida ajrim"]]]],

        ['k' => 'B', 'mavzu' => "Kafolat ushlanmasini qaytarish", 'sud' => $I1, 'taraf' => "Hudud Obod Qurilish MChJ", 'raqam' => "E-26/0059",
         'masul' => $YU['rah'], 'holat' => 'yutildi', 'rol' => 'dav', 'tur' => 'Iqtisodiy ish', 'inst' => '1-instansiya', 'summa' => 355000000,
         'extra' => "Kafolat muddati o'tgan; da'vo qanoatlantirilgan. Ijro varaqasi bo'yicha 355 mln so'mning 190 mln qismi undirilgan.",
         'mj' => [
            [-250, '10:00', "Ishni dastlabki ko'rish", "Keyinga qoldirildi", null],
            [-195, '11:00', "Ishni mohiyati bo'yicha ko'rish", "Da'vo qanoatlantirildi", "Ushlab qolingan kafolat summasi to'liq undirilishi to'g'risida qaror."]],
         'hj' => [['davo', -270], ['ilova', -270], ['boj', -271], ['qaror', null, 1], ['ijro', -170]]],

        ['k' => 'B', 'mavzu' => "Qurilish ruxsatnomasini tiklash", 'sud' => $MS, 'taraf' => "Shahar qurilish nazorati boshqarmasi", 'raqam' => "M-26/0033",
         'masul' => $YU['yus'], 'holat' => 'jarayonda', 'rol' => 'dav', 'tur' => "Ma'muriy ish", 'inst' => '1-instansiya', 'summa' => 0,
         'extra' => "Ma'muriy organ ruxsatnomani bekor qilgan; obyektda ishlar to'xtab turibdi (obyekt qiymati 2,1 mlrd so'm).",
         'mj' => [
            [-22, '10:00', "Ishni dastlabki ko'rish", "Keyinga qoldirildi", "Ma'muriy organdan ruxsatnoma materiallari talab qilindi."],
            [7,   '10:30', "Ishni mohiyati bo'yicha ko'rish", null, null],
            [29,  '10:00', "Sud munozaralari", null, null]],
         'hj' => [['davo', -40], ['dalil', -39, null, ['nomi' => "Ruxsatnoma va loyiha hujjatlari nusxalari"]], ['vakolat', -41]]],

        ['k' => 'B', 'mavzu' => "Yer uchastkasi chegarasi nizosi", 'sud' => $FS, 'taraf' => "Olimov Nodir Sherzodovich", 'raqam' => "F-26/0140",
         'masul' => $YU['rah'], 'holat' => 'yutqazildi', 'rol' => 'dav', 'tur' => 'Fuqarolik ish', 'inst' => '1-instansiya', 'summa' => 0,
         'extra' => "Da'vo rad etilgan, qaror qonuniy kuchga kirgan; shikoyat berilmaydi. Chegarani kadastr orqali aniqlashtirish rejalashtirilgan.",
         'mj' => [
            [-175, '09:30', "Ishni dastlabki ko'rish", "Keyinga qoldirildi", null],
            [-120, '09:30', "Ishni mohiyati bo'yicha ko'rish", "Da'vo rad etildi", "Kadastr hujjatlari da'vo talabini tasdiqlamadi."]],
         'hj' => [['davo', -190], ['qaror', null, 1]]],

        ['k' => 'B', 'mavzu' => "Ish haqi qarzi: jamoaviy da'vo", 'sud' => $FS, 'taraf' => "Ismoilov Umid va boshqalar (3 kishi)", 'raqam' => "F-26/0215",
         'masul' => $YU['kar'], 'holat' => 'jarayonda', 'rol' => 'jav', 'tur' => 'Fuqarolik ish', 'inst' => '1-instansiya', 'summa' => 41600000,
         'extra' => "Mavsumiy ishchilar ish haqi kechiktirilganini da'vo qilmoqda. Qisman to'lov to'langan, qoldiq bo'yicha kelishuv ko'rilmoqda.",
         'mj' => [
            [-18, '14:00', "Ishni dastlabki ko'rish", "Keyinga qoldirildi", "Taraflar kelishuv imkoniyatini muhokama qilish uchun muddat so'radi."],
            [12,  '14:00', "Ishni ko'rishning davomi", null, null]],
         'hj' => [['qdavo', -30], ['javob', -19], ['dalil', -19, null, ['nomi' => "Ish haqi ro'yxatlari va to'lov topshiriqlari"]]]],

        ['k' => 'B', 'mavzu' => "Qurilish xavfi sug'urtasi tovoni", 'sud' => $I1, 'taraf' => "Sug'urta Plyus SK AJ", 'raqam' => "E-26/0192",
         'masul' => $YU['sob'], 'holat' => 'jarayonda', 'rol' => 'dav', 'tur' => 'Iqtisodiy ish', 'inst' => '1-instansiya', 'summa' => 210000000,
         'extra' => "Qurilish maydonchasida sodir bo'lgan hodisa bo'yicha sug'urta tovoni to'lanmagan.",
         'mj' => [
            [-70, '11:00', "Ishni dastlabki ko'rish", "Dastlabki ko'rish o'tkazildi", null],
            [-20, '11:00', "Ishni mohiyati bo'yicha ko'rish", "Keyinga qoldirildi", "Sug'urta kompaniyasi hodisa dalolatnomasini qo'shimcha so'radi."],
            [18,  '11:00', "Ishni ko'rishning davomi", null, null]],
         'hj' => [['pretenz', -95], ['davo', -85], ['ilova', -85, null, ['nomi' => "Sug'urta polisi va hodisa dalolatnomasi"]], ['boj', -86], ['bayon', null, 1]]],

        /* ======================= C: Safo Agro Plyus ======================= */
        ['k' => 'C', 'mavzu' => "Yetkazilmagan xom ashyo avansi", 'sud' => $I1, 'taraf' => "Navbahor Fermer Xo'jaligi", 'raqam' => "E-26/0165",
         'masul' => $YU['yus'], 'holat' => 'jarayonda', 'rol' => 'dav', 'tur' => 'Iqtisodiy ish', 'inst' => '1-instansiya', 'summa' => 96800000,
         'extra' => "To'langan avans bo'yicha xom ashyo yetkazilmagan. Javobgar yetkazib berish mumkin emasligini bildirgan.",
         'mj' => [
            [-63, '10:00', "Ishni dastlabki ko'rish", "Dastlabki ko'rish o'tkazildi", null],
            [-11, '10:30', "Ishni mohiyati bo'yicha ko'rish", null, null],
            [6,   '10:30', "Ishni ko'rishning davomi", null, null]],
         'hj' => [['pretenz', -85], ['davo', -66], ['ilova', -66], ['boj', -67], ['vakolat', -66]]],

        ['k' => 'C', 'mavzu' => "Sut ta'minoti jarimasiga javob", 'sud' => $I1, 'taraf' => "Ishtirok Market MChJ", 'raqam' => "E-26/0182",
         'masul' => $YU['rah'], 'holat' => 'jarayonda', 'rol' => 'jav', 'tur' => 'Iqtisodiy ish', 'inst' => '1-instansiya', 'summa' => 58300000,
         'extra' => "Savdo tarmog'i yetkazib berish kechikkani uchun jarima talab qilmoqda; muddatlar qisman force-major tufayli buzilgan.",
         'mj' => [
            [-28, '15:00', "Ishni dastlabki ko'rish", "Keyinga qoldirildi", "Javobgar force-major hujjatlarini taqdim etdi."],
            [14,  '15:00', "Ishni mohiyati bo'yicha ko'rish", null, null]],
         'hj' => [['qdavo', -44], ['javob', -29], ['dalil', -29, null, ['nomi' => "Force-major tasdiqlovchi hujjatlar"]]]],

        ['k' => 'C', 'mavzu' => "Yer ijarasi qarzini undirish", 'sud' => $FS, 'taraf' => "Qodirov Anvar Ismoilovich", 'raqam' => "F-26/0099",
         'masul' => $YU['kar'], 'holat' => 'yutildi', 'rol' => 'dav', 'tur' => 'Fuqarolik ish', 'inst' => '1-instansiya', 'summa' => 32700000,
         'extra' => "Da'vo to'liq qanoatlantirilgan; qaror ixtiyoriy ijro qilingan, summa to'liq tushgan.",
         'mj' => [
            [-140, '10:00', "Ishni ko'rish", "Da'vo qanoatlantirildi", "Ijara qarzi va penya undirilishi to'g'risida qaror."]],
         'hj' => [['davo', -160], ['boj', -161], ['qaror', null, 0]]],

        ['k' => 'C', 'mavzu' => "Ekologiya jarimasini bekor qilish", 'sud' => $MS, 'taraf' => "Hududiy ekologiya inspeksiyasi", 'raqam' => "M-26/0028",
         'masul' => $YU['yus'], 'holat' => 'jarayonda', 'rol' => 'dav', 'tur' => "Ma'muriy ish", 'inst' => '1-instansiya', 'summa' => 12600000,
         'extra' => "Oqava suv me'yorlari bo'yicha qo'yilgan jarima shikoyat qilinmoqda.",
         'mj' => [
            [-14, '10:00', "Ishni dastlabki ko'rish", "Keyinga qoldirildi", "Inspeksiyadan nazorat materiallari talab qilindi."],
            [16,  '10:00', "Ishni mohiyati bo'yicha ko'rish", null, null]],
         'hj' => [['davo', -30], ['boj', -31], ['dalil', -30, null, ['nomi' => "Laboratoriya tahlillari (oqava suv)"]]]],

        /* ======================= Umumiy (korxonasiz) ======================= */
        ['k' => 'U', 'mavzu' => "Bosh ofis ijarasi nizosi", 'sud' => $FS, 'taraf' => "Mulkdor Trest MChJ", 'raqam' => "F-26/0211",
         'masul' => $YU['sob'], 'holat' => 'jarayonda', 'rol' => 'jav', 'tur' => 'Iqtisodiy ish', 'inst' => '1-instansiya', 'summa' => 73000000,
         'extra' => "Guruh darajasidagi ish (barcha korxonalarga ko'rinadi). Ijara stavkasini bir tomonlama oshirish bo'yicha nizo.",
         'mj' => [
            [-15, '14:00', "Ishni dastlabki ko'rish", "Keyinga qoldirildi", "Taraflar shartnoma matni bo'yicha qo'shimcha tushuntirish berdi."],
            [12,  '14:00', "Ishni mohiyati bo'yicha ko'rish", null, null]],
         'hj' => [['qdavo', -33], ['javob', -16], ['dalil', -16, null, ['nomi' => "Ijara shartnomasi va qo'shimcha kelishuvlar"]]]],

        ['k' => 'U', 'mavzu' => "Dasturiy ta'minot litsenziyasi nizosi", 'sud' => $I1, 'taraf' => "Kod-Servis MChJ", 'raqam' => "E-25/0760",
         'masul' => $YU['kar'], 'holat' => 'yutildi', 'rol' => 'dav', 'tur' => 'Iqtisodiy ish', 'inst' => '1-instansiya', 'summa' => 45000000,
         'extra' => "Guruh darajasidagi ish. Da'vo qanoatlantirilgan, kompensatsiya undirilgan; ish yopilgan.",
         'mj' => [
            [-260, '11:00', "Ishni dastlabki ko'rish", "Keyinga qoldirildi", null],
            [-205, '11:00', "Ishni mohiyati bo'yicha ko'rish", "Da'vo qanoatlantirildi", "Kompensatsiya 45 mln so'm undirilishi to'g'risida qaror."]],
         'hj' => [['davo', -280], ['boj', -281], ['qaror', null, 1]]],
    ];

    $bugunIso = date('Y-m-d');
    $n = 0;
    foreach ($ISHLAR as $isx) {
        $n++;
        $ishId = yur_id('sud', 'ish' . $n);
        $kid = $isx['k'] === 'U' ? '' : $KX[$isx['k']]['id'];
        $c = ['sud' => $isx['sud'], 'raqam' => $isx['raqam'], 'taraf' => $isx['taraf'], 'mavzu' => $isx['mavzu'], 'rol' => $isx['rol'],
              'masul' => $isx['masul'], 'summa' => (int)$isx['summa'], 'kx' => $kxNom($isx['k'])];

        /* majlislar */
        $majlislar = [];
        $mjKun = [];
        foreach ($isx['mj'] as $j => [$kun, $vaqt, $izoh, $natija, $natijaIzoh]) {
            $sana = yur_kun($kun);
            $mjKun[$j] = $sana;
            $m = ['id' => yur_id('mj', "ish$n-mj$j"), 'sana' => $sana, 'vaqt' => $vaqt, 'izoh' => $izoh, 'natija' => ''];
            if ($natija !== null && $sana < $bugunIso) $m['natija'] = $natija;
            if ($natijaIzoh !== null && $sana < $bugunIso) $m['natijaIzoh'] = $natijaIzoh;
            $majlislar[$j] = $m;
        }

        /* hujjatlar */
        $hujjatlar = [];
        $hk = 0;
        foreach ($isx['hj'] as $h) {
            $kod = $h[0];
            $kun = $h[1] ?? null;
            $mj = $h[2] ?? null;
            $ov = $h[3] ?? [];
            [$tur, $nom0, $fayl0, $ext, $izoh0] = YUR_HJ[$kod];
            $sana = $kun !== null ? yur_kun($kun) : ($mj !== null ? $mjKun[$mj] : yur_kun(-10));
            if ($kod === 'davo' && $isx['rol'] === 'jav') { $tur = 'davo'; $nom0 = YUR_HJ['qdavo'][1]; $fayl0 = YUR_HJ['qdavo'][2]; }
            $nomi = $ov['nomi'] ?? $nom0;
            if ($kod === 'bayon' && $mj !== null) $nomi .= ' - ' . yur_dmy($sana);
            $izoh = $ov['izoh'] ?? $izoh0;
            $cc = $c;
            if ($mj !== null) {
                $cc['_mj'] = $majlislar[$mj]['izoh'];
                $nat = trim(($majlislar[$mj]['natija'] ?? '') . (($majlislar[$mj]['natijaIzoh'] ?? '') !== '' ? '. ' . $majlislar[$mj]['natijaIzoh'] : ''));
                $cc['_natija'] = $nat !== '' ? $nat : 'qayd etildi';
            }
            [$sar, $abz] = yur_matn($kod, $cc, $nomi, $sana);
            $baza = trim((string)preg_replace('/[^A-Za-z0-9]+/', '_', str_replace("'", '', yur_ascii($nomi))), '_');
            $baza = substr($baza, 0, 70) . '_' . str_replace('/', '-', $isx['raqam']);
            $fname = $baza . '.' . $ext;
            $bayt = $ext === 'docx' ? yur_docx($sar, $abz) : yur_pdf($sar, $abz);
            $fayl = yur_fayl($ishId, $fname, $bayt, $kim, $sana);
            $hujjatlar[] = [
                'id' => yur_id('hj', "ish$n-hj" . $hk++), 'nomi' => $nomi, 'tur' => $tur, 'sana' => $sana, 'izoh' => $izoh,
                'majlis' => $mj !== null ? $majlislar[$mj]['id'] : '', 'fayllar' => [$fayl], 'kim' => $isx['masul'], 'ts' => $sana . 'T09:30:00Z',
            ];
        }
        usort($hujjatlar, fn($a, $b) => strcmp($b['sana'], $a['sana']));

        $summaMatn = $isx['summa'] > 0 ? "Da'vo summasi: " . yur_som($isx['summa']) . " so'm." : "Mulkiy talab yo'q.";
        $izoh = ($isx['rol'] === 'dav' ? "Da'vogar - biz. " : "Javobgar - biz. ") . $isx['tur'] . ', ' . $isx['inst'] . '. ' . $summaMatn . ' ' . $isx['extra'];
        bw_put('sud', $ishId, [
            'id' => $ishId, 'mavzu' => $isx['mavzu'], 'sud' => $isx['sud'], 'taraf' => $isx['taraf'], 'ishRaqami' => $isx['raqam'],
            'korxonaId' => $kid, 'masul' => $isx['masul'], 'holat' => $isx['holat'], 'izoh' => $izoh,
            'majlislar' => array_values($majlislar), 'hujjatlar' => $hujjatlar,
        ]);
    }
}
