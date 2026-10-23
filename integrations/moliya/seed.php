<?php
/**
 * Biwork Moliya — namuna ma'lumoti (BUTUNLAY XAYOLIY: korxona, odam, hisob raqami, summalar to'qilgan).
 *
 * Yoziladi:
 *   - ikki korxona (korxona yozuvi ICHIDA): Navro'z Savdo Group (kassa+bank, hisob qoldiqlari, kategoriyalar,
 *     13 oylik jurnal) va Yashil Vodiy Agro (faqat bank, qisqaroq jurnal);
 *   - Kreditor-Debitor reyestri (debitor / kreditor entity'lari, kunlik harakat bilan);
 *   - Biznes reja — moliyaviy natijalar (bp_moliya jadvali: reja/fakt/izoh, o'tgan yil, xarajat moddalari);
 *   - Balans (balans jadvali: tovar, xom ashyo, inventar, asosiy vositalar — oyma-oy).
 * Mijoz real foydalanishda bularni 1C/Excel fayllaridan import qiladi; demoda to'g'ridan-to'g'ri bazaga yoziladi.
 */

/* ---------------------------------------------------------------- umumiy yordamchilar */

function mol_id(string $p): string { return $p . '_' . base_convert((string)mt_rand(100000000, 999999999), 10, 36); }
/** Joriy oydan $off oy keyingi/oldingi oy: 'Y-m' */
function mol_oy(int $off): string { return date('Y-m', strtotime(sprintf('first day of %+d months', $off))); }
function mol_r(float $v, int $to = 1000): int { return (int)(round($v / $to) * $to); }
function mol_u(float $a, float $b): float { return $a + (mt_rand(0, 10000) / 10000) * ($b - $a); }
/** $jami ni $n bo'lakka tasodifiy bo'ladi (yig'indi aynan $jami) */
function mol_bol(int $jami, int $n, int $to = 1000): array {
    $w = []; for ($i = 0; $i < $n; $i++) $w[] = mt_rand(40, 170);
    $s = array_sum($w); $out = []; $acc = 0;
    for ($i = 0; $i < $n - 1; $i++) { $v = mol_r($jami * $w[$i] / $s, $to); $out[] = $v; $acc += $v; }
    $out[] = $jami - $acc;
    return $out;
}
/** Oyning ish kunlari ($oy 'Y-m'), bugundan keyingisi tashlanadi */
function mol_kunlar(string $oy, string $bugun, bool $shanba = false): array {
    $out = []; $n = (int)date('t', strtotime($oy . '-01'));
    for ($d = 1; $d <= $n; $d++) {
        $s = sprintf('%s-%02d', $oy, $d);
        if ($s > $bugun) break;
        $w = (int)date('N', strtotime($s));
        if ($w === 7 || ($w === 6 && !$shanba)) continue;
        $out[] = $s;
    }
    return $out;
}
function mol_kun_pick(array $kunlar): string { return $kunlar[mt_rand(0, count($kunlar) - 1)]; }

/* ---------------------------------------------------------------- Biznes reja (BP) ma'lumoti */

/** Oylik P&L (mln so'm). $T tushum, $gm yalpi marja, $e — og'ish darajasi (0 = reja), $sc — korxona o'lchami (xarajatlar masshtabi) */
function mol_pnl(float $T, float $gm, int $mm, float $e, float $sc = 1.0): array {
    $n = fn() => $e > 0 ? mol_u(1 - $e, 1 + $e * 1.6) : 1.0;
    $q = [];
    $q['tushum'] = round($T, 2);
    $yalpi = round($T * $gm, 2);
    $q['yalpi'] = $yalpi; $q['tannarx'] = round($T - $yalpi, 2);
    $ishB = (74 + ($mm >= 7 ? 6 : 0)) * $n() * $sc;
    $wages = $ishB * 0.88; $soc = $ishB * 0.12;
    $ofis = 19.0 * $sc * ($e > 0 ? mol_u(0.99, 1.02) : 1); $omb = 22.0 * $sc * ($e > 0 ? mol_u(0.99, 1.02) : 1);
    $amort = 18.5 * $sc * ($e > 0 ? mol_u(0.99, 1.01) : 1);
    $kom = 7.5 * $sc * $n(); $aloqa = 3.0 * $sc * $n(); $audit = ($mm % 3 === 0 ? 12.0 : 3.5) * $sc * $n(); $bank = 4.0 * $sc * $n(); $xoj = 6.5 * $sc * $n(); $safar = ($mm % 2 === 0 ? 8.0 : 3.0) * $sc * $n();
    $leaf = ["Ma'muriy xodimlar ish haqi" => $wages, 'Ijtimoiy soliq' => $soc, 'Ofis ijarasi' => $ofis, 'Ombor ijarasi' => $omb,
        'Asosiy vositalar amortizatsiyasi' => $amort, 'Kommunal xizmatlar' => $kom, 'Aloqa va internet' => $aloqa,
        'Audit va yuridik xizmatlar' => $audit, 'Bank xizmatlari' => $bank, "Ofis xo'jalik xarajatlari" => $xoj, 'Xizmat safarlari' => $safar];
    $leaf = array_map(fn($v) => round($v, 2), $leaf);
    $mam = array_sum($leaf);
    $opLeaf = ['Jarima va penyalar' => ($mm % 4 === 1 ? 2.4 : 0.6) * $sc * $n(), "Tovar yo'qotish va buzilishlar" => 9.5 * $sc * $n(),
        'Xayriya va homiylik' => 4.0 * $sc * $n(), 'Sud va notarial xarajatlar' => ($mm % 5 === 0 ? 2.8 : 0.5) * $sc * $n(), 'Boshqa operatsion xarajatlar' => 4.5 * $sc * $n()];
    $opLeaf = array_map(fn($v) => round($v, 2), $opLeaf);
    $op = array_sum($opLeaf);
    $sot = round($T * 0.034 * ($e > 0 ? mol_u(0.9, 1.12) : 1), 2);
    $q['sotish'] = $sot; $q['mamuriy'] = round($mam, 2); $q['operatsion'] = round($op, 2);
    $q['ishHaqi'] = round($leaf["Ma'muriy xodimlar ish haqi"] + $leaf['Ijtimoiy soliq'], 2);
    $q['ijara'] = round($leaf['Ofis ijarasi'] + $leaf['Ombor ijarasi'], 2); $q['amort'] = $leaf['Asosiy vositalar amortizatsiyasi'];
    $q['davr'] = round($sot + $q['mamuriy'] + $q['operatsion'], 2);
    $q['boshqaAsosiy'] = round(5 * $sc * $n(), 2);
    $fd = 14.0 * $sc * $n(); $kd = 4.0 * $sc * ($e > 0 ? mol_u(0.2, 2.0) : 1);
    $fx = 29.0 * $sc * ($e > 0 ? mol_u(0.97, 1.08) : 1); $kx = 3.0 * $sc * ($e > 0 ? mol_u(0.2, 2.2) : 1);
    $q['foizD'] = round($fd, 2); $q['kursD'] = round($kd, 2); $q['moliyaD'] = round($fd + $kd, 2);
    $q['foizX'] = round($fx, 2); $q['kursX'] = round($kx, 2); $q['moliyaX'] = round($fx + $kx, 2);
    $q['soliqOldi'] = round($q['yalpi'] - $q['davr'] + $q['boshqaAsosiy'] + $q['moliyaD'] - $q['moliyaX'], 2);
    $q['soliq'] = round(max(0, $q['soliqOldi'] * 0.15), 2);
    $q['sof'] = round($q['soliqOldi'] - $q['soliq'], 2);
    return ['q' => $q, 'mam' => $leaf, 'op' => $opLeaf];
}

/**
 * Biznes reja ma'lumoti. $prior — o'tgan yil fakti, $plan — joriy yil rejasi (12 oy, mln so'm tushum),
 * $dev — fakt/reja nisbati, $sc — xarajatlar masshtabi, $gm — yalpi marja.
 */
function mol_bp(array $prior, array $plan, array $dev, float $sc = 1.0, float $gm = 0.17, float $y24 = 27940.0): array {
    $gacha = mol_oy(-1);                       // oxirgi to'liq oy (hisobot shu oygacha)
    $yil = (int)substr($gacha, 0, 4);
    $gm_ = (int)substr($gacha, 5, 2);
    $oylar = []; $mamL = []; $opL = []; $qamrov = [];
    $revFakt = [];
    for ($m = 1; $m <= 12; $m++) {          // o'tgan yil — faqat fakt
        $ym = sprintf('%04d-%02d', $yil - 1, $m);
        $p = mol_pnl($prior[$m - 1] * mol_u(0.99, 1.01), $gm - 0.012 + mol_u(-0.006, 0.006), $m, 0.04, $sc);
        $oylar[$ym]['fakt'] = $p['q'];
        foreach ($p['mam'] as $k => $v) $mamL[$k][$ym] = $v;
        foreach ($p['op'] as $k => $v) $opL[$k][$ym] = $v;
        $qamrov[] = $ym; $revFakt[$ym] = $p['q']['tushum'];
    }
    $izohT = [
        'low' => ["Tushum rejadan past: ikki yirik xaridor to'lovi keyingi oyga surildi.", "Reja bajarilmadi — buyurtmalar qisqardi, aksiya kech boshlandi."],
        'high' => ["Reja oshirib bajarildi: yangi shartnomalar hisobiga.", "Mavsumiy talab oshdi, qo'shimcha partiya sotildi."],
    ];
    for ($m = 1; $m <= 12; $m++) {          // joriy yil: reja (12 oy), fakt + izoh (hisobot oyigacha)
        $ym = sprintf('%04d-%02d', $yil, $m);
        $pp = mol_pnl((float)$plan[$m - 1], $gm, $m, 0.0, $sc);
        $oylar[$ym]['reja'] = $pp['q'];
        if ($m > $gm_) continue;
        $f = mol_pnl($plan[$m - 1] * $dev[$m - 1] * mol_u(0.995, 1.005), $gm + mol_u(-0.014, 0.008), $m, 0.04, $sc);
        $oylar[$ym]['fakt'] = $f['q'];
        $oylar[$ym]['hisobot'] = true;
        foreach ($f['mam'] as $k => $v) $mamL[$k][$ym] = $v;
        foreach ($f['op'] as $k => $v) $opL[$k][$ym] = $v;
        $qamrov[] = $ym; $revFakt[$ym] = $f['q']['tushum'];
        $iz = [];
        $r = $f['q']['tushum'] / $pp['q']['tushum'];
        if ($r < 0.985) $iz['tushum'] = $izohT['low'][$m % 2];
        elseif ($r > 1.03) $iz['tushum'] = $izohT['high'][$m % 2];
        if ($f['q']['davr'] > $pp['q']['davr'] * 1.04) $iz['davr'] = "Davr xarajatlari rejadan oshdi: reklama kampaniyasi va yetkazib berish xarajatlari ko'paydi.";
        if ($f['q']['moliyaX'] > $pp['q']['moliyaX'] * 1.05) $iz['moliyaX'] = "Kredit liniyasi bo'yicha foiz stavkasi oshdi.";
        if ($f['q']['sof'] < $pp['q']['sof'] * 0.9) $iz['sof'] = "Sof foyda rejadan past: tushum kamayishi va xarajatlar o'sishi ta'siri.";
        if ($iz) $oylar[$ym]['izoh'] = $iz;
    }
    ksort($oylar);
    $xar = ['mamuriy' => ['qatorlar' => [], 'jami' => []], 'operatsion' => ['qatorlar' => [], 'jami' => []]];
    foreach ($mamL as $nomi => $ol) { ksort($ol); $xar['mamuriy']['qatorlar'][] = ['nomi' => $nomi, 'oylar' => $ol]; }
    foreach ($opL as $nomi => $ol) { ksort($ol); $xar['operatsion']['qatorlar'][] = ['nomi' => $nomi, 'oylar' => $ol]; }
    foreach ($qamrov as $ym) {
        $xar['mamuriy']['jami'][$ym] = $oylar[$ym]['fakt']['mamuriy'];
        $xar['operatsion']['jami'][$ym] = $oylar[$ym]['fakt']['operatsion'];
    }
    sort($qamrov);
    $y = ['tushum' => $y24, 'yalpi' => round($y24 * ($gm - 0.018), 2)];
    $y['tannarx'] = round($y24 - $y['yalpi'], 2);
    $y['davr'] = round(2850.0 * $sc, 2); $y['moliyaD'] = round(165.0 * $sc, 2); $y['moliyaX'] = round(372.0 * $sc, 2);
    $y['soliqOldi'] = round($y['yalpi'] - $y['davr'] + 60 * $sc + $y['moliyaD'] - $y['moliyaX'], 2);
    $y['soliq'] = round($y['soliqOldi'] * 0.15, 2); $y['sof'] = round($y['soliqOldi'] - $y['soliq'], 2);
    return ['v' => 2, 'birlik' => 'mln', 'yil' => $yil, 'gacha' => $gacha, 'oylar' => $oylar, 'qoshimcha' => [], 'davrlar' => [],
        'xarajat' => $xar, 'qamrov' => $qamrov, 'yillik' => ['' . ($yil - 2) => ['fakt' => $y, 'manba' => 'BP' . $yil]], 'fot' => null, '_rev' => $revFakt];
}

/* ---------------------------------------------------------------- Pul oqimi (cashflow) */

function mol_cashflow_navro(array $revFakt, array $plan): array {
    $bugun = date('Y-m-d');
    $tx = [];
    $add = function (string $d, string $acc, string $dir, string $cat, string $desc, int $amt) use (&$tx) {
        if ($amt <= 0) return;
        $tx[] = ['id' => mol_id('t'), 'date' => $d, 'account' => $acc, 'direction' => $dir, 'category' => $cat, 'desc' => $desc, 'loyihaId' => null, 'amount' => $amt];
    };
    $mijoz = [["Sarvar Trade MChJ", '112/25'], ["Dilnura Market", '98/25'], ["Fayz Savdo Markazi", '131/25'], ["Bobur Grand Oziq-ovqat", '77/24'],
        ["Asal Tijorat MChJ", '154/25'], ["Nur Universal Savdo", '60/24'], ["Kamalak Supermarket", '143/25'], ["Baraka Ulgurji MChJ", '88/25'],
        ["Umid Savdo Plus", '119/25'], ["Yulduz Minimarket tarmog'i", '161/26'], ["Orzu Retail Group", '127/25'], ["Shirin Hayot MChJ", '172/26']];
    $taminot = [["Tong Un MChJ", 'T-41', 'un'], ["Orzu Yog' MChJ", 'Y-18', "o'simlik yog'i"], ["Sifat Shakar Savdo", 'SH-77', 'shakar'], ["Barkamol Pack", 'B-09', 'qadoqlash materiallari'],
        ["Sabr Choy Import", 'C-33', 'choy'], ["Bahor Sut Mahsulotlari", 'S-52', 'sut mahsulotlari'], ["Oltin Dasht Don", 'D-26', 'guruch va yorma'], ["Sifat Konserva Zavodi", 'K-14', 'konserva']];
    $oylar = []; for ($o = -12; $o <= 0; $o++) $oylar[] = mol_oy($o);
    $kassa = 0;
    foreach ($oylar as $ym) {
        $kunBank = mol_kunlar($ym, $bugun, false);
        $kunKassa = mol_kunlar($ym, $bugun, true);
        if (!$kunBank) continue;
        $nb = count($kunBank);
        $ulush = min(1.0, $nb / 22.0);
        $R = ($revFakt[$ym] ?? ($plan[(int)substr($ym, 5, 2) - 1] * 0.97)) * $ulush;
        $nds = 1.12;
        // --- bank kirimi
        $sotuv = mol_r($R * 1e6 * $nds * mol_u(0.93, 1.02) * 0.93, 1000);
        foreach (mol_bol($sotuv, max(3, (int)round(18 * $ulush))) as $a) {
            [$nom, $sh] = $mijoz[mt_rand(0, count($mijoz) - 1)];
            $add(mol_kun_pick($kunBank), 'bank', 'income', 'Mahsulot sotuvidan tushum', "$nom — №$sh shartnoma bo'yicha to'lov", $a);
        }
        $avans = mol_r($R * 1e6 * 0.045, 1000);
        foreach (mol_bol($avans, max(1, (int)round(3 * $ulush))) as $a) {
            [$nom, $sh] = $mijoz[mt_rand(0, count($mijoz) - 1)];
            $add(mol_kun_pick($kunBank), 'bank', 'income', 'Xaridorlar avansi', "$nom — oldindan to'lov (№$sh)", $a);
        }
        if ($ulush > 0.5) $add($kunBank[min($nb - 1, 2)], 'bank', 'income', 'Depozit foizlari', "Depozit bo'yicha foiz to'lovi", mol_r(mol_u(13e6, 17e6), 1000));
        // --- bank chiqimi
        $tannarx = $R * 1e6 * 0.83 * $nds * mol_u(0.92, 1.03);
        foreach (mol_bol(mol_r($tannarx), max(3, (int)round(10 * $ulush))) as $a) {
            [$nom, $sh, $mah] = $taminot[mt_rand(0, count($taminot) - 1)];
            $add(mol_kun_pick($kunBank), 'bank', 'expense', "Yetkazib beruvchilarga to'lov", "$nom — $mah uchun to'lov (hisob-faktura №$sh/" . mt_rand(100, 999) . ")", $a);
        }
        $add($kunBank[min($nb - 1, 4)], 'bank', 'expense', 'Ish haqi', "Xodimlar mehnat haqi — avans", mol_r(mol_u(118e6, 128e6), 1000));
        if ($ulush >= 1.0) $add($kunBank[$nb - 1], 'bank', 'expense', 'Ish haqi', "Xodimlar mehnat haqi — yakuniy hisob-kitob", mol_r(mol_u(205e6, 224e6), 1000));
        $add($kunBank[min($nb - 1, 1)], 'bank', 'expense', "Ijara to'lovlari", "Ombor ijarasi (shartnoma №21/24)", mol_r(mol_u(22e6, 23e6), 1000));
        $add($kunBank[min($nb - 1, 1)], 'bank', 'expense', "Ijara to'lovlari", "Ofis ijarasi (shartnoma №7/23)", mol_r(mol_u(19e6, 19.8e6), 1000));
        $add(mol_kun_pick($kunBank), 'bank', 'expense', 'Kommunal xarajatlar', "Elektr, gaz va suv uchun to'lov", mol_r(mol_u(11e6, 17e6), 1000));
        $add(mol_kun_pick($kunBank), 'bank', 'expense', "Soliqlar va majburiy to'lovlar", "QQS to'lovi", mol_r($R * 1e6 * mol_u(0.035, 0.05), 1000));
        $add(mol_kun_pick($kunBank), 'bank', 'expense', "Soliqlar va majburiy to'lovlar", "Ijtimoiy soliq va jismoniy shaxslar daromad solig'i", mol_r(mol_u(48e6, 56e6), 1000));
        foreach (mol_bol(mol_r(mol_u(60e6, 105e6) * $ulush), max(1, (int)round(4 * $ulush))) as $a) $add(mol_kun_pick($kunBank), 'bank', 'expense', 'Transport va logistika', "Yuk tashish xizmati (Mehr Logistika)", $a);
        $add(mol_kun_pick($kunBank), 'bank', 'expense', 'Marketing va reklama', "Reklama va aksiya materiallari", mol_r(mol_u(12e6, 34e6), 1000));
        $add(mol_kun_pick($kunBank), 'bank', 'expense', 'Bank xizmatlari va komissiyalar', "Bank komissiyasi", mol_r(mol_u(3.5e6, 6.5e6), 1000));
        if ($ulush > 0.5) {
            $add($kunBank[min($nb - 1, 3)], 'bank', 'expense', "Kredit to'lovi", "Kredit liniyasi — asosiy qarz to'lovi", 60000000);
            $add($kunBank[min($nb - 1, 3)], 'bank', 'expense', "Kredit to'lovi", "Kredit liniyasi — foizlar", mol_r(mol_u(27e6, 30e6), 1000));
        }
        if (mt_rand(0, 3) === 0) $add(mol_kun_pick($kunBank), 'bank', 'expense', 'Boshqa', "Ofis jihozlari xaridi", mol_r(mol_u(4e6, 12e6), 1000));
        // --- kassa: kunlik naqd tushum, haftalik inkassatsiya
        foreach ($kunKassa as $i => $d) {
            $a = mol_r(mol_u(5.5e6, 14.5e6), 1000);
            $add($d, 'kassa', 'income', 'Chakana savdodan naqd tushum', "Kunlik naqd tushum (do'kon kassasi)", $a);
            $kassa += $a;
            if (mt_rand(0, 4) === 0) { $x = mol_r(mol_u(0.4e6, 2.8e6), 1000); $add($d, 'kassa', 'expense', "Ofis va xo'jalik xarajatlari", "Xo'jalik mollari, mayda xarajatlar", $x); $kassa -= $x; }
            if ((int)date('N', strtotime($d)) === 5) {
                $inkas = mol_r(max(0, $kassa - 12e6), 1000);
                if ($inkas > 0) {
                    $add($d, 'kassa', 'expense', 'Inkassatsiya', "Kassadan bankka topshirildi", $inkas);
                    $add($d, 'bank', 'income', 'Mahsulot sotuvidan tushum', "Naqd tushum — kassadan topshirildi", $inkas);
                    $kassa -= $inkas;
                }
            }
        }
    }
    // depozit (ichki harakat): 6 oy oldin joylandi, 2 oy oldin qisman qaytarildi
    $d1 = mol_oy(-6) . '-12'; $d2 = mol_oy(-2) . '-09';
    if ($d1 < $bugun) $add($d1, 'bank', 'expense', 'Depozitga joylash', "Muddatli depozit №D-114 ga joylashtirildi", 600000000);
    if ($d2 < $bugun) $add($d2, 'bank', 'income', 'Depozit qaytarildi', "Depozit №D-114 dan qisman qaytarildi", 250000000);
    usort($tx, fn($a, $b) => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);
    $run = 0; $min = 0; $kRun = 0; $kMin = 0;
    foreach ($tx as $t) {
        $s = $t['direction'] === 'income' ? 1 : -1;
        if ($t['account'] === 'bank') { $run += $s * $t['amount']; $min = min($min, $run); } else { $kRun += $s * $t['amount']; $kMin = min($kMin, $kRun); }
    }
    $obBank = mol_r(-$min + 700e6, 1000000); $obKassa = mol_r(-$kMin + 16e6, 1000);
    $bankFinal = $obBank + $run;
    $dep = 350000000; $joriy = $bankFinal - $dep;
    $p1 = mol_r($joriy * 0.52, 1000); $p2 = mol_r($joriy * 0.27, 1000); $p3 = mol_r($joriy * 0.13, 1000); $p4 = $joriy - $p1 - $p2 - $p3;
    $qoldiq = [
        ['id' => mol_id('q'), 'nomi' => "Asosiy hisob raqam 2020 8000 4001 2345 6001 (UZS)", 'summa' => $p1],
        ['id' => mol_id('q'), 'nomi' => "Soliq to'lovlari hisob raqami 2020 8000 7001 2345 6002", 'summa' => $p2],
        ['id' => mol_id('q'), 'nomi' => "Valyuta hisob raqami 2020 8840 1001 2345 6003 (USD, so'm ekvivalenti)", 'summa' => $p3],
        ['id' => mol_id('q'), 'nomi' => "Akkreditiv hisob raqami 2020 8000 9001 2345 6004", 'summa' => $p4],
        ['id' => mol_id('q'), 'nomi' => "Depozit №D-114 (muddatli)", 'summa' => $dep],
    ];
    $tovarQ = [
        ['id' => mol_id('t'), 'nomi' => "Ulgurji ombordagi tovar qoldig'i", 'summa' => 3142000000],
        ['id' => mol_id('t'), 'nomi' => "Chakana do'kon ombori", 'summa' => 486000000],
    ];
    $kat = [
        'kassa' => ['income' => ['Chakana savdodan naqd tushum', 'Boshqa daromadlar'],
                    'expense' => ["Ofis va xo'jalik xarajatlari", 'Inkassatsiya', 'Transport xarajatlari', 'Xizmat safari xarajatlari', 'Boshqa']],
        'bank' => ['income' => ['Mahsulot sotuvidan tushum', 'Xaridorlar avansi', 'Depozit foizlari', 'Depozit qaytarildi', 'Kredit tushumi', 'Boshqa daromadlar'],
                   'expense' => ["Yetkazib beruvchilarga to'lov", 'Ish haqi', "Ijara to'lovlari", 'Kommunal xarajatlar', "Soliqlar va majburiy to'lovlar",
                       'Transport va logistika', 'Marketing va reklama', 'Bank xizmatlari va komissiyalar', "Kredit to'lovi", 'Depozitga joylash', 'Boshqa']],
    ];
    return ['cashflow' => ['openingBalances' => ['kassa' => $obKassa, 'bank' => $obBank], 'transactions' => $tx,
        'qoldiqlar' => $qoldiq, 'qoldiqSana' => $bugun, 'tovarQoldiq' => $tovarQ],
        'cfCategories' => $kat, 'cfIchki' => ['Depozitga joylash', 'Depozit qaytarildi']];
}

function mol_cashflow_agro(): array {
    $bugun = date('Y-m-d');
    $tx = [];
    $add = function (string $d, string $dir, string $cat, string $desc, int $amt) use (&$tx) {
        if ($amt <= 0) return;
        $tx[] = ['id' => mol_id('t'), 'date' => $d, 'account' => 'bank', 'direction' => $dir, 'category' => $cat, 'desc' => $desc, 'loyihaId' => null, 'amount' => $amt];
    };
    $sezon = [1 => 0.55, 2 => 0.6, 3 => 0.8, 4 => 1.0, 5 => 1.15, 6 => 1.2, 7 => 1.05, 8 => 0.85, 9 => 0.9, 10 => 1.15, 11 => 1.3, 12 => 1.25];
    $xar = [["Mevazor Ulgurji bozori", 'A-11'], ["Sabzavot Trans MChJ", 'A-23'], ["Gulbahor Eksport", 'A-31'], ["Poliz Savdo Group", 'A-17']];
    for ($o = -8; $o <= 0; $o++) {
        $ym = mol_oy($o); $kun = mol_kunlar($ym, $bugun);
        if (!$kun) continue;
        $nk = count($kun);
        $u = min(1.0, $nk / 22.0); $s = $sezon[(int)substr($ym, 5, 2)];
        $tushum = mol_r(900e6 * $s * $u * mol_u(0.9, 1.1), 1000);
        foreach (mol_bol($tushum, max(2, (int)round(9 * $u))) as $a) { [$n, $sh] = $xar[mt_rand(0, 3)]; $add(mol_kun_pick($kun), 'income', 'Mahsulot sotuvidan tushum', "$n — №$sh shartnoma bo'yicha to'lov (pomidor, bodring)", $a); }
        $add($kun[min($nk - 1, 4)], 'expense', 'Ish haqi', "Brigadalar mehnat haqi — avans", mol_r(mol_u(90e6, 100e6), 1000));
        if ($u >= 1.0) $add($kun[$nk - 1], 'expense', 'Ish haqi', "Brigadalar mehnat haqi — yakuniy hisob-kitob", mol_r(mol_u(150e6, 170e6), 1000));
        $add(mol_kun_pick($kun), 'expense', "Urug' va ko'chat", "Ko'chat va urug'lik xaridi", mol_r(mol_u(25e6, 120e6) * $s, 1000));
        $add(mol_kun_pick($kun), 'expense', "O'g'it va himoya vositalari", "Mineral o'g'it va pestitsidlar", mol_r(mol_u(40e6, 95e6) * $s, 1000));
        $add(mol_kun_pick($kun), 'expense', 'Gaz va elektr energiyasi', "Issiqxona isitish uchun gaz va elektr", mol_r(mol_u(120e6, 230e6) * (2.0 - $s * 0.9), 1000));
        $add(mol_kun_pick($kun), 'expense', 'Qadoqlash va transport', "Yashik, qadoq va yuk tashish", mol_r(mol_u(30e6, 70e6) * $s, 1000));
        $add(mol_kun_pick($kun), 'expense', "Soliqlar va majburiy to'lovlar", "Yer solig'i va ijtimoiy soliq", mol_r(mol_u(28e6, 40e6), 1000));
        if ($u > 0.5) $add($kun[min($nk - 1, 3)], 'expense', "Kredit to'lovi", "Agro-kredit bo'yicha to'lov", 45000000);
        if ($o === -6) $add($kun[0], 'income', 'Kredit tushumi', "Agro-kredit (mavsumiy) tushumi", 500000000);
    }
    usort($tx, fn($a, $b) => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);
    $run = 0; $min = 0;
    foreach ($tx as $t) { $run += ($t['direction'] === 'income' ? 1 : -1) * $t['amount']; $min = min($min, $run); }
    $ob = mol_r(-$min + 80e6, 1000000);
    return ['cashflow' => ['openingBalances' => ['kassa' => 0, 'bank' => $ob], 'transactions' => $tx, 'qoldiqlar' => [
            ['id' => mol_id('q'), 'nomi' => 'Asosiy hisob raqam 2020 8000 5002 7777 1001 (UZS)', 'summa' => $ob + $run]], 'qoldiqSana' => $bugun, 'tovarQoldiq' => []],
        'cfCategories' => ['kassa' => ['income' => [], 'expense' => []],
            'bank' => ['income' => ['Mahsulot sotuvidan tushum', 'Kredit tushumi', 'Boshqa daromadlar'],
                'expense' => ['Ish haqi', "Urug' va ko'chat", "O'g'it va himoya vositalari", 'Gaz va elektr energiyasi', 'Qadoqlash va transport', "Soliqlar va majburiy to'lovlar", "Kredit to'lovi", 'Boshqa']]],
        'cfIchki' => []];
}

/* ---------------------------------------------------------------- Kreditor-Debitor */

/** Bitta reyestr yozuvi (shakl — mijoz yasaydigan debitor/kreditor yozuvi bilan bir xil) */
function mol_qarz_yoz(string $tur, string $kid, array $r, string $bosh, string $bugun): void {
    [$nomi, $inn, $guruh, $sh, $maqsad, $kun, $yak, $holat, $chora] = $r;
    $sud = $r[9] ?? null;
    $debitor = $tur === 'debitor';
    $kunlik = []; $boshQ = 0.0; $yakQ = (float)$yak;
    $kunSoni = max(5, (int)((strtotime($bugun) - strtotime($bosh)) / 86400));
    $sanaOl = fn() => date('Y-m-d', strtotime($bosh) + mt_rand(1, $kunSoni - 1) * 86400);
    if ($holat === 'yopildi') {
        $X = mol_r(mol_u(30e6, 90e6), 1000);
        $boshQ = (float)$X; $yakQ = 0.0;
        $kunlik[] = ['sana' => date('Y-m-d', strtotime('-9 days')), 'rashod' => $debitor ? 0 : $X, 'prihod' => $debitor ? $X : 0];
    } else {
        $delta = 0.0;
        for ($i = 0, $n = mt_rand(2, 3); $i < $n; $i++) {      // qarzni oshiruvchi harakat
            $a = mol_r($yak * mol_u(0.08, 0.2), 1000); $delta += $a;
            $kunlik[] = ['sana' => $sanaOl(), 'rashod' => $debitor ? $a : 0, 'prihod' => $debitor ? 0 : $a];
        }
        for ($i = 0, $n = mt_rand(1, 2); $i < $n; $i++) {      // qarzni kamaytiruvchi harakat (to'lov)
            $a = mol_r($yak * mol_u(0.03, 0.12), 1000); $delta -= $a;
            $kunlik[] = ['sana' => $sanaOl(), 'rashod' => $debitor ? 0 : $a, 'prihod' => $debitor ? $a : 0];
        }
        $boshQ = $yak - $delta;
    }
    usort($kunlik, fn($x, $y) => $x['sana'] <=> $y['sana']);
    $o = ['id' => mol_id('kd'), 'korxonaId' => $kid, 'nomi' => $nomi, 'inn' => $inn, 'guruh' => $guruh, 'buhKod' => $debitor ? '4010' : '6010',
        'shartnoma' => $sh, 'maqsad' => $maqsad, 'qarzSanasi' => date('Y-m-d', strtotime("-$kun days")), 'holat' => $holat,
        'umumiyRashod' => 0, 'umumiyPrihod' => 0, 'kunlik' => $kunlik, 'boshSanasi' => $bosh, 'holatSanasi' => $bugun];
    if ($chora) $o['choralar'] = $chora;
    if ($sud) { $o['sudSana'] = date('Y-m-d', strtotime('-' . $sud['sana'] . ' days')); $o['sudRaqam'] = $sud['raqam']; }
    $o[$debitor ? 'boshDebitor' : 'boshKreditor'] = round($boshQ, 2);
    $o[$debitor ? 'yakuniyDebitor' : 'yakuniyKreditor'] = round($yakQ, 2);
    bw_put($tur, $o['id'], $o);
}

function mol_qarz(string $kid): void {
    $bugun = date('Y-m-d');
    $bosh = date('Y-m-d', strtotime('first day of -3 months'));
    // [nomi, inn, guruh, shartnoma, maqsad, qarz sanasi (necha kun oldin), qoldiq, holat, chora, sud]
    $deb = [
        ["Sarvar Trade MChJ", '305112233', 'Ulgurji xaridorlar', '№112/25 — 14.03.2025', "Oziq-ovqat mahsulotlari yetkazib berish", 34, 412e6, 'yangi', null, null],
        ["Dilnura Market", '305224455', 'Chakana tarmoqlar', '№98/25 — 02.02.2025', "Un va yog' yetkazib berish", 52, 186e6, 'muzokara', "Telefon orqali muzokara olib borildi, to'lov muddati 15 kunga surildi", null],
        ["Fayz Savdo Markazi", '305336677', 'Ulgurji xaridorlar', '№131/25 — 21.05.2025', "Shakar va choy yetkazib berish", 71, 238e6, 'pretenziya', "Pretenziya xati yuborildi (chiqish №84)", null],
        ["Bobur Grand Oziq-ovqat", '305448899', 'Chakana tarmoqlar', '№77/24 — 10.09.2024', "Konserva mahsulotlari", 118, 94e6, 'kelishuv', "To'lov jadvali kelishildi: oyiga 25 mln so'm", null],
        ["Asal Tijorat MChJ", '305559900', 'Ulgurji xaridorlar', '№154/25 — 03.07.2025', "Guruch va yorma", 96, 305e6, 'pretenziya', "Ikki marta rasmiy pretenziya yuborildi", null],
        ["Nur Universal Savdo", '305660011', 'Chakana tarmoqlar', '№60/24 — 18.06.2024', "Xo'jalik mollari", 214, 58e6, 'sud', "Sudga da'vo arizasi berildi", ['sana' => 40, 'raqam' => '2-1004-2602/118']],
        ["Kamalak Supermarket", '305771122', 'Chakana tarmoqlar', '№143/25 — 12.06.2025', "Sut mahsulotlari", 22, 127e6, 'yangi', null, null],
        ["Baraka Ulgurji MChJ", '305882233', 'Ulgurji xaridorlar', '№88/25 — 28.02.2025', "Un, yog', shakar kompleks yetkazib berish", 160, 520e6, 'ijro', "Sud qarori chiqdi, ijro varaqasi taqdim etildi", ['sana' => 95, 'raqam' => '2-1004-2601/77']],
        ["Umid Savdo Plus", '305993344', 'Chakana tarmoqlar', '№119/25 — 09.04.2025', "Qadoqlangan oziq-ovqat", 9, 76e6, 'yangi', null, null],
        ["Yulduz Minimarket tarmog'i", '306004455', 'Chakana tarmoqlar', '№161/26 — 11.01.2026', "Kundalik iste'mol mollari", 63, 142e6, 'muzokara', "Direktor bilan uchrashuv o'tkazildi", null],
        ["Orzu Retail Group", '306115566', 'Ulgurji xaridorlar', '№127/25 — 06.05.2025', "Shirinliklar va ichimliklar", 301, 36e6, 'umidsiz', "Tashkilot faoliyati to'xtatilgan, undirish imkoni yo'q", null],
        ["Shirin Hayot MChJ", '306226677', 'Ulgurji xaridorlar', '№172/26 — 05.03.2026', "Choy va kofe", 14, 265e6, 'yangi', null, null],
        ["Ipak Yo'li Foods (Qozog'iston)", '306337788', 'Xorijiy hamkorlar', '№EX-05/26 — 20.02.2026', "Eksport: quritilgan mevalar", 45, 189e6, 'kelishuv', "Hisob-faktura bo'yicha to'lov jadvali kelishildi", null],
        ["Tong Sharq MChJ", '306448899', 'Ulgurji xaridorlar', '№66/24 — 14.07.2024', "Konserva va sharbatlar", 70, 0, 'yopildi', "Qarz to'liq to'landi", null],
    ];
    $kred = [
        ["Tong Un MChJ", '307111222', 'Mahsulot yetkazib beruvchilar', '№T-41 — 05.01.2025', "Un yetkazib berish", 26, 640e6, 'yangi', null],
        ["Orzu Yog' MChJ", '307222333', 'Mahsulot yetkazib beruvchilar', '№Y-18 — 19.02.2025', "O'simlik yog'i yetkazib berish", 38, 420e6, 'kelishuv', "To'lov jadvali: har oyning 10-sanasi"],
        ["Sifat Shakar Savdo", '307333444', 'Mahsulot yetkazib beruvchilar', '№SH-77 — 11.03.2025', "Shakar yetkazib berish", 17, 355e6, 'yangi', null],
        ["Barkamol Pack", '307444555', 'Mahsulot yetkazib beruvchilar', '№B-09 — 02.04.2025', "Qadoqlash materiallari", 48, 82e6, 'yangi', null],
        ["Sabr Choy Import", '307555666', 'Mahsulot yetkazib beruvchilar', '№C-33 — 15.05.2025', "Choy importi (shartnoma valyutada)", 81, 298e6, 'muzokara', "Kechiktirish bo'yicha muzokara olib borilmoqda"],
        ["Bahor Sut Mahsulotlari", '307666777', 'Mahsulot yetkazib beruvchilar', '№S-52 — 27.05.2025', "Sut mahsulotlari", 12, 114e6, 'yangi', null],
        ["Mehr Logistika", '307777888', "Xizmat ko'rsatuvchilar", '№L-12 — 01.01.2026', "Yuk tashish xizmatlari", 19, 67e6, 'yangi', null],
        ["Ombor Ijara Markazi", '307888999', "Xizmat ko'rsatuvchilar", '№21/24 — 01.03.2024', "Ombor ijarasi", 5, 22e6, 'yangi', null],
        ["Kredit liniyasi (bank)", '307999000', 'Bank va lizing', 'Kredit shartnomasi №KL-2025/118', "Aylanma mablag'ga kredit liniyasi", 1, 680e6, 'kelishuv', "Oylik grafik bo'yicha to'lanmoqda"],
        ["Yuk lizing (avtopark)", '308000111', 'Bank va lizing', 'Lizing shartnomasi №LZ-9/25', "Yuk avtomobili lizingi", 1, 210e6, 'kelishuv', "Oylik lizing to'lovlari grafik bo'yicha"],
        ["Byudjetga soliq majburiyatlari", '308111222', 'Byudjet va fondlar', 'QQS va ijtimoiy soliq', "Joriy davr soliq majburiyatlari", 6, 96e6, 'yangi', null],
        ["Reklama Studio MChJ", '308222333', "Xizmat ko'rsatuvchilar", '№R-7/26 — 12.04.2026', "Reklama kampaniyasi", 33, 41e6, 'pretenziya', "Qarshi tomon hisobi bo'yicha farq aniqlanmoqda"],
        ["Sifat Konserva Zavodi", '308333444', 'Mahsulot yetkazib beruvchilar', '№K-14 — 08.08.2025', "Konserva yetkazib berish", 55, 0, 'yopildi', "To'liq hisob-kitob qilindi"],
    ];
    foreach ($deb as $r) mol_qarz_yoz('debitor', $kid, $r, $bosh, $bugun);
    foreach ($kred as $r) mol_qarz_yoz('kreditor', $kid, $r, $bosh, $bugun);
}

function mol_qarz_agro(string $kid): void {
    $bugun = date('Y-m-d');
    $bosh = date('Y-m-d', strtotime('first day of -3 months'));
    foreach ([
        ['debitor', ['Mevazor Ulgurji bozori', '306100101', 'Ulgurji xaridorlar', '№A-11 — 03.03.2026', 'Sabzavot yetkazib berish', 21, 188e6, 'yangi', null]],
        ['debitor', ['Poliz Savdo Group', '306100202', 'Ulgurji xaridorlar', '№A-17 — 11.04.2026', 'Pomidor va bodring', 47, 96e6, 'muzokara', "To'lov muddati kelishilmoqda"]],
        ['debitor', ['Gulbahor Eksport', '306100303', 'Eksport', '№A-31 — 19.05.2026', 'Eksport partiyasi', 33, 245e6, 'kelishuv', "Bank orqali to'lov jadvali kelishildi"]],
        ['kreditor', ["Urug' Plyus MChJ", '306200101', "Ta'minotchilar", '№U-4 — 02.01.2026', "Ko'chat va urug'lik", 58, 132e6, 'yangi', null]],
        ['kreditor', ['Gaz Servis MChJ', '306200202', 'Kommunal', '№G-9 — 01.01.2026', "Issiqxona uchun gaz ta'minoti", 12, 164e6, 'yangi', null]],
    ] as [$tur, $r]) mol_qarz_yoz($tur, $kid, $r, $bosh, $bugun);
}

/* ---------------------------------------------------------------- Balans */

function mol_balans(string $kid, string $pm, ?array $S = null): void {
    require_once $pm . '/php/balans.php';
    $oylar = []; for ($o = -10; $o <= -1; $o++) $oylar[] = mol_oy($o);
    $davr = fn(string $ym) => ['oy' => $ym, 'dan' => $ym . '-01', 'gacha' => date('Y-m-t', strtotime($ym . '-01'))];
    // [nomi, kod, narx (so'm), boshlang'ich miqdor]
    $tovar = [
        ['Un 1-nav, 50 kg qop', 'T-0101', 285000, 1100], ['Un oliy nav, 25 kg qop', 'T-0102', 166000, 640], ["O'simlik yog'i, 5 l", 'T-0110', 78500, 2400], ["O'simlik yog'i, 1 l", 'T-0111', 17900, 5200],
        ['Shakar, 50 kg qop', 'T-0120', 520000, 540], ['Guruch (lazer), 25 kg', 'T-0131', 385000, 460], ['Makaron, 400 g (quti 20 dona)', 'T-0140', 112000, 1300], ['Grechka, 800 g (quti 12 dona)', 'T-0141', 148000, 420],
        ['Qora choy, 100 g (quti 50 dona)', 'T-0150', 265000, 380], ["Yashil choy, 100 g (quti 50 dona)", 'T-0151', 245000, 260], ['Kofe 3 in 1 (quti 20)', 'T-0160', 96000, 610],
        ['Konserva — pomidor pastasi 0,7 l', 'T-0170', 14800, 7300], ['Konserva — loviya 400 g', 'T-0171', 9100, 6100], ["Sut 3,2%, 1 l (quti 12)", 'T-0180', 98000, 540], ['Qatiq 450 g (quti 15)', 'T-0181', 84000, 310],
        ["Tuz, 1 kg (quti 20)", 'T-0190', 36000, 880], ["Tuxum, 30 dona (quti)", 'T-0200', 62000, 720], ["Sariyog', 200 g (quti 40)", 'T-0210', 410000, 190],
        ["Shirinliklar assorti, 1 kg", 'T-0220', 64500, 1600], ['Biskvit 300 g (quti 12)', 'T-0221', 118000, 430], ['Sharbat 1 l (quti 12)', 'T-0230', 112000, 880], ['Mineral suv 1,5 l (6 dona)', 'T-0231', 21500, 3900],
        ["Quritilgan mevalar, 5 kg", 'T-0240', 395000, 140], ["Yong'oq (mag'iz), 10 kg", 'T-0241', 790000, 95], ['Kir yuvish kukuni 3 kg', 'T-0250', 63000, 1450], ['Idish yuvish vositasi 500 ml', 'T-0251', 14500, 2900],
        ["Tualet qog'ozi (12 rulon)", 'T-0260', 26500, 2300], ['Salfetka (10 dona)', 'T-0261', 22800, 1500], ["Bir martalik idishlar (to'plam)", 'T-0270', 39000, 780], ['Sovun 125 g (quti 72)', 'T-0280', 205000, 240],
    ];
    $xom = [
        ['Karton quti 40x30x25', 'X-1001', 3200, 6500], ['Stretch plyonka 500 mm', 'X-1002', 58000, 220], ['Qadoqlash skotchi 48 mm', 'X-1003', 7800, 1100], ['Polietilen paket 30x40 (1000 dona)', 'X-1004', 96000, 130],
        ["Pallet (yog'och taglik)", 'X-1010', 62000, 380], ['Narx yorliqlari (rulon)', 'X-1011', 11500, 640], ["Dizel yoqilg'isi (l)", 'X-1020', 12900, 4200], ['AI-92 benzin (l)', 'X-1021', 9800, 2600],
    ];
    $inv = [
        ["Ish kiyimi (to'plam)", 'I-5001', 185000, 64], ["Himoya qo'lqoplari (juft)", 'I-5002', 9500, 310], ["Qo'l aravachasi", 'I-5003', 420000, 14], ['Ofis stullari', 'I-5004', 310000, 22],
        ['Kassa cheklari lentasi', 'I-5005', 7200, 540], ["Ombor tarozisi 300 kg", 'I-5006', 2650000, 4], ["Yong'in o'chirgichlar", 'I-5007', 245000, 38], ["Yuk ko'targich (aravachali)", 'I-5008', 1380000, 6],
    ];
    if ($S) { $tovar = $S['tovar']; $xom = $S['xom']; $inv = $S['inv']; }
    $matBlok = function (string $blok, array $items, string $hisob, float $kam) use ($oylar, $davr, $kid) {
        $q = []; $sv = [];
        foreach ($items as $i => $it) { $q[$i] = (float)$it[3]; $sv[$i] = round($it[3] * $it[2], 2); }
        foreach ($oylar as $mi => $ym) {
            $qator = []; $src = 8;
            foreach ($items as $i => $it) {
                [$nomi, $kod, $narx] = $it;
                $b = $q[$i]; $bS = $sv[$i];
                $sez = in_array((int)substr($ym, 5, 2), [11, 12], true) ? 1.12 : 1.0;
                $c = (float)round($b * mol_u(0.78, 1.12) * $kam * $sez);
                $k = (float)round($c * mol_u(0.86, 1.2));
                $o = $b + $k - $c; if ($o < 0) { $k += -$o; $o = 0.0; }
                $nx = $narx * (1 + 0.004 * $mi);
                $kS = round($k * $nx, 2);
                $avg = ($b + $k) > 0 ? ($bS + $kS) / ($b + $k) : 0.0;     // o'rtacha tannarx (zanjir uzilmasligi uchun)
                $cS = round($c * $avg, 2);
                $oS = round($bS + $kS - $cS, 2);
                $qator[] = ['nomi' => $nomi, 'kod' => $kod, 'ombor' => 'Markaziy ombor', 'hisob' => $hisob,
                    'bK' => $b, 'bS' => $bS, 'kK' => $k, 'kS' => $kS,
                    'cK' => $c, 'cS' => $cS, 'oK' => $o, 'oS' => $oS, 'src' => $src++];
                $q[$i] = $o; $sv[$i] = $oS;
            }
            $n = ['tur' => 'material', 'qatorlar' => $qator, 'nazorat' => null, 'ogoh' => [], 'cheklov' => ['birlik']];
            balans_yoz(bal_yakunla($n, $davr($ym), $blok, true), $kid, 'namuna-admin', '', $blok);
        }
    };
    $matBlok('tovar', $tovar, '2910', 1.0);
    $matBlok('xomashyo', $xom, '1010', 0.9);
    $matBlok('inventar', $inv, '0110', 0.25);
    // asosiy vositalar: [nomi, guruh, inventar №, qabul sanasi, qiymat, yillik amortizatsiya %, qaysi oyda sotib olingan (-1 = oldin)]
    $os = [
        ["Ombor binosi (Chirchiq yo'li)", 'Binolar va inshootlar', 'OS-0001', '2021-05-14', 1850000000, 3, -1],
        ["Ofis binosining ta'miri (kapital)", 'Binolar va inshootlar', 'OS-0002', '2023-09-01', 240000000, 5, -1],
        ["Yuk avtomobili 5 t (UZ 01 A 712 BA)", 'Transport vositalari', 'OS-0010', '2022-03-20', 420000000, 12, -1],
        ["Yuk avtomobili 3 t (UZ 01 B 245 CA)", 'Transport vositalari', 'OS-0011', '2023-02-11', 310000000, 12, -1],
        ["Yengil avtomobil (xizmat)", 'Transport vositalari', 'OS-0012', '2024-06-03', 215000000, 12, -1],
        ["Yuk avtomobili 10 t (lizingdan)", 'Transport vositalari', 'OS-0013', '2025-04-08', 640000000, 12, -1],
        ['Yuklagich (forklift) 2,5 t', 'Mashina va uskunalar', 'OS-0020', '2022-07-18', 195000000, 10, -1],
        ['Sovutgich kamera (30 m3)', 'Mashina va uskunalar', 'OS-0021', '2023-11-27', 128000000, 10, -1],
        ['Ombor stellajlari (komplekt)', 'Mashina va uskunalar', 'OS-0022', '2021-06-02', 96000000, 10, -1],
        ['Qadoqlash liniyasi', 'Mashina va uskunalar', 'OS-0023', '2024-02-15', 172000000, 10, -1],
        ['Server va kompyuter jihozlari', 'Ofis jihozlari', 'OS-0030', '2024-09-09', 64000000, 20, -1],
        ['Ofis mebeli', 'Ofis jihozlari', 'OS-0031', '2022-01-12', 38000000, 10, -1],
        ['Kassa apparatlari (5 dona)', 'Ofis jihozlari', 'OS-0032', '2023-05-23', 27000000, 20, -1],
        ["Skaner va shtrix-kod jihozlari", 'Ofis jihozlari', 'OS-0033', $oylar[4] . '-10', 54000000, 20, 4],
    ];
    if ($S) $os = $S['os'];
    $stoim = []; $amortT = [];
    foreach ($os as $i => $a) {
        $oyOtgan = max(0, (int)((strtotime($oylar[0] . '-01') - strtotime($a[3])) / (86400 * 30.4)));
        $stoim[$i] = ($a[6] >= 0) ? 0.0 : (float)$a[4];
        $amortT[$i] = ($a[6] >= 0) ? 0.0 : round($a[4] * $a[5] / 100 / 12 * $oyOtgan, 2);
    }
    foreach ($oylar as $mi => $ym) {
        $qator = []; $src = 8;
        foreach ($os as $i => $a) {
            if ($a[6] >= 0 && $mi < $a[6]) continue;
            $osish = ($a[6] >= 0 && $a[6] === $mi) ? (float)$a[4] : 0.0;
            $bS = $stoim[$i]; $bA = $amortT[$i];
            $am = round($a[4] * $a[5] / 100 / 12, 2);
            $oS = $bS + $osish; $oA = $bA + $am;
            if ($oA > $oS) { $am = max(0.0, $oS - $bA); $oA = $bA + $am; }
            $qator[] = ['nomi' => $a[0], 'inv' => $a[2], 'guruh' => $a[1], 'sana' => $a[3], 'qiymat' => (float)$a[4], 'foiz' => (float)$a[5],
                'bStoim' => $bS, 'bAmort' => $bA, 'bQoldiq' => round($bS - $bA, 2), 'osish' => $osish, 'amort' => $am, 'kamayish' => 0.0, 'chiqim' => 0.0,
                'oStoim' => $oS, 'oAmort' => round($oA, 2), 'oQoldiq' => round($oS - $oA, 2), 'src' => $src++];
            $stoim[$i] = $oS; $amortT[$i] = round($oA, 2);
        }
        $n = ['tur' => 'os', 'qatorlar' => $qator, 'nazorat' => null, 'ogoh' => [], 'cheklov' => ['muddat', 'usul']];
        balans_yoz(bal_yakunla($n, $davr($ym), 'asosiy', true), $kid, 'namuna-admin', '', 'asosiy');
    }
}

/** Yashil Vodiy Agro uchun balans qatorlari (issiqxona xo'jaligi) */
function mol_balans_agro(): array {
    return [
        'tovar' => [
            ['Pomidor, 1-nav (yashik 6 kg)', 'T-2001', 58000, 1400], ['Pomidor "Cherri" (yashik 3 kg)', 'T-2002', 64000, 520], ['Pomidor, 2-nav (yashik 6 kg)', 'T-2003', 39000, 880],
            ['Bodring, 1-nav (yashik 8 kg)', 'T-2010', 46000, 1100], ['Bodring "Kornishon" (yashik 5 kg)', 'T-2011', 52000, 430], ["Qalampir bolgar (yashik 5 kg)", 'T-2020', 71000, 260],
            ['Baqlajon (yashik 8 kg)', 'T-2021', 44000, 210], ["Ko'kat assorti (quti 2 kg)", 'T-2030', 38000, 300], ["Ko'chat — pomidor (dona)", 'T-2040', 3200, 9000], ["Ko'chat — bodring (dona)", 'T-2041', 2900, 7000],
        ],
        'xom' => [
            ["Pomidor urug'i F1 (paket 1000 dona)", 'X-3001', 1450000, 38], ["Bodring urug'i F1 (paket 1000 dona)", 'X-3002', 980000, 45], ['Torf substrati (qop 50 l)', 'X-3010', 42000, 1600],
            ["Mineral o'g'it NPK, 50 kg", 'X-3020', 385000, 140], ['Kaliy nitrat, 25 kg', 'X-3021', 310000, 90], ['Pestitsid (kontakt), 1 l', 'X-3030', 165000, 120],
            ['Biologik vosita, 1 kg', 'X-3031', 210000, 60], ['Issiqxona plyonkasi UV (rulon)', 'X-3040', 4900000, 16], ["Tomchilatib sug'orish shlangi (rulon 500 m)", 'X-3041', 1250000, 24],
            ['Plastik yashik 6 kg', 'X-3050', 9500, 5200],
        ],
        'inv' => [
            ["Ish kiyimi (to'plam)", 'I-6001', 185000, 70], ['Rezina etik (juft)', 'I-6002', 96000, 110], ['Bog\' qaychisi (sekator)', 'I-6003', 68000, 90], ["Sug'orish nasosi 1,5 kVt", 'I-6004', 2150000, 8],
            ["Qo'l aravachasi", 'I-6005', 420000, 12], ['Himoya niqobi (respirator)', 'I-6006', 38000, 160], ['Tarozi 150 kg', 'I-6007', 1250000, 5],
        ],
        'os' => [
            ['Issiqxona №1 (metall karkas, 1 ga)', 'Binolar va inshootlar', 'OS-1001', '2021-03-15', 2850000000, 5, -1],
            ['Issiqxona №2 (0,6 ga)', 'Binolar va inshootlar', 'OS-1002', '2022-04-20', 1680000000, 5, -1],
            ['Ombor va qadoqlash sexi', 'Binolar va inshootlar', 'OS-1003', '2022-08-10', 520000000, 3, -1],
            ['Quduq va nasos stansiyasi', 'Binolar va inshootlar', 'OS-1004', '2021-02-01', 190000000, 5, -1],
            ['Ofis va maishiy xona (blok-konteyner)', 'Binolar va inshootlar', 'OS-1005', '2023-10-05', 86000000, 5, -1],
            ['Isitish qozonxonasi', 'Mashina va uskunalar', 'OS-1010', '2021-03-15', 640000000, 10, -1],
            ["Tomchilatib sug'orish tizimi", 'Mashina va uskunalar', 'OS-1011', '2022-04-20', 310000000, 10, -1],
            ['Sovutgich kamera (20 m3)', 'Mashina va uskunalar', 'OS-1012', '2023-06-12', 145000000, 10, -1],
            ['Avtomatik iqlim nazorati tizimi', 'Mashina va uskunalar', 'OS-1013', mol_oy(-6) . '-10', 215000000, 10, 4],
            ['Mini-traktor (30 ot kuchi)', 'Transport vositalari', 'OS-1020', '2023-03-02', 280000000, 12, -1],
            ['Yuk avtomobili 3 t', 'Transport vositalari', 'OS-1021', '2024-05-18', 305000000, 12, -1],
        ],
    ];
}

/* ---------------------------------------------------------------- kirish nuqtasi */

function biwork_seed(array $ctx): void {
    bw_seed_rng(20261020);
    $pm = $ctx['pm'];
    require_once $pm . '/php/bp-moliya.php';
    $bp = mol_bp([2350, 2300, 2780, 2950, 2700, 2650, 2820, 2900, 3050, 3180, 3350, 3820],
        [2700, 2650, 3200, 3380, 3120, 3060, 3250, 3340, 3500, 3640, 3850, 4350],
        [0.978, 1.030, 1.034, 0.973, 1.022, 0.984, 1.052, 0.976, 1.033, 1.018, 1.041, 1.036], 1.0, 0.17, 27940.0);
    $rev = $bp['_rev']; unset($bp['_rev']);
    $yil = (int)$bp['yil'];
    $plan = [];
    for ($m = 1; $m <= 12; $m++) $plan[$m - 1] = (float)($bp['oylar'][sprintf('%04d-%02d', $yil, $m)]['reja']['tushum'] ?? 3000);

    $k1 = 'k_navroz';
    $cf = mol_cashflow_navro($rev, $plan);
    bw_put('korxona', $k1, array_merge([
        'id' => $k1, 'tartib' => 1, 'nomi' => "Navro'z Savdo Group", 'rasmiyNomi' => "«Navro'z Savdo Group» MChJ",
        'tavsif' => "Oziq-ovqat va xo'jalik mollari ulgurji va chakana savdosi", 'rahbar' => 'Sardor Karimov', 'rahbarLavozim' => 'Direktor',
        'stir' => '305118902', 'yuridikManzil' => "Toshkent sh., Chilonzor tumani, Bunyodkor shoh ko'chasi, 48-uy",
        'telefon' => '+998 71 200 14 08', 'email' => 'info@navroz-savdo.uz',
        'bank' => "«Ziyo Moliya Bank» ATB Chilonzor filiali, h/r 2020 8000 4001 2345 6001, MFO 00412",
        'rekvizitQatori' => "STIR 305118902 · h/r 2020 8000 4001 2345 6001 · «Ziyo Moliya Bank» ATB, MFO 00412",
        'kassaYoq' => false, 'qoldiqYuritish' => true, 'biznesAktiv' => false,
    ], $cf));
    mol_qarz($k1);
    bpm_yoz($bp, $k1, 'namuna-admin', '');       // biznes reja (bp_moliya jadvali)
    mol_balans($k1, $pm);                        // balans (balans jadvali)

    $k2 = 'k_vodiy';
    $cf2 = mol_cashflow_agro();
    bw_put('korxona', $k2, array_merge([
        'id' => $k2, 'tartib' => 2, 'nomi' => 'Yashil Vodiy Agro', 'rasmiyNomi' => "«Yashil Vodiy Agro» MChJ",
        'tavsif' => "Issiqxona sabzavotchiligi: pomidor va bodring yetishtirish", 'rahbar' => 'Dilshod Rahimov', 'rahbarLavozim' => 'Rahbar',
        'stir' => '306774215', 'yuridikManzil' => "Toshkent viloyati, Zangiota tumani, Kuyganyor QFY",
        'telefon' => '+998 90 311 27 45', 'email' => 'agro@yashilvodiy.uz',
        'bank' => "«Ziyo Moliya Bank» ATB Zangiota filiali, h/r 2020 8000 5002 7777 1001, MFO 00655",
        'rekvizitQatori' => "STIR 306774215 · h/r 2020 8000 5002 7777 1001 · «Ziyo Moliya Bank» ATB, MFO 00655",
        'kassaYoq' => true, 'qoldiqYuritish' => true, 'biznesAktiv' => false,
    ], $cf2));
    mol_qarz_agro($k2);
    $bp2 = mol_bp([430, 470, 625, 780, 900, 940, 820, 665, 705, 900, 1020, 980],
        [495, 540, 720, 900, 1035, 1080, 945, 765, 810, 1035, 1170, 1125],
        [1.020, 0.972, 1.041, 1.028, 0.981, 1.052, 0.963, 1.030, 1.018, 1.036, 1.025, 1.010], 0.4, 0.30, 8400.0);
    unset($bp2['_rev']);
    bpm_yoz($bp2, $k2, 'namuna-admin', '');
    mol_balans($k2, $pm, mol_balans_agro());
}
