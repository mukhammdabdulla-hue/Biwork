<?php
declare(strict_types=1);

/**
 * Brauzer oqimi (HTTP) sinovi: ro'yxatdan o'tish → sinov → Ochish → xodim litsenziya beradi → yuklab olish →
 * ilova faollashtiradi → o'rindiq → to'xtatish; xavfsizlik tekshiruvlari.
 * Ishga tushirish: bash tests/run.sh   (o'zi vaqtinchalik server ko'taradi)
 *   BASE=http://127.0.0.1:8099 STAFF_EMAIL=... STAFF_PASS=... php tests/e2e.php
 */
require __DIR__ . '/../sdk/php/BiworkConnect.php';

$BASE = rtrim(getenv('BASE') ?: 'http://127.0.0.1:8099', '/');
$STAFF = [getenv('STAFF_EMAIL') ?: 'admin@biwork.test', getenv('STAFF_PASS') ?: 'Admin-Parol-2026'];
$fails = 0;
$total = 0;
require __DIR__ . '/helpers.php';

echo "== Ochiq sahifalar\n";
$anon = new Browser($BASE);
foreach (['/' => 'tayyor dasturlar', '/mahsulotlar' => 'Biwork Reja', '/reja' => 'Tariflar', '/login' => 'Kirish', '/register' => "Ro'yxatdan o'tish"] as $p => $needle) {
    $r = $anon->get($p);
    ok($r['status'] === 200 && str_contains($r['body'], $needle), "GET $p 200 va '$needle'");
}
ok($anon->get('/yoq')['status'] === 404, 'noma\'lum mahsulot: 404');
ok($anon->get('/app')['location'] === '/login?next=%2Fapp' || str_contains((string)$anon->get('/app')['location'], '/login'), '/app kirishsiz: /login ga yo\'naltiriladi');
ok(str_contains((string)$anon->get('/admin')['location'], '/login'), '/admin kirishsiz: /login ga yo\'naltiriladi');
ok($anon->get('/app/download/1')['status'] === 303, 'yuklash kirishsiz: yo\'naltiriladi');
$pk = json_decode($anon->get('/api/v1/pubkey')['body'], true);
ok(($pk['ok'] ?? false) && count($pk['keys']) >= 1 && $pk['keys'][0]['alg'] === 'Ed25519', 'ochiq kalit API');
$keys = BiworkConnect::loadKeys($pk);

echo "== Ro'yxatdan o'tish va sinov\n";
$cust = new Browser($BASE);
$xss = '<script>alert(1)</script> MChJ';
$r = $cust->req('POST', '/register', ['csrf' => '', 'company' => 'x', 'name' => 'x', 'email' => 'a@b.uz', 'password' => 'Kuchli-Parol-1']);
ok($r['status'] === 419, 'CSRFsiz POST rad etiladi (419)');
$r = $cust->post('/register?tool=reja', '/register', ['tool' => 'reja', 'company' => $xss, 'name' => 'Ali Valiyev', 'email' => 'ali@sinov.uz', 'phone' => '+998901234567', 'password' => 'qisqa']);
ok($r['status'] === 422 && str_contains($r['body'], 'kamida 10'), 'qisqa parol rad etiladi');
$r = $cust->post('/register?tool=reja', '/register', ['tool' => 'reja', 'company' => $xss, 'name' => 'Ali Valiyev', 'email' => 'ali@sinov.uz', 'phone' => '+998901234567', 'password' => 'Kuchli-Parol-1']);
ok($r['status'] === 303 && $r['location'] === '/app/tools/reja', "ro'yxatdan o'tdi → /app/tools/reja");
$page = $cust->follow($r);
ok(str_contains($page['body'], 'kunlik sinovni boshlash'), 'sinovni boshlash tugmasi');
ok(!str_contains($page['body'], '<script>alert(1)') && str_contains($page['body'], '&lt;script&gt;'), 'XSS: korxona nomi ekranlanadi');
$r = $cust->post('/app/tools/reja', '/app/tools/reja/trial');
$page = $cust->follow($r);
ok(str_contains($page['body'], 'Sinov boshlandi') && str_contains($page['body'], 'Brauzerda ochish'), 'sinov boshlandi, Ochish tugmasi bor');
$r = $cust->post('/app/tools/reja', '/app/tools/reja/trial');
ok(str_contains($cust->follow($r)['body'], 'allaqachon ishlatilgan'), 'ikkinchi sinov rad etiladi');
ok(!str_contains($page['body'], 'BW-REJ-'), 'sinov kaliti mijozga ko\'rsatilmaydi');

echo "== Ochish (launch token)\n";
$r = $cust->get('/app/open/reja');
ok($r['status'] === 303 || $r['status'] === 302, 'Ochish: yo\'naltirish');
$loc = (string)$r['location'];
ok(str_starts_with($loc, 'http://127.0.0.1:') && str_contains($loc, 'bw_launch=bwl1.'), 'sozlangan vosita manziliga imzolangan token bilan: ' . substr($loc, 0, 60) . '…');
parse_str((string)parse_url($loc, PHP_URL_QUERY), $q);
$sdk = new BiworkConnect(['server' => $BASE, 'tool' => 'reja', 'data_dir' => sys_get_temp_dir() . '/bw-e2e-' . bin2hex(random_bytes(3)), 'public_keys' => $pk]);
$claims = $sdk->verifyLaunch($q['bw_launch'] ?? '');
ok($claims['user']['email'] === 'ali@sinov.uz' && $claims['mode'] === 'demo' && $claims['lic']['type'] === 'trial', 'token: foydalanuvchi, demo rejim, sinov');
ok(($r['headers']['referrer-policy'] ?? '') === 'no-referrer', 'Referrer-Policy: no-referrer (token Referer orqali sizmaydi)');

echo "== Yuklab olish: sinovda yo'q\n";
$rel = (int)(getenv('RELEASE_ID') ?: 1);
ok($cust->get("/app/download/$rel")['status'] === 403, 'sinov litsenziyasi yuklab ololmaydi (403)');

echo "== Xodim: kirish va litsenziya berish\n";
$staff = new Browser($BASE);
$r = $staff->post('/login', '/login', ['email' => $STAFF[0], 'password' => 'noto\'g\'ri']);
ok($r['status'] === 401, "noto'g'ri parol: 401");
$r = $staff->post('/login', '/login', ['email' => $STAFF[0], 'password' => $STAFF[1], 'next' => '//evil.example']);
ok($r['status'] === 303 && $r['location'] === '/admin', "xodim kirdi → /admin (tashqi 'next' e'tiborga olinmadi)");
ok(str_contains($staff->get('/admin')['body'], 'Boshqaruv'), 'admin paneli ochildi');
ok($cust->get('/admin')['status'] === 403, 'mijoz /admin ga kira olmaydi (403)');
// vosita manifestini admin panelidan yuklash (SSH siz hostingda tool:register o'rnini bosadi)
$mf = tempnam(sys_get_temp_dir(), 'bwmf');
$manifest = json_decode((string)file_get_contents(__DIR__ . '/../integrations/reja/manifest.json'), true);
$manifest['tagline'] = 'Manifest orqali yangilangan tavsif';
file_put_contents($mf, json_encode($manifest));
$page = $staff->get('/admin/tools');
ok(str_contains($page['body'], "manifest.json orqali qo'shish") && str_contains($page['body'], '/admin/tools/new'), 'mahsulotlar sahifasida «Yangi mahsulot» va manifest yuklash shakli');
$r = $staff->req('POST', '/admin/tools/register', ['csrf' => $staff->csrf('/admin/tools'), 'manifest' => new CURLFile($mf, 'application/json', 'manifest.json')], ['multipart' => true]);
ok(str_contains($staff->follow($r)['body'], 'allaqachon bor') && !str_contains($staff->get('/mahsulotlar')['body'], 'Manifest orqali yangilangan tavsif'), "mavjud mahsulotni manifest bilan almashtirish tasdiqlashsiz rad etiladi");
$r = $staff->req('POST', '/admin/tools/register', ['csrf' => $staff->csrf('/admin/tools'), 'overwrite' => '1', 'manifest' => new CURLFile($mf, 'application/json', 'manifest.json')], ['multipart' => true]);
ok($r['status'] === 303 && str_contains($staff->follow($r)['body'], 'ro&#039;yxatga olindi') && str_contains($staff->get('/mahsulotlar')['body'], 'Manifest orqali yangilangan tavsif'), 'manifest yuklandi (tasdiqlangach): vosita yangilandi');
file_put_contents($mf, '{ bu json emas');
$r = $staff->req('POST', '/admin/tools/register', ['csrf' => $staff->csrf('/admin/tools'), 'manifest' => new CURLFile($mf, 'application/json', 'manifest.json')], ['multipart' => true]);
ok(str_contains($staff->follow($r)['body'], 'JSON formatida emas'), "buzuq manifest rad etiladi");
file_put_contents($mf, json_encode(['slug' => 'Yomon Nom', 'code' => 'ab', 'name' => 'X']));
$r = $staff->req('POST', '/admin/tools/register', ['csrf' => $staff->csrf('/admin/tools'), 'manifest' => new CURLFile($mf, 'application/json', 'manifest.json')], ['multipart' => true]);
ok(str_contains($staff->follow($r)['body'], 'kichik lotin'), "noto'g'ri slug/code rad etiladi");
$r = $cust->req('POST', '/admin/tools/register', ['csrf' => $cust->csrf('/app/tools/reja'), 'manifest' => new CURLFile($mf, 'application/json', 'manifest.json')], ['multipart' => true]);
ok($r['status'] === 403, 'mijoz vosita qo\'sha olmaydi (403)');
@unlink($mf);
$req = $cust->post('/app/tools/reja', '/app/tools/reja/request', ['plan' => 'standard', 'seats' => '1', 'comment' => 'Bizga 1 ta kompyuter kerak']);
ok(str_contains($cust->follow($req)['body'], 'So&#039;rov yuborildi'), "mijoz sotib olish so'rovini yubordi");
$reqs = $staff->get('/admin/requests');
ok(str_contains($reqs['body'], 'Bizga 1 ta kompyuter kerak'), "xodim so'rovni ko'rdi");
preg_match('#/admin/licenses/new\?request=(\d+)#', $reqs['body'], $m);
$rid = $m[1] ?? '';
ok($rid !== '', "'Litsenziya berish' havolasi");
$form = $staff->get('/admin/licenses/new?request=' . $rid);
ok(preg_match('/<option value="(\d+)" selected>[^<]*<\/option>/', $form['body'], $mm) === 1, 'forma so\'rov bo\'yicha oldindan to\'ldirildi');
$cid = (int)$mm[1];
$r = $staff->post('/admin/licenses/new?request=' . $rid, '/admin/licenses/new', ['request' => $rid, 'customer' => (string)$cid, 'tool' => '1', 'plan' => 'standard', 'seats' => '1', 'until' => '', 'instance_url' => '', 'note' => 'e2e']);
ok($r['status'] === 303 && preg_match('#^/admin/licenses/(\d+)$#', (string)$r['location'], $lm) === 1, 'litsenziya berildi');
$lid = (int)$lm[1];
$detail = $staff->follow($r);
preg_match('/BW-REJ-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}/', $detail['body'], $km);
$KEY = $km[0] ?? '';
ok($KEY !== '', "kalit yaratildi: $KEY");
ok(str_contains($staff->get('/admin/requests?status=handled')['body'], 'Bajarilgan'), "so'rov 'Bajarilgan' bo'ldi");

echo "== Mijoz: kalit va yuklab olish\n";
$page = $cust->get('/app/tools/reja');
ok(str_contains($page['body'], $KEY) && str_contains($page['body'], 'Yuklab olish'), 'mijoz kalitni va Yuklab olish bo\'limini ko\'rdi');
$dl = $cust->get("/app/download/$rel");
ok($dl['status'] === 200 && strlen($dl['body']) > 1000, 'yuklab olish 200 (' . strlen($dl['body']) . ' bayt)');
ok(hash('sha256', $dl['body']) === ($dl['headers']['x-sha256'] ?? ''), 'SHA-256 sarlavhasi fayl bilan mos');
ok(str_contains($dl['headers']['content-disposition'] ?? '', 'attachment'), 'attachment sarlavhasi');
$other = new Browser($BASE);
$r = $other->post('/register', '/register', ['company' => 'Boshqa MChJ', 'name' => 'Vali', 'email' => 'vali@boshqa.uz', 'password' => 'Kuchli-Parol-2']);
ok($r['status'] === 303, 'ikkinchi korxona ro\'yxatdan o\'tdi (' . $r['status'] . ')');
ok($other->get("/app/download/$rel")['status'] === 403, 'boshqa korxona yuklab ololmaydi (403)');
ok(!str_contains($other->get('/app/tools/reja')['body'], $KEY), 'boshqa korxona kalitni ko\'rmaydi');

echo "== Ilova (API) faollashtirishi\n";
$dev1 = str_repeat('1', 32);
$dev2 = str_repeat('2', 32);
[$st, $j] = api($BASE, 'activate', ['key' => $KEY, 'device_id' => $dev1, 'tool' => 'reja', 'device_name' => 'ZAVOD-PC', 'platform' => 'Windows', 'app_version' => '1.8']);
ok($st === 200 && ($j['ok'] ?? false) && str_starts_with($j['token'] ?? '', 'bwk1.'), 'faollashtirildi, imzolangan token olindi');
$payload = BiworkConnect::verifyToken('bwk1', $j['token'], $keys);
ok($payload['dev'] === $dev1 && $payload['cust']['name'] === $xss && $payload['seats'] === 1, 'token ochiq kalit bilan tekshirildi');
[$st, $j] = api($BASE, 'activate', ['key' => $KEY, 'device_id' => $dev2, 'tool' => 'reja']);
ok($st === 409 && $j['error']['code'] === 'seats_full' && in_array('ZAVOD-PC', $j['error']['devices'], true), "2-qurilma: o'rindiq band (409), qaysi qurilma band ekani aytiladi");
[$st, $j] = api($BASE, 'check', ['key' => $KEY, 'device_id' => $dev1, 'tool' => 'reja']);
ok($st === 200 && ($j['license']['seats_used'] ?? 0) === 1, 'davriy tekshiruv');
$page = $cust->get('/app/tools/reja');
ok(str_contains($page['body'], 'ZAVOD-PC'), 'mijoz portalda qurilmani ko\'rdi');
preg_match('#/app/devices/(\d+)/release#', $page['body'], $dm);
$r = $cust->post('/app/tools/reja', '/app/devices/' . ($dm[1] ?? 0) . '/release');
ok(str_contains($cust->follow($r)['body'], 'Qurilma bo&#039;shatildi'), "mijoz qurilmani bo'shatdi");
$r = $other->post('/app/tools/reja', '/app/devices/' . ($dm[1] ?? 0) . '/release');
ok($r['status'] === 403 || $r['status'] === 404, "begona korxona qurilmani bo'sha olmaydi (" . $r['status'] . ')');
[$st, $j] = api($BASE, 'check', ['key' => $KEY, 'device_id' => $dev1]);
ok($st === 403 && $j['error']['code'] === 'not_activated', "bo'shatilgan qurilma tekshiruvdan o'tmaydi");
[$st, $j] = api($BASE, 'activate', ['key' => $KEY, 'device_id' => $dev2, 'tool' => 'reja', 'device_name' => 'YANGI-PC']);
ok($st === 200, "bo'shagan o'rindiqqa yangi qurilma faollashdi");

echo "== To'xtatish va bekor qilish\n";
$r = $staff->post('/admin/licenses/' . $lid, "/admin/licenses/$lid/status", ['status' => 'suspended']);
[$st, $j] = api($BASE, 'check', ['key' => $KEY, 'device_id' => $dev2]);
ok($st === 403 && $j['error']['code'] === 'suspended', "to'xtatilgan litsenziya API da rad etiladi");
ok(str_contains($cust->get('/app/tools/reja')['body'], "To&#039;xtatilgan"), "mijoz portalda 'To'xtatilgan' ko'radi");
ok($cust->get("/app/download/$rel")['status'] === 403, "to'xtatilganda yuklab olish yopiq");
$r = $staff->post('/admin/licenses/' . $lid, "/admin/licenses/$lid/status", ['status' => 'active']);
[$st] = api($BASE, 'check', ['key' => $KEY, 'device_id' => $dev2]);
ok($st === 200, 'qayta yoqildi: ishlaydi');
$r = $staff->post('/admin/licenses/' . $lid, "/admin/licenses/$lid/until", ['until' => date('Y-m-d', time() + 400 * 86400)]);
[$st, $j] = api($BASE, 'check', ['key' => $KEY, 'device_id' => $dev2]);
ok($j['license']['days_left'] >= 399, 'muddat uzaytirildi: ' . ($j['license']['days_left'] ?? '?') . ' kun');
$events = $staff->get("/admin/licenses/$lid")['body'];
foreach (['Litsenziya berildi', 'Faollashtirildi', "Qurilma bo&#039;shatildi", 'Yuklab olindi', "To&#039;xtatildi", 'Muddat o&#039;zgardi'] as $ev) {
    ok(str_contains($events, $ev), "jurnalda: $ev");
}

echo "== Xavfsizlik\n";
$bad = 0;
for ($i = 0; $i < 12; $i++) {
    [$st] = api($BASE, 'activate', ['key' => sprintf('BW-REJ-AAAA-BBBB-%04d', $i), 'device_id' => str_repeat('3', 32)]);
    $bad = $st;
}
ok($bad === 429, "noto'g'ri kalitlarni ketma-ket sinash 429 bilan to'xtatiladi");
[$st, $j] = api($BASE, 'activate', ['key' => $KEY, 'device_id' => 'nothex']);
ok($st === 429 || $st === 400, "noto'g'ri device_id rad etiladi (" . $st . ')');
$r = $anon->req('POST', '/api/v1/licenses/activate', [], ['json' => []]);
ok(in_array($r['status'], [400, 429], true), "bo'sh so'rov rad etiladi");
$r = $cust->req('POST', '/app/team/add', ['csrf' => $cust->csrf('/app/team'), 'name' => 'X', 'email' => 'x@y.uz', 'password' => 'Kuchli-Parol-3']);
ok($r['status'] === 303, 'egasi xodim qo\'sha oldi');
$member = new Browser($BASE);
$member->post('/login', '/login', ['email' => 'x@y.uz', 'password' => 'Kuchli-Parol-3']);
$r = $member->get('/app');
ok($r['status'] === 303 && str_contains((string)$r['location'], '/app/profile'), 'xodim birinchi kirishda parolni almashtirishga majbur');
$pg = $member->get('/app/profile');
ok($pg['status'] === 200, 'profil sahifasi ochiladi');
$r = $member->post('/app/profile', '/app/profile', ['current' => 'Kuchli-Parol-3', 'password' => 'Yangi-Parol-77']);
ok($member->get('/app')['status'] === 200, 'parol almashtirgach ilovaga kirdi');
ok($member->get('/app/team')['status'] === 403, 'xodim (member) jamoa sahifasini ko\'ra olmaydi');
$page = $member->get('/app/tools/reja');
ok(!str_contains($page['body'], $KEY) && str_contains($page['body'], 'Brauzerda ochish'), 'xodim kalitni ko\'rmaydi, lekin vositani ocha oladi');
$r = $staff->post('/admin', '/logout');
ok($r['status'] === 303 && $staff->get('/admin')['status'] === 303, 'chiqish ishladi');

echo "\n" . ($fails === 0 ? "HAMMASI O'TDI ($total)" : "$fails TA XATO ($total dan)") . "\n";
exit($fails === 0 ? 0 : 1);
