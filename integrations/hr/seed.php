<?php
declare(strict_types=1);
/**
 * Biwork HR namuna ma'lumoti — butunlay XAYOLIY: "Zamin Plast MChJ" (plastik quvur va qadoq ishlab chiqaruvchi) va uning xodimlari.
 *
 * Nima yoziladi:
 *  1. korxona (ichki ish vaqti — QR + GPS + selfi yoqilgan, 3 ish joyi, ish jadvali, bayram kalendari);
 *  2. xodim kartalari (kontakt) + ularga ochiladigan tizim hisoblari (telefon = login; kutubxonadagi "tanishuv" shularga bog'liq);
 *  3. davomat: oxirgi ~3 oy (kechikishlar, sabablar, ta'til, kasallik, safar, masofadan ishlash) + QR belgilar jurnali va selfilar;
 *  4. tabel tuzatishlari va imzolar;
 *  5. hujjatlar kutubxonasi: PDF hujjatlar (PM ning o'z PDF yozuvchisi), versiyalar, xodimlarning tanishish holati.
 *
 * Eslatma ("bosh korxona" mantig'i): PM da ishvaqt.uz manbasi NOM bo'yicha ("avtosanoat invest") bitta korxonaga bog'langan
 * (boshKorxonami / bosh_korxona_id). Bu yerda u ishlatilmaydi: korxonada `davomatIchki` bayrog'i yoqiladi — ichki manba
 * (davomatYoqiqmi / davomat_manba) shu bayroqqa qaraydi; tashqi tarmoqqa chiqish YO'Q (ishvaqt tokeni bo'sh).
 */
require_once __DIR__ . '/seed/xodimlar.php';
require_once __DIR__ . '/seed/hujjatlar.php';

const HR_KID = 'k_zamin01';

function hr_gauss(): float {
    $u = (mt_rand(1, 10000) / 10001.0); $v = (mt_rand(1, 10000) / 10001.0);
    return sqrt(-2.0 * log($u)) * cos(2.0 * M_PI * $v);
}
function hr_vaqt(int $daq): string { return sprintf('%02d:%02d', intdiv($daq, 60), $daq % 60); }
function hr_hex8(string $s): string { return substr(md5('zamin|' . $s), 0, 8); }

/**
 * PM ning PDF yozuvchisi shrift resursini "ASIFont" deb nomlaydi (faylda ko'rinmaydi, lekin metama'lumotda qoladi):
 * bir xil uzunlikdagi neytral nom bilan almashtiriladi (xref ofsetlari buzilmaydi).
 */
function hr_pdf_tozala(string $b): string
{
    return str_replace('ASIFont', 'ZmnFont', $b);
}

function biwork_seed(array $ctx): void
{
    $pm = (string)$ctx['pm'];
    $out = (string)$ctx['out'];
    $assets = __DIR__ . '/seed-assets';
    bw_seed_rng(20261020);
    foreach (['ishvaqt', 'davomat-ichki', 'tabel', 'kutubxona', 'pdf'] as $f) require_once "$pm/php/$f.php";
    $pdo = db();
    $bugun = bw_date(0);
    $yil = (int)date('Y');

    /* ============================ 1. KORXONA ============================ */
    $bolimlar = [HR_B_BOSH, HR_B_KADR, HR_B_BUX, HR_B_SAVDO, HR_B_ISH, HR_B_OMB];
    sort($bolimlar);
    @mkdir("$out/brend", 0775, true);
    copy("$assets/zamin-logo.png", "$out/brend/" . HR_KID . '.png');
    bw_put('korxona', HR_KID, [
        'id' => HR_KID, 'tartib' => 1, 'logoV' => 1,
        'nomi' => 'Zamin Plast',
        'rasmiyNomi' => '"ZAMIN PLAST" MChJ',
        'tavsif' => "Plastik quvur va qadoq mahsulotlari ishlab chiqarish",
        'rahbar' => 'Rustamov Alisher Bahodirovich',
        'rahbarLavozim' => 'Bosh direktori',
        'stir' => '305482197',
        'yuridikManzil' => "Toshkent viloyati, Zangiota tumani, Sanoat hududi, 7-uy",
        'telefon' => '+998 71 200 45 10',
        'email' => 'info@zaminplast.example',
        'bank' => "h/r 20208000900123456001, MFO 00987, «Kapital Invest Bank» Chilonzor filiali",
        'kassaYoq' => true, 'qoldiqYuritish' => false, 'biznesAktiv' => false, 'savdoAktiv' => false,
        'davomatIchki' => true,
        'bolimlar' => $bolimlar,
        'joylashuv' => ['viloyat' => 'UZ-TO', 'manzil' => "Zangiota tumani, Sanoat hududi", 'lat' => 41.2631, 'lng' => 69.1587],
        'cashflow' => ['openingBalances' => ['kassa' => 0, 'bank' => 0], 'transactions' => []],
    ]);

    /* ============================ 2. XODIMLAR ============================ */
    $op = ['90', '91', '93', '94', '95', '97', '98', '99', '33', '88'];
    $kocha = ["Chilonzor tumani, 9-kvartal", "Yunusobod tumani, 4-mavze", "Mirzo Ulug'bek tumani, Qorasaroy ko'chasi", "Sergeli tumani, 6-mavze", "Yakkasaroy tumani, Shota Rustaveli ko'chasi", "Zangiota tumani, Qo'shko'prik", "Olmazor tumani, Beruniy ko'chasi", "Chirchiq shahri, Navro'z mavzesi"];
    $royxat = hr_xodimlar_royxati();
    $xod = [];
    $idByFam = [];
    $telGen = fn() => '+998 ' . $op[mt_rand(0, count($op) - 1)] . ' ' . mt_rand(100, 999) . ' ' . sprintf('%02d %02d', mt_rand(10, 99), mt_rand(10, 99));
    foreach ($royxat as $i => $r) {
        [$fam, $ism, $sh, $bolim, $lavozim, $ishS, $tug, $oylik, $x] = $r;
        $cid = 'c_' . hr_hex8('xodim' . $i);
        $ishSana = is_array($ishS) ? hr_kun((int)$ishS['kun']) : $ishS;
        $tel = $telGen();
        $login = preg_replace('/\D+/', '', $tel);
        $toliq = $fam . ' ' . $ism . ' ' . $sh;
        $ofis = !empty($x['ofis']);
        $karta = [
            'id' => $cid, 'korxonaId' => HR_KID,
            'familiya' => $fam, 'ismi' => $ism, 'sharifi' => $sh, 'ism' => $toliq,
            'bolim' => $bolim, 'lavozim' => $lavozim,
            'tabelRaqam' => sprintf('%03d', $i + 1),
            'ichkiRaqam' => (string)($x['ip'] ?? ''),
            'tugilganKun' => $tug,
            'inn' => (string)mt_rand(300000000, 699999999),
            'telefon' => $tel,
            'shtatBirlik' => 1, 'oylik' => $oylik, 'ishSana' => $ishSana,
            'koef' => round(1.0 + mt_rand(0, 5) / 10, 1), 'premiyaTur' => 'ikkalasi',
            'email' => $ofis ? (strtolower(substr(preg_replace("/[^A-Za-z]/", '', $ism), 0, 1)) . '.' . strtolower(preg_replace("/[^A-Za-z]/", '', $fam)) . '@zaminplast.example') : '',
            'manzil' => mt_rand(0, 9) < 6 ? ($kocha[mt_rand(0, count($kocha) - 1)] . ', ' . mt_rand(1, 60) . '-uy') : '',
            'favqIsm' => mt_rand(0, 9) < 5 ? (bw_pick(["turmush o'rtog'i", 'otasi', 'onasi', 'akasi', 'singlisi']) . ' — ' . bw_pick(BW_ISM_ERKAK)) : '',
        ];
        $karta['favqTel'] = $karta['favqIsm'] !== '' ? $telGen() : '';
        if (isset($x['ketdi'])) $karta['ishdanSana'] = hr_kun((int)$x['ketdi']);
        $karta = array_filter($karta, fn($v) => $v !== '' && $v !== null);
        bw_put('kontakt', $cid, $karta);
        $xod[$cid] = ['ism' => $toliq, 'login' => $login, 'bolim' => $bolim, 'lavozim' => $lavozim, 'ishSana' => $ishSana,
            'ishdanSana' => $karta['ishdanSana'] ?? '', 'ofis' => $ofis, 'p' => $x, 'idx' => $i];
        $idByFam[$fam] = $cid;
    }
    // tug'ilgan kuni yaqin kishilar (bosh sahifadagi eslatma uchun)
    foreach ([['Aminova', 1999, 3], ['Sultonov', 1985, 6]] as [$fam, $y, $n]) {
        $c = entity_oq('kontakt', $idByFam[$fam]); unset($c['_rev']);
        $c['tugilganKun'] = hr_tugilgan_yaqin($y, $n);
        bw_put('kontakt', $idByFam[$fam], $c);
    }
    // tizim hisoblari (telefon = login, parol "1"): kutubxonadagi tanishuv ularga bog'lanadi
    $idlar = korxona_idlari();
    foreach ($xod as $cid => $x) kontakt_login_yarat(entity_oq('kontakt', $cid), 'namuna-admin', $idlar);
    $hrLogin = $xod[$idByFam['Yusupova']]['login'];

    /* ============================ 3. ISH VAQTI SOZLAMASI ============================ */
    $bayram = [$yil . '-09-01' => 'bayram', $yil . '-10-01' => 'bayram'];
    davomat_soz_yoz(HR_KID, [
        'jadval' => ['boshlanish' => '09:00', 'tugash' => '18:00', 'tushlik' => 60, 'tolerans' => 5, 'kunlar' => [1, 2, 3, 4, 5], 'engQisqa' => 15, 'kalendar' => $bayram],
        'radiusSukut' => 100, 'aniqlikMax' => 150, 'rasmKun' => 3,
    ], $hrLogin);
    $lokDefs = [
        'ofis' => ['nomi' => 'Bosh ofis', 'lat' => 41.26335, 'lng' => 69.15845, 'radius' => 80],
        'sex' => ['nomi' => 'Ishlab chiqarish sexi', 'lat' => 41.26142, 'lng' => 69.16052, 'radius' => 120],
        'ombor' => ['nomi' => 'Ombor va yuklash maydoni', 'lat' => 41.26078, 'lng' => 69.16210, 'radius' => 120],
    ];
    $lok = [];
    foreach ($lokDefs as $kk => $d) $lok[$kk] = davomat_lokatsiya_yoz(HR_KID, $d, $hrLogin);
    meta_set('davomat_domen', 'work.zaminplast.example');

    /* ============================ 4. DAVOMAT ============================ */
    $bosh = strtotime(date('Y-m-01', strtotime('-3 months')));
    $bugunTs = strtotime($bugun);
    $hozirDaq = (int)date('G') * 60 + (int)date('i');
    // kelmaslik davrlari: [familiya, necha kun oldin dan, gacha (manfiy = kelajak), tur]
    $davrlar = [
        ['Rahimova', 70, 57, "Mehnat ta'tili (otpusk)"], ['Sultonov', 52, 39, "Mehnat ta'tili (otpusk)"], ['Ismoilova', 31, 24, "Mehnat ta'tili (otpusk)"],
        ['Xolmatova', 78, 71, "Mehnat ta'tili (otpusk)"], ['Qosimov', 33, 28, 'Kasallik varaqasi'], ['Boboyeva', 11, 7, 'Kasallik varaqasi'],
        ['Hasanov', 2, 0, 'Kasallik varaqasi'], ['Sobirov', 21, 19, 'Xizmat safari'], ['Mahmudov', 7, 5, 'Xizmat safari'], ['Mahmudov', 45, 43, 'Xizmat safari'],
        ['Normatov', 9, 8, 'Xizmat safari'], ['Rustamov', 13, 12, 'Xizmat safari'], ['Ergashev', 27, 26, 'Xizmat safari'], ['Xolmatova', 4, 3, 'Xizmat safari'], ['Ibragimov', 14, 13, "O'z hisobidan ta'til (b/s)"], ['Mirzayeva', 36, 36, "O'z hisobidan ta'til (b/s)"],
        ['Valiyev', 3, 3, "Sog'liq (qisqa muddat)"], ['Davlatov', 49, 49, "Sog'liq (qisqa muddat)"], ['Yusupova', 25, 24, "O'quv / malaka oshirish"],
        ['Saidov', 41, 40, 'Masofadan ishlash'], ['Saidov', 20, 20, 'Masofadan ishlash'], ['Saidov', 6, 6, 'Masofadan ishlash'], ['Karimova', 14, 14, 'Masofadan ishlash'],
        ['Aminova', 27, 27, 'Masofadan ishlash'], ['Aminova', 13, 12, 'Masofadan ishlash'],
        // kelajakdagi (oldindan belgilangan) ta'til va safarlar
        ['Tursunova', -6, -19, "Mehnat ta'tili (otpusk)"], ['Abdullayev', -3, -4, 'Xizmat safari'], ['Egamberdiyev', -10, -23, "Mehnat ta'tili (otpusk)"],
    ];
    $sababKun = [];
    foreach ($davrlar as [$fam, $a, $b, $tur]) {
        for ($d = $a; $d >= $b; $d--) $sababKun[$idByFam[$fam]][bw_date($d)] = $tur;
    }
    $sababTurlar = ["Transport (yo'l tirbandligi)", 'Oilaviy sabab', 'Ob-havo', 'Rahbar ruxsati bilan', "Sog'liq (qisqa muddat)", 'Boshqa'];
    $sababVazn = [40, 20, 8, 12, 12, 8];
    $sababMatn = [
        "Transport (yo'l tirbandligi)" => ["Yo'lda tirbandlik bo'ldi", "Avtobus kechikdi", "Yo'lda avtohalokat tufayli tirbandlik"],
        'Oilaviy sabab' => ["Bolani bog'chaga olib bordi", "Oilaviy ishlar", "Farzandi kasal bo'lib qoldi"],
        'Ob-havo' => ["Kuchli yomg'ir, transport yo'q"],
        'Rahbar ruxsati bilan' => ["Rahbar bilan kelishilgan", "Ertalab bankka bordi (ruxsat bilan)"],
        "Sog'liq (qisqa muddat)" => ["Ertalab o'zini yomon his qildi", "Shifokorga bordi"],
        'Boshqa' => ["Hujjat rasmiylashtirish"],
    ];
    $pickVazn = function () use ($sababVazn) {
        $r = mt_rand(1, array_sum($sababVazn));
        foreach ($sababVazn as $i => $w) { if (($r -= $w) <= 0) return $i; }
        return 0;
    };
    $pLate = [0.04, 0.15, 0.34];
    // bugungi kun (bosh sahifa va "Masalalar" jonli ko'rinsin): kechikkanlar (daqiqa) va sababsiz kelmaganlar
    $bugunKech = [$idByFam['Sobirov'] => 561, $idByFam['Hamidov'] => 552, $idByFam['Davlatov'] => 578];
    $bugunYoq = [$idByFam['Mahmudov'] => true];
    $kunTuri = function (string $s) use ($bayram): string {
        if (isset($bayram[$s])) return 'bayram';
        return (int)date('N', strtotime($s)) <= 5 ? 'ish' : 'dam';
    };

    $insD = $pdo->prepare('INSERT OR REPLACE INTO davomat (id, korxona, sana, oy, xodim, kontakt, ism, kirish, chiqish, lokatsiya, sabab, sabab_tur, kim, ts, ver, deleted)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,0)');
    $insB = $pdo->prepare('INSERT INTO davomat_belgi (id, korxona, sana, kontakt, kun_id, tur, vaqt, lat, lng, aniqlik, masofa, lokatsiya_id, lokatsiya, rasm, ua, ip, kim, ts)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $uaList = ['Mozilla/5.0 (Linux; Android 13; SM-A135F) AppleWebKit/537.36 Chrome/118.0 Mobile Safari/537.36',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 Mobile Safari/604.1',
        'Mozilla/5.0 (Linux; Android 12; Redmi Note 11) AppleWebKit/537.36 Chrome/117.0 Mobile Safari/537.36'];
    $selfiN = 0;
    $rasmKopiya = function (string $sana) use ($assets, &$selfiN): string {
        $p = davomat_rasm_papka(HR_KID, $sana);
        if ($p === '') return '';
        if (!is_dir($p)) @mkdir($p, 0775, true);
        $f = bin2hex(random_bytes(16)) . '.jpg';
        $selfiN++;
        copy($assets . '/selfi-' . (($selfiN % 8) + 1) . '.jpg', $p . '/' . $f);
        return $f;
    };
    $pdo->beginTransaction();
    for ($t = $bosh; $t <= $bugunTs + 30 * 86400; $t += 86400) {
        $sana = date('Y-m-d', $t);
        $oy = substr($sana, 0, 7);
        $kelajak = $sana > $bugun;
        $tur = $kunTuri($sana);
        foreach ($xod as $cid => $x) {
            if ($x['ishSana'] !== '' && $sana < $x['ishSana']) continue;
            if ($x['ishdanSana'] !== '' && $sana > $x['ishdanSana']) continue;
            $id = davomat_id(HR_KID, $sana, $cid);
            if (isset($sababKun[$cid][$sana])) {               // "Davr belgilash": dam kunlariga ham yoziladi
                $st = $sababKun[$cid][$sana];
                $insD->execute([$id, HR_KID, $sana, $oy, $cid, $cid, $x['ism'], '', '', '', $st, $st, $hrLogin, $t]);
                continue;
            }
            if ($kelajak || $tur !== 'ish') continue;
            if ($sana === $bugun && isset($bugunYoq[$cid])) continue;
            $pr = $x['p'];
            $intizom = (int)($pr['intizom'] ?? 0);
            $late = (mt_rand(0, 9999) / 10000.0) < $pLate[$intizom];
            if ($sana === $bugun) $late = isset($bugunKech[$cid]);   // bugun: faqat belgilangan kechikkanlar (qolganlar vaqtida)
            if ($late) $kel = 546 + (int)min(55, round(-log(max(0.02, mt_rand(1, 1000) / 1000.0)) * 11));
            else $kel = min(544, 540 + (int)($pr['kelish'] ?? -10) + (int)round(hr_gauss() * 4));
            $kel = max(7 * 60 + 45, $kel);
            if ($sana === $bugun && isset($bugunKech[$cid])) { $kel = $bugunKech[$cid]; $late = true; }
            // ba'zan xodim umuman kelmaydi: yarmida sababi ko'rsatiladi (sababli), yarmida yo'q (sababsiz)
            if ($sana !== $bugun && mt_rand(1, 1000) <= 9 + 4 * $intizom) {
                if (mt_rand(0, 1)) {
                    $st = bw_pick(['Oilaviy sabab', "Sog'liq (qisqa muddat)", 'Rahbar ruxsati bilan', 'Boshqa']);
                    $insD->execute([$id, HR_KID, $sana, $oy, $cid, $cid, $x['ism'], '', '', '', bw_pick($sababMatn[$st]), $st, $hrLogin, $t]);
                }
                continue;
            }
            $ketish = 1080 + mt_rand(0, 28);
            $sabab = ''; $sTur = '';
            if (mt_rand(1, 100) <= 4) { $ketish = 990 + mt_rand(0, 60); $sTur = 'Rahbar ruxsati bilan'; $sabab = 'Rahbar ruxsati bilan ertaroq ketdi'; }
            if ($late && mt_rand(1, 100) <= 62 && !($sana === $bugun && $cid === $idByFam['Davlatov'])) { $sTur = $sababTurlar[$pickVazn()]; $sabab = bw_pick($sababMatn[$sTur]); }
            if ($sana === $bugun && $cid === $idByFam['Sobirov']) { $sTur = "Transport (yo'l tirbandligi)"; $sabab = "Yo'lda tirbandlik bo'ldi"; }
            $chiqishYoq = false;
            if ($sana === $bugun) {
                if ($hozirDaq < 1080) $chiqishYoq = true;        // ish kuni tugamagan
            } elseif (mt_rand(1, 1000) <= 12) $chiqishYoq = true;   // chiqishni belgilashni unutgan
            $kirishS = hr_vaqt($kel);
            $chiqishS = $chiqishYoq ? '' : hr_vaqt($ketish);
            $lk = $x['ofis'] ? 'ofis' : (str_contains($x['bolim'], 'Ombor') ? 'ombor' : 'sex');
            $L = $lok[$lk];
            $ts = strtotime($sana . ' ' . ($chiqishYoq ? $kirishS : $chiqishS) . ':00');
            $insD->execute([$id, HR_KID, $sana, $oy, $cid, $cid, $x['ism'], $kirishS, $chiqishS, $L['nomi'], $sabab, $sTur, $sabab !== '' ? $hrLogin : '', $ts]);
            // QR belgilar jurnali (hodisalar)
            $yaqin = ($bugunTs - $t) / 86400 <= 2;               // selfi faqat oxirgi 3 kun (keyin o'chadi)
            $hodisalar = [['kirish', $kel]];
            if (!$chiqishYoq) {
                if (mt_rand(1, 100) <= 22) { $c1 = 780 + mt_rand(0, 25); $hodisalar[] = ['chiqish', $c1]; $hodisalar[] = ['kirish', $c1 + mt_rand(35, 70)]; }
                $hodisalar[] = ['chiqish', $ketish];
            }
            foreach ($hodisalar as [$tt, $daq]) {
                $lat = $L['lat'] + mt_rand(-45, 45) / 100000.0; $lng = $L['lng'] + mt_rand(-45, 45) / 100000.0;
                $masofa = davomat_masofa($lat, $lng, (float)$L['lat'], (float)$L['lng']);
                $insB->execute([bin2hex(random_bytes(16)), HR_KID, $sana, $cid, $id, $tt, hr_vaqt($daq), round($lat, 7), round($lng, 7),
                    (float)mt_rand(6, 42), round($masofa, 1), $L['id'], $L['nomi'], $yaqin ? $rasmKopiya($sana) : '',
                    $uaList[$x['idx'] % 3], '198.51.100.' . (10 + $x['idx']), $x['login'], strtotime($sana . ' ' . hr_vaqt($daq) . ':00')]);
            }
        }
    }
    $pdo->commit();

    /* ============================ 5. TABEL ============================ */
    $kechki = ['Sultonov' => 2, 'Hamidov' => 2, 'Toshpulatov' => 3, 'Normatov' => 2];
    foreach ($kechki as $fam => $son) {
        $cid = $idByFam[$fam];
        $n = 0;
        for ($d = 8; $d <= 40 && $n < $son; $d += 3) {
            $s = bw_date($d);
            if ((int)date('N', strtotime($s)) > 5 || isset($bayram[$s]) || isset($sababKun[$cid][$s])) continue;
            if ($xod[$cid]['ishdanSana'] !== '' && $s > $xod[$cid]['ishdanSana']) continue;
            tabel_tuzatish_yoz(HR_KID, $cid . '|' . $s, 'k/i');
            $n++;
        }
    }
    tabel_soz_yoz(HR_KID, [
        ['lavozim' => "Xodimlar bo'yicha menejer", 'ism' => 'Yusupova M.A.'],
        ['lavozim' => 'Bosh buxgalter', 'ism' => 'Karimova G.Sh.'],
    ]);

    /* ============================ 6. HUJJATLAR KUTUBXONASI ============================ */
    $admin = $ctx['admin'];
    $odamlar = kutubxona_odamlar();
    $tmpDir = "$out/.tmp-pdf";
    @mkdir($tmpDir, 0775, true);
    $hujjatPdf = function (array $h, string $versiya) use ($tmpDir): string {
        $pdf = new PdfHujjat($h['nomi'], false, 'Hujjat № ' . $h['raqam'] . ' · versiya ' . $versiya, ['kid' => HR_KID, 'nomi' => 'Zamin Plast MChJ']);
        $pdf->paragraf($h['tavsif'], 10);
        foreach ($h['bandlar'] as $sarlavha => $bandlar) {
            $html = '<h3>' . htmlspecialchars($sarlavha, ENT_QUOTES, 'UTF-8') . '</h3>';
            foreach ($bandlar as $b) $html .= '<p>' . htmlspecialchars($b, ENT_QUOTES, 'UTF-8') . '</p>';
            $pdf->html($html);
        }
        $pdf->html('<p> </p><p>Tasdiqlayman: Bosh direktor ______________ A. B. Rustamov</p>');
        $bytes = hr_pdf_tozala($pdf->chiqar());
        $yol = $tmpDir . '/h-' . md5($h['raqam'] . $versiya) . '.pdf';
        file_put_contents($yol, $bytes);
        return $yol;
    };
    $royxatH = hr_hujjatlar_royxati();
    $oz = $pdo->prepare('INSERT OR IGNORE INTO kutubxona_tanish (hujjat, username, ver, ts, ip) VALUES (?,?,?,?,?)');
    foreach ($royxatH as $h) {
        $kuch = bw_date((int)$h['kuch']);
        $in = ['nomi' => $h['nomi'], 'tavsif' => $h['tavsif'], 'yonalish' => $h['yonalish'], 'raqam' => $h['raqam'], 'versiya' => $h['v1'] ? $h['v1'][0] : $h['versiya'],
            'kuchSana' => $h['v1'] ? bw_date((int)$h['v1'][1]) : $kuch, 'korxonaId' => '', 'bolimlar' => $h['bolimlar'], 'majburiy' => $h['majburiy']];
        $p1 = $hujjatPdf($h, (string)$in['versiya']);
        $f1 = ['name' => 'hujjat-' . $h['raqam'] . '-v' . $in['versiya'] . '.pdf', 'tmp_name' => $p1, 'error' => UPLOAD_ERR_OK, 'size' => filesize($p1)];
        [$kod, $jav] = kutubxona_yoz($admin, $in, $f1, false);
        if ($kod !== 200) throw new RuntimeException('Kutubxona: ' . json_encode($jav, JSON_UNESCAPED_UNICODE));
        $id = $jav['hujjat']['id'];
        if ($h['v1']) {          // ikkinchi fayl — yangi versiya (ver = 2); tarixda v1 qoladi
            $in2 = $in; $in2['versiya'] = $h['versiya']; $in2['kuchSana'] = $kuch; $in2['id'] = $id;
            $p2 = $hujjatPdf($h, $h['versiya']);
            $f2 = ['name' => 'hujjat-' . $h['raqam'] . '-v' . $h['versiya'] . '.pdf', 'tmp_name' => $p2, 'error' => UPLOAD_ERR_OK, 'size' => filesize($p2)];
            [$kod, $jav] = kutubxona_yoz($admin, $in2, $f2, false);
            if ($kod !== 200) throw new RuntimeException('Kutubxona v2: ' . json_encode($jav, JSON_UNESCAPED_UNICODE));
        }
        // vaqtlarni orqaga suramiz (hujjatlar bugun yaratilgandek ko'rinmasin)
        $tsY = strtotime('-' . (int)$h['yaratilgan'] . ' days 10:00');
        $tsF = strtotime('-' . (int)$h['kuch'] . ' days 11:30');
        $tarix = json_decode((string)$pdo->query('SELECT tarix FROM kutubxona WHERE id = ' . $pdo->quote($id))->fetchColumn(), true) ?: [];
        foreach ($tarix as $ti => $_) {
            $tarix[$ti]['ts'] = strtotime('-' . (int)($h['v1'][1] ?? $h['yaratilgan']) . ' days 10:00');
            $tarix[$ti]['kim'] = $hrLogin;
        }
        $pdo->prepare('UPDATE kutubxona SET ts = ?, yaratilgan = ?, fayl_ts = ?, tarix = ?, kim = ?, yaratgan = ?, fayl_kim = ? WHERE id = ?')
            ->execute([$tsF, $tsY, $tsF, js_json($tarix), $hrLogin, $hrLogin, $hrLogin, $id]);
        // tanishish: majburiy hujjat auditoriyasidan ulush (joriy fayl versiyasi bo'yicha)
        if ($h['majburiy']) {
            $r = $pdo->query('SELECT * FROM kutubxona WHERE id = ' . $pdo->quote($id))->fetch(PDO::FETCH_ASSOC);
            $ver = (int)$r['ver'];
            foreach (kutubxona_auditoriya($r, $odamlar) as $o) {
                if (str_starts_with((string)$o['username'], 'namuna')) continue;
                if ((mt_rand(0, 9999) / 10000.0) >= $h['tanish']) continue;
                $kun = max(1, (int)$h['kuch'] - mt_rand(0, max(1, min(40, (int)$h['kuch'] - 1))));
                $oz->execute([$id, $o['username'], $ver, strtotime('-' . $kun . ' days ' . mt_rand(9, 17) . ':' . sprintf('%02d', mt_rand(0, 59))), '198.51.100.' . mt_rand(10, 60)]);
            }
        }
    }
    foreach (glob($tmpDir . '/*') ?: [] as $f) @unlink($f);
    @rmdir($tmpDir);
    // texnik "namuna-admin" hisobi hujjat auditoriyasiga kirmasin
    $pdo->exec("UPDATE users SET active = 0 WHERE username = 'namuna-admin'");
}
