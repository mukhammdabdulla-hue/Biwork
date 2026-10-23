<?php
declare(strict_types=1);
/**
 * Biwork Loyiha — namuna ma'lumoti (BUTUNLAY XAYOLIY: korxona, odamlar, raqamlar).
 *
 * Mazmun: bitta xayoliy korxona (Yulduz Invest Group), 24 xodim (kontakt + inert hisob),
 * 11 ta loyiha (bosqich, vazifa, risk, pasport, hujjat talablari), g'oyalar taxtasi, topshiriqlar,
 * ish kundaligi yozuvlari va hisobot hujjati.
 *
 * DEMO FOYDALANUVCHI: portaldan kirgan odamning PM-dagi logini oldindan noma'lum (bw-<mijoz>-<id>).
 * Shuning uchun ma'lumotda uning o'rniga `bw-demo-me` belgisi turadi; users jadvaliga birinchi `bw-%`
 * hisob qo'shilganda SQLite triggeri belgini haqiqiy login bilan almashtiradi (yozuvlar va xabarlar).
 * Natijada demo foydalanuvchida «Men berganlarim», «Menga berilgan», g'oya qarori va xabarlar to'la ko'rinadi.
 */
function biwork_seed(array $ctx): void {
    bw_seed_rng();
    $pdo = db();
    $ME = 'bw-demo-me';
    $KID = 'yulduz';
    $KORX = 'Yulduz Invest Group';

    /* ------------------------------------------------------------------ yordamchilar */
    /** Bugundan $d kun oldingi (musbat) / keyingi (manfiy) sana, mahalliy soat bilan ISO (UTC). */
    $iso = function (int $d, int $h = 10, int $mi = 15) {
        return gmdate('Y-m-d\TH:i:s.000\Z', strtotime(bw_date($d) . sprintf(' %02d:%02d:00 +0500', $h, $mi)));
    };
    $put = function (string $tur, string $id, array $data) use ($ctx) {
        $data['id'] = $id;
        entity_yoz($tur, $id, $data, (string)$ctx['kim']);
    };
    $hex = function () {
        return sprintf('%08x%08x%08x%08x', mt_rand(), mt_rand(), mt_rand(), mt_rand());
    };
    $pdfMk = function (string $title, array $lines): string {
        $esc = fn($s) => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
        $c = "BT /F1 16 Tf 56 780 Td (" . $esc($title) . ") Tj ET\n";
        $y = 750;
        foreach ($lines as $l) { $c .= "BT /F1 11 Tf 56 $y Td (" . $esc($l) . ") Tj ET\n"; $y -= 18; }
        $objs = ["<< /Type /Catalog /Pages 2 0 R >>", "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
            "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>",
            "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
            "<< /Length " . strlen($c) . " >>\nstream\n" . $c . "endstream"];
        $out = "%PDF-1.4\n"; $off = [];
        foreach ($objs as $i => $o) { $off[] = strlen($out); $out .= ($i + 1) . " 0 obj\n$o\nendobj\n"; }
        $x = strlen($out);
        $out .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($off as $o) $out .= sprintf("%010d 00000 n \n", $o);
        return $out . "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n$x\n%%EOF\n";
    };
    /** Haqiqiy (kichik PDF) fayl yozadi + reyestrga qo'shadi; yozuvga qo'yiladigan meta qaytaradi. */
    $fayl = function (string $nom, string $egaTur, string $egaKod, string $kim, int $ago, array $lines) use ($pdo, $iso, $hex, $pdfMk) {
        $id = $hex();
        $stored = $id . '.pdf';
        $dir = UPLOAD_DIR . '/' . $egaTur . '/' . $egaKod;
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $data = $pdfMk(preg_replace('/\.pdf$/i', '', $nom), $lines);
        file_put_contents($dir . '/' . $stored, $data);
        $up = $iso($ago, 11, 20);
        $pdo->prepare('INSERT OR REPLACE INTO uploads(id, stored_name, rel_path, original_name, ext, size, uploaded_at, uploaded_by, ega_tur, ega_kod) VALUES(?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $stored, $egaTur . '/' . $egaKod . '/' . $stored, $nom, 'pdf', strlen($data), $up, $kim, $egaTur, $egaKod]);
        return ['id' => $id, 'storedName' => $stored, 'originalName' => $nom, 'size' => strlen($data), 'uploadedAt' => $up, 'uploadedBy' => $kim];
    };

    /* ------------------------------------------------------------------ korxona */
    $bolimlar = ['Rahbariyat', 'Loyihalar ofisi (PMO)', 'Texnik departament', 'Qurilish', 'IT', 'Moliya', 'Marketing', "Ta'minot",
        'Logistika', 'Yuridik', 'Sifat nazorati', 'Kadrlar', 'Ishlab chiqarish', 'Energetika'];
    // Xayoliy korxona logotipi: PDF hisobotlar blankasi va tepa tanlagich shu bilan chiqadi (aks holda PM o'zining standart blankasini qo'yadi)
    $brendDir = DATA_DIR . '/brend';
    if (!is_dir($brendDir)) @mkdir($brendDir, 0775, true);
    file_put_contents($brendDir . '/' . $KID . '.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAMAAAADACAYAAABS3GwHAAAEQElEQVR42u3dzW0TURiFYc+VxYYW0kRaoBKKYMuKLUVQCS2kibTABkUKK5QIbCf23Jn7c553CUJkxuf9vnPtTHI4AAAAAAAAYH6Wnr6Y4939s5ckg6fHhyVeAIFHayEWoUeyDIvQI1mGIvzoma3zswg+krdBEX4kb4NF8JG8DYrwI3kbFOFHsgRF+JEsQRF+JEtQhB/JEhThR7IExW1DMmUPy4Bet0ARfiRLoAJBBTL9kboFivAjWQIVCCoQQAD1B4E1yAaADQAQQP1BYA2yAWADAAQACKD/I+scYAPABgAIABAAIABAAIAA2JwP3z65CQQACBA7/W0BAgAE0P1BAEKAAAAB4qa9LUAAgAC6PghAEBAAIIDpDgIQBQQQahCAMCAAQADTHAQgDggAEMAUBwEIBAIILwgAEMAmAQGEFgQgFAggrCAAQACbBQQQUgK4BSAAhpn+NgwBAALYNCCAUIIAAAHipr+NQwBgNcu5vzje3T+b2Fn8/vpz6ut7enxYhhNAWEm1pQDFDUTyazdUBbINBD9uA9gGwr8lxQ1G8msz9LtAKpHgR1Ug28BrEF+BSODeq0AqkeBXqkDTfRI8igS/Pn//788+/vgi/ASYW4JTwR9FhNErT4wAPYrwnuD3KsIs56zp3gUa5YW7Jfxr/p3wv5/pvx3au0TuXbQArV/ItVO81RZIGRzHtGnmrVLBj9sArV7gWtN7ry2QWBcjH4l0LnBP4iqQSiT4NoAAuHYCCILwvxD5UyEuUbsS1TjA1vxEODn4cZ8EC4jwq0CNg7J2etea/sJ/pum4BZcDM/q7RIJvAzQP0K1TfO30F34boBv+hnnk5wFmxLtAb7BVBdrriTBb4IVT7wLZAI03ApwBAAIk1R/XQACAAAABAALozq6FAAABAAKoDK6JAAABAAKoCq6NAAABgLp4HqBhRXj9vfp7/t+pzwj4qRAd8W8IPbjSBg/ENA7+qb9zWHUGiAu/beAMMG3/XxPorb6uRMmcARpM/LVBsw1UoKnrzl4iQQXapWZsHdSRvlYVyNTvslaBAMOHkgQqUPNK0UsIZ7gGFUjdUYtUIHVHLVKBuq0OIwXsmmtLEUcFCqoYtoEKFB8kZwMCCI9t4AxwTUeePSyp1+0MYFLaBqcGvVuQFwoP3oRvgNcvfPJEbPVMsg1g6tsGDsGAQzDgDAAQACAAQACAAAABAAIABAAIAOwmwKmPjYFROZdnGwA2AEAAgADOAcjp/zYAbAC3AARQgxBYf2wA2ABuAQigBiGw/ly1AUiA2cKvAkEF2sIqYITpf9MGIAFmCb8KBBVoD8uAHqf/qg1AAowe/tUViAQYOfxVzgAkwKjhr3YIJgFGDH81AUiAEcN/OFz4BRlr8Ms10Hvwq28A2wCjhX+zDWAbYJSBWkb+4iH83W8AGwE9D82m05kMaN0UuqonhBB4AAAAAACwEX8Aalwd6Nh1YI4AAAAASUVORK5CYII='));
    $put('korxona', $KID, [
        'tartib' => 1, 'nomi' => $KORX, 'rasmiyNomi' => $KORX . ' MChJ', 'rahbar' => 'Rahimov Jasur Anvarovich',
        'tavsif' => "Qurilish, texnologiya va ishlab chiqarish yo'nalishlaridagi xayoliy investitsiya guruhi (namuna).",
        'kassaYoq' => true, 'logoV' => 1, 'qoldiqYuritish' => false, 'biznesAktiv' => false, 'savdoAktiv' => false,
        'bolimlar' => $bolimlar,
        'cashflow' => ['openingBalances' => ['kassa' => 0, 'bank' => 0], 'transactions' => []],
    ]);

    /* ------------------------------------------------------------------ xodimlar (kontakt + inert hisob) */
    // slug => [familiya, ismi, sharifi, bo'lim, lavozim, rol, telefon(9), tug'ilgan, ishga kirgan]
    $S = [
        'jasur'   => ['Rahimov', 'Jasur', 'Anvarovich', 'Rahbariyat', 'Bosh direktor', 'rahbar', '901120101', '1981-04-12', '2016-02-01'],
        'dilnoza' => ['Yusupova', 'Dilnoza', 'Karimovna', 'Loyihalar ofisi (PMO)', 'PMO rahbari', 'rahbar', '931120102', '1985-09-03', '2018-05-14'],
        'otabek'  => ['Tursunov', 'Otabek', 'Rustamovich', 'Moliya', 'Moliya direktori', 'rahbar', '941120103', '1983-01-27', '2017-03-06'],
        'farrux'  => ['Ergashev', 'Farrux', 'Abdullayevich', 'Texnik departament', 'Texnik direktor', 'rahbar', '971120104', '1980-11-09', '2016-09-12'],
        'madina'  => ['Nazarova', 'Madina', 'Shavkatovna', 'Loyihalar ofisi (PMO)', 'Katta loyiha menejeri', 'user', '901120105', '1990-06-21', '2019-08-19'],
        'bobur'   => ['Saidov', 'Bobur', 'Ikromovich', 'IT', 'IT loyihalar menejeri', 'user', '911120106', '1988-02-14', '2019-01-21'],
        'nigora'  => ['Qodirova', 'Nigora', 'Sobirovna', 'Qurilish', 'Qurilish loyihalari menejeri', 'user', '931120107', '1987-07-30', '2018-10-01'],
        'sardor'  => ['Abdullayev', 'Sardor', 'Hamidovich', 'Qurilish', 'Bosh muhandis', 'user', '941120108', '1984-03-18', '2017-06-05'],
        'malika'  => ['Mirzayeva', 'Malika', 'Dilshodovna', 'Marketing', "Marketing boshlig'i", 'user', '971120109', '1991-12-05', '2020-02-10'],
        'dilshod' => ['Hasanov', 'Dilshod', 'Rahimjonovich', 'IT', 'Backend dasturchi', 'user', '901120110', '1993-05-11', '2020-09-14'],
        'sevara'  => ['Ismoilova', 'Sevara', 'Aliyevna', 'IT', 'Tizim tahlilchisi', 'user', '991120111', '1994-08-26', '2021-03-01'],
        'akmal'   => ['Olimov', 'Akmal', 'Nodirovich', "Ta'minot", "Ta'minot boshlig'i", 'user', '911120112', '1986-10-02', '2017-11-20'],
        'timur'   => ['Sobirov', 'Timur', 'Ravshanovich', 'Logistika', 'Ombor va logistika menejeri', 'user', '931120113', '1989-01-19', '2019-04-08'],
        'zilola'  => ['Xolmatova', 'Zilola', 'Bahodirovna', 'Yuridik', 'Yurist', 'user', '941120114', '1992-04-08', '2020-06-22'],
        'behzod'  => ['Karimov', 'Behzod', 'Anvarovich', 'Sifat nazorati', 'Sifat muhandisi', 'user', '971120115', '1990-09-17', '2019-10-07'],
        'feruza'  => ['Rahimova', 'Feruza', 'Olimovna', 'Kadrlar', 'HR menejeri', 'user', '901120116', '1992-11-23', '2020-01-13'],
        'umid'    => ['Yusupov', 'Umid', 'Sherzodovich', 'Moliya', 'Byudjet mutaxassisi', 'user', '911120117', '1991-02-06', '2021-05-17'],
        'gulnora' => ['Tursunova', 'Gulnora', 'Ilhomovna', 'Marketing', 'Dizayner', 'user', '931120118', '1995-07-14', '2022-02-28'],
        'nodir'   => ['Ergashev', 'Nodir', 'Jamoliddinovich', 'Ishlab chiqarish', "Ishlab chiqarish boshlig'i", 'user', '941120119', '1982-12-29', '2016-12-05'],
        'eldor'   => ['Nazarov', 'Eldor', 'Baxodirovich', 'IT', 'DevOps muhandisi', 'user', '971120120', '1992-03-03', '2021-08-09'],
        'shahnoza' => ['Saidova', 'Shahnoza', 'Rustamovna', "Ta'minot", 'Xaridlar mutaxassisi', 'user', '901120121', '1993-10-10', '2021-01-25'],
        'javlon'  => ['Qodirov', 'Javlon', 'Alisherovich', 'Qurilish', 'Prorab', 'user', '991120122', '1985-05-25', '2018-03-12'],
        'rustam'  => ['Mirzayev', 'Rustam', 'Karimovich', 'Energetika', 'Bosh energetik', 'user', '911120123', '1983-08-16', '2017-09-04'],
        'lola'    => ['Hasanova', 'Lola', 'Akmalovna', 'Loyihalar ofisi (PMO)', 'Loyiha koordinatori', 'user', '931120124', '1996-01-31', '2022-06-06'],
    ];
    $U = [];   // slug => ['un' => login, 'fio' => to'liq ism]
    $n = 0;
    foreach ($S as $slug => $x) {
        $n++;
        [$fam, $ism, $sh, $bol, $lav, $rol, $tel, $tug, $ish] = $x;
        $fio = "$fam $ism $sh";
        $un = '998' . $tel;
        $cid = 'c_' . $slug;
        $u = create_user($un, bin2hex(random_bytes(16)), $rol, $fio);
        $pdo->prepare('UPDATE users SET kontakt_id = ?, korxonalar = ? WHERE id = ?')->execute([$cid, json_encode([$KID]), (int)$u['id']]);
        try { $pdo->prepare('UPDATE users SET parol_ozgartir = 0 WHERE id = ?')->execute([(int)$u['id']]); } catch (Throwable $e) {}
        $put('kontakt', $cid, [
            'korxonaId' => $KID, 'familiya' => $fam, 'ismi' => $ism, 'sharifi' => $sh, 'ism' => $fio,
            'bolim' => $bol, 'lavozim' => $lav, 'tabelRaqam' => sprintf('T-%04d', 10 + $n), 'ichkiRaqam' => (string)(100 + $n),
            'tugilganKun' => $tug, 'inn' => '30' . sprintf('%07d', 4180000 + $n * 137), 'telefon' => '+998 ' . substr($tel, 0, 2) . ' ' . substr($tel, 2, 3) . ' ' . substr($tel, 5, 2) . ' ' . substr($tel, 7, 2),
            'email' => strtolower(substr($ism, 0, 1)) . '.' . strtolower($fam) . '@yulduz-invest.example',
            'ishSana' => $ish, 'ishdanSana' => null,
        ]);
        $U[$slug] = ['un' => $un, 'fio' => $fio, 'cid' => $cid];
    }
    // namuna-admin ro'yxatlarda (mas'ul tanlash) ko'rinmasin
    $pdo->prepare('UPDATE users SET active = 0 WHERE username = ?')->execute([(string)$ctx['kim']]);

    /* ------------------------------------------------------------------ vazifa quruvchi */
    $PMOUN = $U['dilnoza']['un'];
    /** Bitta loyiha vazifasi: holat kodidan to'liq ijro/nazorat zanjiri (tarix bilan) quriladi. */
    $vazifa = function (string $pid, int $i, array $s, array $fz, string $pmUn) use ($U, $iso, $PMOUN, $fayl): array {
        $id = sprintf('t_%s_%02d', $pid, $i + 1);
        $un = array_map(fn($k) => $U[$k]['un'], $s['u']);
        $nom = implode(', ', array_map(fn($k) => $U[$k]['fio'], $s['u']));
        $b = $s['b']; $m = $s['m']; $st = $s['s'];
        $t = ['id' => $id, 'nomi' => $s['n'], 'boshlanish' => bw_date($b), 'muddat' => bw_date($m), 'ustuvorlik' => $s['pr'] ?? 'orta',
            'faza' => $fz[$s['f']] ?? null, 'masul' => $nom, 'mehnatKun' => max(1, (int)round(abs($b - $m) * 0.55)), 'masulUsers' => $un,
            'masulKorganlar' => new stdClass(), 'izoh' => $s['iz'] ?? '', 'dependsOn' => null, 'kechikishSabab' => '', 'bajarilganIzoh' => '',
            'yakunHujjatlar' => [], 'progress' => 0, 'holat' => 'kutilmoqda', 'yaratildi' => bw_date($b + 4), 'tarix' => []];
        $ev = function (string $tur, int $ago, string $kim, string $matn = '', array $q = []) use (&$t, $iso) {
            $t['tarix'][] = array_merge(['tur' => $tur, 'sana' => bw_date($ago), 'ts' => $iso($ago, 9 + (strlen($tur) % 7), 5 + (strlen($tur) * 7) % 50), 'kim' => $kim, 'matn' => $matn], $q);
        };
        $ev('yaratildi', $b + 4, $pmUn, $s['iz'] ?? '');
        $ev('biriktirildi', $b + 4, $pmUn, $nom);
        $kor = [];
        if ($st !== 'ack') {
            foreach ($un as $j => $u) { $kor[$u] = bw_date($b + 3); $ev('kordi', $b + 3 - ($j % 2 ? 0 : 0), $u); }
        }
        $setQabul = function (string $h, ?string $izoh = null) use (&$t, $b, $un, $iso) {
            $t['qabulHolati'] = $h; $t['qabulIzoh'] = $izoh; $t['qabulKim'] = $un[0];
            $t['qabulSana'] = bw_date($b + 3); $t['qabulTs'] = $iso($b + 3, 11, 40);
        };
        if ($st === 'ack') { $t['qabulHolati'] = 'kutilmoqda'; $t['qabulIzoh'] = null; $t['qabulKim'] = null; $t['qabulSana'] = null; }
        elseif ($st === 'rad') { $setQabul('rad', $s['rd'] ?? ''); $ev('radEtdi', $b + 3, $un[0], $s['rd'] ?? ''); }
        else { $setQabul('qabul'); $ev('qabulQildi', $b + 3, $un[0]); }

        $files = function (array $names, int $ago) use ($pid, $id, $un, $fayl, $s) {
            $out = [];
            foreach ($names as $nm) $out[] = $fayl($nm, 'loyiha', 'L-' . $pid, $un[0], $ago, ["Namuna hujjat: " . $s['n'], 'Loyiha: ' . $pid, 'Bu fayl demo uchun xayoliy ma\'lumot bilan yaratilgan.']);
            return $out;
        };
        $bajar = function (int $doneAgo, string $izoh) use (&$t, $ev, $un, $s, $files) {
            $t['progress'] = 100; $t['holat'] = 'bajarilgan'; $t['bajarilganSana'] = bw_date($doneAgo);
            $t['bajarilganTs'] = gmdate('Y-m-d\TH:i:s.000\Z', strtotime(bw_date($doneAgo) . ' 15:10:00 +0500')); $t['bajarilganKim'] = $un[0];
            $t['bajarilganIzoh'] = $izoh;
            $t['yakunHujjatlar'] = !empty($s['fy']) ? $files($s['fy'], $doneAgo) : [];
            $ev('bajardi', $doneAgo, $un[0], $izoh, ['fayllar' => $t['yakunHujjatlar']]);
        };
        $h = $s['h'] ?? "Ish to'liq bajarildi, tasdiqlovchi hujjatlar biriktirildi.";
        if ($st !== 'new' && $st !== 'ack' && $st !== 'rad') $ev('boshlandi', $b + 1, $un[0]);
        if ($st === 'ok') {
            $dd = max(2, $s['dd'] ?? ($m + mt_rand(-3, 4)));
            $bajar($dd, $h);
            $t['nazoratHolati'] = 'qabul'; $t['nazoratKim'] = $PMOUN; $t['nazoratSana'] = bw_date($dd - 1); $t['nazoratTs'] = $iso($dd - 1, 10, 30); $t['nazoratIzoh'] = '';
            $ev('qabul', $dd - 1, $PMOUN, 'Natija talabga mos, qabul qilindi.');
        } elseif ($st === 'wait') {
            $dd = $s['dd'] ?? 2;
            $bajar($dd, $h);
            $t['nazoratHolati'] = 'kutilmoqda'; $t['nazoratIzoh'] = null;
        } elseif ($st === 'back') {
            $dd = 7;
            $bajar($dd, $h);
            $yt = ['ts' => $iso($dd, 15, 10), 'kim' => $un[0], 'izoh' => $h, 'fayllar' => $t['yakunHujjatlar'], 'sabab' => $s['nz'] ?? 'To\'ldirish kerak'];
            $t['nazoratHolati'] = 'qaytarildi'; $t['nazoratKim'] = $PMOUN; $t['nazoratSana'] = bw_date(4); $t['nazoratTs'] = $iso(4, 10, 20);
            $t['nazoratIzoh'] = $s['nz'] ?? "To'ldirish kerak";
            $ev('qaytarildi', 4, $PMOUN, $t['nazoratIzoh']);
            $t['holat'] = 'jarayonda'; $t['progress'] = 90; $t['bajarilganSana'] = null; $t['bajarilganIzoh'] = ''; $t['yakunHujjatlar'] = []; $t['yakunTarixi'] = [$yt];
            $ev('izoh', 3, $un[0], "Eslatmalar bo'yicha ishni yangilashni boshladim, ikki kunda qayta topshiraman.");
        } elseif ($st === 'p' || $st === 'late') {
            $t['progress'] = $s['p']; $t['holat'] = 'jarayonda';
            if ($st === 'late') {
                $t['kechikishSabab'] = $s['sb'] ?? '';
                if (($s['sb'] ?? '') !== '') {
                    $t['vadaSana'] = bw_date($s['vd']);
                    $t['vadaTarixi'] = [['kiritildi' => bw_date(max(1, $m - 2)), 'sabab' => $s['sb'], 'vadaSana' => bw_date($s['vd']), 'kim' => $un[0]]];
                    $ev('vada', max(1, $m - 2), $un[0], $s['sb'], ['vada' => bw_date($s['vd'])]);
                }
            }
            if (!empty($s['ch'])) $ev('izoh', 3, $un[0], $s['ch']);
        }
        if ($kor) $t['masulKorganlar'] = $kor;
        if (!empty($s['dep'])) $t['dependsOn'] = array_map(fn($k) => sprintf('t_%s_%02d', $pid, $k + 1), $s['dep']);
        return $t;
    };

    /* ------------------------------------------------------------------ loyihalar */
    $loyihalar = [];
    // [id, raqam, nomi, yonalish, tavsif, pm, homiy, byudjet(so'm), boshlanish(ago), fazalar, vazifalar, bosqichlar, hujjatTalab, risklar, pasport, yakun]
    $L = [];

    $L[] = ['id' => 'loy01', 'raqam' => 1, 'nomi' => 'Yangi bosh ofis binosi qurilishi', 'yo' => 'korxona', 'pm' => 'nigora', 'homiy' => 'jasur',
        'tavsif' => "Guruhning 6 qavatli yangi bosh ofis binosini qurish: loyihalash, poydevor va karkas, muhandislik tarmoqlari, pardozlash va foydalanishga topshirish.",
        'byudjet' => 3800000000, 'b' => 210,
        'fazalar' => ['Loyihalash va ruxsatnomalar', 'Poydevor va karkas', 'Muhandislik tarmoqlari va pardozlash', 'Topshirish va obodonlashtirish'],
        'v' => [
            ['n' => 'Texnik topshiriq va eskiz loyihani tasdiqlash', 'f' => 0, 'u' => ['nigora', 'farrux'], 'b' => 210, 'm' => 185, 's' => 'ok', 'pr' => 'yuqori', 'fy' => ['Eskiz-loyiha-tasdiq.pdf']],
            ['n' => "Geodeziya va muhandislik-geologik qidiruv", 'f' => 0, 'u' => ['sardor'], 'b' => 195, 'm' => 165, 's' => 'ok'],
            ['n' => 'Qurilish ruxsatnomasi va davlat ekspertizasi', 'f' => 0, 'u' => ['zilola', 'nigora'], 'b' => 180, 'm' => 130, 's' => 'ok', 'dep' => [0, 1], 'pr' => 'yuqori', 'fy' => ['Ekspertiza-xulosasi.pdf']],
            ['n' => "Poydevor ishlari (qazish, armatura, beton quyish)", 'f' => 1, 'u' => ['javlon', 'sardor'], 'b' => 128, 'm' => 82, 's' => 'ok', 'dep' => [2], 'fy' => ['Yashirin-ishlar-dalolatnomasi.pdf']],
            ['n' => 'Karkas va ustunlar', 'f' => 1, 'u' => ['sardor', 'javlon'], 'b' => 85, 'm' => 8, 's' => 'wait', 'dd' => 3, 'dep' => [3], 'pr' => 'yuqori', 'fy' => ['Karkas-texnik-nazorat.pdf'], 'h' => "Barcha qavatlar karkasi tugallandi; laboratoriya sinovlari me'yorga mos."],
            ['n' => 'Tomyopma va fasad ishlari', 'f' => 1, 'u' => ['javlon'], 'b' => 50, 'm' => -12, 's' => 'p', 'p' => 55, 'dep' => [4]],
            ['n' => 'Elektr tarmoqlari va past kuchlanishli tizimlar', 'f' => 2, 'u' => ['rustam'], 'b' => 35, 'm' => -22, 's' => 'p', 'p' => 45],
            ['n' => 'Ventilyatsiya va konditsionerlash (HVAC)', 'f' => 2, 'u' => ['sardor', 'akmal'], 'b' => 45, 'm' => 6, 's' => 'late', 'p' => 70, 'pr' => 'yuqori', 'sb' => "Kompressor bloklari bojxonada kechikdi, yetkazib beruvchi yangi sanani yozma tasdiqladi.", 'vd' => -9],
            ['n' => 'Ichki pardozlash ishlari', 'f' => 2, 'u' => ['javlon'], 'b' => -5, 'm' => -48, 's' => 'new'],
            ['n' => 'Ofis mebeli va jihozlari xaridi', 'f' => 2, 'u' => ['shahnoza', 'akmal'], 'b' => -18, 'm' => -60, 's' => 'ack'],
            ['n' => "Yong'in xavfsizligi tizimini sinash va dalolatnoma", 'f' => 3, 'u' => ['rustam'], 'b' => -38, 'm' => -65, 's' => 'rad', 'rd' => "Sinov litsenziyalangan tashkilot tomonidan bajarilishi kerak — bizda tegishli sertifikat yo'q, tashqi pudratchi jalb qilish lozim."],
            ['n' => 'Atrof hududni obodonlashtirish', 'f' => 3, 'u' => ['javlon'], 'b' => -45, 'm' => -72, 's' => 'new'],
            ['n' => 'Binoni foydalanishga topshirish va dalolatnoma', 'f' => 3, 'u' => ['nigora', 'zilola'], 'b' => -65, 'm' => -75, 's' => 'new', 'pr' => 'yuqori'],
        ],
        'bosq' => [['Qurilish ruxsatnomasi olindi', 130, true], ['Poydevor topshirildi', 80, true], ['Karkas tugallandi', 8, false], ['Muhandislik tarmoqlari tayyor', -30, false], ['Binoni topshirish', -75, false]],
        'hujjat' => [['Qurilish ruxsatnomasi nusxasi', 130, 2, true], ['Davlat ekspertizasi xulosasi', 128, 2, true], ['Yashirin ishlar dalolatnomalari (poydevor)', 80, 3, true], ['Texnik nazorat hisoboti — karkas', 3, 4, false], ['Foydalanishga topshirish dalolatnomasi', -75, 12, false]],
        'risk' => [
            ["Import jihozlari (HVAC, lift) yetkazilishining kechikishi", 3, 2, 'akmal', 'ochiq', "Muqobil yetkazib beruvchi bilan oldindan shartnoma; kritik pozitsiyalar uchun xavfsizlik zaxirasi."],
            ["Qurilish materiallari narxining oshishi", 2, 3, 'otabek', 'ochiq', "Armatura va betonga narxni qotirish bo'yicha shartnomalar tuzish."],
            ["Qishki sovuq tufayli pardozlash ishlarining to'xtashi", 2, 2, 'sardor', 'ochiq', "Isitish jihozlarini oldindan xarid qilish, ish grafigini qishga moslash."],
            ["Pudratchida malakali ishchilar yetishmasligi", 2, 3, 'javlon', 'ochiq', ''],
            ["Yer osti suvlari sathining ko'tarilishi (poydevor)", 1, 3, 'sardor', 'yopilgan', "Drenaj tizimi o'rnatildi, poydevor ishlari xavfsiz tugadi."],
        ],
        'pasport' => ['asos' => "Hozirgi ofis 4 ta binoga tarqalgan, ijara xarajati yiliga 1,2 mlrd so'mdan oshadi va bo'limlar o'rtasida muvofiqlashtirish sekinlashgan.",
            'maqsad' => "2027-yil 1-chorakda 6 qavatli yangi bosh ofisga ko'chib o'tish; ijara xarajatlarini 70% ga qisqartirish.",
            'nat' => ["6 qavatli bino foydalanishga topshirilgan", "Barcha bo'limlar yagona binoga ko'chirilgan", "Muhandislik tarmoqlari sinovdan o'tgan"],
            'mez' => ["Byudjetdan chetlanish ≤ 5%", "Muddatdan kechikish ≤ 30 kun", "Xodimlar qoniqish so'rovnomasi ≥ 4/5"],
            'kiradi' => "Bino qurilishi, muhandislik tarmoqlari, mebel va jihozlar, atrof hudud.", 'kirmaydi' => "Eski binolarni sotish va ijarani tugatish (alohida loyiha).",
            'taxmin' => "Moliyalashtirish bank krediti va o'z mablag'idan 60/40 nisbatda ta'minlanadi.", 'chek' => "Qurilish ruxsatnomasi muddati; shahar elektr tarmog'i quvvati.",
            'mf' => [['Direktorlar kengashi', 'Homiy', 3], ['Moliya departamenti', 'Moliyalashtirish', 2], ['Barkamol Qurilish Servis MChJ', 'Bosh pudratchi', 3], ['Shahar arxitektura bo\'limi', 'Ruxsat beruvchi organ', 2]]],
    ];

    $L[] = ['id' => 'loy02', 'raqam' => 2, 'nomi' => "ERP tizimini joriy etish (moliya va ombor modullari)", 'yo' => 'ichki', 'pm' => 'bobur', 'homiy' => 'otabek',
        'tavsif' => "Hisob-kitob, ombor va xaridlarni yagona ERP tizimiga o'tkazish; eski jadval va qo'lda yuritiladigan hisobotlarni almashtirish.",
        'byudjet' => 1450000000, 'b' => 150,
        'fazalar' => ['Tahlil va loyihalash', 'Sozlash va ishlab chiqish', 'Migratsiya va sinov', 'Ishga tushirish va o\'qitish'],
        'v' => [
            ['n' => "Biznes-jarayonlarni tahlil qilish va talablarni yig'ish", 'f' => 0, 'u' => ['sevara', 'bobur'], 'b' => 150, 'm' => 120, 's' => 'ok', 'fy' => ['Talablar-reyestri.pdf']],
            ['n' => 'Texnik topshiriq (TZ) ni tasdiqlash', 'f' => 0, 'u' => ['sevara'], 'b' => 125, 'm' => 105, 's' => 'ok', 'dep' => [0], 'pr' => 'yuqori'],
            ['n' => "Moliya moduli: hisoblar rejasi va byudjetlashtirish sozlamalari", 'f' => 1, 'u' => ['dilshod', 'umid'], 'b' => 100, 'm' => 50, 's' => 'ok', 'dep' => [1]],
            ['n' => 'Ombor modulini sozlash', 'f' => 1, 'u' => ['dilshod', 'timur'], 'b' => 90, 'm' => 25, 's' => 'wait', 'dd' => 2, 'dep' => [1]],
            ['n' => "Xarid va yetkazib beruvchilar moduli", 'f' => 1, 'u' => ['dilshod', 'shahnoza'], 'b' => 60, 'm' => -10, 's' => 'p', 'p' => 60],
            ['n' => "Eski tizimdan ma'lumotlarni ko'chirish (migratsiya)", 'f' => 2, 'u' => ['eldor', 'sevara'], 'b' => 40, 'm' => -20, 's' => 'p', 'p' => 35, 'pr' => 'yuqori'],
            ['n' => 'Foydalanuvchi huquqlari va rollar matritsasi', 'f' => 2, 'u' => ['eldor'], 'b' => 30, 'm' => 4, 's' => 'late', 'p' => 55, 'sb' => "Bo'limlar rahbarlari rollar ro'yxatini kech taqdim etdi.", 'vd' => -6],
            ['n' => "Integratsiya: bank-mijoz va soliq hisobotlari", 'f' => 2, 'u' => ['dilshod'], 'b' => 20, 'm' => -35, 's' => 'p', 'p' => 20],
            ['n' => 'Sinov muhitida qabul sinovlari (UAT)', 'f' => 2, 'u' => ['behzod', 'sevara'], 'b' => -10, 'm' => -40, 's' => 'new'],
            ['n' => "Xodimlarni o'qitish (guruhlar bo'yicha)", 'f' => 3, 'u' => ['feruza', 'sevara'], 'b' => -30, 'm' => -52, 's' => 'ack'],
            ['n' => "Boshlang'ich qoldiqlarni kiritish va yakuniy migratsiya", 'f' => 3, 'u' => ['umid', 'timur'], 'b' => -42, 'm' => -56, 's' => 'new'],
            ['n' => "Ishga tushirish (go-live) va qo'llab-quvvatlash rejasi", 'f' => 3, 'u' => ['bobur'], 'b' => -52, 'm' => -60, 's' => 'new', 'pr' => 'yuqori'],
        ],
        'bosq' => [['TZ tasdiqlandi', 105, true], ['Moliya moduli tayyor', 50, true], ['Migratsiya tugallandi', -20, false], ['Go-live', -60, false]],
        'hujjat' => [['Texnik topshiriq (TZ) — imzolangan', 105, 1, true], ['Qabul sinovi dasturi', -38, 8, false], ["Foydalanuvchi qo'llanmasi", -50, 9, false]],
        'risk' => [
            ["Eski ma'lumotlar sifati past — migratsiya kechikishi", 3, 3, 'eldor', 'ochiq', "Migratsiyadan oldin ma'lumotlarni tozalash bosqichi; har bo'lim mas'uli tasdiqlaydi."],
            ["Xodimlarning yangi tizimga qarshiligi", 2, 2, 'feruza', 'ochiq', "Erta o'qitish va 'chempionlar' guruhi."],
            ["Yetkazib beruvchi tomonidan modul versiyasining o'zgarishi", 1, 2, 'bobur', 'ochiq', ''],
        ],
        'pasport' => ['asos' => "Moliya va ombor hisobi alohida jadvallarda yuritiladi, oylik yopish 9–10 kun oladi va xatolar ko'p.",
            'maqsad' => "2026-yil oxirigacha yagona ERP; oylik yopish muddatini 3 kunga tushirish.",
            'nat' => ["Moliya, ombor va xarid modullari ishga tushgan", "Eski jadvallardan ma'lumot ko'chirilgan", "120 foydalanuvchi o'qitilgan"],
            'mez' => ["Oylik yopish ≤ 3 ish kuni", "Ombor qoldig'i aniqligi ≥ 98%"],
            'kiradi' => "Moliya, ombor, xarid modullari; migratsiya; o'qitish.", 'kirmaydi' => "Ishlab chiqarish va CRM modullari (2-bosqich).",
            'taxmin' => "Yetkazib beruvchi 3 ta tayyor integratsiya taqdim etadi.", 'chek' => "Yil yakunigacha hisobot davri bilan to'qnashmaslik.",
            'mf' => [['Moliya departamenti', 'Asosiy foydalanuvchi', 3], ['Ombor xizmati', 'Foydalanuvchi', 2], ['ERP yetkazib beruvchi', 'Pudratchi', 2]]],
    ];

    $L[] = ['id' => 'loy03', 'raqam' => 3, 'nomi' => 'Mijozlar uchun mobil ilova (2.0 versiya)', 'yo' => 'korxona', 'pm' => 'madina', 'homiy' => 'jasur',
        'tavsif' => "Mijozlar kabineti: buyurtmalar tarixi, to'lovlar, bildirishnomalar va qo'llab-quvvatlash — yagona iOS/Android ilovada.",
        'byudjet' => 620000000, 'b' => 95,
        'fazalar' => ['Dizayn', 'Ishlab chiqish', 'Sinov va nashr'],
        'v' => [
            ['n' => "Foydalanuvchi tadqiqoti va jarayon xaritasi", 'f' => 0, 'u' => ['malika', 'sevara'], 'b' => 95, 'm' => 70, 's' => 'ok', 'fy' => ['Tadqiqot-natijalari.pdf']],
            ['n' => 'UI/UX dizayn maketlari (Figma)', 'f' => 0, 'u' => ['gulnora'], 'b' => 75, 'm' => 45, 's' => 'ok', 'dep' => [0]],
            ['n' => 'Autentifikatsiya va profil moduli', 'f' => 1, 'u' => ['dilshod'], 'b' => 50, 'm' => 20, 's' => 'ok', 'dep' => [1]],
            ["n" => "Buyurtmalar tarixi va to'lov integratsiyasi", 'f' => 1, 'u' => ['dilshod', 'eldor'], 'b' => 35, 'm' => -5, 's' => 'p', 'p' => 75],
            ['n' => 'Bildirishnomalar (push) xizmati', 'f' => 1, 'u' => ['eldor'], 'b' => 25, 'm' => -12, 's' => 'p', 'p' => 50],
            ['n' => 'Avtomatik testlar va yuklama sinovi', 'f' => 2, 'u' => ['behzod'], 'b' => 10, 'm' => -25, 's' => 'p', 'p' => 20],
            ['n' => 'Yopiq beta-sinov (50 mijoz)', 'f' => 2, 'u' => ['madina', 'malika'], 'b' => -8, 'm' => -33, 's' => 'new'],
            ["n" => "Do'konlarga joylashtirish (App Store / Google Play)", 'f' => 2, 'u' => ['madina'], 'b' => -30, 'm' => -45, 's' => 'ack'],
        ],
        'bosq' => [['Dizayn tasdiqlandi', 45, true], ['Beta-versiya tayyor', -25, false], ["Do'konlarda e'lon qilish", -45, false]],
        'hujjat' => [['Dizayn tizimi (UI-kit)', 45, 1, true], ['Xavfsizlik tekshiruvi xulosasi', -22, 5, false]],
        'risk' => [
            ["Do'kon (App Store/Google Play) tekshiruvidan o'tmaslik", 2, 2, 'madina', 'ochiq', "Talablarni oldindan tekshirish; zaxira kunlar."],
            ["To'lov provayderi API o'zgarishi", 1, 3, 'dilshod', 'ochiq', "Versiyalangan integratsiya, monitoring."],
        ],
        'pasport' => ['asos' => "Mijozlar buyurtma holatini qo'ng'iroq orqali so'raydi; qo'llab-quvvatlashga kuniga 150+ murojaat tushadi.",
            'maqsad' => "Mijozlar murojaatini 40% ga kamaytiradigan va takroriy buyurtmani oshiradigan ilova.",
            'nat' => ["iOS va Android ilovasi nashr etilgan", "Buyurtma va to'lov tarixi ilovada", "Push-bildirishnomalar ishlaydi"],
            'mez' => ["Oylik faol foydalanuvchi ≥ 3 000", "Do'kon bahosi ≥ 4,5"],
            'kiradi' => "Mobil ilova (iOS/Android), push xizmati.", 'kirmaydi' => "Veb-kabinet (alohida).",
            'taxmin' => "To'lov provayderi sandbox muhitini o'z vaqtida beradi.", 'chek' => "Brend yangilanishi bilan bir vaqtda e'lon qilinishi kerak.",
            'mf' => [['Mijozlar bo\'limi', 'Foydalanuvchi', 3], ['Marketing', 'Hamkor', 2]]],
    ];

    $L[] = ['id' => 'loy04', 'raqam' => 4, 'nomi' => 'Ombor logistikasini avtomatlashtirish (shtrix-kod va RFID)', 'yo' => 'korxona', 'pm' => 'timur', 'homiy' => 'otabek',
        'tavsif' => "Markaziy omborda mahsulot harakatini shtrix-kod va RFID orqali yuritish; inventarizatsiya vaqtini qisqartirish.",
        'byudjet' => 780000000, 'b' => 120,
        'fazalar' => ['Tayyorgarlik', "O'rnatish", "Sinov va o'qitish"],
        'v' => [
            ['n' => "Omborning joriy holatini auditdan o'tkazish", 'f' => 0, 'u' => ['timur', 'behzod'], 'b' => 120, 'm' => 95, 's' => 'ok'],
            ['n' => 'Shtrix-kod va RFID uskunalarini tanlash (tender)', 'f' => 0, 'u' => ['shahnoza', 'timur'], 'b' => 100, 'm' => 70, 's' => 'ok', 'dep' => [0], 'fy' => ['Tender-xulosasi.pdf']],
            ['n' => "Uskunalar shartnomasi va to'lov", 'f' => 0, 'u' => ['zilola', 'otabek'], 'b' => 75, 'm' => 55, 's' => 'ok', 'dep' => [1]],
            ['n' => 'Saqlash joylarini qayta rejalashtirish va markirovka', 'f' => 1, 'u' => ['timur'], 'b' => 60, 'm' => 25, 's' => 'ok'],
            ["n" => "RFID darvozalari va skanerlarni o'rnatish", 'f' => 1, 'u' => ['eldor', 'timur'], 'b' => 40, 'm' => 5, 's' => 'late', 'p' => 80, 'pr' => 'yuqori', 'sb' => "Ikkinchi partiya skanerlar yetib kelmadi; yetkazib beruvchi 10 kunga surdi.", 'vd' => -8, 'dep' => [2]],
            ['n' => 'Ombor dasturini ERP bilan bog\'lash', 'f' => 1, 'u' => ['dilshod'], 'b' => 30, 'm' => -15, 's' => 'p', 'p' => 40],
            ["n" => "Xodimlarni o'qitish (omborchilar)", 'f' => 2, 'u' => ['feruza', 'timur'], 'b' => -5, 'm' => -25, 's' => 'new'],
            ['n' => 'Sinov ishlatish va inventarizatsiya', 'f' => 2, 'u' => ['behzod', 'timur'], 'b' => -22, 'm' => -35, 's' => 'new'],
            ['n' => 'Yakuniy qabul va ishga tushirish', 'f' => 2, 'u' => ['timur'], 'b' => -33, 'm' => -40, 's' => 'ack'],
        ],
        'bosq' => [['Uskunalar shartnomasi imzolandi', 55, true], ["O'rnatish tugallandi", -3, false], ['Ishga tushirish', -40, false]],
        'hujjat' => [['Tender xulosasi', 70, 1, true], ['Uskunalar yetkazib berish shartnomasi', 55, 2, true], ['Sinov dasturi', -20, 7, false]],
        'risk' => [
            ["Uskunalar yetkazib berish kechikishi", 3, 2, 'shahnoza', 'ochiq', "Yetkazib beruvchi bilan haftalik nazorat; jarima bandi."],
            ["Ombor xodimlarining yangi usulga o'tishdagi qiyinchiliklari", 2, 2, 'timur', 'ochiq', "Bosqichma-bosqich o'qitish."],
        ],
        'pasport' => ['asos' => "Inventarizatsiya 6 kun to'xtab turadi, qoldiq xatolari yiliga 2,1% ni tashkil qiladi.",
            'maqsad' => "Inventarizatsiyani 1 kunga tushirish; qoldiq aniqligini 99% ga yetkazish.",
            'nat' => ["RFID darvozalari va skanerlar ishlaydi", "Ombor dasturi ERP bilan bog'langan"],
            'mez' => ["Inventarizatsiya ≤ 1 kun", "Qoldiq xatosi ≤ 1%"],
            'kiradi' => "Markaziy ombor.", 'kirmaydi' => "Filial omborlar.",
            'taxmin' => "ERP loyihasi ombor modulini o'z vaqtida tayyorlaydi.", 'chek' => "Ombor ish jarayonini to'xtatmasdan o'rnatish.",
            'mf' => [['Ombor xizmati', 'Asosiy foydalanuvchi', 3], ['Moliya', 'Moliyalashtirish', 2]]],
    ];

    $L[] = ['id' => 'loy05', 'raqam' => 5, 'nomi' => "Bosh binoda quyosh panellari o'rnatish", 'yo' => 'ichki', 'pm' => 'rustam', 'homiy' => 'farrux',
        'tavsif' => "Mavjud ofis binosi tomiga 120 kVt quvvatli quyosh elektr stansiyasi o'rnatib, elektr xarajatini kamaytirish.",
        'byudjet' => 940000000, 'b' => 70,
        'fazalar' => ['Loyiha va smeta', 'Xarid va montaj', 'Ulash va qabul'],
        'v' => [
            ["n" => "Tomning yuk ko'tarish qobiliyatini tekshirish", 'f' => 0, 'u' => ['sardor', 'rustam'], 'b' => 70, 'm' => 55, 's' => 'ok'],
            ['n' => 'Quvvat hisobi va panellar joylashuv sxemasi', 'f' => 0, 'u' => ['rustam'], 'b' => 60, 'm' => 40, 's' => 'ok', 'dep' => [0], 'fy' => ['Quvvat-hisobi.pdf']],
            ['n' => 'Tender va yetkazib beruvchini tanlash', 'f' => 0, 'u' => ['shahnoza', 'akmal'], 'b' => 50, 'm' => 25, 's' => 'ok'],
            ["n" => "Moliyalashtirish tartibini kelishish", 'f' => 0, 'u' => ['otabek', 'umid'], 'b' => 40, 'm' => 20, 's' => 'wait', 'dd' => 2],
            ['n' => 'Konstruksiyalar va panellarni xarid qilish', 'f' => 1, 'u' => ['shahnoza'], 'b' => 20, 'm' => -30, 's' => 'p', 'p' => 46],
            ['n' => 'Montaj ishlari', 'f' => 1, 'u' => ['rustam', 'javlon'], 'b' => -25, 'm' => -70, 's' => 'new'],
            ['n' => 'Tarmoqqa ulash va hisoblagichlarni almashtirish', 'f' => 2, 'u' => ['rustam'], 'b' => -60, 'm' => -90, 's' => 'ack'],
            ["n" => "Sinov va foydalanishga qabul", 'f' => 2, 'u' => ['rustam', 'behzod'], 'b' => -85, 'm' => -100, 's' => 'new'],
        ],
        'bosq' => [['Tender yakunlandi', 25, true], ['Montaj boshlanishi', -25, false], ['Tarmoqqa ulanish', -90, false]],
        'hujjat' => [['Quvvat hisobi', 40, 1, true], ['Tarmoqqa ulanish texnik shartlari', -55, 6, false]],
        'risk' => [["Panellar narxining oshishi", 2, 2, 'otabek', 'ochiq', "Narxni oldindan qotirish."], ["Tom konstruksiyasini kuchaytirish zarurati", 1, 3, 'sardor', 'ochiq', '']],
        'pasport' => ['asos' => "Elektr xarajati yiliga 480 mln so'm; quyosh energiyasi 35–40% ni qoplaydi.",
            'maqsad' => "Elektr xarajatini 38% ga kamaytirish, 4,5 yilda o'zini oqlash.",
            'nat' => ["120 kVt stansiya tarmoqqa ulangan"], 'mez' => ["Yillik ishlab chiqarish ≥ 170 MVt·soat"],
            'kiradi' => "Bosh bino tomi.", 'kirmaydi' => "Boshqa binolar.", 'taxmin' => "Tarmoqqa ulanish shartlari 60 kunda beriladi.", 'chek' => "Tom konstruksiyasi chegarasi.",
            'mf' => [['Texnik departament', 'Homiy', 3], ['Moliya', 'Moliyalashtirish', 2]]],
    ];

    $L[] = ['id' => 'loy06', 'raqam' => 6, 'nomi' => "Hujjat aylanishini raqamlashtirish", 'yo' => 'ichki', 'pm' => 'lola', 'homiy' => 'dilnoza', 'yakun' => true,
        'tavsif' => "Qog'ozdagi hujjat oqimini elektron tizimga ko'chirish: tasdiqlash marshrutlari, elektron imzo, arxiv.",
        'byudjet' => 310000000, 'b' => 260,
        'fazalar' => ['Tahlil', 'Joriy etish'],
        'v' => [
            ['n' => 'Joriy hujjat oqimlarini xaritalash', 'f' => 0, 'u' => ['lola', 'sevara'], 'b' => 260, 'm' => 230, 's' => 'ok'],
            ['n' => 'Elektron hujjat tizimini tanlash va shartnoma', 'f' => 0, 'u' => ['bobur', 'zilola'], 'b' => 235, 'm' => 200, 's' => 'ok', 'dep' => [0]],
            ["n" => "Hujjat shablonlari va tasdiqlash marshrutlari", 'f' => 1, 'u' => ['lola', 'feruza'], 'b' => 200, 'm' => 150, 's' => 'ok'],
            ["n" => "Arxivni skanerlash va indekslash", 'f' => 1, 'u' => ['lola'], 'b' => 155, 'm' => 90, 's' => 'ok'],
            ["n" => "Elektron imzo (ERI) ulash", 'f' => 1, 'u' => ['eldor', 'zilola'], 'b' => 100, 'm' => 60, 's' => 'ok'],
            ["n" => "Barcha bo'limlarni o'qitish va pilot", 'f' => 1, 'u' => ['feruza', 'lola'], 'b' => 65, 'm' => 35, 's' => 'ok'],
            ["n" => "Yakuniy qabul va qog'oz aylanishini to'xtatish", 'f' => 1, 'u' => ['dilnoza'], 'b' => 40, 'm' => 25, 's' => 'ok', 'fy' => ['Yakuniy-qabul-dalolatnomasi.pdf']],
        ],
        'bosq' => [['Pilot bo\'limlarda ishga tushirildi', 60, true], ["Qog'ozsiz aylanishga o'tildi", 25, true]],
        'hujjat' => [['Yakuniy qabul dalolatnomasi', 25, 6, true]],
        'risk' => [["Elektron imzo sertifikatlarining muddati", 1, 2, 'eldor', 'yopilgan', "Yangilash jadvali tuzildi."]],
        'pasport' => ['asos' => "Hujjat tasdiqlash o'rtacha 6 kun olardi.", 'maqsad' => "Tasdiqlash muddatini 1 kunga tushirish.",
            'nat' => ["Elektron hujjat tizimi ishlaydi", "Arxiv raqamlashtirilgan"], 'mez' => ["Tasdiqlash ≤ 1 kun"],
            'kiradi' => "Barcha ichki hujjatlar.", 'kirmaydi' => "Tashqi (davlat) hujjat almashinuvi.", 'taxmin' => '', 'chek' => '',
            'mf' => [['Barcha bo\'limlar', 'Foydalanuvchi', 3]]],
    ];

    $L[] = ['id' => 'loy07', 'raqam' => 7, 'nomi' => 'Sifat menejmenti tizimi (ISO 9001) sertifikatsiyasi', 'yo' => 'ichki', 'pm' => 'behzod', 'homiy' => 'farrux',
        'tavsif' => "Sifat menejmenti tizimini xalqaro standartga muvofiq joriy etish va mustaqil sertifikatsiya organidan o'tish.",
        'byudjet' => 210000000, 'b' => 180,
        'fazalar' => ['Tayyorgarlik', 'Joriy etish', 'Sertifikatsiya'],
        'v' => [
            ["n" => "Bo'shliqlar tahlili (gap analysis)", 'f' => 0, 'u' => ['behzod', 'dilnoza'], 'b' => 180, 'm' => 150, 's' => 'ok'],
            ['n' => "Sifat siyosati va maqsadlarini tasdiqlash", 'f' => 0, 'u' => ['dilnoza', 'behzod'], 'b' => 150, 'm' => 125, 's' => 'ok', 'dep' => [0]],
            ['n' => 'Jarayonlarni hujjatlashtirish (SOP)', 'f' => 1, 'u' => ['behzod', 'nodir'], 'b' => 125, 'm' => 60, 's' => 'ok'],
            ["n" => "Ichki auditorlarni o'qitish", 'f' => 1, 'u' => ['feruza', 'behzod'], 'b' => 70, 'm' => 35, 's' => 'ok'],
            ["n" => "Birinchi ichki audit", 'f' => 1, 'u' => ['behzod', 'nodir'], 'b' => 40, 'm' => 8, 's' => 'back', 'nz' => "Ishlab chiqarish uchastkasi bo'yicha audit hisoboti to'liq emas; nomuvofiqliklar reyestrini qo'shing.", 'h' => "Audit o'tkazildi, hisobot tayyorlandi."],
            ['n' => 'Nomuvofiqliklarni bartaraf etish', 'f' => 1, 'u' => ['nodir', 'sardor'], 'b' => 25, 'm' => -8, 's' => 'p', 'p' => 45],
            ["n" => "Rahbariyat tahlili (management review)", 'f' => 2, 'u' => ['dilnoza', 'jasur'], 'b' => -5, 'm' => -18, 's' => 'new'],
            ["n" => "Sertifikatsiya organi auditi (1-bosqich)", 'f' => 2, 'u' => ['behzod'], 'b' => -15, 'm' => -25, 's' => 'ack'],
            ['n' => 'Sertifikatni olish', 'f' => 2, 'u' => ['behzod'], 'b' => -25, 'm' => -30, 's' => 'new'],
        ],
        'bosq' => [['SOP lar tasdiqlandi', 60, true], ['Ichki audit yakunlandi', 8, false], ['Sertifikat olindi', -30, false]],
        'hujjat' => [['Sifat siyosati — imzolangan', 125, 1, true], ['Ichki audit hisoboti', 8, 4, false]],
        'risk' => [["Sertifikatsiya auditida jiddiy nomuvofiqlik topilishi", 2, 3, 'behzod', 'ochiq', "Oldindan tashqi konsultant ko'rigi."], ["Xodimlarning hujjatlarni yuritishga e'tiborsizligi", 2, 2, 'nodir', 'ochiq', "Haftalik nazorat."]],
        'pasport' => ['asos' => "Yirik mijozlar tender shartida ISO 9001 sertifikatini talab qiladi.", 'maqsad' => "2026-yil oxirigacha ISO 9001:2015 sertifikatini olish.",
            'nat' => ["Sertifikat olingan", "Ichki auditorlar tayyor"], 'mez' => ["Sertifikatsiya auditida jiddiy nomuvofiqlik yo'q"],
            'kiradi' => "Ishlab chiqarish va logistika.", 'kirmaydi' => "Qurilish yo'nalishi.", 'taxmin' => '', 'chek' => '',
            'mf' => [['Ishlab chiqarish', 'Ijrochi', 3], ['Sertifikatsiya organi', 'Auditor', 3]]],
    ];

    $L[] = ['id' => 'loy08', 'raqam' => 8, 'nomi' => 'Samarqand filialini ochish', 'yo' => 'korxona', 'pm' => 'madina', 'homiy' => 'jasur',
        'tavsif' => "Samarqand shahrida sotuv va servis markazini ochish: joy, shtat, jihozlar, marketing va rasmiy ochilish.",
        'byudjet' => 1100000000, 'b' => 40,
        'fazalar' => ['Tayyorgarlik', 'Jihozlash', 'Ochilish'],
        'v' => [
            ['n' => 'Bozor tahlili va joy tanlash', 'f' => 0, 'u' => ['malika', 'madina'], 'b' => 40, 'm' => 22, 's' => 'ok', 'fy' => ['Bozor-tahlili.pdf']],
            ['n' => "Ijara shartnomasi va yuridik ro'yxatdan o'tish", 'f' => 0, 'u' => ['zilola'], 'b' => 30, 'm' => 8, 's' => 'wait', 'dd' => 2, 'dep' => [0]],
            ['n' => 'Filial shtat jadvali va nomzodlarni tanlash', 'f' => 0, 'u' => ['feruza'], 'b' => 20, 'm' => -25, 's' => 'p', 'p' => 50],
            ['n' => 'Ofis va ombor jihozlari xaridi', 'f' => 1, 'u' => ['shahnoza', 'akmal'], 'b' => -10, 'm' => -60, 's' => 'new'],
            ['n' => 'IT infratuzilma va aloqa kanallari', 'f' => 1, 'u' => ['eldor'], 'b' => -30, 'm' => -75, 's' => 'new'],
            ["n" => "Xodimlarni qabul qilish va o'qitish", 'f' => 1, 'u' => ['feruza'], 'b' => -55, 'm' => -100, 's' => 'new'],
            ['n' => 'Marketing: ochilish tadbiri', 'f' => 2, 'u' => ['malika', 'gulnora'], 'b' => -90, 'm' => -130, 's' => 'new'],
            ['n' => 'Filialni rasmiy ochish', 'f' => 2, 'u' => ['madina'], 'b' => -125, 'm' => -140, 's' => 'ack', 'pr' => 'yuqori'],
        ],
        'bosq' => [['Joy tanlandi', 22, true], ['Shtat to\'ldirildi', -100, false], ['Rasmiy ochilish', -140, false]],
        'hujjat' => [['Ijara shartnomasi', 8, 1, false], ["Yuridik shaxs filialini ro'yxatdan o'tkazish guvohnomasi", -5, 1, false]],
        'risk' => [["Malakali xodimlarni topishdagi qiyinchilik", 2, 2, 'feruza', 'ochiq', "Mahalliy kollejlar bilan hamkorlik."], ["Ijara narxining oshishi", 1, 2, 'zilola', 'ochiq', '']],
        'pasport' => ['asos' => "Samarqand va viloyat bo'yicha buyurtmalar oshgan, yetkazib berish 3–4 kun oladi.", 'maqsad' => "2027-yil bahorida filial ochib, viloyat bozorida 12% ulush olish.",
            'nat' => ["Filial rasmiy ochilgan", "18 xodim ishga olingan"], 'mez' => ["Birinchi chorakda sotuv rejasi ≥ 80%"],
            'kiradi' => "Filial ochish.", 'kirmaydi' => "Boshqa viloyatlar.", 'taxmin' => '', 'chek' => '',
            'mf' => [['Direktorlar kengashi', 'Homiy', 3], ['Viloyat hokimligi', 'Ruxsat beruvchi', 2]]],
    ];

    $L[] = ['id' => 'loy09', 'raqam' => 9, 'nomi' => 'Yangi brend va korporativ veb-sayt', 'yo' => 'ichki', 'pm' => 'malika', 'homiy' => 'jasur',
        'tavsif' => "Guruhning yangi brend strategiyasi, firma uslubi va uch tilli korporativ veb-sayt.",
        'byudjet' => 390000000, 'b' => 130,
        'fazalar' => ['Strategiya', 'Dizayn', 'Sayt ishlab chiqish', 'Ishga tushirish'],
        'v' => [
            ['n' => 'Brend strategiyasi va pozitsiyalash', 'f' => 0, 'u' => ['malika'], 'b' => 130, 'm' => 100, 's' => 'ok'],
            ['n' => 'Logotip va firma uslubi (brendbuk)', 'f' => 1, 'u' => ['gulnora'], 'b' => 105, 'm' => 70, 's' => 'ok', 'dep' => [0], 'fy' => ['Brendbuk.pdf']],
            ['n' => 'Veb-sayt maketlari va kontent strukturasi', 'f' => 1, 'u' => ['gulnora', 'malika'], 'b' => 80, 'm' => 50, 's' => 'ok'],
            ['n' => 'Sayt dasturlash va CMS sozlash', 'f' => 2, 'u' => ['dilshod', 'eldor'], 'b' => 55, 'm' => 20, 's' => 'ok', 'dd' => 15],
            ['n' => 'Matn va tarjima (uz/ru/en)', 'f' => 2, 'u' => ['malika'], 'b' => 45, 'm' => 10, 's' => 'late', 'p' => 85, 'sb' => "Ingliz tilidagi matnlar tarjimonda tekshiruvda.", 'vd' => -3],
            ['n' => "SEO sozlash va tahlil tizimlari", 'f' => 2, 'u' => ['sevara'], 'b' => 30, 'm' => 5, 's' => 'late', 'p' => 70, 'sb' => ''],
            ['n' => 'Yuklama sinovi va xavfsizlik tekshiruvi', 'f' => 3, 'u' => ['eldor', 'behzod'], 'b' => 20, 'm' => 12, 's' => 'back', 'nz' => "Sayt 500 ta bir vaqtdagi foydalanuvchida sekinlashdi; keshlashni optimallashtiring.", 'h' => "Yuklama sinovi o'tkazildi."],
            ['n' => 'Saytni ishga tushirish va domen yo\'naltirish', 'f' => 3, 'u' => ['eldor', 'malika'], 'b' => 10, 'm' => 8, 's' => 'late', 'p' => 30, 'sb' => "Yuklama sinovi natijasi kutilmoqda.", 'vd' => -2, 'pr' => 'yuqori'],
        ],
        'bosq' => [['Brendbuk tasdiqlandi', 70, true], ['Sayt beta-versiyasi', 20, true], ['Saytni ishga tushirish', 12, false]],
        'hujjat' => [['Brendbuk (PDF)', 70, 1, true], ['Veb-sayt xavfsizlik xulosasi', 8, 6, false]],
        'risk' => [["Ishga tushirishning yana kechikishi", 3, 2, 'malika', 'ochiq', "Haftalik nazorat va yuklama sinovining ustuvorligi."], ["Tarjima sifati", 2, 1, 'malika', 'ochiq', "Tarjimonning ikkinchi o'qishi."]],
        'pasport' => ['asos' => "Brend va sayt 8 yil yangilanmagan; yirik mijozlar ishonchi uchun zamonaviy ko'rinish kerak.", 'maqsad' => "Yangi brend va sayt bilan so'rovlar sonini 30% oshirish.",
            'nat' => ["Yangi brendbuk", "Uch tilli sayt"], 'mez' => ["Saytga kunlik tashrif ≥ 1 200", "So'rovlar +30%"],
            'kiradi' => "Brend va sayt.", 'kirmaydi' => "Mobil ilova.", 'taxmin' => '', 'chek' => '',
            'mf' => [['Marketing', 'Ijrochi', 3], ['Bosh direktor', 'Homiy', 3]]],
    ];

    $L[] = ['id' => 'loy10', 'raqam' => 10, 'nomi' => "Xodimlarni o'qitish va attestatsiya dasturi (2026–2027)", 'yo' => 'ichki', 'pm' => 'feruza', 'homiy' => 'dilnoza',
        'tavsif' => "Yillik o'quv kalendari, liderlik va texnik kurslar, attestatsiya.", 'byudjet' => 260000000, 'b' => 25,
        'fazalar' => ['Ehtiyojni aniqlash', 'Dastur va kontent', "O'tkazish"],
        'v' => [
            ["n" => "Ko'nikmalar ehtiyojini so'rovnoma orqali aniqlash", 'f' => 0, 'u' => ['feruza', 'lola'], 'b' => 25, 'm' => 5, 's' => 'ok'],
            ["n" => "O'quv byudjetini tasdiqlash", 'f' => 0, 'u' => ['umid', 'otabek'], 'b' => 15, 'm' => -5, 's' => 'p', 'p' => 78],
            ["n" => "Trener va o'quv markazlarini tanlash", 'f' => 1, 'u' => ['feruza'], 'b' => -5, 'm' => -40, 's' => 'new'],
            ["n" => "Yillik o'quv kalendari", 'f' => 1, 'u' => ['feruza', 'dilnoza'], 'b' => -30, 'm' => -60, 's' => 'ack'],
            ["n" => "Liderlik moduli (o'rta bo'g'in rahbarlari)", 'f' => 2, 'u' => ['feruza'], 'b' => -70, 'm' => -120, 's' => 'new'],
            ["n" => "Texnik kurslar (IT, sifat)", 'f' => 2, 'u' => ['behzod', 'bobur'], 'b' => -100, 'm' => -160, 's' => 'new'],
            ["n" => "Attestatsiya va natijalarni baholash", 'f' => 2, 'u' => ['feruza', 'dilnoza'], 'b' => -170, 'm' => -200, 's' => 'new'],
        ],
        'bosq' => [['Byudjet tasdiqlandi', -5, false], ["O'quv kalendari e'lon qilindi", -60, false]],
        'hujjat' => [["O'quv ehtiyoji so'rovnomasi natijalari", 5, 0, true]],
        'risk' => [["Rahbarlar o'qishga vaqt ajratmasligi", 2, 2, 'feruza', 'ochiq', "Kalendarni oldindan kelishish."]],
        'pasport' => ['asos' => "Xodimlar so'rovnomasida 'o'qitish yetarli emas' deganlar 46%.", 'maqsad' => "Har xodimga yiliga kamida 24 soat o'qitish.",
            'nat' => ["Yillik kalendar bajarilgan"], 'mez' => ["O'qitish qamrovi ≥ 85%"], 'kiradi' => "Barcha bo'limlar.", 'kirmaydi' => '', 'taxmin' => '', 'chek' => '',
            'mf' => [['Kadrlar bo\'limi', 'Ijrochi', 3]]],
    ];

    $L[] = ['id' => 'loy11', 'raqam' => 11, 'nomi' => 'Qadoqlash liniyasini modernizatsiya qilish', 'yo' => 'korxona', 'pm' => 'nodir', 'homiy' => 'farrux', 'yakun' => true,
        'tavsif' => "Ishlab chiqarishdagi qadoqlash liniyasini yangi yuqori tezlikdagi uskuna bilan almashtirish.", 'byudjet' => 1650000000, 'b' => 300,
        'fazalar' => ['Loyiha', 'Montaj', 'Ishga tushirish'],
        'v' => [
            ['n' => 'Texnik-iqtisodiy asos (TEO)', 'f' => 0, 'u' => ['farrux', 'umid'], 'b' => 300, 'm' => 260, 's' => 'ok'],
            ['n' => 'Uskunani tanlash va shartnoma', 'f' => 0, 'u' => ['shahnoza', 'nodir'], 'b' => 265, 'm' => 225, 's' => 'ok'],
            ['n' => 'Liniya uchun maydonni tayyorlash', 'f' => 1, 'u' => ['javlon', 'rustam'], 'b' => 230, 'm' => 180, 's' => 'ok'],
            ['n' => 'Uskunani montaj va ulash', 'f' => 1, 'u' => ['nodir', 'eldor'], 'b' => 185, 'm' => 110, 's' => 'ok', 'fy' => ['Montaj-dalolatnomasi.pdf']],
            ['n' => 'Sinov va sozlash', 'f' => 2, 'u' => ['behzod', 'nodir'], 'b' => 115, 'm' => 70, 's' => 'ok'],
            ["n" => "Operatorlarni o'qitish va ishga tushirish", 'f' => 2, 'u' => ['nodir', 'feruza'], 'b' => 75, 'm' => 40, 's' => 'ok'],
        ],
        'bosq' => [['Uskuna yetkazildi', 180, true], ['Liniya ishga tushdi', 40, true]],
        'hujjat' => [['Ishga tushirish dalolatnomasi', 40, 5, true]],
        'risk' => [["Uskuna sinovda tezlikka chiqmasligi", 2, 3, 'nodir', 'yopilgan', "Sinov ishlatishda 100% quvvatga chiqdi."]],
        'pasport' => ['asos' => "Eski liniya tezligi buyurtmalarning 60% ini qoplardi.", 'maqsad' => "Qadoqlash tezligini 2,2 barobar oshirish.",
            'nat' => ["Yangi liniya ishga tushgan"], 'mez' => ["Tezlik ≥ 240 dona/daq"], 'kiradi' => "Qadoqlash sexi.", 'kirmaydi' => '', 'taxmin' => '', 'chek' => '',
            'mf' => [['Ishlab chiqarish', 'Foydalanuvchi', 3]]],
    ];

    /* loyihalarni yozish */
    // loyihaga asos bo'luvchi hujjatlar: [nomi, turi, raqami, necha kun oldin, fayl bormi]
    $ASOS = [
        'loy01' => [["Qurilish pudrat shartnomasi", 'Shartnoma', 'QP-2026/014', 205, true], ["Loyihani ochish haqida buyruq", 'Buyruq / farmoyish', 'B-41', 210, true],
            ["Loyihalash topshirig'i", 'Texnik topshiriq', 'TT-2026/07', 200, false], ["Qurilish smetasi (4-versiya)", 'Smeta / byudjet', 'S-2026/03', 190, true]],
        'loy02' => [["ERP yetkazib berish shartnomasi", 'Shartnoma', 'IT-2026/021', 170, true], ["Biznes talablar hujjati (BRD)", 'Texnik topshiriq', 'BRD-v3', 160, true],
            ["Loyihani boshlash haqida buyruq", 'Buyruq / farmoyish', 'B-58', 175, false]],
        'loy03' => [["Mobil ilova texnik topshirig'i", 'Texnik topshiriq', 'TT-2026/11', 140, true], ["UX tadqiqot hisoboti", 'Loyiha hujjati', 'UX-2026/02', 120, false],
            ["G'oya qarori (taxtadan)", 'Buyruq / farmoyish', 'Q-2026/22', 150, false]],
        'loy04' => [["RFID uskunalari yetkazish shartnomasi", 'Shartnoma', 'XR-2026/009', 110, true], ["Ombor jarayonlari xaritasi", 'Loyiha hujjati', 'LOG-2026/01', 130, false],
            ["Pilot sinov dasturi kelishuvi", 'Kelishuv / xat', 'LOG-2026/03', 100, false]],
        'loy05' => [["Quyosh panellari montaj shartnomasi", 'Shartnoma', 'EN-2026/005', 95, true], ["Texnik-iqtisodiy asoslash", 'Loyiha hujjati', 'TEA-2026/02', 115, true],
            ["Elektr tarmog'iga ulanish bo'yicha kelishuv xati", 'Kelishuv / xat', 'EN-2026/12', 105, false]],
        'loy06' => [["Hujjat aylanishi tizimi shartnomasi", 'Shartnoma', 'IT-2026/004', 320, true], ["Qabul-topshirish dalolatnomasi", 'Loyiha hujjati', 'QT-2026/17', 40, true]],
        'loy07' => [["ISO 9001 sertifikatsiya shartnomasi", 'Shartnoma', 'SN-2026/006', 150, true], ["Sifat siyosati (loyiha varianti)", 'Loyiha hujjati', 'SM-2026/01', 140, false],
            ["Auditorlik tashkiloti bilan kelishuv", 'Kelishuv / xat', 'SN-2026/09', 145, false]],
        'loy08' => [["Filial ijara shartnomasi", 'Shartnoma', 'IJ-2026/003', 60, true], ["Filial ochish haqida buyruq", 'Buyruq / farmoyish', 'B-77', 70, false],
            ["Filial byudjeti", 'Smeta / byudjet', 'S-2026/11', 65, true]],
        'loy09' => [["Brend strategiyasi (qisqacha)", 'Loyiha hujjati', 'MKT-2026/02', 90, true], ["Veb-sayt texnik topshirig'i", 'Texnik topshiriq', 'TT-2026/15', 85, false]],
        'loy10' => [["O'quv dasturi haqida buyruq", 'Buyruq / farmoyish', 'B-69', 80, true], ["Kompetensiyalar matritsasi", 'Loyiha hujjati', 'HR-2026/05', 75, false]],
        'loy11' => [["Qadoqlash liniyasi yetkazib berish shartnomasi", 'Shartnoma', 'IS-2026/002', 300, true], ["Ishga tushirish dalolatnomasi", 'Loyiha hujjati', 'IS-2026/31', 45, true]],
    ];
    foreach ($L as $x) {
        $pid = $x['id'];
        $pmUn = $U[$x['pm']]['un'];
        $fz = [];
        foreach ($x['fazalar'] as $k => $fn) $fz[$k] = 'fz_' . $pid . '_' . ($k + 1);
        $vaz = [];
        foreach ($x['v'] as $i => $s) $vaz[] = $vazifa($pid, $i, $s, $fz, $pmUn);
        // bog'liqlik (kritik yo'l hisobi): vazifa o'zidan oldingisi tugaganidan KEYINGI kunda boshlansin,
        // aks holda Gantt'da «oldingisi tugamasdan boshlanadi» ogohlantirishi chiqadi
        $ix = [];
        foreach ($vaz as $k => $t) $ix[$t['id']] = $k;
        for ($pass = 0; $pass < 8; $pass++) {
            $ch = false;
            foreach ($vaz as $k => $t) {
                foreach ((array)($t['dependsOn'] ?? []) as $dep) {
                    if (!isset($ix[$dep])) continue;
                    $need = date('Y-m-d', strtotime($vaz[$ix[$dep]]['muddat'] . ' +1 day'));
                    if ($need > $vaz[$k]['boshlanish']) {
                        $ch = true;
                        if ($need <= $vaz[$k]['muddat']) $vaz[$k]['boshlanish'] = $need;
                        else { $vaz[$k]['dependsOn'] = null; break; }
                    }
                }
            }
            if (!$ch) break;
        }
        $allDone = true; $anyProg = false;
        foreach ($vaz as $t) { if ($t['holat'] !== 'bajarilgan') $allDone = false; if (($t['progress'] ?? 0) > 0) $anyProg = true; }
        $holat = !empty($x['yakun']) ? 'yakunlangan' : ($anyProg ? 'jarayonda' : 'rejalashtirilgan');
        $mud = '';
        foreach ($vaz as $t) if ($t['muddat'] > $mud) $mud = $t['muddat'];
        $p = ['id' => $pid, 'raqam' => $x['raqam'], 'nomi' => $x['nomi'], 'yonalish' => $x['yo'], 'korxonaId' => $KID,
            'tavsif' => $x['tavsif'], 'holat' => $holat, 'masul' => $U[$x['pm']]['fio'], 'boshIjrochi' => $pmUn,
            'boshlanish' => bw_date($x['b']), 'muddat' => $mud, 'byudjet' => $x['byudjet'], 'vazifalar' => $vaz];
        $p['fazalar'] = array_map(fn($k, $fn) => ['id' => $fz[$k], 'nomi' => $fn], array_keys($x['fazalar']), $x['fazalar']);
        $p['bosqichlar'] = [];
        foreach ($x['bosq'] as $k => [$bn, $bd, $bj]) $p['bosqichlar'][] = ['id' => 'ms_' . $pid . '_' . ($k + 1), 'nomi' => $bn, 'sana' => bw_date($bd), 'bajarilgan' => $bj];
        $p['hujjatTalablari'] = [];
        foreach ($x['hujjat'] as $k => [$hn, $hd, $hv, $hf]) {
            $r = ['id' => 'dr_' . $pid . '_' . ($k + 1), 'nomi' => $hn, 'muddat' => bw_date($hd), 'vazifaId' => $hv !== null ? sprintf('t_%s_%02d', $pid, $hv + 1) : null, 'fayl' => null];
            if ($hf) {
                $f = $fayl(preg_replace('/[^A-Za-z0-9._-]+/', '-', str_replace(["'", ' '], ['', '-'], $hn)) . '.pdf', 'loyiha', 'L-' . $pid, $pmUn, max(2, $hd - 1),
                    ['Namuna hujjat: ' . $hn, 'Loyiha: ' . $x['nomi'], "Demo uchun xayoliy ma'lumot."]);
                $r['fayllar'] = [$f]; $r['fayl'] = $f;
            }
            $p['hujjatTalablari'][] = $r;
        }
        $p['asosHujjatlar'] = [];
        foreach (($ASOS[$pid] ?? []) as $k => [$an, $at, $ar, $ag, $af]) {
            $a = ['id' => 'as_' . $pid . '_' . ($k + 1), 'nomi' => $an, 'tur' => $at, 'raqam' => $ar, 'sana' => bw_date($ag), 'izoh' => '',
                'fayllar' => [], 'yuklaganKim' => $pmUn, 'yuklanganSana' => bw_date($ag)];
            if ($af) {
                $a['fayllar'] = [$fayl(preg_replace('/[^A-Za-z0-9._-]+/', '-', str_replace(["'", ' '], ['', '-'], $an)) . '.pdf', 'loyiha', 'L-' . $pid, $pmUn, $ag,
                    ['Namuna hujjat: ' . $an, 'Raqami: ' . $ar, 'Loyiha: ' . $x['nomi'], "Demo uchun xayoliy ma'lumot."])];
            }
            $p['asosHujjatlar'][] = $a;
        }
        $p['risklar'] = [];
        foreach ($x['risk'] as $k => [$rn, $re, $rt, $rg, $rh, $rc]) {
            $r = ['id' => 'rk_' . $pid . '_' . ($k + 1), 'nomi' => $rn, 'ehtimol' => $re, 'taSir' => $rt, 'egasi' => $U[$rg]['fio'], 'holat' => $rh, 'chora' => $rc];
            if ($rh === 'yopilgan') $r['yopilgan'] = bw_date(20 + $k * 9);
            $p['risklar'][] = $r;
        }
        $q = $x['pasport'];
        $nz = fn($arr, $pf) => array_map(fn($k, $v) => ['id' => $pf . $pid . $k, 'nomi' => $v, 'bajarildi' => $holat === 'yakunlangan'], array_keys($arr), $arr);
        $p['pasport'] = ['asos' => $q['asos'], 'maqsad' => $q['maqsad'], 'natijalar' => $nz($q['nat'], 'nt'), 'mezonlar' => $nz($q['mez'], 'mz'),
            'doiraKiradi' => $q['kiradi'], 'doiraKirmaydi' => $q['kirmaydi'], 'taxminlar' => $q['taxmin'], 'cheklovlar' => $q['chek'],
            'homiy' => $U[$x['homiy']]['fio'], 'manfaatdorlar' => []];
        foreach ($q['mf'] as $k => [$mn, $mr, $ms]) $p['pasport']['manfaatdorlar'][] = ['id' => 'mf' . $pid . $k, 'ism' => $mn, 'rol' => $mr, 'taSir' => $ms];
        // bazaviy reja (reja-fakt uchun); oxirgi vazifa(lar) keyin qo'shilgan hisoblanadi
        if ($holat !== 'rejalashtirilgan') {
            $ids = array_map(fn($t) => $t['id'], $vaz);
            $drop = $pid === 'loy02' ? 2 : ($pid === 'loy09' ? 1 : 0);
            $p['pasport']['baza'] = ['sana' => bw_date($x['b'] - 3), 'kim' => $pmUn, 'boshlanish' => bw_date($x['b']), 'muddat' => bw_date(max(-300, (int)(array_reduce($vaz, fn($c, $t) => max($c, (int)round((strtotime(bw_date(0)) - strtotime($t['muddat'])) / 86400)), -9999)) - ($pid === 'loy01' ? -12 : 0))),
                'byudjet' => (int)round($x['byudjet'] * 0.94), 'vazifaIds' => array_slice($ids, 0, count($ids) - $drop)];
            $rv = [];
            foreach ($vaz as $t) {
                $sh = ($t['holat'] !== 'bajarilgan' && $t['muddat'] < bw_date(0)) ? 6 : (($t['progress'] ?? 0) > 0 && $t['holat'] !== 'bajarilgan' ? 3 : 0);
                $rv[$t['id']] = ['boshlanish' => $t['boshlanish'], 'muddat' => date('Y-m-d', strtotime($t['muddat'] . " -$sh days"))];
            }
            $p['reja'] = ['olingan' => bw_date($x['b'] - 8), 'muddat' => $mud, 'vazifalar' => $rv];
        }
        $act = [];
        foreach (array_slice(array_reverse($vaz), 0, 4) as $k => $t) $act[] = ['text' => 'Yangi vazifa qo\'shildi: "' . $t['nomi'] . '"', 'time' => bw_date(max(0, $x['b'] - 5 - $k))];
        $act[] = ['text' => 'Loyiha yaratildi', 'time' => bw_date($x['b'] + 6)];
        $p['activity'] = $act;
        $put('loyiha', $pid, $p);
        $loyihalar[$pid] = $p;
    }

    /* ------------------------------------------------------------------ g'oyalar */
    $FK = fn($kim, $q, $iz, $ago) => ['kim' => $kim, 'qaror' => $q, 'izoh' => $iz, 'sana' => bw_date($ago), 'fayllar' => []];
    $goyalar = [
        ['raqam' => 1, 'nomi' => "Mijozlar uchun yagona shaxsiy kabinet (veb va mobil)", 'tak' => 'bobur', 'ago' => 130, 'yo' => 'korxona', 'byudjet' => 620000000, 'muddat' => '6 oy',
            'tavsif' => "Mijoz buyurtma holati, to'lov va hujjatlarini bitta kabinetda ko'radi, qo'llab-quvvatlashga murojaat kamayadi.",
            'natija' => "Murojaat 40% ga kamayadi, takroriy buyurtma 12% ga oshadi.", 'k' => ['dilnoza', 'farrux', 'malika', 'otabek'], 'qar' => 'jasur', 'holat' => 'qabul', 'loy' => 'loy03',
            'fk' => [['dilnoza', 'qollab', "Portfelga mos, mijoz tajribasini yaxshilaydi.", 126], ['farrux', 'ozgartirish', "Avval API qatlamini barqarorlashtirish kerak; muddatni 7 oyga uzaytiring.", 125], ['malika', 'qollab', "Brend loyihasi bilan birga chiqarish mumkin.", 124], ['otabek', 'qollab', "Byudjet doirasida; to'lov integratsiyasi xarajatini aniqlashtiring.", 123]],
            'rez' => ["Qabul qilindi. Loyiha ofisi boshqaradi, muddat 6 oy.", 'jasur', 120]],
        ['raqam' => 2, 'nomi' => "Omborda shtrix-kod va RFID orqali hisob yuritish", 'tak' => 'timur', 'ago' => 160, 'yo' => 'korxona', 'byudjet' => 780000000, 'muddat' => '5 oy',
            'tavsif' => "Mahsulot harakati real vaqtda yuritilib, inventarizatsiya 6 kundan 1 kunga tushadi.", 'natija' => "Qoldiq xatosi 2,1% dan 1% gacha kamayadi.",
            'k' => ['otabek', 'akmal', 'behzod'], 'qar' => 'otabek', 'holat' => 'qabul', 'loy' => 'loy04',
            'fk' => [['otabek', 'qollab', "Investitsiya 14 oyda qaytadi.", 150], ['akmal', 'qollab', "Ombor xodimlari uchun qulay bo'ladi.", 149], ['behzod', 'qollab', "Sifat nazorati uchun ham foydali.", 148]],
            'rez' => ["Tasdiqlandi — 2-chorakdan boshlash.", 'otabek', 144]],
        ['raqam' => 3, 'nomi' => "Bosh bino tomida quyosh panellari o'rnatish", 'tak' => 'rustam', 'ago' => 100, 'yo' => 'ichki', 'byudjet' => 940000000, 'muddat' => '8 oy',
            'tavsif' => "120 kVt quvvatli quyosh stansiyasi elektr xarajatini kamaytiradi.", 'natija' => "Elektr xarajati 38% ga kamayadi.",
            'k' => ['farrux', 'otabek', 'sardor'], 'qar' => 'farrux', 'holat' => 'qabul', 'loy' => 'loy05',
            'fk' => [['farrux', 'qollab', "Texnik jihatdan amalga oshirsa bo'ladi.", 95], ['otabek', 'ozgartirish', "Moliyalashtirish rejasini alohida ko'rib chiqamiz.", 94], ['sardor', 'qollab', "Tom konstruksiyasi yetarli.", 93]],
            'rez' => ["Qabul qilindi.", 'farrux', 90]],
        ['raqam' => 4, 'nomi' => "Ofisda 4 kunlik ish haftasini sinab ko'rish", 'tak' => 'feruza', 'ago' => 75, 'yo' => 'ichki', 'byudjet' => 0, 'muddat' => '3 oy',
            'tavsif' => "Uch oylik tajriba: dushanbadan payshanbagacha 10 soatlik ish kuni.", 'natija' => "Xodimlar qoniqishi va mahsuldorlik o'sishi kutilmoqda.",
            'k' => ['jasur', 'dilnoza', 'nodir', 'otabek'], 'qar' => 'jasur', 'holat' => 'rad', 'loy' => null,
            'fk' => [['dilnoza', 'qollab', "Qoniqish uchun yaxshi.", 70], ['nodir', 'qarshi', "Ishlab chiqarish smenalariga mos kelmaydi.", 69], ['otabek', 'ozgartirish', "Faqat ofis bo'limlari uchun sinab ko'rish mumkin.", 68]],
            'rez' => ["Hozircha rad etildi: ishlab chiqarish va logistika grafiklariga mos emas. Keyingi yilda ofis bo'limlari uchun qayta ko'rib chiqamiz.", 'jasur', 62]],
        ['raqam' => 5, 'nomi' => "Xodimlar uchun ichki bilim almashish platformasi", 'tak' => 'sevara', 'ago' => 12, 'yo' => 'ichki', 'byudjet' => 85000000, 'muddat' => '3 oy',
            'tavsif' => "Bo'limlar tajribasi, yo'riqnomalar va savol-javoblar yagona qidiriladigan platformada.", 'natija' => "Yangi xodimning moslashish muddati 30% ga qisqaradi.",
            'k' => ['feruza', 'dilnoza', 'bobur', 'umid'], 'qar' => $ME, 'holat' => 'korib', 'loy' => null,
            'fk' => [['feruza', 'qollab', "Yangi xodimlar uchun juda foydali.", 9], ['bobur', 'ozgartirish', "Mavjud hujjat tizimi bilan birlashtirish kerak.", 8]],
            'rez' => null],
        ['raqam' => 6, 'nomi' => "Yetkazib beruvchilar bahosini avtomatik yuritish (scorecard)", 'tak' => 'akmal', 'ago' => 6, 'yo' => 'ichki', 'byudjet' => 40000000, 'muddat' => '2 oy',
            'tavsif' => "Har yetkazib beruvchi: muddat, sifat va narx bo'yicha oylik ball.", 'natija' => "Yomon yetkazib beruvchilar vaqtida aniqlanadi.",
            'k' => ['otabek', 'shahnoza', 'behzod'], 'qar' => 'jasur', 'holat' => 'korib', 'loy' => null,
            'fk' => [['shahnoza', 'qollab', "Tender qarorlarini asoslashga yordam beradi.", 4]], 'rez' => null],
        ['raqam' => 7, 'nomi' => "Qog'ozsiz ofis: elektron hujjat aylanishi", 'tak' => 'lola', 'ago' => 290, 'yo' => 'ichki', 'byudjet' => 310000000, 'muddat' => '8 oy',
            'tavsif' => "Hujjatlarni elektron tasdiqlash va saqlash.", 'natija' => "Tasdiqlash muddati 6 kundan 1 kunga tushadi.",
            'k' => ['dilnoza', 'zilola', 'bobur'], 'qar' => 'jasur', 'holat' => 'qabul', 'loy' => 'loy06',
            'fk' => [['dilnoza', 'qollab', "Muhim tashabbus.", 285], ['zilola', 'ozgartirish', "Elektron imzo masalasini huquqiy tomondan ko'rib chiqing.", 284], ['bobur', 'qollab', "Texnik jihatdan oson.", 283]],
            'rez' => ["Qabul qilindi; loyiha ofisiga topshirildi.", 'jasur', 280]],
        ['raqam' => 8, 'nomi' => "Mijozlarga 24/7 chat-bot orqali qo'llab-quvvatlash", 'tak' => 'sevara', 'ago' => 9, 'yo' => 'korxona', 'byudjet' => 120000000, 'muddat' => '4 oy',
            'tavsif' => "Tez-tez beriladigan savollarga avtomatik javob; murakkablarini operatorga yo'naltirish.", 'natija' => "Operatorlarga yuklama 35% ga kamayadi.",
            'k' => [$ME, 'malika', 'bobur', 'madina'], 'qar' => 'dilnoza', 'holat' => 'korib', 'loy' => null,
            'fk' => [['malika', 'qollab', "Mijoz tajribasini yaxshilaydi.", 6], ['bobur', 'ozgartirish', "Mobil ilova loyihasi bilan birlashtirish kerak.", 5], ['madina', 'qollab', "Ilova yo'l xaritasiga kiritsak bo'ladi.", 4]], 'rez' => null],
        ['raqam' => 9, 'nomi' => "Mehnat muhofazasi bo'yicha mobil tekshiruv varaqalari", 'tak' => 'behzod', 'ago' => 3, 'yo' => 'ichki', 'byudjet' => 30000000, 'muddat' => '2 oy',
            'tavsif' => "Prorablar telefonda xavfsizlik ko'rigini to'ldiradi, buzilishlar avtomatik hisobotga tushadi.", 'natija' => "Xavfsizlik hodisalari 25% ga kamayadi.",
            'k' => [], 'qar' => 'farrux', 'holat' => 'yangi', 'loy' => null, 'fk' => [], 'rez' => null],
        ['raqam' => 10, 'nomi' => "Hududiy filial: Samarqandda sotuv va servis markazi", 'tak' => 'malika', 'ago' => 85, 'yo' => 'korxona', 'byudjet' => 1100000000, 'muddat' => '10 oy',
            'tavsif' => "Viloyat mijozlariga yetkazib berish va servisni yaxshilash uchun filial.", 'natija' => "Viloyat bozorida 12% ulush.",
            'k' => ['jasur', 'otabek', 'dilnoza', 'feruza'], 'qar' => 'jasur', 'holat' => 'qabul', 'loy' => 'loy08',
            'fk' => [['otabek', 'qollab', "Rentabellik hisobi ijobiy.", 80], ['dilnoza', 'qollab', "Loyiha ofisi boshqaradi.", 79], ['feruza', 'ozgartirish', "Kadrlar rejasini oldindan tayyorlash kerak.", 78]],
            'rez' => ["Qabul qilindi; 2027-yil bahorida ochish rejalashtirildi.", 'jasur', 74]],
        ['raqam' => 11, 'nomi' => "Mijozlar fikrini yig'ish uchun QR-so'rovnoma", 'tak' => 'malika', 'ago' => 5, 'yo' => 'korxona', 'byudjet' => 15000000, 'muddat' => '1 oy',
            'tavsif' => "Filial va ofislarda QR-kod orqali 3 savolli so'rovnoma; natijalar haftalik hisobotga tushadi.", 'natija' => "Mijoz qoniqishi har hafta o'lchanadi.",
            'k' => ['dilnoza', 'madina', 'sevara'], 'qar' => 'dilnoza', 'holat' => 'korib', 'loy' => null,
            'fk' => [['madina', 'qollab', "Arzon va tez joriy qilinadi.", 3], ['sevara', 'ozgartirish', "Ma'lumotlarni mavjud CRM bilan bog'lash kerak.", 2]], 'rez' => null],
        ['raqam' => 12, 'nomi' => "Ombor uchun elektrokarlar parkini yangilash", 'tak' => 'nodir', 'ago' => 2, 'yo' => 'ichki', 'byudjet' => 260000000, 'muddat' => '5 oy',
            'tavsif' => "Eskirgan dizel yuk ko'targichlar o'rniga 6 ta elektrokar: yoqilg'i va ta'mir xarajati kamayadi.", 'natija' => "Yillik xarajat 28% ga kamayadi.",
            'k' => ['otabek', 'timur', 'jasur'], 'qar' => 'otabek', 'holat' => 'korib', 'loy' => null,
            'fk' => [['timur', 'qollab', "Ombor xodimlari uchun xavfsizroq.", 1]], 'rez' => null],
        ['raqam' => 13, 'nomi' => "Loyiha ofisi uchun shablonlar kutubxonasi (WBS, risk, hisobot)", 'tak' => 'lola', 'ago' => 1, 'yo' => 'ichki', 'byudjet' => 0, 'muddat' => '1 oy',
            'tavsif' => "Yangi loyiha ochishda tayyor bosqich, risk va hujjat shablonlaridan foydalanish.", 'natija' => "Loyihani ochish vaqti 3 kundan 1 kunga tushadi.",
            'k' => [], 'qar' => 'dilnoza', 'holat' => 'yangi', 'loy' => null, 'fk' => [], 'rez' => null],
    ];
    foreach ($goyalar as $g) {
        $un = fn($k) => $k === $ME ? $ME : $U[$k]['un'];
        $korch = array_map($un, $g['k']);
        $fik = []; $tar = [];
        foreach ($g['fk'] as [$k, $q, $iz, $ago]) { $f = $FK($un($k), $q, $iz, $ago); $fik[] = $f; $tar[] = $f + ['ts' => $iso($ago, 14, 30)]; }
        $o = ['id' => 'g_' . $g['raqam'], 'raqam' => $g['raqam'], 'nomi' => $g['nomi'], 'tavsif' => $g['tavsif'], 'natija' => $g['natija'], 'byudjet' => $g['byudjet'],
            'muddat' => $g['muddat'], 'yonalish' => $g['yo'], 'korchilar' => $korch, 'navbatma' => false, 'qarorchi' => $un($g['qar']), 'fayllar' => [],
            'taklifchi' => $un($g['tak']), 'sana' => bw_date($g['ago']), 'holat' => $g['holat'], 'fikrlar' => $fik, 'fikrTarixi' => $tar, 'izohlar' => [], 'rezolyutsiya' => null, 'loyihaId' => $g['loy']];
        if ($g['rez']) $o['rezolyutsiya'] = ['matn' => $g['rez'][0], 'kim' => $un($g['rez'][1]), 'sana' => bw_date($g['rez'][2])];
        if ($g['loy']) $o['loyihaSana'] = bw_date($g['rez'][2] - 1);
        if ($g['raqam'] === 1) $o['izohlar'][] = ['id' => 'gc_1_1', 'ts' => $iso(122, 12, 5), 'kim' => $U['jasur']['un'], 'matn' => "Mobil ilova va brend loyihasi bir-biriga bog'liq, muvofiqlashtirib boring.", 'fayllar' => []];
        if ($g['raqam'] === 5) $o['izohlar'][] = ['id' => 'gc_5_1', 'ts' => $iso(7, 10, 40), 'kim' => $U['sevara']['un'], 'matn' => "Prototip sifatida mavjud hujjat tizimidan foydalansak bo'ladi, 2 hafta ichida ko'rsataman.", 'fayllar' => []];
        $put('goya', $o['id'], $o);
    }

    /* ------------------------------------------------------------------ topshiriqlar */
    $TP = [];
    // [beruvchi, [bajaruvchilar], nomi, tavsif, muddat(ago), muhimlik, holat, yaratilgan(ago), bajarildiAgo|null, bajarildiIzoh, takror|null]
    $TP[] = [$ME, ['dilnoza'], "Choraklik loyihalar portfeli hisobotini tayyorlash", "Barcha faol loyihalar: bosqich, byudjet ijrosi, kritik risklar va rahbariyat qarori talab qilinadigan masalalar.", -6, 'yuqori', 'bajarilmoqda', 9];
    $TP[] = [$ME, ['otabek', 'umid'], "Loyihalar byudjetining reja-fakt tahlilini taqdim etish", "Har loyiha bo'yicha sarf, qoldiq va yil oxirigacha prognoz.", 2, 'yuqori', 'bajarilmoqda', 14];
    $TP[] = [$ME, ['farrux'], "Yangi ofis binosi: texnik nazorat natijalari bo'yicha qisqa ma'lumot", "Karkas ishlari bo'yicha laboratoriya sinovlari va nazoratchi xulosasi.", 1, 'orta', 'bajarildi', 8, 1, "Ma'lumot tayyor: beton mustahkamligi me'yordan 8% yuqori, nazoratchi xulosasi ilova qilindi."];
    $TP[] = [$ME, ['feruza'], "O'qitish dasturi byudjeti uchun uchta variant tayyorlash", "Minimal, o'rtacha va to'liq qamrovli variantlar taqqoslab berilsin.", -9, 'orta', 'yangi', 1];
    $TP[] = [$ME, ['malika', 'gulnora'], "Yangi brend sayti ishga tushirilishi bo'yicha e'lon rejasi", "Ijtimoiy tarmoqlar, mijozlarga xat va matbuot xabari jadvali.", -4, 'orta', 'yangi', 2];
    $TP[] = [$ME, ['akmal'], "Yetkazib beruvchilar ro'yxatini yangilash va baholash", "Faol 38 yetkazib beruvchi: muddat, sifat, narx bo'yicha qisqa baho.", 14, 'orta', 'yopilgan', 34, 16, "Ro'yxat yangilandi, 5 ta yetkazib beruvchi 'kuzatuv'ga qo'yildi."];
    $TP[] = [$ME, ['zilola'], "Pudratchi shartnomasi shablonini yangilash", "Jarima, kafolat va kechikish bandlarini zamonaviylashtirish.", 29, 'orta', 'yopilgan', 55, 31, "Yangi shablon tasdiqlandi, barcha bo'limlarga yuborildi."];
    $TP[] = [$ME, ['bobur'], "Haftalik: loyihalar holati bo'yicha yig'ilish bayonnomasi", "Har dushanba yig'ilishidan keyin bayonnoma va qarorlar ro'yxati.", 0, 'orta', 'bajarilmoqda', 60, null, '', 'haftalik'];
    $TP[] = ['jasur', [$ME], "Direktorlar kengashi uchun loyihalar yig'ma taqdimoti", "Bosh direktor kengashiga 10 daqiqalik yig'ma taqdimot: holat, xavflar, qaror kerak masalalar.", -3, 'yuqori', 'yangi', 1];
    $TP[] = ['dilnoza', [$ME], "Yangi loyiha g'oyalarini ko'rib chiqish (3 ta g'oya)", "G'oyalar taxtasidagi muhokamadagi g'oyalar bo'yicha qaror yoki izoh.", -5, 'orta', 'bajarilmoqda', 4];
    $TP[] = ['otabek', [$ME], "Yillik byudjetga loyiha xarajatlari bo'yicha tasdiq", "2027-yil byudjetiga kiritiladigan loyiha xarajatlari ro'yxatini tasdiqlash.", 3, 'yuqori', 'bajarilmoqda', 16];
    $TP[] = ['jasur', [$ME, 'dilnoza'], "Filial ochish bo'yicha yakuniy qaror", "Samarqand filiali: byudjet, shtat va muddat bo'yicha yakuniy kelishuv.", -10, 'yuqori', 'yangi', 2];
    $TP[] = ['jasur', ['dilnoza'], "Loyihalar ofisi uchun yangi nizom loyihasi", "Vazifalar, mas'uliyat va hisobot tartibi.", -7, 'orta', 'bajarilmoqda', 18];
    $TP[] = ['jasur', ['otabek'], "Chorak yakunlari bo'yicha moliyaviy xulosa", "Daromad, xarajat va loyiha moliyalashtirishi bo'yicha qisqa xulosa.", 2, 'yuqori', 'bajarildi', 12, 1, "Xulosa tayyor: reja 97% bajarilgan, loyiha xarajatlari byudjet doirasida."];
    $TP[] = ['dilnoza', ['madina', 'lola'], "Barcha faol loyihalar uchun risk reyestrini yangilash", "Yangi risklar, javob choralari va mas'ullar.", 1, 'orta', 'bajarilmoqda', 10];
    $TP[] = ['dilnoza', ['bobur'], "ERP: foydalanuvchi rollari matritsasini kelishish", "Bo'lim rahbarlari bilan rollar va huquqlar jadvalini kelishish.", -2, 'orta', 'yangi', 3];
    $TP[] = ['dilnoza', ['lola'], "Loyiha statuslari bo'yicha haftalik hisobot (har dushanba)", "Har dushanba kuni barcha faol loyihalar holati bitta sahifada.", 0, 'orta', 'bajarilmoqda', 70, null, '', 'haftalik'];
    $TP[] = ['farrux', ['sardor', 'nigora'], "Binoning elektr quvvati hisobini qayta tekshirish", "Quyosh panellari qo'shilgach umumiy yuklamani qayta hisoblash.", -3, 'orta', 'bajarilmoqda', 6];
    $TP[] = ['farrux', ['rustam'], "Quyosh panellari uchun shartnoma loyihasi", "Yetkazib beruvchi bilan shartnoma loyihasi va texnik ilova.", 20, 'orta', 'yopilgan', 45, 22, "Shartnoma loyihasi yuristlar bilan kelishildi."];
    $TP[] = ['otabek', ['umid'], "Oylik byudjet ijrosi hisoboti", "Har oy 5-sanasigacha oldingi oy bo'yicha byudjet ijrosi.", -14, 'orta', 'bajarilmoqda', 200, null, '', 'oylik'];
    $TP[] = ['otabek', ['shahnoza'], "Uch yetkazib beruvchidan taklif narxlarini solishtirish", "RFID skanerlari uchun narx, kafolat va yetkazib berish muddati.", 3, 'orta', 'bajarildi', 10, 2, "Solishtirma jadval tayyor, eng maqbul taklif 2-yetkazib beruvchi."];
    $TP[] = ['feruza', ['lola'], "Yangi xodimlar uchun tanishuv paketi", "Birinchi hafta rejasi, kerakli hujjatlar va aloqa ro'yxati.", 25, 'past', 'yopilgan', 40, 26, "Paket tayyor va kadrlar portaliga joylandi."];
    $TP[] = ['akmal', ['shahnoza', 'timur'], "Ombor qoldiqlari inventarizatsiyasi", "Markaziy ombor bo'yicha oylik inventarizatsiya.", 4, 'yuqori', 'bajarilmoqda', 18];
    $TP[] = ['malika', ['gulnora'], "Yangi brend bannerlari to'plami", "Veb va ijtimoiy tarmoqlar uchun 12 ta banner.", 4, 'orta', 'bajarildi', 12, 3, "12 ta banner tayyor, papka ulashildi."];
    $TP[] = ['zilola', ['feruza'], "Mehnat shartnomalari shablonlarini yangilash", "Yangi qonunchilik talablariga moslash.", -6, 'orta', 'yangi', 1];
    $TP[] = ['nodir', ['behzod'], "Sifat nazorati jurnalini raqamlashtirish", "Qog'oz jurnalni elektron shaklga o'tkazish.", -12, 'orta', 'bajarilmoqda', 22];
    $TP[] = ['sardor', ['javlon'], "Qurilish maydoni xavfsizlik ko'rigi (har hafta)", "Har juma kuni xavfsizlik ko'rigi bayonnomasi.", 0, 'yuqori', 'bajarilmoqda', 80, null, '', 'haftalik'];
    $TP[] = ['jasur', ['farrux', 'otabek', 'dilnoza'], "Direktorlar kengashi: 2027-yil strategik maqsadlar loyihasi", "Har direktor o'z yo'nalishi bo'yicha 3 ta maqsad taklif qiladi.", -20, 'yuqori', 'bajarilmoqda', 5];
    $TP[] = ['dilnoza', ['eldor'], "Zaxira nusxalash va tiklash sinovi", "Loyiha ma'lumotlarini tiklash sinovi va hisobot.", -2, 'orta', 'qaytarilgan', 25, null, ''];
    $TP[] = ['otabek', ['umid'], "Q2 byudjet ijrosi hisoboti", "Ikkinchi chorak yakunlari.", 90, 'orta', 'yopilgan', 120, 92, "Hisobot topshirildi va qabul qilindi."];
    foreach ($TP as $i => $r) {
        [$ber, $baj, $nomi, $tav, $mud, $muh, $holat, $yar] = $r;
        $bajAgo = $r[8] ?? null; $bajIz = $r[9] ?? ''; $takror = $r[10] ?? null;
        $un = fn($k) => $k === $ME ? $ME : $U[$k]['un'];
        $beruvchi = $un($ber);
        $bajUn = array_map($un, $baj);
        $kor = [$beruvchi => true];
        if (in_array($holat, ['bajarilmoqda', 'bajarildi', 'yopilgan', 'qaytarilgan'], true) || ($takror)) foreach ($bajUn as $u) $kor[$u] = true;
        $t = ['id' => 'tp_' . sprintf('%02d', $i + 1), 'raqam' => $i + 1, 'beruvchi' => $beruvchi, 'bajaruvchilar' => $bajUn, 'nomi' => $nomi, 'tavsif' => $tav,
            'muddat' => bw_date($mud), 'muhimlik' => $muh, 'holat' => $holat === 'qaytarilgan' ? 'bajarilmoqda' : $holat, 'yaratilgan' => $iso($yar, 9, 40), 'bajarildi' => null,
            'korganlar' => $kor, 'takror' => $takror ?: '', 'takrorTugash' => '', 'tarix' => []];
        if ($bajAgo !== null && in_array($holat, ['bajarildi', 'yopilgan'], true)) {
            $f = $fayl('Topshiriq-hisoboti-' . ($i + 1) . '.pdf', 'topshiriq', 'T-' . sprintf('%03d', $i + 1), $bajUn[0], $bajAgo, ["Topshiriq: $nomi", "Namuna hujjat (xayoliy ma'lumot)."]);
            $t['bajarildi'] = ['izoh' => $bajIz, 'fayllar' => [$f], 'sana' => $iso($bajAgo, 14, 5), 'kim' => $bajUn[0]];
        }
        if ($holat === 'qaytarilgan') {
            $t['tarix'] = [['tur' => 'qaytarildi', 'sana' => bw_date(4), 'ts' => $iso(4, 11, 15), 'kim' => $beruvchi, 'matn' => "Tiklash sinovi faqat bitta ma'lumotlar bazasi uchun o'tkazilgan — barcha loyiha ma'lumotlari uchun takrorlang."]];
            $t['nazoratIzoh'] = $t['tarix'][0]['matn']; $t['nazoratKim'] = $beruvchi; $t['nazoratSana'] = bw_date(4); $t['korganlar'] = [$beruvchi => true];
        }
        if ($takror) {
            $step = ['haftalik' => 7, 'oylik' => 30][$takror];
            $start = $yar - 1; $dav = [];
            $n = $takror === 'haftalik' ? 7 : 6;
            // langar: $n davr oldin; so'nggisi belgilanmagan qoladi (joriy muddat)
            $langarAgo = $takror === 'haftalik' ? ($n * 7) : 6 * 30;
            $t['takrorBosh'] = date('Y-m-d', strtotime(bw_date($langarAgo)));
            for ($k = 0; $k < $n - 1; $k++) {
                $sana = $takror === 'haftalik' ? date('Y-m-d', strtotime($t['takrorBosh'] . ' +' . ($k * 7) . ' days')) : date('Y-m-d', strtotime($t['takrorBosh'] . " +$k month"));
                $ok = !($k === 2 && $takror === 'haftalik');
                $dav[] = ['sana' => $sana, 'holat' => $ok ? 'bajarildi' : 'bajarilmadi', 'izoh' => $ok ? 'Bajarildi.' : "Bayram munosabati bilan yig'ilish o'tkazilmadi.", 'fayllar' => [], 'kim' => $bajUn[0], 'ts' => $iso(max(1, (int)round((time() - strtotime($sana)) / 86400)), 16, 0)];
            }
            $t['davrlar'] = $dav;
            // joriy muddat = eng kichik belgilanmagan davr sanasi
            $keyingi = $takror === 'haftalik' ? date('Y-m-d', strtotime($t['takrorBosh'] . ' +' . (($n - 1) * 7) . ' days')) : date('Y-m-d', strtotime($t['takrorBosh'] . ' +' . ($n - 1) . ' month'));
            $t['muddat'] = $keyingi;
        }
        $put('topshiriq', $t['id'], $t);
    }

    /* ------------------------------------------------------------------ ish kundaligi (korxona ichida) */
    $KN = [
        ['uchrashuv', 130, 'dilnoza', "Direktorlar kengashi bilan chorak loyihalari bo'yicha yig'ilish", "Barcha faol loyihalar holati ko'rib chiqildi; ofis binosi va ERP bo'yicha qo'shimcha nazorat belgilandi.", "Ikki loyiha uchun haftalik nazorat joriy etildi.", "Keyingi yig'ilish 2 haftadan so'ng.", null, 'loy01'],
        ['material', 126, 'sevara', "ERP talablari reyestri to'plandi", "Moliya, ombor va xarid bo'limlari bilan 7 ta intervyu o'tkazildi, 84 ta talab yig'ildi.", "Talablar reyestri tayyor.", "Texnik topshiriqni yozish.", null, 'loy02'],
        ['qaror', 120, 'jasur', "Mobil ilova (2.0) loyihasi qabul qilindi", "G'oyalar taxtasidagi taklif ko'rib chiqildi va loyiha sifatida tasdiqlandi.", "Loyiha ofisiga topshirildi.", "Loyiha menejeri tayinlash.", 620000000, 'loy03'],
        ['zakupka', 110, 'shahnoza', "RFID skanerlari bo'yicha uch taklif olindi", "Uchta yetkazib beruvchidan narx, kafolat va yetkazib berish muddati bo'yicha takliflar yig'ildi.", "Taqqoslash jadvali tuzildi.", "Qaror uchun moliya direktori bilan kelishish.", null, 'loy04'],
        ['hisobot', 100, 'nigora', "Ofis qurilishi: poydevor ishlari yakunlandi", "Poydevor ishlari tugatildi, texnik nazorat dalolatnomalari imzolandi.", "Poydevor topshirildi.", "Karkas ishlarini boshlash.", null, 'loy01'],
        ['tadqiqot', 95, 'malika', "Raqobatchilar veb-saytlarini tahlil qilish", "Bozordagi 8 ta kompaniya sayti: struktura, tezlik va kontent bo'yicha solishtirildi.", "Yangi sayt uchun 12 ta tavsiya.", "Maketlar ustida ishlash.", null, 'loy09'],
        ['uchrashuv', 88, 'madina', "Mobil ilova dizayn ko'rigi", "UI maketlari ichki jamoa bilan ko'rib chiqildi.", "Maketlar tasdiqlandi, 3 ta o'zgartirish kiritildi.", "Dasturlashni boshlash.", null, 'loy03'],
        ['qaror', 80, 'otabek', "Quyosh panellari moliyalashtirish variantlari", "Bank krediti va o'z mablag'i variantlari taqqoslandi.", "60/40 nisbatda moliyalashtirish tanlandi.", "Bank bilan muzokaralar.", 940000000, 'loy05'],
        ['material', 74, 'behzod', "ISO 9001 bo'yicha hujjatlar to'plami", "Standart talablari va mavjud ichki hujjatlar solishtirildi.", "Bo'shliqlar ro'yxati (31 ta band).", "SOP larni yozish.", null, 'loy07'],
        ['uchrashuv', 68, 'timur', "Ombor xodimlari bilan RFID bo'yicha seminar", "Yangi tizimning ish jarayoniga ta'siri tushuntirildi.", "Xodimlar savollari yig'ildi.", "O'qitish dasturini tayyorlash.", null, 'loy04'],
        ['zakupka', 62, 'akmal', "Qurilish materiallari xaridi (armatura)", "42 tonna armatura narxi qotirib shartnoma tuzildi.", "Material omborga kirdi.", "Beton xaridi bo'yicha muzokaralar.", 612000000, 'loy01'],
        ['hisobot', 55, 'umid', "Sentabr loyihalar byudjeti hisoboti", "Loyiha xarajatlari reja bilan taqqoslandi, ofis qurilishida 3% chetlanish.", "Hisobot rahbariyatga yuborildi.", "Chetlanish sabablarini aniqlash.", null, null],
        ['tadqiqot', 50, 'dilshod', "To'lov provayderlari API tahlili", "Uchta provayderning integratsiya imkoniyatlari va komissiyalari o'rganildi.", "Eng qulayi tanlandi.", "Sandbox so'rash.", null, 'loy03'],
        ['uchrashuv', 44, 'zilola', "Samarqand ijara bo'yicha muzokaralar", "Mulk egasi bilan ijara narxi va shartlari muhokama qilindi.", "Narx 8% ga kamaydi.", "Shartnoma loyihasini tayyorlash.", null, 'loy08'],
        ['qaror', 40, 'dilnoza', "Karkas ishlarida qo'shimcha nazorat joriy etildi", "Kechikish xavfi sababli haftalik texnik ko'rik belgilandi.", "Haftalik ko'rik jadvali tasdiqlandi.", "Natijani kuzatish.", null, 'loy01'],
        ['material', 36, 'eldor', "ERP migratsiyasi uchun ma'lumotlar nusxasi", "Eski tizimdan 5 yillik ma'lumotlar eksport qilindi va tozalash uchun tayyorlandi.", "Nusxa tayyor.", "Tozalash qoidalarini kelishish.", null, 'loy02'],
        ['sotuv', 33, 'malika', "Yirik mijoz bilan ramka shartnoma muzokaralari", "Mijoz ISO 9001 sertifikatini talab qildi.", "Sertifikatsiya loyihasi ustuvor.", "Shartnoma loyihasini yuborish.", null, 'loy07'],
        ['uchrashuv', 28, 'feruza', "Kadrlar bilan o'qitish ehtiyoji bo'yicha so'rovnoma", "Bo'lim rahbarlari bilan so'rovnoma natijalari muhokama qilindi.", "Ustuvor 5 yo'nalish aniqlandi.", "O'quv byudjetini tayyorlash.", null, 'loy10'],
        ['hisobot', 24, 'nigora', "HVAC yetkazib berish kechikishi haqida", "Yetkazib beruvchi kompressor bloklari bojxonada ekanini bildirdi.", "Yangi sana yozma tasdiqlandi.", "Haftalik kuzatuv.", null, 'loy01'],
        ['zakupka', 20, 'shahnoza', "Ofis mebeli bo'yicha dastlabki takliflar", "Besh yetkazib beruvchidan namuna va narxlar so'raldi.", "Takliflar kutilmoqda.", "Taqqoslash.", null, 'loy01'],
        ['material', 17, 'gulnora', "Brendbuk namunalari", "Logotip variantlari va ranglar palitrasi tayyorlandi.", "Variant 2 tanlandi.", "Maketlarda qo'llash.", null, 'loy09'],
        ['qaror', 14, 'jasur', "Samarqand filialini ochish tasdiqlandi", "Rentabellik hisobi ijobiy; 2027-yil bahorida ochish belgilandi.", "Loyiha tasdiqlandi.", "Loyiha menejeri tayinlash.", 1100000000, 'loy08'],
        ['uchrashuv', 12, 'bobur', "ERP yetkazib beruvchi bilan haftalik uchrashuv", "Modullar holati va yangi risklar muhokama qilindi.", "Xarid moduli 60% tayyor.", "Rollar matritsasini kelishish.", null, 'loy02'],
        ['hisobot', 10, 'rustam', "Quyosh stansiyasi: tender natijalari", "Uchta taklif baholandi, texnik va moliyaviy mezonlar bo'yicha 2-taklif tanlandi.", "Yetkazib beruvchi tanlandi.", "Shartnoma imzolash.", null, 'loy05'],
        ['tadqiqot', 9, 'sevara', "Chat-bot platformalari tahlili", "Uchta platforma funksionalligi solishtirildi.", "Tavsiya tayyor.", "G'oyani taxtaga joylash.", null, null],
        ['uchrashuv', 8, 'behzod', "Birinchi ichki audit yopilish yig'ilishi", "Audit natijalari bo'lim boshliqlariga e'lon qilindi.", "11 nomuvofiqlik aniqlandi.", "Bartaraf etish rejasi.", null, 'loy07'],
        ['zakupka', 7, 'akmal', "Sertifikatsiya organi xizmati bo'yicha taklif", "Ikki organdan narx so'raldi.", "Narxlar solishtirildi.", "Qaror qabul qilish.", 48000000, 'loy07'],
        ['qaror', 6, 'otabek', "O'quv dasturi byudjeti ko'rib chiqildi", "Rejalashtirilgan 260 mln so'm 2 bosqichga bo'lindi.", "Birinchi bosqich tasdiqlandi.", "Ikkinchi bosqich qarorini kutish.", 130000000, 'loy10'],
        ['hisobot', 5, 'lola', "Haftalik loyiha holati hisoboti", "11 loyihaning holati yig'ildi: 6 jarayonda, 2 yakunlangan, 3 e'tibor talab qiladi.", "Hisobot rahbariyatga yuborildi.", "Kechikkan vazifalarni nazoratga olish.", null, null],
        ['uchrashuv', 4, 'madina', "Beta-sinov mijozlari bilan tanishuv", "50 mijozdan 31 tasi qatnashishga rozi.", "Ro'yxat shakllantirildi.", "Taklifnomalarni yuborish.", null, 'loy03'],
        ['material', 3, 'dilshod', "Buyurtmalar tarixi moduli", "To'lov provayderi bilan integratsiya sinovdan o'tdi.", "Modul 75% tayyor.", "Push-xizmat bilan bog'lash.", null, 'loy03'],
        ['hisobot', 2, 'nodir', "Qadoqlash liniyasi 3 oylik natijalari", "Tezlik 2,3 barobar oshdi, brak 0,6%.", "Maqsad bajarildi.", "Loyihani yopish.", null, 'loy11'],
        ['qaror', 1, 'dilnoza', "Brend sayti ishga tushishi 3 kunga surildi", "Yuklama sinovi natijasi qoniqarsiz, keshlash optimallashtiriladi.", "Yangi sana: 3 kundan keyin.", "Qayta sinov.", null, 'loy09'],
    ];
    $kn = [];
    foreach ($KN as $i => [$tur, $ago, $kimS, $mavzu, $matn, $natija, $keyingi, $summa, $loy]) {
        $y = ['id' => 'kn_' . sprintf('%02d', $i + 1), 'sana' => bw_date($ago), 'tur' => $tur, 'mavzu' => $mavzu, 'matn' => $matn, 'natija' => $natija, 'keyingi' => $keyingi,
            'summa' => $summa, 'loyihaId' => $loy, 'fayllar' => [], 'kim' => $U[$kimS]['un'], 'ts' => $iso($ago, 16, 20)];
        if ($summa === null) unset($y['summa']);
        if ($loy === null) unset($y['loyihaId']);
        $kn[] = $y;
    }
    $kx = entity_oq('korxona', $KID);
    $kx['kundalik'] = $kn;
    $put('korxona', $KID, $kx);

    // hisobot hujjati (kundalik > hisobotlar)
    $html = '<h1>Loyihalar ofisi — oylik hisobot</h1><p><i>' . $KORX . ' · ' . date('Y-m-d') . '</i></p>'
        . "<h2>Umumiy ko'rsatkichlar</h2><table><tr><th>Ko'rsatkich</th><th>Qiymat</th></tr><tr><td>Faol loyihalar</td><td>9</td></tr><tr><td>Yakunlangan loyihalar</td><td>2</td></tr><tr><td>Kechikkan vazifalar</td><td>6</td></tr><tr><td>Ochiq topshiriqlar</td><td>18</td></tr></table>"
        . "<h2>Asosiy yutuqlar</h2><ul><li>Hujjat aylanishini raqamlashtirish loyihasi yakunlandi.</li><li>Qadoqlash liniyasi sinovdan o'tib, to'liq quvvatga chiqdi.</li><li>Mobil ilova (2.0) dizayn bosqichi tasdiqlandi.</li></ul>"
        . "<h2>E'tibor talab qiladigan masalalar</h2><ul><li>Bosh ofis binosi: HVAC jihozlari yetkazilishi kechikmoqda (kritik yo'l).</li><li>Brend sayti: yuklama sinovi natijasi qoniqarsiz, ishga tushirish surildi.</li></ul>"
        . "<h2>Keyingi oy rejasi</h2><p>ERP migratsiyasini yakunlash, Samarqand filiali uchun shtat jadvalini tasdiqlash, ISO 9001 bo'yicha nomuvofiqliklarni bartaraf etish.</p>";
    $pdo->prepare('INSERT INTO hujjatlar(id, korxona_id, sarlavha, html, shablon, ver, created_at, created_by, updated_at, updated_by) VALUES(?,?,?,?,0,1,?,?,?,?)')
        ->execute(['hj_oylik01', $KID, "Loyihalar ofisi — oylik hisobot", $html, $iso(8, 17, 0), $U['dilnoza']['un'], $iso(8, 17, 0), $U['dilnoza']['un']]);

    /* ------------------------------------------------------------------ demo foydalanuvchi: belgi -> haqiqiy login */
    $pdo->exec(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS bw_demo_claim AFTER INSERT ON users
WHEN NEW.username LIKE 'bw-%' AND NEW.username <> 'bw-demo-me'
BEGIN
  UPDATE entities SET data = REPLACE(data, '"bw-demo-me"', '"' || NEW.username || '"') WHERE data LIKE '%"bw-demo-me"%';
  UPDATE bildirishnoma SET username = NEW.username WHERE username = 'bw-demo-me';
END
SQL);
    bildirishnoma_yoz($ME, "Sizga topshiriq berildi: Direktorlar kengashi uchun loyihalar yig'ma taqdimoti\nMuddat: " . strtolower(bw_date(-3)) . "\nBerdi: " . $U['jasur']['fio'], 'topshiriq', 'tp-yangi-tp_09-' . $ME, 'topshiriq:tp_09');
    bildirishnoma_yoz($ME, "Sizga topshiriq berildi: Yillik byudjetga loyiha xarajatlari bo'yicha tasdiq\nMuddat: " . bw_date(3) . "\nBerdi: " . $U['otabek']['fio'], 'topshiriq', 'tp-yangi-tp_11-' . $ME, 'topshiriq:tp_11');
    bildirishnoma_yoz($ME, "G'oya bo'yicha fikringiz kutilmoqda: Mijozlarga 24/7 chat-bot orqali qo'llab-quvvatlash\nTaklif qildi: " . $U['sevara']['fio'], 'goya', 'gy-fikr-g_8-' . $ME, 'goya:g_8');
    bildirishnoma_yoz($ME, "G'oya bo'yicha fikringiz kutilmoqda: Xodimlar uchun ichki bilim almashish platformasi\nTaklif qildi: " . $U['sevara']['fio'], 'goya', 'gy-fikr-g_5-' . $ME, 'goya:g_5');
}
