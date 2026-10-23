<?php
declare(strict_types=1);
/**
 * Biwork Savdo — namuna ma'lumoti (BUTUNLAY XAYOLIY).
 *
 * Ikki xayoliy korxona (bitta guruh): ulgurji oziq-ovqat savdosi va qurilish mollari savdosi.
 * Har korxonada 10-12 oylik savdo yozuvlari (korxona.savdo.items) va oxirgi oylarda yozilgan yuk xatlari
 * (korxona.yukXatlari, har biri savdo yozuvlariga bog'langan — yukXatiId). Shakl PM mijozi yasaydigan
 * obyektlar bilan AYNAN bir xil (app.js: addSavdoItem, yukxati.js: savdoYozuvlari).
 * Sana — seed yasalgan kunga nisbatan; tasodif — bw_seed_rng() (qayta yasalganda bir xil).
 */

/** Oyning (1..12) mavsumiy ko'paytuvchisi: umumiy savdo hajmi (Ramazon/Navro'z, to'y mavsumi, yozgi pasayish). */
const SV_OY_KOEF = [1 => 0.88, 2 => 1.12, 3 => 1.38, 4 => 1.04, 5 => 1.02, 6 => 0.92, 7 => 0.84, 8 => 0.98, 9 => 1.22, 10 => 1.16, 11 => 1.0, 12 => 1.1];

function sv_tel(): string {
    return '+998 ' . bw_pick([90, 91, 93, 94, 95, 97, 98, 99, 33, 88]) . ' ' . bw_rand(100, 999) . '-' . bw_rand(10, 99) . '-' . bw_rand(10, 99);
}
function sv_pasport(): string {
    return bw_pick(['AA', 'AB', 'AC', 'KA', 'AD']) . ' ' . bw_rand(1000000, 9999999);
}
function sv_jshshir(bool $ayol = false): string {
    $yil = bw_rand(1968, 1999);
    return ($ayol ? '4' : '3') . sprintf('%02d%02d%02d', bw_rand(1, 28), bw_rand(1, 12), $yil % 100) . bw_rand(10000, 99999);
}

/** Xaridor: nomi, hajm koeffitsienti (kattaligi), tanlanish og'irligi, mahsulot kalitlari, to'lov turi, shaxsiy rekvizit. */
function sv_xaridor(string $nomi, float $olcham, int $ogirlik, array $mahsulot, string $tolov, ?bool $ayol = null): array {
    return ['nomi' => $nomi, 'olcham' => $olcham, 'ogirlik' => $ogirlik, 'mahsulot' => $mahsulot, 'tolov' => $tolov,
            'tel' => sv_tel(), 'pasport' => $ayol === null ? '' : sv_pasport(), 'jshshir' => $ayol === null ? '' : sv_jshshir($ayol)];
}

function sv_korxona_a(): array {
    /* narx — so'm / birlik (seed kuni uchun, oy o'tgan sari ozgina o'sadi); min..max — bitta qatordagi miqdor (olcham = 1 uchun) */
    $m = [
        'un1'  => ['nomi' => "Un, oliy nav", 'birlik' => 'kg', 'narx' => 6400, 'min' => 600, 'max' => 3000, 'qadam' => 50, 'sez' => [3 => 1.5, 2 => 1.25, 9 => 1.1]],
        'un2'  => ['nomi' => "Un, 1-nav", 'birlik' => 'kg', 'narx' => 5500, 'min' => 800, 'max' => 4000, 'qadam' => 100, 'sez' => [3 => 1.4, 2 => 1.2]],
        'shak' => ['nomi' => "Shakar, qum", 'birlik' => 'kg', 'narx' => 13400, 'min' => 500, 'max' => 2500, 'qadam' => 50, 'sez' => [3 => 1.6, 2 => 1.3, 9 => 1.15, 10 => 1.1]],
        'gur1' => ['nomi' => "Guruch, Devzira", 'birlik' => 'kg', 'narx' => 26500, 'min' => 200, 'max' => 1200, 'qadam' => 50, 'sez' => [9 => 1.4, 10 => 1.4, 11 => 1.25, 3 => 1.3, 7 => 0.7]],
        'gur2' => ['nomi' => "Guruch, Lazer", 'birlik' => 'kg', 'narx' => 19800, 'min' => 300, 'max' => 1500, 'qadam' => 50, 'sez' => [9 => 1.3, 10 => 1.3, 3 => 1.2]],
        'yog'  => ['nomi' => "O'simlik yog'i, 5 litr", 'birlik' => 'dona', 'narx' => 79000, 'min' => 40, 'max' => 300, 'qadam' => 10, 'sez' => [3 => 1.4, 9 => 1.25, 10 => 1.2]],
        'mak'  => ['nomi' => "Makaron, spagetti 400 g", 'birlik' => 'quti', 'narx' => 64000, 'min' => 20, 'max' => 150, 'qadam' => 5, 'sez' => []],
        'choy' => ['nomi' => "Qora choy, barg 100 g", 'birlik' => 'quti', 'narx' => 198000, 'min' => 10, 'max' => 80, 'qadam' => 5, 'sez' => [12 => 1.3, 1 => 1.3, 2 => 1.2, 7 => 0.7]],
        'shar' => ['nomi' => "Mevali sharbat, 1 litr", 'birlik' => 'quti', 'narx' => 92000, 'min' => 30, 'max' => 250, 'qadam' => 10, 'sez' => [5 => 1.5, 6 => 1.9, 7 => 2.2, 8 => 2.0, 9 => 1.2, 12 => 0.6, 1 => 0.5, 2 => 0.6]],
        'kon'  => ['nomi' => "Konserva, yashil no'xat 400 g", 'birlik' => 'quti', 'narx' => 172000, 'min' => 10, 'max' => 90, 'qadam' => 5, 'sez' => [12 => 1.3, 3 => 1.2]],
        'tuz'  => ['nomi' => "Tuz, yodlangan", 'birlik' => 'kg', 'narx' => 1700, 'min' => 200, 'max' => 2000, 'qadam' => 50, 'sez' => [8 => 1.3, 9 => 1.5]],
        'may'  => ['nomi' => "Mayiz, qora", 'birlik' => 'kg', 'narx' => 41000, 'min' => 40, 'max' => 300, 'qadam' => 10, 'sez' => [2 => 1.8, 3 => 2.0, 12 => 1.4, 6 => 0.6, 7 => 0.5]],
        'yon'  => ['nomi' => "Yong'oq mag'zi", 'birlik' => 'kg', 'narx' => 92000, 'min' => 20, 'max' => 150, 'qadam' => 5, 'sez' => [2 => 1.7, 3 => 2.0, 12 => 1.5, 6 => 0.6, 7 => 0.5]],
        'kon2' => ['nomi' => "Konfet assorti", 'birlik' => 'kg', 'narx' => 54000, 'min' => 40, 'max' => 300, 'qadam' => 10, 'sez' => [3 => 1.9, 2 => 1.5, 12 => 1.6, 5 => 1.3]],
    ];
    $hammasi = array_keys($m);
    $b = [
        sv_xaridor("Zarif Market MChJ", 1.0, 12, $hammasi, 'nasiya'),
        sv_xaridor("Olmos Supermarket", 1.1, 11, $hammasi, 'bank'),
        sv_xaridor("Tandir Non Ishlab chiqarish", 1.9, 10, ['un1', 'un2', 'shak', 'yog', 'tuz'], 'bank'),
        sv_xaridor("Ziyo Konditer sexi", 1.0, 7, ['un1', 'shak', 'yon', 'may', 'yog', 'kon2'], 'bank'),
        sv_xaridor("Dilkash Restorani", 0.8, 8, ['gur1', 'gur2', 'yog', 'mak', 'choy', 'tuz', 'may'], 'nasiya'),
        sv_xaridor("Oltin Vodiy To'yxonasi", 2.0, 9, ['gur1', 'gur2', 'yog', 'un1', 'shak', 'choy', 'shar', 'tuz'], 'nasiya'),
        sv_xaridor("Madina Mini-market", 0.5, 6, $hammasi, 'naqd'),
        sv_xaridor("Fayz Savdo XK", 1.5, 8, ['un1', 'un2', 'shak', 'gur2', 'tuz', 'yog'], 'bank'),
        sv_xaridor("Yulduz Ulgurji MChJ", 2.2, 9, $hammasi, 'nasiya'),
        sv_xaridor("5-sonli maktab oshxonasi", 0.6, 3, ['un2', 'gur2', 'yog', 'mak', 'tuz'], 'bank'),
        sv_xaridor("Shifo poliklinikasi oshxonasi", 0.5, 3, ['gur2', 'mak', 'yog', 'choy', 'shak'], 'bank'),
        sv_xaridor("Bobur Choyxonasi", 0.7, 5, ['gur1', 'yog', 'choy', 'tuz', 'shar'], 'naqd'),
        sv_xaridor("YaTT Rustam Qodirov", 0.6, 4, $hammasi, 'naqd', false),
        sv_xaridor("YaTT Dilnoza Ergasheva", 0.5, 4, ['shak', 'un1', 'yog', 'choy', 'mak', 'shar', 'kon2'], 'naqd', true),
        sv_xaridor("Anor Mini-market", 0.6, 5, $hammasi, 'naqd'),
        sv_xaridor("Sevinch Savdo MChJ", 1.1, 5, $hammasi, 'bank'),
        sv_xaridor("Mehmon mehmonxonasi", 0.8, 4, ['gur1', 'yog', 'choy', 'mak', 'shar', 'kon'], 'nasiya'),
        sv_xaridor("YaTT Sherzod Nazarov", 0.7, 3, ['un1', 'shak', 'yog', 'gur2', 'tuz'], 'naqd', false),
    ];
    return [
        'id' => 'k_bahor', 'tartib' => 1, 'nomi' => "Bahor Ulgurji Savdo", 'rasmiyNomi' => "«Bahor Ulgurji Savdo» MChJ",
        'tavsif' => "Oziq-ovqat mahsulotlarini ulgurji sotish va yetkazib berish", 'rahbar' => "Sardor Rahimov",
        'rekvizitQatori' => "Toshkent sh., Chilonzor t., Bunyodkor ko'chasi 48 · STIR 307 218 645 · h/r 2020 8000 4051 7723 001 · MFO 00873 · Tel: +998 71 200-14-40",
        'oy' => 11, 'kunlik' => 0.98, 'baza' => 600e6, 'mahsulot' => $m, 'xaridor' => $b, 'yxOy' => 5, 'raqamBosh' => 240,
    ];
}

function sv_korxona_b(): array {
    $m = [
        'sem'  => ['nomi' => "Sement M400, quyma", 'birlik' => 'tonna', 'narx' => 905000, 'min' => 6, 'max' => 40, 'qadam' => 1, 'sez' => [4 => 1.3, 5 => 1.4, 6 => 1.3, 7 => 1.2, 8 => 1.3, 9 => 1.3, 1 => 0.5, 2 => 0.6, 12 => 0.6]],
        'arm'  => ['nomi' => "Armatura A500, 12 mm", 'birlik' => 'tonna', 'narx' => 9800000, 'min' => 1, 'max' => 8, 'qadam' => 1, 'sez' => [4 => 1.3, 5 => 1.4, 6 => 1.3, 8 => 1.2, 9 => 1.3, 1 => 0.5, 2 => 0.6, 12 => 0.6]],
        'gis'  => ['nomi' => "G'isht, qizil M150", 'birlik' => 'dona', 'narx' => 1250, 'min' => 2000, 'max' => 15000, 'qadam' => 500, 'sez' => [4 => 1.3, 5 => 1.5, 6 => 1.4, 8 => 1.3, 9 => 1.3, 1 => 0.4, 2 => 0.5]],
        'gaz'  => ['nomi' => "Gazobeton blok 600x300x200", 'birlik' => 'dona', 'narx' => 33500, 'min' => 100, 'max' => 700, 'qadam' => 20, 'sez' => [4 => 1.2, 5 => 1.3, 6 => 1.2, 9 => 1.3, 1 => 0.5]],
        'gip'  => ['nomi' => "Gipsokarton 12,5 mm", 'birlik' => 'dona', 'narx' => 62000, 'min' => 50, 'max' => 400, 'qadam' => 10, 'sez' => [3 => 1.3, 4 => 1.3, 10 => 1.2, 11 => 1.2]],
        'boy'  => ['nomi' => "Emulsiya bo'yoq, 20 kg", 'birlik' => 'chelak', 'narx' => 420000, 'min' => 5, 'max' => 45, 'qadam' => 1, 'sez' => [3 => 1.4, 4 => 1.5, 10 => 1.3, 11 => 1.2]],
        'kaf'  => ['nomi' => "Kafel plitka 30x60", 'birlik' => 'm²', 'narx' => 118000, 'min' => 40, 'max' => 420, 'qadam' => 5, 'sez' => [3 => 1.3, 4 => 1.3, 10 => 1.3, 11 => 1.3]],
        'tru'  => ['nomi' => "Profil quvur 40x40, 6 m", 'birlik' => 'dona', 'narx' => 104000, 'min' => 20, 'max' => 200, 'qadam' => 5, 'sez' => [4 => 1.2, 5 => 1.2, 9 => 1.2]],
        'qum'  => ['nomi' => "Qum, yuvilgan", 'birlik' => 'm³', 'narx' => 96000, 'min' => 20, 'max' => 120, 'qadam' => 5, 'sez' => [4 => 1.3, 5 => 1.4, 6 => 1.3, 8 => 1.2, 9 => 1.2, 1 => 0.4]],
        'shag' => ['nomi' => "Shag'al 5-20 mm", 'birlik' => 'm³', 'narx' => 138000, 'min' => 20, 'max' => 100, 'qadam' => 5, 'sez' => [4 => 1.3, 5 => 1.4, 6 => 1.3, 8 => 1.2, 9 => 1.2, 1 => 0.4]],
    ];
    $h = array_keys($m);
    $b = [
        sv_xaridor("Mustahkam Qurilish MChJ", 1.8, 12, $h, 'nasiya'),
        sv_xaridor("Yangi Uy Invest", 2.0, 10, ['sem', 'arm', 'gis', 'gaz', 'qum', 'shag', 'tru'], 'nasiya'),
        sv_xaridor("Obod Mahalla Qurilish", 1.2, 8, ['sem', 'gis', 'gaz', 'qum', 'shag', 'gip', 'boy'], 'bank'),
        sv_xaridor("Ravnaq Remont XK", 0.7, 9, ['gip', 'boy', 'kaf', 'tru', 'gaz'], 'bank'),
        sv_xaridor("Poytaxt Interyer MChJ", 0.8, 7, ['gip', 'boy', 'kaf', 'tru'], 'bank'),
        sv_xaridor("Nurli Maskan MChJ", 1.3, 7, $h, 'nasiya'),
        sv_xaridor("YaTT Olimjon Saidov", 0.5, 6, ['sem', 'gis', 'qum', 'shag', 'boy'], 'naqd', false),
        sv_xaridor("YaTT Akmal Hasanov", 0.5, 5, ['gis', 'gaz', 'gip', 'kaf', 'boy'], 'naqd', false),
        sv_xaridor("Shahar Uylari Qurilish", 1.6, 6, ['sem', 'arm', 'gis', 'gaz', 'tru'], 'bank'),
        sv_xaridor("Oila Uy-joy kooperativi", 0.8, 5, $h, 'naqd'),
    ];
    return [
        'id' => 'k_zamin', 'tartib' => 2, 'nomi' => "Zamin Qurilish Mollari", 'rasmiyNomi' => "«Zamin Qurilish Mollari» MChJ",
        'tavsif' => "Qurilish va ta'mirlash materiallari savdosi", 'rahbar' => "Otabek Yusupov",
        'rekvizitQatori' => "Toshkent vil., Zangiota t., Sanoat yo'li 7 · STIR 309 774 102 · h/r 2020 8000 7012 3345 001 · MFO 00421 · Tel: +998 71 244-30-30",
        'oy' => 9, 'kunlik' => 0.5, 'baza' => 300e6, 'mahsulot' => $m, 'xaridor' => $b, 'yxOy' => 4, 'raqamBosh' => 86,
    ];
}

/** Narx: oyma-oy ozgina o'sish + kichik tebranish; yirik xaridorga ~2% chegirma. */
function sv_narx(array $p, int $oyIdx, int $oyJami, float $olcham): int {
    $n = $p['narx'] * (1 - 0.0065 * max(0, $oyJami - $oyIdx)) * (1 + bw_rand(-12, 12) / 1000) * ($olcham >= 1.5 ? 0.98 : 1.0);
    $r = $n >= 10000 ? 100 : ($n >= 1000 ? 50 : 10);
    return (int)(round($n / $r) * $r);
}

/** To'lov holati: [izoh, kassaSana|null]. */
function sv_tolov(array $x, string $sana, int $yosh, string $bugun): array {
    $t = $x['tolov'];
    $kun = static fn(int $d): string => date('Y-m-d', strtotime("$sana +$d days"));
    if ($t === 'naqd') {
        return [bw_pick(['Puli olindi', 'Puli olindi', 'Naqd pul', "Click orqali to'landi"]), null];
    }
    if ($t === 'bank') {
        $kassa = $kun(bw_rand(1, 8));
        if ($kassa > $bugun) return [bw_pick(['Qaytadi', 'Qaytadi', 'Avans olindi']), $kassa];
        return ["Bank o'tkazmasi", $kassa];
    }
    $kassa = $kun(bw_pick([15, 15, 30]) + bw_rand(-2, 6));
    if ($kassa > $bugun) return [$yosh <= 4 && bw_rand(1, 4) === 1 ? 'Avans olindi' : 'Qaytadi', $kassa];
    return ['Puli olindi', $kassa];
}

/** Bitta korxona obyektini yasaydi (savdo yozuvlari + yuk xatlari). */
function sv_yasa(array $c, int $bugunTs): array {
    $bugun = date('Y-m-d', $bugunTs);
    $boshOy = date('Y-m-01', strtotime('first day of this month -' . $c['oy'] . ' months', $bugunTs));
    $boshTs = strtotime($boshOy);
    $yxBosh = date('Y-m-01', strtotime('first day of this month -' . $c['yxOy'] . ' months', $bugunTs));
    $mah = $c['mahsulot'];
    $xar = $c['xaridor'];
    $jamiOg = array_sum(array_column($xar, 'ogirlik'));
    $yetkazma = [];
    for ($t = $boshTs; $t <= $bugunTs; $t += 86400) {
        $kun = date('Y-m-d', $t);
        $hafta = (int)date('N', $t);
        if ($hafta === 7) continue;                  // yakshanba — dam
        $oy = (int)date('n', $t);
        $oyIdx = (int)round(($t - $boshTs) / (30.4 * 86400));
        $lam = $c['kunlik'] * (SV_OY_KOEF[$oy] ?? 1.0) * (1 + 0.011 * $oyIdx) * ($hafta === 6 ? 0.6 : 1.0);
        $soni = (int)floor($lam) + ((bw_rand(0, 999) / 1000) < ($lam - floor($lam)) ? 1 : 0);
        if (bw_rand(1, 5) === 1) $soni += bw_pick([-1, 1]);
        if ($soni < 0) $soni = 0;
        for ($i = 0; $i < $soni; $i++) {
            $x = null;
            for ($urinish = 0; $urinish < 8 && $x === null; $urinish++) {
                $r = bw_rand(1, $jamiOg); $s = 0;
                foreach ($xar as $cand) { $s += $cand['ogirlik']; if ($r <= $s) { $x = $cand; break; } }
                if ($x !== null && str_contains($x['nomi'], 'maktab') && in_array($oy, [6, 7, 8], true)) $x = null;
            }
            if ($x === null) $x = $xar[0];
            $qSoni = min(bw_pick([1, 1, 1, 2, 2, 2, 3, 3, 4]), count($x['mahsulot']));
            $nomz = [];
            foreach ($x['mahsulot'] as $key) $nomz[$key] = 10 * ($mah[$key]['sez'][$oy] ?? 1.0);
            $qator = [];
            for ($q = 0; $q < $qSoni && $nomz; $q++) {
                $tot = array_sum($nomz); $rr = bw_rand(0, (int)($tot * 100)) / 100; $acc = 0; $tanlov = array_key_first($nomz);
                foreach ($nomz as $key => $w) { $acc += $w; if ($rr <= $acc) { $tanlov = $key; break; } }
                unset($nomz[$tanlov]);
                $p = $mah[$tanlov];
                $ort = ($p['min'] + $p['max']) / 2;
                $mq = (bw_rand((int)($p['min'] * 10), (int)($p['max'] * 10)) / 10 + $ort) / 2 * $x['olcham'];
                $mq = max($p['min'] * 0.5, min($p['max'] * 1.4, $mq));
                $mq = max((float)$p['qadam'], round($mq / $p['qadam']) * $p['qadam']);
                $qator[] = ['mahsulot' => $p['nomi'], 'birlik' => $p['birlik'], 'miqdor' => (int)round($mq),
                            'narx' => sv_narx($p, $oyIdx, (int)round(($bugunTs - $boshTs) / (30.4 * 86400)), $x['olcham'])];
            }
            $yosh = (int)floor(($bugunTs - $t) / 86400);
            [$izoh, $kassa] = sv_tolov($x, $kun, $yosh, $bugun);
            $yetkazma[] = ['sana' => $kun, 'x' => $x, 'qator' => $qator, 'izoh' => $izoh, 'kassa' => $kassa, 'yosh' => $yosh];
        }
    }
    // oylik hajmni maqsadga keltiramiz: tasodifiy yetkazmalar yig'indisi juda "tishli" chiqmasin (mavsumiylik + ozgina o'sish saqlanadi)
    $qadam = [];
    foreach ($mah as $p) $qadam[$p['nomi']] = (float)$p['qadam'];
    $oyY = [];
    foreach ($yetkazma as $y) foreach ($y['qator'] as $q) $oyY[substr($y['sana'], 0, 7)] = ($oyY[substr($y['sana'], 0, 7)] ?? 0) + $q['miqdor'] * $q['narx'];
    $oyIdxMap = []; $oylarTartib = array_keys($oyY); sort($oylarTartib);
    foreach ($oylarTartib as $i => $ym) $oyIdxMap[$ym] = $i;
    $ogirlik = static fn(int $ts): float => (int)date('N', $ts) === 7 ? 0.0 : ((int)date('N', $ts) === 6 ? 0.6 : 1.0);
    foreach ($oyY as $ym => $yig) {
        $oy = (int)substr($ym, 5, 2);
        $maqsad = $c['baza'] * (SV_OY_KOEF[$oy] ?? 1.0) * (1 + 0.011 * $oyIdxMap[$ym]) * (1 + bw_rand(-45, 45) / 1000);
        if ($ym === date('Y-m', $bugunTs)) {                 // joriy oy to'liq emas: o'tgan ish kunlari ulushi
            $jami = 0.0; $otgan = 0.0;
            for ($d = strtotime($ym . '-01'); date('Y-m', $d) === $ym; $d += 86400) { $jami += $ogirlik($d); if ($d <= $bugunTs) $otgan += $ogirlik($d); }
            $maqsad *= $jami > 0 ? $otgan / $jami : 1;
        }
        $f = $yig > 0 ? $maqsad / $yig : 1;
        foreach ($yetkazma as $i => $y) {
            if (substr($y['sana'], 0, 7) !== $ym) continue;
            foreach ($y['qator'] as $j => $q) {
                $st = $qadam[$q['mahsulot']] ?? 1.0;
                $yetkazma[$i]['qator'][$j]['miqdor'] = (int)max($st, round($q['miqdor'] * $f / $st) * $st);
            }
        }
    }
    // bir nechta eski qarzdorlik (muddati o'tgan) — namuna jonli ko'rinsin
    $qarzdor = 0;
    foreach ($yetkazma as $i => $y) {
        if ($y['yosh'] >= 52 && $y['yosh'] <= 110 && $y['x']['tolov'] === 'nasiya' && $qarzdor < 3 && bw_rand(1, 14) === 1) {
            $yetkazma[$i]['izoh'] = "Qaytadi — muddat o'tgan"; $yetkazma[$i]['kassa'] = null; $qarzdor++;
        }
    }
    // raqamlash va yozuvlar
    $items = []; $yx = []; $raqam = $c['raqamBosh']; $n = 0;
    foreach ($yetkazma as $y) {
        if (!$y['qator']) continue;
        $raqam++;
        $yxBor = $y['sana'] >= $yxBosh;
        $yxId = null;
        if ($yxBor) {
            $yxId = 'yx_' . substr(md5($c['id'] . $raqam), 0, 8);
            $ts = gmdate('Y-m-d\TH:i:s\Z', strtotime($y['sana'] . ' 09:00:00') + bw_rand(0, 8 * 3600) - 5 * 3600);
            $yx[] = ['id' => $yxId, 'raqam' => $raqam, 'sana' => $y['sana'], 'xaridor' => $y['x']['nomi'], 'pasport' => $y['x']['pasport'],
                     'jshshir' => $y['x']['jshshir'], 'tel' => $y['x']['tel'], 'izoh' => $y['izoh'], 'qatorlar' => $y['qator'], 'ts' => $ts];
        }
        $eskiRaqam = !$yxBor && bw_rand(1, 100) <= 86;       // eski (Excel davri) yozuvlarda raqam qo'lda yozilgan
        foreach ($y['qator'] as $q) {
            $n++;
            $it = ['id' => 's_' . substr(md5($c['id'] . 's' . $n), 0, 8), 'sana' => $y['sana'], 'mahsulot' => $q['mahsulot'], 'birlik' => $q['birlik'],
                   'miqdor' => $q['miqdor'], 'narx' => $q['narx'], 'summa' => (int)round($q['miqdor'] * $q['narx']),
                   'xaridor' => $y['x']['nomi'], 'izoh' => $y['izoh'], 'yukXati' => ($yxBor || $eskiRaqam) ? (string)$raqam : null,
                   'kassaSana' => $y['kassa']];
            if ($yxBor) $it['yukXatiId'] = $yxId;
            $items[] = $it;
        }
    }
    return ['id' => $c['id'], 'tartib' => $c['tartib'], 'logoV' => 1, 'nomi' => $c['nomi'], 'rasmiyNomi' => $c['rasmiyNomi'], 'tavsif' => $c['tavsif'],
            'rahbar' => $c['rahbar'], 'rekvizitQatori' => $c['rekvizitQatori'],
            'savdoAktiv' => true, 'biznesAktiv' => true,          // biznesAktiv — «Yuk xatlari» yorlig'ini ochadi (mavsum/yig'im reja yo'q)
            'savdo' => ['items' => $items], 'yukXatlari' => $yx,
            'cashflow' => ['openingBalances' => ['kassa' => 0, 'bank' => 0], 'transactions' => []]];
}

function biwork_seed(array $ctx): void {
    bw_seed_rng(20261020);
    $bugunTs = strtotime('today');
    @mkdir($ctx['out'] . '/brend', 0775, true);       // korxona belgisi (xayoliy): tanlagichda va yuk xati blankasida
    foreach ([sv_korxona_a(), sv_korxona_b()] as $cfg) {
        $k = sv_yasa($cfg, $bugunTs);
        copy(__DIR__ . '/seed-assets/' . ($k['id'] === 'k_bahor' ? 'bahor' : 'zamin') . '-logo.png', $ctx['out'] . '/brend/' . $k['id'] . '.png');
        bw_put('korxona', $k['id'], $k);
    }
}
