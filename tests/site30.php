<?php
declare(strict_types=1);

/**
 * Sayt yangilanishi sinovi: bosh sahifa (nima ekani + barcha mahsulot kartalari), 30 kunlik bepul sinov hamma joyda, demo bo'lmasa
 * halol yozuv, mahsulot sahifasidagi «Qanday ishlatiladi» qo'llanmasi, adminda qo'llanmani tahrirlash, yangi logotip va dizayn fayli.
 * Ishga tushirish: bash tests/run.sh   (BASE, STAFF_EMAIL, STAFF_PASS kerak; Db uchun BIWORK_DATA/BIWORK_CONFIG)
 */
require __DIR__ . '/../portal/src/bootstrap.php';

$BASE = rtrim(getenv('BASE') ?: 'http://127.0.0.1:8099', '/');
$STAFF = [getenv('STAFF_EMAIL') ?: 'admin@biwork.test', getenv('STAFF_PASS') ?: 'Admin-Parol-2026'];
$fails = 0;
$total = 0;
require __DIR__ . '/helpers.php';

function flat30(array $a, string $prefix = ''): array
{
    $o = [];
    foreach ($a as $k => $v) {
        $key = $prefix === '' ? (string)$k : $prefix . '[' . $k . ']';
        if (is_array($v)) {
            $o += flat30($v, $key);
        } else {
            $o[$key] = $v;
        }
    }
    return $o;
}
$tx = static fn(string $h): string => html_entity_decode($h, ENT_QUOTES | ENT_HTML5);
$tool = static fn(int $id): array => Db::one('SELECT * FROM tools WHERE id = ?', [$id]) ?? [];

echo "== Sinov holati (Trial) va 30 kun\n";
ok(Catalog::trialDays() === 30, 'standart sinov muddati 30 kun');
$base = ['slug' => 'x', 'status' => 'active', 'trial_enabled' => 1, 'trial_days' => 30, 'web_url' => 'https://demo.example/', 'marketing' => []];
$a = Trial::of($base);
ok($a['state'] === 'ready' && $a['can'] && $a['badge'] === '30 kun bepul sinov', "demo manzili bor, sinov yoqilgan: ready, «30 kun bepul sinov»");
$b = Trial::of(['web_url' => ''] + $base);
ok($b['state'] === 'nodemo' && !$b['can'] && $b['badge'] === '30 kun bepul sinov' && str_contains($b['note'], 'demo manzili hali ulanmagan'), "demo manzili yo'q: nodemo, nima yetishmayotgani yozilgan");
ok(Trial::of(['status' => 'soon'] + $base)['state'] === 'soon' && Trial::of(['status' => 'soon'] + $base)['badge'] === 'Tez orada', "«tez orada» mahsulot: sinov yo'q");
ok(Trial::of(['trial_enabled' => 0] + $base)['state'] === 'demo' && Trial::of(['trial_enabled' => 0, 'web_url' => ''] + $base)['state'] === 'off', 'sinov o\'chirilgan: demo muddatsiz yoki hech narsa');
ok(Trial::of(['trial_days' => 7] + $base)['badge'] === '7 kun bepul sinov', 'mahsulotga alohida kun qo\'yilsa, shu ko\'rsatiladi');
ok(Trial::button(['web_url' => ''] + $base, null) === null && Trial::button($base, null) !== null && str_contains(Trial::button($base, null)[0], '/register?tool=x'), "«Sinab ko'rish» tugmasi faqat demo bo'lsa chiqadi");

$staff = new Browser($BASE);
$r = $staff->post('/login', '/login', ['email' => $STAFF[0], 'password' => $STAFF[1]]);
ok($r['status'] === 303, 'xodim kirdi');
$anon = new Browser($BASE);

echo "== Bosh sahifa: nima ekani va barcha mahsulotlar\n";
$pub = Catalog::tools();
$home = $anon->get('/');
$h = $tx($home['body']);
ok($home['status'] === 200, 'GET / 200');
ok(!str_contains($h, 'Nega Biwork') && !str_contains($h, 'Avval sinab ko\'ring') && !str_contains($h, "Litsenziya ochiq"), "«Nega Biwork» bloki olib tashlangan");
ok(preg_match('#<h1>(.*?)</h1>#s', $home['body'], $hm) === 1 && str_contains(strip_tags($hm[1]), 'tayyor dasturlar'), 'bosh sarlavha: nima ekani');
ok(preg_match('#<p class="lead">(.*?)</p>#s', $home['body'], $lm) === 1 && str_starts_with($lm[1], 'Biwork —') && str_contains($lm[1], '30 kun') , "bitta jumla: Biwork nima va 30 kun sinov");
preg_match_all('#<article class="ptile[^"]*">#', $home['body'], $tiles);
ok(count($tiles[0]) === count($pub) + 1, 'bosh sahifada ' . count($pub) . ' ta mahsulot kartasi + «boshqa dastur» kartasi: ' . count($tiles[0]));
$noUrl = 0;
foreach ($pub as $t) {
    $s = $t['slug'];
    $okTile = str_contains($home['body'], 'href="' . Util::url('/' . $s) . '"') && str_contains($h, $t['name']);
    $tr = Trial::of($t);
    $hasTry = str_contains($home['body'], '/register?tool=' . $s);
    $okBtn = $tr['can'] ? $hasTry : !$hasTry;
    $okTile = $okTile && $okBtn;
    $noUrl += $tr['can'] ? 0 : 1;
    ok($okTile, "kartada {$t['name']}: «Batafsil» havolasi, sinab ko'rish tugmasi faqat demo bo'lsa (" . $tr['state'] . ')');
}
ok(!$noUrl || (str_contains($h, 'demo hali ulanmagan') || str_contains($h, 'Tez orada')), "demosi yo'q mahsulotlarda «demo hali ulanmagan»/«Tez orada» yozuvi bor");
$lastSoon = array_values(array_filter($pub, static fn($x) => $x['status'] === 'soon'));
if ($lastSoon) {
    $posSoon = strpos($home['body'], '>' . htmlspecialchars($lastSoon[0]['name'], ENT_QUOTES) . '</b>');
    $posFirst = strpos($home['body'], '>' . htmlspecialchars($pub[0]['name'], ENT_QUOTES) . '</b>');
    ok($posSoon !== false && $posFirst !== false && $posSoon > $posFirst && end($pub)['status'] === 'soon', "«tez orada» mahsulot ro'yxat oxirida");
}
ok(str_contains($home['body'], 'id="mahsulotlar"') && str_contains($home['body'], 'id="qanday"') && str_contains($home['body'], '/#qollanmalar') && (!array_filter($pub, static fn($x) => $x['status'] === 'active' && !empty($x['marketing']['guide']['steps'])) || str_contains($home['body'], 'id="qollanmalar"')), "bo'lim havolalari bor: mahsulotlar, qo'llanmalar, sinovdan ishga tushirishgacha");
ok(substr_count($h, '30 kun bepul') >= 2, "bosh sahifada «30 kun bepul» ko'rinadi");
ok(!str_contains($h, '14 kun'), "bosh sahifada «14 kun» yo'q");
$css = $anon->get('/assets/site.css');
ok($css['status'] === 200 && str_contains($css['body'], '.ptile') && str_contains($css['body'], '.gstep') && str_contains($home['body'], '/assets/site.css'), 'dizayn fayli (site.css) ulangan va yuklanadi');
$lg = $anon->get('/assets/biwork-logo.png');
$sz = getimagesizefromstring($lg['body']) ?: [0, 0];
ok($lg['status'] === 200 && $sz[0] === 722 && $sz[1] === 187, "logotip: yozuvsiz (tagline'siz) nusxa, " . $sz[0] . 'x' . $sz[1]);
ok(str_contains($home['body'], 'width="154" height="40"'), "yuqori logotip 40 px balandlikda");
$bw = getimagesizefromstring($anon->get('/assets/biwork-logo-white.png')['body']) ?: [0, 0];
ok($bw[0] === 722 && $bw[1] === 187, 'qorong\'u mavzu logotipi ham shu o\'lchamda');
$cat = $anon->get('/mahsulotlar');
ok($cat['status'] === 200 && substr_count($cat['body'], 'class="card tool-card') >= count($pub) && !str_contains($tx($cat['body']), '14 kun'), "katalogda hamma mahsulot, 14 kun yo'q");

echo "== Dizayn: masofa tizimi, ranglar kontrasti, to'ldirilgan qatorlar\n";
$pcss = $anon->get('/assets/portal.css');
$scss = $css['body'];
$tokOk = $pcss['status'] === 200;
foreach (['--s1: 4px', '--s2: 8px', '--s3: 12px', '--s4: 16px', '--s5: 24px', '--s6: 32px', '--s7: 48px', '--s8: 64px'] as $tk) {
    $tokOk = $tokOk && str_contains($pcss['body'], $tk);
}
ok($tokOk && str_contains($scss, 'var(--s5)') && str_contains($scss, '--card-p') && str_contains($scss, '--sec'), "masofalar bitta tizimda: 4/8/12/16/24/32/48/64 tokenlari portal.css da, site.css ularni ishlatadi");
ok(substr_count($scss, 'var(--s') >= 120 && !preg_match('/\b(?:padding|margin|gap)\s*:[^;}]*\b(?:13|14|18|22|26|28)px/', $scss), "site.css masofalarni tokenlardan oladi (" . substr_count($scss, 'var(--s') . " ta), 13/14/18/22 px kabi tasodifiy qiymatlar yo'q");
$lumn = static function (string $hex): float {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    $c = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    foreach ($c as $i => $v) {
        $v /= 255;
        $c[$i] = $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    }
    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
};
$ratio = static function (string $a, string $b) use ($lumn): float {
    $x = $lumn($a);
    $y = $lumn($b);
    return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
};
$tokens = static function (string $css, string $start): array {
    $i = strpos($css, $start);
    $j = $i === false ? false : strpos($css, '}', $i);
    $out = [];
    if ($i !== false && $j !== false && preg_match_all('/(--[a-z0-9-]+)\s*:\s*(#[0-9A-Fa-f]{3,6})\s*[;}]/', substr($css, $i, $j - $i + 1), $mm, PREG_SET_ORDER)) {
        foreach ($mm as $x) {
            $out[$x[1]] = $x[2];
        }
    }
    return $out;
};
$light = $tokens($pcss['body'], ':root {');
$dark = $tokens($pcss['body'], '[data-theme="dark"] {') + $light;
$pairsLight = [['--text', '--bg'], ['--text', '--surface'], ['--text-2', '--surface'], ['--text-2', '--bg'], ['--muted', '--surface'], ['--muted', '--bg'], ['--faint', '--surface'], ['--link', '--surface'], ['--link', '--bg'],
    ['--ok', '--ok-bg'], ['--warn', '--warn-bg'], ['--bad', '--bad-bg'], ['--info', '--info-bg'], ['--d1', '--navy'], ['--d2', '--navy'], ['--d3', '--navy'], ['--accent-d', '--navy'], ['--green-700', '--green-soft']];
$pairsDark = [['--text', '--bg'], ['--text', '--surface'], ['--text-2', '--surface'], ['--muted', '--surface'], ['--muted', '--bg'], ['--faint', '--surface'], ['--link', '--surface'], ['--link', '--bg'], ['--warn', '--surface'], ['--bad', '--surface'], ['--info', '--surface'], ['--ok', '--surface']];
$badL = [];
foreach ($pairsLight as [$f, $b]) {
    if (!isset($light[$f], $light[$b]) || $ratio($light[$f], $light[$b]) < 4.5) {
        $badL[] = "$f/$b";
    }
}
$badD = [];
foreach ($pairsDark as [$f, $b]) {
    if (!isset($dark[$f], $dark[$b]) || $ratio($dark[$f], $dark[$b]) < 4.5) {
        $badD[] = "$f/$b";
    }
}
ok(!$badL && count($light) > 20, "och mavzu: matn, havola, belgi va to'q fon ranglari WCAG AA (4.5:1) dan o'tadi" . ($badL ? ' (past: ' . implode(', ', $badL) . ')' : ''));
ok(!$badD && count($dark) > 20, "qorong'i mavzu: matn, havola va belgi ranglari WCAG AA (4.5:1) dan o'tadi" . ($badD ? ' (past: ' . implode(', ', $badD) . ')' : ''));
ok($ratio('#FFFFFF', $light['--green-700'] ?? '#000') >= 4.5 && $ratio('#032519', $light['--btn-d'] ?? '#000') >= 4.5 && $ratio('#04261B', $dark['--green'] ?? '#000') >= 4.5, "tugmalar: oq matn yashil fonda, to'q fonda va qorong'i mavzuda o'qiladi");
$guided30 = array_filter($pub, static fn($x) => $x['status'] === 'active' && !empty($x['marketing']['guide']['steps']));
if ($guided30) {
    $soonGuide = array_filter($pub, static fn($x) => $x['status'] === 'soon' && !empty($x['marketing']['guide']['steps']));
    ok(substr_count($home['body'], 'class="card guide-card"') === count($guided30) && (!$soonGuide || !str_contains($home['body'], 'href="' . Util::url('/' . array_values($soonGuide)[0]['slug'] . '#qollanma') . '"')), "bosh sahifadagi qo'llanmalar: faqat chiqqan mahsulotlar (" . count($guided30) . "), «tez orada» mahsulotniki yo'q");
    $lastCard = strrpos($home['body'], 'class="card guide-card"');
    $askPos = strpos($home['body'], 'class="guide-ask fill"');
    ok($askPos !== false && $lastCard !== false && $askPos > $lastCard && str_contains($home['body'], 'href="#sorov">Savol yozish'), "qo'llanmalar to'ri oxirida «Savol yozish» kartasi (bo'sh katak qoldirmaydi)");
}
$lastTile = max((int)strrpos($home['body'], '<article class="ptile">'), (int)strrpos($home['body'], '<article class="ptile soon">'));
ok($lastTile > 0 && strrpos($home['body'], '<article class="ptile ask"') > $lastTile, "«boshqa dastur» kartasi mahsulot kartalaridan keyin, oxirgi katakda");
ok(str_contains($cat['body'], 'class="card tool-card soon ask fill"') && !str_contains($cat['body'], 'style="padding-top:8px"'), "katalogda «boshqa dastur» kartasi qatorni to'ldiradi, qator ichidagi uslub yo'q");
$planTool = null;
foreach ($pub as $t) {
    if ($t['status'] === 'active' && Catalog::plans((int)$t['id']) && !empty($t['marketing']['faq'])) {
        $planTool = $t;
        break;
    }
}
if ($planTool) {
    $pb = $anon->get('/' . $planTool['slug'])['body'];
    $nPlans = count(Catalog::plans((int)$planTool['id']));
    ok(str_contains($pb, 'class="grid plans plans-' . min($nPlans, 4) . '"') && substr_count($pb, 'class="card plan"') === $nPlans, "/{$planTool['slug']}: tariflar to'ri tarif soniga mos ustunlarda ($nPlans)");
    ok(str_contains($pb, 'class="wrap faq-wrap"') && str_contains($pb, 'class="faq-h"') && substr_count($pb, '<details>') === count($planTool['marketing']['faq']), "/{$planTool['slug']}: savollar ikki ustunda: sarlavha chapda, javoblar o'ngda");
}
$adash = $staff->get('/admin')['body'];
ok(str_contains($adash, 'class="grid g4 stats"') && substr_count($adash, 'class="card stat"') === 8 && str_contains($adash, 'Saytdagi faol mahsulotlar'), "admin boshqaruvida 8 ta ko'rsatkich 4 ustunda (yetim katak yo'q)");

echo "== Mahsulot sahifasi: sinov holati\n";
$demoTool = null;
$noDemoTool = null;
foreach ($pub as $t) {
    if ($t['status'] === 'active' && Trial::of($t)['can'] && !$demoTool) {
        $demoTool = $t;
    }
    if ($t['status'] === 'active' && !Trial::of($t)['can'] && !$noDemoTool) {
        $noDemoTool = $t;
    }
}
if ($demoTool) {
    $ph = $tx($anon->get('/' . $demoTool['slug'])['body']);
    ok(str_contains($ph, '30 kun bepul sinov') && !str_contains($ph, 'hozircha ochilmagan') && str_contains($ph, '30 kun bepul'), "{$demoTool['slug']}: demo bor — «30 kun bepul sinov», tugma bor");
}
if ($noDemoTool) {
    $pr = $anon->get('/' . $noDemoTool['slug']);
    $ph = $tx($pr['body']);
    ok(str_contains($pr['body'], 'class="trial-note"') && str_contains($ph, '30 kunlik bepul sinov hozircha ochilmagan') && str_contains($ph, 'demo manzili hali ulanmagan'), "{$noDemoTool['slug']}: demo yo'q — sahifada nima yetishmasligi ochiq yozilgan");
    ok(!str_contains($pr['body'], '/register?tool=' . $noDemoTool['slug']) && str_contains($ph, "So'rov qoldirish"), "{$noDemoTool['slug']}: yolg'on «Sinab ko'rish» tugmasi yo'q, «So'rov qoldirish» bor");
    // demo manzili ulansa, o'sha sahifa o'zgaradi
    Db::exec('UPDATE tools SET web_url = ? WHERE id = ?', ['https://demo.example.uz/' . $noDemoTool['slug'] . '/', (int)$noDemoTool['id']]);
    $pr2 = $anon->get('/' . $noDemoTool['slug']);
    ok(str_contains($pr2['body'], '/register?tool=' . $noDemoTool['slug']) && !str_contains($pr2['body'], 'class="trial-note"'), "demo manzili ulangach «Sinab ko'rish» chiqadi, ogohlantirish yo'qoladi");
    Db::exec('UPDATE tools SET web_url = ? WHERE id = ?', [(string)$noDemoTool['web_url'], (int)$noDemoTool['id']]);
}
$soonT = $lastSoon[0] ?? null;
if ($soonT) {
    $pr = $anon->get('/' . $soonT['slug']);
    $ph = $tx($pr['body']);
    ok(str_contains($ph, 'Mahsulot hali chiqmagan') && !str_contains($pr['body'], '/register?tool=' . $soonT['slug']), "{$soonT['slug']}: «tez orada» — sinov va'da qilinmaydi");
}

echo "== Qo'llanma: admin formadan tahrirlash va ochiq sahifa\n";
$send = static function (string $path, array $fields, array $files = []) use ($staff): array {
    $fields['csrf'] = $staff->csrf('/admin/tools');
    return $staff->req('POST', $path, flat30($fields) + $files, ['multipart' => true]);
};
$r = $send('/admin/tools/new', ['name' => "Biwork Qo'llanma Sinov"]);
ok($r['status'] === 303 && preg_match('#^/admin/tools/(\d+)/edit$#', (string)$r['location'], $m) === 1, "sinov mahsuloti yaratildi");
$id = (int)($m[1] ?? 0);
$form = $staff->get('/admin/tools/' . $id . '/edit')['body'];
ok(str_contains($form, 'id="qollanma"') && str_contains($form, 'name="guide[intro]"') && str_contains($form, 'data-rep="guide_steps"') && str_contains($form, 'name="guide[tips]"'), "admin formada «Qo'llanma» bo'limi bor");
ok(preg_match('#name="trial_days"[^>]*value="30"#', $form) === 1, "yangi mahsulotda sinov muddati 30 kun");
ok((int)$tool($id)['trial_days'] === 30, "bazada ham 30 kun");
$plans = Db::all('SELECT * FROM plans WHERE tool_id = ? ORDER BY sort', [$id]);
$fields = [
    'id' => $id, 'name' => "Biwork Qo'llanma Sinov", 'tagline' => "Qo'llanma sinovi uchun mahsulot: qadamlar tekshiriladi",
    'description' => "Birinchi abzats: sinov mahsuloti nima qiladi.\r\n\r\nIkkinchi abzats.", 'category' => 'Namuna', 'icon' => 'chart',
    'web_url' => '', 'trial_enabled' => '1', 'trial_days' => '30', 'headline' => 'Qo\'llanma sinovi', 'lead' => 'Bu sahifa qo\'llanma bo\'limini tekshiradi.',
    'features' => [['title' => 'Birinchi imkoniyat', 'text' => 'Matn', 'points' => '', 'shot' => '']],
    'guide' => [
        'intro' => 'Boshlash uchun shu qadamlarni bajaring',
        'steps' => [
            ['title' => 'Birinchi qadam: kiring', 'text' => 'Ro\'yxatdan o\'ting va oching.', 'points' => "Email yetarli\r\nParol 10 belgi", 'shot' => ''],
            ['title' => 'Ikkinchi qadam: yarating', 'text' => 'Yangi yozuv qo\'shing.', 'points' => '', 'shot' => ''],
            ['title' => '', 'text' => 'sarlavhasiz qadam tashlanadi', 'points' => '', 'shot' => ''],
        ],
        'tips' => "Birinchi eslatma\r\nIkkinchi eslatma",
    ],
    'plans' => [(int)$plans[0]['id'] => ['name' => 'Standart', 'price' => '1000000', 'seats' => '1', 'days' => '365', 'active' => '1']],
    'do' => 'publish',
];
$png = tempnam(sys_get_temp_dir(), 'bwpng');
if (Media::gd()) {
    $im = imagecreatetruecolor(1200, 700);
    imagefill($im, 0, 0, imagecolorallocate($im, 30, 70, 130));
    imagepng($im, $png);
} else {
    file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
}
$r = $send('/admin/tools/' . $id . '/edit', $fields, ['new_shots[0]' => new CURLFile($png, 'image/png', 'a.png')]);
$m1 = json_decode((string)$tool($id)['marketing'], true) ?: [];
ok($r['status'] === 303 && count($m1['guide']['steps'] ?? []) === 2 && $m1['guide']['intro'] === 'Boshlash uchun shu qadamlarni bajaring', "qo'llanma saqlandi (sarlavhasiz qadam tashlandi): " . count($m1['guide']['steps'] ?? []) . ' qadam');
ok(($m1['guide']['steps'][0]['points'] ?? []) === ['Email yetarli', 'Parol 10 belgi'] && ($m1['guide']['tips'] ?? []) === ['Birinchi eslatma', 'Ikkinchi eslatma'], 'qadam bandlari va eslatmalar saqlandi');
$file = (string)($m1['shots'][0]['file'] ?? '');
ok($file !== '', 'rasm yuklandi: ' . $file);
// ikkinchi saqlash: rasmni birinchi qadamga biriktiramiz (rasm faqat qo'llanmada ishlatiladi)
$fields['guide']['steps'][0]['shot'] = $file;
$fields['shots'] = [['file' => $file, 'caption' => 'Birinchi ekran', 'alt' => '']];
$r = $send('/admin/tools/' . $id . '/edit', $fields);
$m2 = json_decode((string)$tool($id)['marketing'], true) ?: [];
ok(($m2['guide']['steps'][0]['shot'] ?? '') === $file && is_file(Media::dir($id) . '/' . $file), "qadamga rasm biriktirildi, fayl saqlangan");
$pg = $anon->get('/' . $tool($id)['slug']);
$ph = $tx($pg['body']);
ok($pg['status'] === 200 && str_contains($pg['body'], 'id="qollanma"') && str_contains($ph, 'Qanday ishlatiladi') && str_contains($ph, 'Birinchi qadam: kiring') && str_contains($ph, 'Ikkinchi qadam: yarating') && str_contains($ph, 'Email yetarli') && str_contains($ph, 'Ikkinchi eslatma'), "ochiq sahifada qo'llanma: qadamlar, bandlar, eslatmalar");
ok(substr_count($pg['body'], '<li class="gstep') === 2 && str_contains($pg['body'], '/media/' . $id . '/' . $file), "2 ta qadam, birinchisida ekran rasmi");
ok(str_contains($ph, '30 kunlik bepul sinov hozircha ochilmagan'), "demo manzili yo'q: qo'llanma ostida ham ochiq yozuv");
// rasmni qo'llanmada ishlatilgan bo'lsa, ro'yxatdan chiqarilmasa ham o'chib ketmasligi (keep ro'yxati)
$fields['shots'] = [];
$r = $send('/admin/tools/' . $id . '/edit', $fields);
$m3 = json_decode((string)$tool($id)['marketing'], true) ?: [];
ok(($m3['shots'] ?? []) === [] && is_file(Media::dir($id) . '/' . $file), "rasm ro'yxatdan chiqdi, lekin qo'llanma ishlatayotgani uchun fayl o'chmadi");
// qadamlarni o'chirish
$fields['guide'] = ['intro' => '', 'steps' => [['title' => '', 'text' => '', 'points' => '', 'shot' => '']], 'tips' => ''];
$r = $send('/admin/tools/' . $id . '/edit', $fields);
$m4 = json_decode((string)$tool($id)['marketing'], true) ?: [];
clearstatcache();
ok(($m4['guide']['steps'] ?? null) === [] && !str_contains($anon->get('/' . $tool($id)['slug'])['body'], 'id="qollanma"') && !is_file(Media::dir($id) . '/' . $file), "qo'llanma bo'shatilsa bo'lim chiqmaydi, ishlatilmagan rasm tozalanadi");
ok(count(array_filter(ToolEditor::readiness($tool($id) + ['marketing' => $m4], []), static fn($x) => $x['label'] === "Qo'llanma" && !$x['ok'])) === 1, "tayyorlik ro'yxatida «Qo'llanma» bandi bor");
// oldindan ko'rish saqlamaydi
$r = $send('/admin/tools/preview', ['id' => $id, 'name' => "Biwork Qo'llanma Sinov", 'guide' => ['intro' => 'Oldindan', 'steps' => [['title' => 'Ko\'rish qadami', 'text' => '', 'points' => '', 'shot' => '']], 'tips' => '']]);
ok(str_contains($tx($r['body']), 'Ko\'rish qadami') && ((json_decode((string)$tool($id)['marketing'], true)['guide']['steps'] ?? []) === []), "oldindan ko'rishda qo'llanma ko'rinadi, lekin saqlanmaydi");

echo "== Biwork AI: Reja sahifasi matni (ixtiyoriy, tasdiqdan keyin yozadi, ma'lumot Anthropic'ga ketishi ochiq)\n";
$rejaMan = json_decode((string)file_get_contents(BW_ROOT . '/../integrations/reja/manifest.json'), true) ?: [];
$faqMan = array_column($rejaMan['marketing']['faq'] ?? [], 'a', 'q');
$aiQ = "Biwork AI nima qiladi?";
$aiTexts = [(string)($faqMan[$aiQ] ?? ''), (string)($faqMan["Ma'lumotlarim qayerda saqlanadi?"] ?? ''), (string)($faqMan['Internet kerakmi?'] ?? ''), implode(' ', array_filter($rejaMan['highlights'] ?? [], static fn($x) => str_contains((string)$x, 'Biwork AI')))];
ok(isset($faqMan[$aiQ]) && str_contains($faqMan[$aiQ], 'tasdiqlaganingizdan keyin') && str_contains($faqMan[$aiQ], 'o\'chiq'), "manifest: «Biwork AI nima qiladi?»: ixtiyoriy, sukut bo'yicha o'chiq, tasdiqdan keyin joylaydi");
ok(str_contains($aiTexts[1], 'Anthropic') && str_contains($aiTexts[1], 'Biwork serverlariga yuborilmaydi') && str_contains($aiTexts[1], 'parollar'), "manifest: ma'lumot qayerda saqlanadi — Biwork serverlariga ketmaydi, Biwork AI yoqilsa Anthropic'ga ketishi aytilgan");
ok(str_contains($aiTexts[2], 'ixtiyoriy Biwork AI internet talab qiladi'), "manifest: «Internet kerakmi?» — faqat ixtiyoriy Biwork AI internet talab qiladi");
ok(str_contains($aiTexts[3], 'ixtiyoriy') && in_array("Ma'lumot sizda saqlanadi", $rejaMan['marketing']['badges'] ?? [], true) && !in_array("Ma'lumot korxonada qoladi", $rejaMan['marketing']['badges'] ?? [], true), "manifest: imkoniyatda Biwork AI «ixtiyoriy», belgi «Ma'lumot sizda saqlanadi»");
$over = [];
foreach (['ishonchli', 'kafolat', 'xatosiz', 'mukammal', '100%', 'har doim to\'g\'ri', 'hech qachon xato'] as $w) {
    foreach ($aiTexts as $t) {
        if (mb_stripos($t, $w) !== false) {
            $over[$w] = true;
        }
    }
}
ok(!$over, "Biwork AI matnida «ishonchli», «kafolat» kabi so'zlar yo'q (haqiqiy kalit bilan hali sinalmagan)" . ($over ? ' (topildi: ' . implode(', ', array_keys($over)) . ')' : ''));
$rp = $anon->get('/reja');
$rh = $tx($rp['body']);
ok($rp['status'] === 200 && str_contains($rh, $aiQ) && str_contains($rh, 'Anthropic') && str_contains($rh, 'faqat siz tasdiqlaganingizdan keyin'), "/reja: savol-javobda Biwork AI va Anthropic haqida ochiq yozilgan");
ok(str_contains($rh, "Ma'lumot sizda saqlanadi") && !str_contains($rh, "Ma'lumot korxonada qoladi") && !str_contains($rh, "Ma'lumot korxonangizda qoladi"), "/reja: «ma'lumot korxonada qoladi» degan mutlaq gap yo'q");
ok(str_contains($tx($anon->get('/')['body']), "Ma'lumot sizda saqlanadi.") && !str_contains($tx($anon->get('/')['body']), "Ma'lumot korxonangizda qoladi"), "bosh sahifadagi «Yuklab oling» qadamida mutlaq gap yo'q");
$rdb = json_decode((string)Db::value("SELECT highlights FROM tools WHERE slug = 'reja'"), true) ?: [];
ok(count(array_filter($rdb, static fn($x) => str_contains((string)$x, 'Biwork AI (ixtiyoriy)'))) === 1, "Reja katalog yozuvida (admin ma'lumoti) Biwork AI imkoniyati bor");

echo "== Qo'llanma matnlari (portal/content)\n";
$withGuide = 0;
foreach (['reja', 'hr', 'loyiha', 'savdo', 'moliya', 'yuridik', 'monitoring'] as $slug) {
    $f = BW_ROOT . '/content/' . $slug . '.json';
    if (!is_file($f)) {
        continue;
    }
    $j = json_decode((string)file_get_contents($f), true);
    $raw = $j['guide'] ?? [];
    $c = Marketing::clean(['guide' => $raw])['guide'];
    $stepsRaw = is_array($raw['steps'] ?? null) ? count($raw['steps']) : 0;
    ok($stepsRaw >= 5 && count($c['steps']) === $stepsRaw && count($c['tips']) === count($raw['tips'] ?? []) && $c['intro'] === ($raw['intro'] ?? null), "$slug: qo'llanma to'g'ri ($stepsRaw qadam), tozalashda hech narsa kesilmadi");
    $man = json_decode((string)file_get_contents(BW_ROOT . '/../integrations/' . $slug . '/manifest.json'), true);
    $shots = array_column($man['marketing']['shots'] ?? [], 'file');
    $bad = array_values(array_filter(array_column($c['steps'], 'shot'), static fn($s) => $s !== '' && !in_array($s, $shots, true)));
    ok(!$bad, "$slug: qadam rasmlari mahsulot rasmlari ichida" . ($bad ? ' (yo\'q: ' . implode(', ', $bad) . ')' : ''));
    $miss = array_values(array_filter($shots, static fn($s) => !is_file(BW_ROOT . '/public/assets/products/' . $slug . '/' . $s)));
    ok(!$miss, "$slug: rasm fayllari saytda bor" . ($miss ? ' (yo\'q: ' . implode(', ', $miss) . ')' : ''));
    $withGuide++;
    $row = Db::one('SELECT id FROM tools WHERE slug = ?', [$slug]);
    if ($row) {
        $mk = json_decode((string)Db::value('SELECT marketing FROM tools WHERE id = ?', [(int)$row['id']]), true) ?: [];
        if (!empty($mk['guide']['steps'])) {
            $ph = $tx($anon->get('/' . $slug)['body']);
            ok(str_contains($ph, 'Qanday ishlatiladi') && str_contains($ph, (string)$mk['guide']['steps'][0]['title']), "/$slug sahifasida qo'llanma chiqadi");
        }
    }
}
ok($withGuide >= 1, "kamida bitta mahsulot uchun qo'llanma fayli bor ($withGuide)");

// tozalash: sinov mahsuloti qoladi (keyingi bosqichlarga xalaqit bermaydi), lekin yashiriladi
Db::exec("UPDATE tools SET status = 'hidden' WHERE id = ?", [$id]);

echo "\nJami: $total, xato: $fails\n";
exit($fails === 0 ? 0 : 1);
