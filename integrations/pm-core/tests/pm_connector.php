<?php
declare(strict_types=1);

/**
 * PM ↔ Biwork ulanishi (pm-core) sinovi. Haqiqiy portal va haqiqiy (patchlangan) PM serverlari kerak — odatda
 * `bash integrations/pm-core/tests/run.sh` orqali ishga tushiriladi.
 *
 * Muhit: PORTAL, PM_DEMO (demo rejimi), PM_APP (app rejimi, litsenziya majburiy), PM_DEMO_DIR, PM_APP_DIR, BIWORK_DATA (portal kalitlari),
 *        CUST_EMAIL, CUST_PASS, STAFF_EMAIL, STAFF_PASS, KEY_HR, KEY_LOY, LICENSE_ID_HR
 */
require __DIR__ . '/../../../portal/src/bootstrap.php';
require __DIR__ . '/../../../tests/helpers.php';

$fails = 0;
$total = 0;
$env = static fn(string $k): string => getenv($k) ?: throw new RuntimeException("$k muhit o'zgaruvchisi kerak");
$PORTAL = rtrim($env('PORTAL'), '/');
$DEMO = rtrim($env('PM_DEMO'), '/');
$APP = rtrim($env('PM_APP'), '/');
$DEMO_DIR = $env('PM_DEMO_DIR');
$APP_DIR = $env('PM_APP_DIR');

/** Portal "Ochish" tokeni (haqiqiy imzo bilan). */
function launchToken(string $aud, array $over = []): string
{
    $now = Util::now();
    return Tokens::sign(Tokens::LAUNCH, array_replace_recursive([
        'v' => 1, 'iss' => 'biwork', 'aud' => $aud, 'jti' => bin2hex(random_bytes(12)), 'iat' => $now, 'exp' => $now + 120,
        'mode' => 'demo',
        'user' => ['id' => 77, 'name' => 'Sinov Foydalanuvchi', 'email' => 'sinov@example.uz', 'role' => 'owner', 'tool_role' => 'admin'],
        'cust' => ['id' => 5, 'name' => 'Sinov MChJ'],
        'lic' => ['id' => 1, 'type' => 'paid', 'plan' => 'business', 'features' => ['web_demo'], 'limits' => new stdClass(), 'exp' => null],
        'portal' => 'http://portal.invalid/app/tools/' . $aud,
    ], $over));
}
function me(Browser $b): array { return json_decode($b->get('/api/me')['body'], true) ?: []; }
function perms(array $me): array { return array_filter($me['bolimlar'] ?? [], static fn($v) => $v !== 'none'); }
function pageErr(array $r): string
{
    preg_match('#<p class="e">(.*?)</p>#s', $r['body'], $m);
    return html_entity_decode($m[1] ?? '', ENT_QUOTES);
}
function jsonOf(array $r): array { return json_decode($r['body'], true) ?: []; }
function newKontakt(string $id): array
{
    return ['yozuv' => ['id' => $id, 'korxonaId' => 'k_x', 'familiya' => 'Sinov', 'ismi' => 'Xodim', 'ism' => 'Sinov Xodim', 'bolim' => 'Test', 'lavozim' => 'Test']];
}

echo "== Demo: portaldan 'Ochish' (haqiqiy oqim)\n";
$cust = new Browser($PORTAL);
$r = $cust->post('/login', '/login', ['email' => $env('CUST_EMAIL'), 'password' => $env('CUST_PASS')]);
ok($r['status'] === 303, 'mijoz portalga kirdi');
$r = $cust->get('/app/open/hr');
$loc = (string)$r['location'];
ok($r['status'] === 302 && str_starts_with($loc, $DEMO . '/?bw_launch=bwl1.'), "portal HR demosiga yo'naltiradi");
$hr = new Browser($DEMO);
$r = $hr->get(substr($loc, strlen($DEMO)));
ok($r['status'] === 303 && str_starts_with((string)$r['location'], '/'), "PM tokenni qabul qilib '/' ga yo'naltiradi (token manzilda qolmaydi)");
ok(str_contains($r['headers']['set-cookie'] ?? '', 'asi_session'), 'PM sessiya cookie berdi');
$m = me($hr);
ok(($m['role'] ?? '') === 'rahbar' && str_starts_with((string)($m['username'] ?? ''), 'bw-'), "demo foydalanuvchi: rol 'rahbar', login bw-… (" . ($m['username'] ?? '?') . ')');
ok(($m['bwMask'] ?? false) === true, "/api/me: bwMask=true (mijoz admin/rahbarni ham niqob bo'yicha cheklaydi)");
$p = perms($m);
ok(isset($p['kontakt'], $p['kontakt.royxat'], $p['hujjat']) && !isset($p['moliya']) && !isset($p['savdo']) && !isset($p['pm']) && !isset($p['yuridik']),
    "HR: faqat kontakt/hujjat/hisobot ochiq (" . implode(',', array_keys($p)) . ')');
ok(($m['tanishuv'] ?? 0) >= 2 && ($m['yolboshchi'] ?? true) === false, 'namunada Navigator va qo\'llanma oynasi o\'chiq');
$r = $hr->get('/');
ok($r['status'] === 200 && str_contains($r['body'], 'Biwork HR') && str_contains($r['body'], 'bwDemoBar'), 'asosiy sahifa: Biwork HR brendi va namuna paneli');
ok(!str_contains($r['body'], 'Avtosanoat Invest'), "asosiy sahifada Avtosanoat brendi qolmagan");
$r = $hr->get('/api/state');
$st = jsonOf($r);
ok($r['status'] === 200 && empty($st['loyihalar']) && empty($st['qarzlar']) && empty($st['sudlar']) && empty($st['topshiriqlar']), "HR: /api/state da boshqa modul ma'lumoti yo'q");
$r = $hr->req('POST', '/api/yozuv/loyiha', [], ['json' => ['yozuv' => ['id' => 'p_sinov', 'nomi' => 'Sinov']]]);
ok($r['status'] === 403, "HR: loyiha yozib bo'lmaydi (403)");
$r = $hr->req('POST', '/api/yozuv/sud', [], ['json' => ['yozuv' => ['id' => 's_sinov']]]);
ok($r['status'] === 403, "HR: sud ishi yozib bo'lmaydi (403)");

echo "== Demo: token xatoliklari\n";
$r = (new Browser($DEMO))->get(substr($loc, strlen($DEMO)));
ok($r['status'] === 403 && str_contains(pageErr($r), 'ishlatilgan'), "ikkinchi marta ishlatilgan token rad etiladi (replay): " . pageErr($r));
$b = new Browser($DEMO);
$tok = launchToken('hr');
$r = $b->get('/?bw_launch=' . substr($tok, 0, -3) . 'AAA');
ok($r['status'] === 403 && !str_contains($r['headers']['set-cookie'] ?? '', 'asi_session'), "buzilgan imzo: 403 va sessiya yo'q (" . pageErr($r) . ')');
$r = $b->get('/?bw_launch=' . launchToken('hr', ['exp' => Util::now() - 5, 'iat' => Util::now() - 130]));
ok($r['status'] === 403 && str_contains(pageErr($r), 'eskirgan'), "muddati o'tgan token: " . pageErr($r));
$r = $b->get('/?bw_launch=' . launchToken('reja'));
ok($r['status'] === 403 && str_contains(pageErr($r), 'mahsulot'), "boshqa vositaga (reja) berilgan token: 403 (" . pageErr($r) . ')');
$r = $b->get('/?bw_launch=' . urlencode(Tokens::sign(Tokens::LICENSE, ['aud' => 'hr', 'jti' => 'x', 'iat' => Util::now(), 'exp' => Util::now() + 60])));
ok($r['status'] === 403, "litsenziya tokeni 'Ochish' o'rnida o'tmaydi");
$r = $b->get('/?bw_launch=' . launchToken('hr', ['cust' => ['id' => 0]]));
ok($r['status'] === 403, "korxonasiz token rad etiladi");
$r = $b->get('/');
ok($r['status'] === 401 && str_contains($r['body'], 'portalidan oching'), "sandbox cookie yo'q: asosiy sahifa o'rniga 'portaldan oching' (401)");
$r = $b->get('/api/state');
ok($r['status'] === 401 && str_contains($r['body'], 'portal'), "sandbox cookie yo'q: API 401");
ok(!is_file($DEMO_DIR . '/data/pm.db'), "demo serverda asosiy data/pm.db yaratilmagan (sandboxsiz so'rov bazaga tegmaydi)");

echo "== Demo: sandbox cookie soxtalashtirish\n";
$b = new Browser($DEMO);
$r = $b->req('GET', '/api/state');
$fake = new Browser($DEMO);
$ch = curl_init($DEMO . '/api/state');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => 'bw_sbx=5.hr.' . str_repeat('a', 32) . '; asi_session=' . str_repeat('b', 64)]);
curl_exec($ch);
ok(curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 401, "soxta imzoli sandbox cookie: 401");
curl_close($ch);
$ch = curl_init($DEMO . '/api/state');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => 'bw_sbx=../../etc.hr.' . str_repeat('a', 32)]);
curl_exec($ch);
ok(curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 401, "yo'l almashtirishga urinish (../): 401");
curl_close($ch);

echo "== Demo: mahsulot va korxona bo'yicha ajratish (sandbox)\n";
$loy = new Browser($DEMO);
$cust->get('/app/open/loyiha');
$locL = (string)$cust->get('/app/open/loyiha')['location'];
$r = $loy->get(substr($locL, strlen($DEMO)));
ok($r['status'] === 303, "Loyiha demosi ochildi (alohida token, alohida sandbox)");
$mL = me($loy);
$pL = perms($mL);
ok(isset($pL['pm'], $pL['topshiriq'], $pL['kontakt.royxat']) && !isset($pL['kundalik']) && !isset($pL['moliya']) && !isset($pL['savdo']) && !isset($pL['yuridik']) && !isset($pL['kontakt.davomat']),
    'Loyiha: pm/topshiriq + kontakt.royxat ochiq, kundalik/moliya/savdo/yuridik/davomat yopiq (' . implode(',', array_keys($pL)) . ')');
ok(($pL['kontakt.royxat'] ?? '') === 'view', "Loyiha: kontakt.royxat faqat 'view' (modullar: …:view chegarasi)");
$rw = $loy->req('POST', '/api/yozuv/kontakt', [], ['json' => newKontakt('c_loy_yozuv')]);
ok($rw['status'] === 403, "Loyiha: kontakt.royxat 'view' — xodim yozib bo'lmaydi (403)");
ok(is_dir($DEMO_DIR . '/data/demo/c5-hr') || is_dir($DEMO_DIR . '/data/demo/c1-hr') || count(glob($DEMO_DIR . '/data/demo/c*-hr') ?: []) >= 1, 'HR sandbox papkasi bor');
ok(count(glob($DEMO_DIR . '/data/demo/c*-loyiha') ?: []) >= 1, 'Loyiha sandbox papkasi alohida');

// bir mahsulot, ikki korxona: o'zgarish bir-biriga ko'rinmaydi
$a = new Browser($DEMO);
$a->get('/?bw_launch=' . launchToken('hr', ['cust' => ['id' => 11], 'user' => ['id' => 1, 'name' => 'A foydalanuvchi']]));
$bb = new Browser($DEMO);
$bb->get('/?bw_launch=' . launchToken('hr', ['cust' => ['id' => 12], 'user' => ['id' => 1, 'name' => 'B foydalanuvchi']]));
$korxonaA = jsonOf($a->get('/api/state'))['korxonalar'][0]['id'] ?? 'k_x';
$kont = newKontakt('c_faqat_a');
$kont['yozuv']['korxonaId'] = $korxonaA;
$rw = $a->req('POST', '/api/yozuv/kontakt', [], ['json' => $kont]);
ok($rw['status'] === 200, "A korxona: xodim qo'shdi (" . $rw['status'] . ' ' . substr($rw['body'], 0, 80) . ')');
$idsA = array_column(jsonOf($a->get('/api/state'))['kontaktlar'] ?? [], 'id');
$idsB = array_column(jsonOf($bb->get('/api/state'))['kontaktlar'] ?? [], 'id');
ok(in_array('c_faqat_a', $idsA, true) && !in_array('c_faqat_a', $idsB, true), "A korxonaning o'zgarishi B korxonaga ko'rinmaydi (alohida sandbox)");

echo "== Demo: namunani boshidan boshlash\n";
$r = $a->req('POST', '/bw/reset');
ok($r['status'] === 403, "X-Biwork sarlavhasisiz /bw/reset rad etiladi (CSRF)");
// A ning cookie'larini yuborish uchun Browser'ning cookie faylidan foydalanamiz (X-Biwork sarlavhasi bilan)
$r = (static function (Browser $br, string $base): array {
    $c = curl_init($base . '/bw/reset');
    $ref = new ReflectionProperty($br, 'jar');
    $ref->setAccessible(true);
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $ref->getValue($br), CURLOPT_COOKIEJAR => $ref->getValue($br), CURLOPT_HTTPHEADER => ['X-Biwork: 1']]);
    $body = (string)curl_exec($c);
    $s = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    return ['status' => $s, 'body' => $body];
})($a, $DEMO);
ok($r['status'] === 200, "X-Biwork bilan /bw/reset ishladi");
$a2 = new Browser($DEMO);
$a2->get('/?bw_launch=' . launchToken('hr', ['cust' => ['id' => 11], 'user' => ['id' => 1, 'name' => 'A foydalanuvchi']]));
$idsA2 = array_column(jsonOf($a2->get('/api/state'))['kontaktlar'] ?? [], 'id');
ok(!in_array('c_faqat_a', $idsA2, true), "reset: A korxonaning qo'shgan xodimi yo'qoldi (shablonga qaytdi)");
// reset sessiyani ham o'chiradi — /bw/relogin foydalanuvchini yangi bazada qayta kiritadi (aks holda "Boshidan" tugmasi login sahifasiga olib borardi)
$r = $a->get('/api/me');
ok($r['status'] === 401, "reset: eski sessiya yangi bazada yaroqsiz");
$r = $a->get('/bw/relogin');
ok($r['status'] === 303 && str_contains((string)($r['location'] ?? ''), 'dashboard'), "/bw/relogin: mahsulot bosh sahifasiga yo'naltiradi");
ok(($me = me($a)) && ($me['user']['username'] ?? $me['username'] ?? '') === 'bw-11-1', "/bw/relogin: foydalanuvchi yangi bazada qayta kirdi (bw-11-1)");
$r = $a2->get('/bw/relogin');
ok($r['status'] === 303 && ($r['location'] ?? '') === '/', "/bw/relogin: reset qilinmagan sessiyada hech narsa qilmaydi (/ ga qaytaradi)");

echo "== App rejimi: litsenziya darvozasi (o'rnatilgan nusxa)\n";
$pass = '';
if (preg_match('/Parol:\s*(\S+)/', (string)@file_get_contents($APP_DIR . '/data/ADMIN-PAROL.txt'), $pm)) $pass = $pm[1];
$adm = new Browser($APP);
$r = $adm->get('/login');
ok($r['status'] === 200 && str_contains($r['body'], 'Biwork') && !str_contains($r['body'], 'Avtosanoat Invest'), 'login sahifasi: Biwork brendi, Avtosanoat yo\'q');
$r = $adm->req('POST', '/api/login', [], ['json' => ['username' => 'admin', 'password' => $pass]]);
ok($r['status'] === 200, "PM administratori parol bilan kirdi" . ($pass === '' ? " (ADMIN-PAROL.txt topilmadi!)" : ''));
$r = $adm->get('/');
ok($r['status'] === 302 && str_ends_with((string)$r['location'], '/licenziya'), "litsenziyasiz: bosh sahifa → /licenziya");
$r = $adm->get('/api/state');
ok($r['status'] === 402 && (jsonOf($r)['error']['code'] ?? '') === 'license_required', 'litsenziyasiz API: 402 license_required');
ok($adm->get('/api/me')['status'] === 200, "/api/me litsenziyasiz ham ishlaydi");
$r = $adm->get('/licenziya');
ok($r['status'] === 200 && str_contains($r['body'], 'Biwork HR') && str_contains($r['body'], 'Biwork Yuridik') && str_contains($r['body'], 'Faollashtirilmagan'), '/licenziya: hamma mahsulot holati + faollashtirish shakli');
$csrf = static function (Browser $b): string { preg_match('/name="csrf" value="([a-f0-9]+)"/', $b->get('/licenziya')['body'], $m); return $m[1] ?? ''; };
$r = $adm->req('POST', '/licenziya', ['op' => 'activate', 'key' => $env('KEY_HR'), 'csrf' => 'yolgon']);
ok(str_contains($r['body'], 'eskirgan') && $adm->get('/')['status'] === 302, "CSRF tokensiz faollashtirib bo'lmaydi");
$r = $adm->req('POST', '/licenziya', ['op' => 'activate', 'key' => 'BW-XXX-AAAA-BBBB-CCCC', 'csrf' => $csrf($adm)]);
ok(str_contains($r['body'], 'tegishli emas') || str_contains($r['body'], 'class="e"'), "noma'lum mahsulot kaliti: xato ko'rsatiladi");

$r = $adm->req('POST', '/licenziya', ['op' => 'activate', 'key' => $env('KEY_HR'), 'csrf' => $csrf($adm)]);
ok($r['status'] === 200 && str_contains(html_entity_decode($r['body'], ENT_QUOTES), 'faollashtirildi'), 'Biwork HR litsenziyasi faollashtirildi' . (pageErr($r) !== '' ? ': ' . pageErr($r) : ''));
ok($adm->get('/')['status'] === 200, "faollashtirilgach PM ochiladi");
$m = me($adm);
$p = perms($m);
ok(($m['role'] ?? '') === 'admin' && isset($p['kontakt'], $p['hujjat']) && !isset($p['moliya']) && !isset($p['pm']) && !isset($p['savdo']) && !isset($p['yuridik']),
    "faqat HR litsenziyasi: admin rolida ham faqat HR modullari ochiq (" . implode(',', array_keys($p)) . ')');
ok(($m['bwMask'] ?? false) === true, 'admin uchun ham bwMask=true (mijoz tab yashirishi uchun)');
$r = $adm->req('POST', '/api/yozuv/loyiha', [], ['json' => ['yozuv' => ['id' => 'p_x', 'nomi' => 'Sinov']]]);
ok($r['status'] === 403 || $r['status'] === 400, "litsenziyasi yo'q modulga yozish rad etiladi (loyiha: " . $r['status'] . ')');

$r = $adm->req('POST', '/licenziya', ['op' => 'activate', 'key' => $env('KEY_LOY'), 'csrf' => $csrf($adm)]);
ok(str_contains(html_entity_decode($r['body'], ENT_QUOTES), 'faollashtirildi'), 'Biwork Loyiha litsenziyasi ham faollashtirildi');
$p = perms(me($adm));
ok(isset($p['kontakt'], $p['pm'], $p['topshiriq']) && !isset($p['moliya']), "ikki litsenziya: HR + Loyiha modullari birlashmasi (" . implode(',', array_keys($p)) . ')');
ok(($p['kontakt.royxat'] ?? '') === 'edit', "kontakt.royxat: HR 'edit' beradi, Loyiha 'view' — kuchlisi qoladi");
$html = $adm->get('/')['body'];
ok(str_contains($html, '<div class="brand-mark">Biwork</div>'), "bir necha mahsulot faol: brend 'Biwork'");

echo "== App rejimi: server tomondan to'xtatish\n";
$staff = new Browser($PORTAL);
$staff->post('/login', '/login', ['email' => $env('STAFF_EMAIL'), 'password' => $env('STAFF_PASS')]);
$lid = (int)$env('LICENSE_ID_HR');
$staff->post("/admin/licenses/$lid", "/admin/licenses/$lid/status", ['status' => 'suspended']);
$adm->req('POST', '/licenziya', ['op' => 'refresh', 'slug' => 'hr', 'csrf' => $csrf($adm)]);
$p = perms(me($adm));
ok(!isset($p['kontakt.davomat']) && isset($p['pm']), "HR to'xtatildi: HR modullari yopildi, Loyiha ishlayveradi (" . implode(',', array_keys($p)) . ')');
$staff->post("/admin/licenses/$lid", "/admin/licenses/$lid/status", ['status' => 'active']);
$adm->req('POST', '/licenziya', ['op' => 'refresh', 'slug' => 'hr', 'csrf' => $csrf($adm)]);
ok(isset(perms(me($adm))['kontakt.davomat']), "qayta yoqilgach HR modullari qaytdi");

echo "== App rejimi: oddiy foydalanuvchi va SSO\n";
$u = new Browser($APP);
$r = $u->get('/?bw_launch=' . launchToken('hr', ['mode' => 'live', 'user' => ['id' => 91, 'name' => 'Ilova foydalanuvchisi', 'tool_role' => 'user']]));
ok($r['status'] === 303, "SSO (live, oddiy foydalanuvchi) ishladi");
$mu = me($u);
ok(($mu['role'] ?? '') === 'user', "tool_role=user → PM da 'user'");
$r = $u->req('POST', '/licenziya', ['op' => 'deactivate', 'slug' => 'hr', 'csrf' => $csrf($u)]);
ok(str_contains(html_entity_decode($r['body'], ENT_QUOTES), 'faqat administrator') && isset(perms(me($adm))['kontakt.davomat']), "oddiy foydalanuvchi litsenziyani o'chira olmaydi");
$ua = new Browser($APP);
$ua->get('/?bw_launch=' . launchToken('loyiha', ['mode' => 'live', 'user' => ['id' => 92, 'name' => 'Admin Egasi', 'tool_role' => 'admin']]));
ok((me($ua)['role'] ?? '') === 'admin', "live + tool_role=admin → PM da 'admin'");
$um = new Browser($APP);
$r = $um->get('/?bw_launch=' . launchToken('moliya', ['mode' => 'live', 'user' => ['id' => 93, 'name' => 'Moliya foydalanuvchisi', 'tool_role' => 'user']]));
ok($r['status'] === 303 && !isset(perms(me($um))['moliya']), "litsenziyasi yo'q mahsulot (moliya) tokeni bilan kirsa ham moliya moduli ochilmaydi — modullarni mahalliy litsenziya belgilaydi");
$r = (new Browser($APP))->get('/?bw_launch=' . launchToken('reja', ['mode' => 'live']));
ok($r['status'] === 403, "app: boshqa vosita (reja) tokeni rad etiladi");

echo "== Guruhga xos qismlar paketda bo'shatilgan (stub-group.php), umumiy xususiyatlar esa joyida\n";
$gruh = static function (string $name) use ($env): array {
    preg_match('/const ' . $name . ' = \[(.*?)\];/s', (string)file_get_contents(__DIR__ . '/../tools/stub-group.php'), $mm);
    preg_match_all("/'([^']+)'/", preg_replace('#//[^\n]*#', '', (string)($mm[1] ?? '')), $nn);
    return $nn[1];
};
$gPhp = $gruh('GRUH_PHP'); $gJs = $gruh('GRUH_JS');
ok(count($gPhp) === 10 && count($gJs) === 10, "stub ro'yxati: A qismi (10 PHP + 10 JS)");
$MARK = 'Biwork: bu qism paketga kiritilmagan';
foreach (['app' => $APP_DIR, 'demo' => $DEMO_DIR] as $nom => $dir) {
    $bad = [];
    foreach ($gPhp as $f) { $p = "$dir/php/$f"; $c = (string)@file_get_contents($p); if (!str_contains($c, $MARK) || strlen($c) > 8000) { $bad[] = $f; continue; } exec('php -l ' . escapeshellarg($p) . ' 2>&1', $o, $rc); if ($rc !== 0) $bad[] = "$f(sintaksis)"; }
    foreach ($gJs as $f) { $c = (string)@file_get_contents("$dir/assets/mod/$f"); if (!str_contains($c, $MARK) || strlen($c) > 4000 || preg_match('/^(?!export|\/\/|$)/m', $c)) $bad[] = $f; }
    ok($bad === [], "$nom: guruhga xos fayllar bo'shatilgan, kichik, PHP sintaksisi to'g'ri" . ($bad ? ' — muammo: ' . implode(', ', $bad) : ''));
    $kept = ['php/versiya.php', 'php/manba-holat.php', 'php/gh-balans.php', 'php/gh-qarz.php', 'php/bp-moliya.php', 'php/balans.php', 'assets/mod/aktiv.js', 'assets/mod/manba.js', 'assets/mod/mavsumreja.js'];
    $bad = array_filter($kept, static fn($f) => str_contains((string)@file_get_contents("$dir/$f"), 'bu qism paketga kiritilmagan') || filesize("$dir/$f") < 1500);
    ok($bad === [], "$nom: umumiy xususiyatlar (aktiv, manba, mavsumreja, versiya) va B qismi stublanmagan" . ($bad ? ' — ' . implode(', ', $bad) : ''));
}
$KEEP_DIR = getenv('PM_KEEP_DIR') ?: '';
if ($KEEP_DIR !== '') {
    $bad = array_merge(array_filter($gPhp, static fn($f) => str_contains((string)@file_get_contents("$KEEP_DIR/php/$f"), 'bu qism paketga kiritilmagan') || filesize("$KEEP_DIR/php/$f") < 3000),
        array_filter($gJs, static fn($f) => str_contains((string)@file_get_contents("$KEEP_DIR/assets/mod/$f"), 'bu qism paketga kiritilmagan') || filesize("$KEEP_DIR/assets/mod/$f") < 3000));
    ok($bad === [], "--keep-group: asl fayllar saqlangan" . ($bad ? ' — ' . implode(', ', $bad) : ''));
}
foreach (['/api/otpbp', '/api/otpsavdo', '/api/otpdds', '/api/lizingbp', '/api/realizatsiya', '/api/dds'] as $yo) {
    $r = $adm->get($yo . '?korxona=k_yoq');
    ok($r['status'] >= 400 && $r['status'] < 500, "stublangan marshrut $yo: so'rov 4xx bilan rad etiladi (" . $r['status'] . ')');
}
$r = $adm->get('/api/tekshir');
$tk = jsonOf($r);
$xato = array_filter((array)($tk['natija'] ?? []), static fn($x) => ($x['daraja'] ?? '') === 'xato');
ok($r['status'] === 200 && !empty($tk['natija']) && !$xato,
    "/api/tekshir (o'rnatish tekshiruvi): stublangan fayllar va olib tashlangan seed xato deb chiqmaydi" . ($xato ? ' — ' . json_encode(array_values($xato), JSON_UNESCAPED_UNICODE) : ''));
foreach (['app' => $APP_DIR, 'demo' => $DEMO_DIR] as $nom => $dir) {
    ok(!file_exists("$dir/php/seed/gh-biznes-reja.json") && filesize("$dir/assets/bino.webp") < 100000,
        "$nom: Green House reja fayli (php/seed) yo'q, fon rasmi (bino.webp) neytral Biwork rasmi (uchinchi tomon belgisi yo'q)");
}
// php/seed olib tashlash: haqiqiy PM da fayl bor (bu sinov nusxasida yo'q) — shuning uchun kichik soxta daraxtda tekshiramiz
$st = sys_get_temp_dir() . '/bw-seed-' . bin2hex(random_bytes(4));
mkdir("$st/php/seed", 0777, true);
file_put_contents("$st/php/seed/gh-biznes-reja.json", '{"biznesReja":{"mavsumlar":[]}}');
file_put_contents("$st/php/versiya.php", "<?php\n\$muhim = ['php/a.php', /* 1.4.1 — mavsum */ 'php/seed/gh-biznes-reja.json',\n  /* 3.2 */ 'php/profil.php'];\n");
exec('php ' . escapeshellarg(__DIR__ . '/../tools/stub-group.php') . ' ' . escapeshellarg($st) . ' 2>&1', $so, $src);
$vv = (string)file_get_contents("$st/php/versiya.php");
exec('php -l ' . escapeshellarg("$st/php/versiya.php") . ' 2>&1', $lo, $lrc);
ok(!file_exists("$st/php/seed") && !str_contains($vv, 'gh-biznes-reja') && str_contains($vv, "'php/a.php'") && str_contains($vv, "'php/profil.php'") && $lrc === 0,
    "stub-group: php/seed/gh-biznes-reja.json va uning o'rnatish tekshiruvi yozuvi olib tashlanadi, qolgan ro'yxat va sintaksis joyida");
exec('rm -rf ' . escapeshellarg($st));

echo "== Qurilmadan olib tashlash\n";
$adm->req('POST', '/licenziya', ['op' => 'deactivate', 'slug' => 'hr', 'csrf' => $csrf($adm)]);
$adm->req('POST', '/licenziya', ['op' => 'deactivate', 'slug' => 'loyiha', 'csrf' => $csrf($adm)]);
$r = $adm->get('/');
ok($r['status'] === 302 && str_ends_with((string)$r['location'], '/licenziya'), "ikkala litsenziya olib tashlangach PM yana yopiq");

echo "\n";
if ($fails === 0) {
    echo "HAMMASI O'TDI ($total)\n";
    exit(0);
}
echo "XATOLAR: $fails / $total\n";
exit(1);
