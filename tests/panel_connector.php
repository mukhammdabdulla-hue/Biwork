<?php
declare(strict_types=1);

/**
 * Reja paneli ↔ Biwork portali ulanishi (konnektor) sinovi. Haqiqiy portal va haqiqiy (patchlangan) panel serverlari kerak,
 * shuning uchun odatda `bash tests/run.sh` orqali ishga tushiriladi.
 *
 * Muhit: PORTAL, PANEL_OPEN (SSO yoqilgan, litsenziya majburiy emas), PANEL_ENF (SSO + majburiy litsenziya),
 *        PANEL_OPEN_DB (SQLite yo'li), BIWORK_DATA (portal ma'lumotlari: imzolash kalitlari), CUST_EMAIL, CUST_PASS,
 *        STAFF_EMAIL, STAFF_PASS, PANEL_ADMIN_LOGIN, PANEL_ADMIN_PASS
 */
require __DIR__ . '/../portal/src/bootstrap.php';
require __DIR__ . '/helpers.php';

$fails = 0;
$total = 0;
$env = static fn(string $k): string => getenv($k) ?: throw new RuntimeException("$k muhit o'zgaruvchisi kerak");
$PORTAL = rtrim($env('PORTAL'), '/');
$OPEN = rtrim($env('PANEL_OPEN'), '/');
$ENF = rtrim($env('PANEL_ENF'), '/');
$openDb = new PDO('sqlite:' . $env('PANEL_OPEN_DB'));

/** Portal "Ochish" tokeni (haqiqiy imzo bilan) — portalni chetlab o'tib turli holatlarni sinash uchun. */
function launchToken(array $over = []): string
{
    $now = Util::now();
    return Tokens::sign(Tokens::LAUNCH, array_replace_recursive([
        'v' => 1, 'iss' => 'biwork', 'aud' => 'reja', 'jti' => bin2hex(random_bytes(12)), 'iat' => $now, 'exp' => $now + 120,
        'mode' => 'demo',
        'user' => ['id' => 77, 'name' => 'Sinov Foydalanuvchi', 'email' => 'sinov@example.uz', 'role' => 'owner', 'tool_role' => 'admin'],
        'cust' => ['id' => 5, 'name' => 'Sinov MChJ'],
        'lic' => ['id' => 1, 'type' => 'paid', 'plan' => 'business', 'features' => ['web_demo'], 'limits' => new stdClass(), 'exp' => null],
        'portal' => 'http://portal.invalid/app/tools/reja',
    ], $over));
}
function me(Browser $b): array
{
    return json_decode($b->get('/api/me')['body'], true) ?: [];
}
function formErr(array $r): string
{
    preg_match('#<div class="lg-err"[^>]*>(.*?)</div>#s', $r['body'], $m);
    return html_entity_decode($m[1] ?? '', ENT_QUOTES);
}
function errText(array $r): string
{
    preg_match('#<div class="lg-info"><p>(.*?)</p>#s', $r['body'], $m);
    return html_entity_decode($m[1] ?? '', ENT_QUOTES);
}

echo "== Portaldan 'Ochish' (haqiqiy oqim): mijoz → portal → panel\n";
$cust = new Browser($PORTAL);
$r = $cust->post('/login', '/login', ['email' => $env('CUST_EMAIL'), 'password' => $env('CUST_PASS')]);
ok($r['status'] === 303, 'mijoz portalga kirdi');
$r = $cust->get('/app/open/reja');
$loc = (string)$r['location'];
ok($r['status'] === 302 && str_starts_with($loc, $OPEN . '/?bw_launch=bwl1.'), "portal panelga yo'naltiradi (" . substr($loc, 0, 60) . '…)');
ok(($r['headers']['referrer-policy'] ?? '') === 'no-referrer', "Referrer-Policy: no-referrer (token qayerga ham oqib ketmaydi)");
ok(!str_contains($loc, 'BW-REJ'), 'litsenziya kaliti tokenga kirmagan');

$user = new Browser($OPEN);
$r = $user->get(substr($loc, strlen($OPEN)));
ok($r['status'] === 303 && $r['location'] === '/', "panel tokenni qabul qilib '/' ga yo'naltiradi (token manzilda qolmaydi)");
ok(str_contains($r['headers']['set-cookie'] ?? '', 'ngd_sess'), 'panel sessiya cookie berdi');
$m = me($user);
ok(($m['ok'] ?? false) && str_starts_with((string)$m['login'], 'bw-') && $m['role'] === 'planner', "namuna rejimida rol 'planner' (" . ($m['login'] ?? '?') . ')');
ok($m['name'] === 'Sinov Egasi', 'foydalanuvchi ismi portaldan olindi');
$r = $user->get('/');
ok($r['status'] === 200, 'panelning bosh sahifasi ochiladi');
$r = $user->req('GET', '/api/users');
ok($r['status'] === 403, 'namuna rejimida foydalanuvchilarni boshqarish yopiq (403)');

$r = (new Browser($OPEN))->get(substr($loc, strlen($OPEN)));
ok($r['status'] === 403 && str_contains(errText($r), 'ishlatilgan'), "ikkinchi marta ishlatilgan token rad etiladi (replay): " . errText($r));

echo "== Token xatoliklari\n";
$b = new Browser($OPEN);
$tok = launchToken();
$r = $b->get('/?bw_launch=' . substr($tok, 0, -3) . 'AAA');
ok($r['status'] === 403 && !isset($r['headers']['set-cookie']), "buzilgan imzo: 403 va sessiya yo'q (" . errText($r) . ')');
$r = $b->get('/?bw_launch=' . launchToken(['exp' => Util::now() - 5, 'iat' => Util::now() - 130]));
ok($r['status'] === 403 && str_contains(errText($r), 'eskirgan'), 'muddati o\'tgan token: ' . errText($r));
$r = $b->get('/?bw_launch=' . launchToken(['aud' => 'boshqa-vosita']));
ok($r['status'] === 403, "boshqa vositaga mo'ljallangan token: 403 (" . errText($r) . ')');
$r = $b->get('/?bw_launch=' . urlencode(Tokens::sign(Tokens::LICENSE, ['aud' => 'reja', 'jti' => 'x', 'iat' => Util::now(), 'exp' => Util::now() + 60])));
ok($r['status'] === 403, "litsenziya tokeni 'Ochish' o'rnida o'tmaydi: 403");
$r = $b->get('/?bw_launch=');
ok($r['status'] === 403 || $r['status'] === 302, "bo'sh token sessiya bermaydi");
ok(($me = me($b)) && ($me['auth'] ?? false) === false, 'xato tokenlardan keyin sessiya ochilmagan');

echo "== Haqiqiy ish rejimi (live) va rol\n";
$b = new Browser($OPEN);
$r = $b->get('/?bw_launch=' . launchToken(['mode' => 'live', 'user' => ['id' => 78, 'name' => 'Admin Egasi']]));
ok($r['status'] === 303, 'live token qabul qilindi');
ok((me($b)['role'] ?? '') === 'admin', "live + tool_role=admin → panelda 'admin'");
$b2 = new Browser($OPEN);
$b2->get('/?bw_launch=' . launchToken(['mode' => 'live', 'user' => ['id' => 79, 'name' => 'Oddiy', 'tool_role' => 'user']]));
ok((me($b2)['role'] ?? '') === 'planner', "live + tool_role=user → 'planner'");
$b3 = new Browser($OPEN);
$b3->get('/?bw_launch=' . launchToken(['mode' => 'demo', 'user' => ['id' => 78, 'name' => 'Admin Egasi']]));
ok((me($b3)['role'] ?? '') === 'planner', "o'sha foydalanuvchi namuna rejimida qayta kirsa rol 'planner' ga tushadi");
$openDb->exec("UPDATE users SET active = 0 WHERE login = 'bw-5-79'");
$r = (new Browser($OPEN))->get('/?bw_launch=' . launchToken(['mode' => 'live', 'user' => ['id' => 79, 'name' => 'Oddiy', 'tool_role' => 'user']]));
ok($r['status'] === 403 && str_contains(errText($r), "o'chirilgan"), "panelda o'chirilgan foydalanuvchi portal orqali kira olmaydi: " . errText($r));
$pw = (string)$openDb->query("SELECT password_hash FROM users WHERE login = 'bw-5-78'")->fetchColumn();
ok($pw !== '' && !password_verify('', $pw) && !password_verify('password', $pw), "SSO foydalanuvchining parolini bilib bo'lmaydi (tasodifiy)");
$r = (new Browser($OPEN))->req('POST', '/login.php', ['login' => 'bw-5-78', 'password' => '']);
ok($r['status'] !== 303, "SSO foydalanuvchi bo'sh parol bilan kira olmaydi");

echo "== Majburiy litsenziya (o'rnatilgan ilova rejimi)\n";
$anon = new Browser($ENF);
$r = $anon->get('/');
ok($r['status'] === 302 && str_contains((string)$r['location'], 'login'), "kirishsiz: avval login sahifasiga");
$adm = new Browser($ENF);
$page = $adm->get('/login.php');
preg_match('/name="csrf" value="([a-f0-9]+)"/', $page['body'], $cm);
$r = $adm->req('POST', '/login.php', ['csrf' => $cm[1] ?? '', 'login' => $env('PANEL_ADMIN_LOGIN'), 'password' => $env('PANEL_ADMIN_PASS')]);
ok($r['status'] === 303, 'panel administratori parol bilan kirdi');
$r = $adm->get('/');
ok($r['status'] === 302 && str_ends_with((string)$r['location'], '/license.php'), "litsenziyasiz: bosh sahifa → /license.php");
$r = $adm->get('/api/orders');
ok($r['status'] === 402 && (json_decode($r['body'], true)['error']['code'] ?? '') === 'license_required', 'litsenziyasiz API: 402 license_required');
ok($adm->get('/api/health')['status'] === 200 || $adm->get('/api/me')['status'] === 200, "/api/me va /api/health litsenziyasiz ham ishlaydi");
$r = $adm->get('/license.php');
ok($r['status'] === 200 && str_contains($r['body'], 'Faollashtirilmagan') && str_contains($r['body'], 'name="key"'), 'license.php: holat + faollashtirish shakli');

$r = $adm->post('/license.php', '/license.php', ['op' => 'activate', 'key' => 'BW-REJ-AAAA-BBBB-CCCC']);
ok($r['status'] === 200 && str_contains($r['body'], 'lg-err'), "noto'g'ri kalit: xato ko'rsatiladi");
$r = $adm->req('POST', '/license.php', ['op' => 'activate', 'key' => $env('LICENSE_KEY'), 'csrf' => 'yolgon']);
ok(str_contains($r['body'], 'eskirgan') && $adm->get('/')['status'] === 302, 'CSRF tokensiz faollashtirib bo\'lmaydi');

$r = $adm->post('/license.php', '/license.php', ['op' => 'activate', 'key' => $env('LICENSE_KEY')]);
ok($r['status'] === 200 && str_contains($r['body'], 'faollashtirildi'), 'litsenziya faollashtirildi' . (formErr($r) !== '' ? ': ' . formErr($r) : ''));
ok($adm->get('/')['status'] === 200, "faollashtirilgandan so'ng panel ochiladi");
ok($adm->get('/api/me')['status'] === 200 && $adm->get('/api/orders')['status'] !== 402, 'API ham ochiq');
$st = $adm->get('/license.php')['body'];
ok(str_contains($st, 'Namuna korxona') && str_contains($st, 'Qurilma'), "license.php korxona va qurilmani ko'rsatadi");

$lic = json_decode((string)file_get_contents($env('PANEL_ENF_DIR') . '/data/biwork-license.json'), true) ?: [];
ok(($lic['key'] ?? '') === $env('LICENSE_KEY') && str_starts_with((string)($lic['token'] ?? ''), 'bwk1.'), 'panel imzolangan litsenziya tokenini saqladi (bwk1)');

echo "== Server tomondan to'xtatish va qayta yoqish\n";
$staff = new Browser($PORTAL);
$staff->post('/login', '/login', ['email' => $env('STAFF_EMAIL'), 'password' => $env('STAFF_PASS')]);
$lid = (int)$env('LICENSE_ID');
$staff->post("/admin/licenses/$lid", "/admin/licenses/$lid/status", ['status' => 'suspended']);
$r = $adm->post('/license.php', '/license.php', ['op' => 'refresh']);
ok(str_contains($r['body'], 'lg-err') || str_contains($r['body'], 'Bloklangan'), 'to\'xtatilgan litsenziya "Hozir tekshirish"da bloklanadi');
$r = $adm->get('/');
ok($r['status'] === 302 && str_ends_with((string)$r['location'], '/license.php'), "bloklangan: panel yopiladi");
ok($adm->get('/api/orders')['status'] === 402, 'bloklangan: API 402');
$staff->post("/admin/licenses/$lid", "/admin/licenses/$lid/status", ['status' => 'active']);
$adm->post('/license.php', '/license.php', ['op' => 'refresh']);
ok($adm->get('/')['status'] === 200, "qayta yoqilgach \"Hozir tekshirish\" paneldagi blokni olib tashlaydi (kalit qayta kiritilmaydi)");

echo "== Portaldan 'Ochish' majburiy rejimda\n";
$u = new Browser($ENF);
$r = $u->get('/?bw_launch=' . launchToken(['mode' => 'live', 'user' => ['id' => 91, 'name' => 'Ilova foydalanuvchisi', 'tool_role' => 'user']]));
ok($r['status'] === 303, "litsenziya faol: SSO kirish ishlaydi");
ok($u->get('/')['status'] === 200, 'foydalanuvchi panelni ko\'radi');
$r = $u->post('/license.php', '/license.php', ['op' => 'deactivate']);
ok(str_contains($r['body'], 'administrator') && $adm->get('/')['status'] === 200, 'oddiy foydalanuvchi litsenziyani o\'chira olmaydi');

echo "== Qurilmadan olib tashlash\n";
$r = $adm->post('/license.php', '/license.php', ['op' => 'deactivate']);
ok($r['status'] === 200 && str_contains(html_entity_decode($r['body'], ENT_QUOTES), "bo'shatildi"), "administrator litsenziyani qurilmadan oladi, o'rindiq bo'shaydi");
$r = $adm->get('/');
ok($r['status'] === 302 && str_ends_with((string)$r['location'], '/license.php'), 'olib tashlangach panel yana yopiq');
$r = $staff->get("/admin/licenses/$lid")['body'];
ok(str_contains($r, "Bo'shatilgan"), "portalda qurilma 'Bo'shatilgan' deb ko'rinadi");

echo "\n";
if ($fails === 0) {
    echo "HAMMASI O'TDI ($total)\n";
    exit(0);
}
echo "XATOLAR: $fails / $total\n";
exit(1);
