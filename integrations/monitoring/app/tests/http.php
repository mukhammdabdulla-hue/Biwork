<?php
declare(strict_types=1);

/**
 * HTTP sinovi: kirish, himoya (CSRF, rollar), API, Biwork portalidan "Ochish" (SSO) va umumiy namuna.
 * Odatda `bash tests/run.sh` ishga tushiradi (serverlar va portal kalitlarini tayyorlaydi).
 *
 * Muhit: BASE (SSO + shaxsiy nusxa), BASE_DEMO (umumiy namuna), BASE_ENF (majburiy litsenziya),
 *        PORTAL_SRC (biwork/portal/src), BIWORK_DATA (portal imzo kalitlari), ADMIN_LOGIN, ADMIN_PASS
 */
$env = static fn(string $k): string => getenv($k) ?: throw new RuntimeException("$k muhit o'zgaruvchisi kerak");
$BASE = rtrim($env('BASE'), '/');
$DEMO = rtrim($env('BASE_DEMO'), '/');
$ENF = rtrim($env('BASE_ENF'), '/');
$haveSso = is_file($env('PORTAL_SRC') . '/bootstrap.php');
if ($haveSso) {
    require $env('PORTAL_SRC') . '/bootstrap.php';
}

$fails = 0;
$total = 0;
function ok(bool $c, string $n): void
{
    global $fails, $total;
    $total++;
    echo ($c ? '  ✓ ' : '  ✗ ') . $n . "\n";
    if (!$c) {
        $fails++;
    }
}

/** Cookie saqlaydigan, yo'naltirishga ergashmaydigan oddiy HTTP mijoz. */
final class Http
{
    private string $jar;
    public ?string $csrf = null;

    public function __construct(public string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'monjar');
    }

    public function req(string $method, string $path, ?array $json = null, array $form = [], bool $withCsrf = true): array
    {
        $ch = curl_init(str_starts_with($path, 'http') ? $path : $this->base . $path);
        $hdr = [];
        $h = ['Accept: application/json'];
        if ($method !== 'GET' && $withCsrf && $this->csrf) {
            $h[] = 'X-CSRF-Token: ' . $this->csrf;
        }
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_HEADERFUNCTION => static function ($c, $line) use (&$hdr) {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $hdr[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            }]);
        if ($json !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
            $h[] = 'Content-Type: application/json';
        } elseif ($form) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
            $h[] = 'Content-Type: application/json';
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
        $body = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => $body, 'json' => json_decode($body, true), 'headers' => $hdr, 'location' => $hdr['location'] ?? null];
    }

    public function get(string $p): array
    {
        return $this->req('GET', $p);
    }

    public function api(string $method, string $p, ?array $json = null): array
    {
        return $this->req($method, '/api' . $p, $json);
    }

    /** Sessiya ochilgach CSRF belgisini /api/me dan oladi. */
    public function me(): array
    {
        $r = $this->get('/api/me');
        $this->csrf = $r['json']['csrf'] ?? $this->csrf;
        return $r['json'] ?? [];
    }
}

function launchToken(array $over = []): string
{
    $now = time();
    return Tokens::sign(Tokens::LAUNCH, array_replace_recursive([
        'v' => 1, 'iss' => 'biwork', 'aud' => 'monitoring', 'jti' => bin2hex(random_bytes(12)), 'iat' => $now, 'exp' => $now + 120,
        'mode' => 'demo',
        'user' => ['id' => 77, 'name' => 'Sinov Foydalanuvchi', 'email' => 'sinov@example.uz', 'role' => 'owner', 'tool_role' => 'admin'],
        'cust' => ['id' => 5, 'name' => 'Sinov MChJ'],
        'lic' => ['id' => 1, 'type' => 'paid', 'plan' => 'business', 'features' => ['web_demo'], 'limits' => new stdClass(), 'exp' => null],
        'portal' => 'http://portal.invalid/app/tools/monitoring',
    ], $over));
}
function pageText(array $r): string
{
    preg_match('#<div class="lg-info"><p>(.*?)</p>#s', $r['body'], $m);
    return html_entity_decode($m[1] ?? '', ENT_QUOTES);
}

echo "== Asosiy: kirish va API (shaxsiy nusxa)\n";
$a = new Http($BASE);
$r = $a->get('/api/health');
ok($r['status'] === 200 && ($r['json']['ok'] ?? false), '/api/health ochiq');
$r = $a->get('/api/me');
ok($r['status'] === 401, 'kirmagan foydalanuvchiga API yopiq (401)');
$r = $a->get('/');
ok($r['status'] === 302 && str_contains((string)$r['location'], 'login.php'), "kirmagan foydalanuvchi kirish sahifasiga yo'naltiriladi");
$r = $a->req('POST', '/login.php', null, ['login' => 'admin', 'password' => 'noto-g-ri-parol-1', 'csrf' => 'x']);
ok($r['status'] === 200 && !isset($r['headers']['location']) && preg_match("/lg-err/", $r['body']) === 1, "noto'g'ri parol rad etiladi");
$login = $a->get('/login.php');
preg_match('/name="csrf" value="([a-f0-9]+)"/', $login['body'], $m);
$r = $a->req('POST', '/login.php', null, ['login' => $env('ADMIN_LOGIN'), 'password' => $env('ADMIN_PASS'), 'csrf' => $m[1] ?? '']);
ok($r['status'] === 303, "to'g'ri parol bilan kiriladi");
$me = $a->me();
ok(($me['user']['role'] ?? '') === 'admin' && !empty($me['csrf']) && ($me['demo'] ?? false), 'administrator sifatida kirdi, namuna korxona yoqilgan');
$r = $a->get('/');
ok($r['status'] === 200 && str_contains($r['body'], 'lang="uz"') && str_contains($r['body'], 'assets/app.js'), 'bosh sahifa (SPA qobig\'i) ochiladi');
ok(str_contains($r['headers']['content-security-policy'] ?? '', "default-src 'self'"), 'Content-Security-Policy yoqilgan');
foreach (['assets/app.css', 'assets/app.js', 'assets/fonts/Inter-Regular.woff2', 'assets/favicon.svg'] as $f) {
    ok($a->get('/' . $f)['status'] === 200, "/$f yuklanadi");
}
ok($a->get('/.htaccess')['status'] === 403, 'nuqta bilan boshlanuvchi fayllar yopiq');
ok(!str_contains($a->get('/src/Db.php')['body'], 'final class Db'), 'dastur kodi tashqariga berilmaydi');
$r = $a->get('/api/nonexistent');
ok($r['status'] === 404 && isset($r['json']['error']['message']), 'noma\'lum manzil — JSON 404');

echo "== Himoya: CSRF va rollar\n";
$r = $a->req('POST', '/api/alerts/1/seen', null, [], false);
ok($r['status'] === 403 && ($r['json']['error']['code'] ?? '') === 'csrf', 'CSRF belgisisiz o\'zgartirish rad etiladi (403)');
$ov = $a->api('GET', '/overview')['json'];
ok(($ov['alerts_total'] ?? 0) >= 8 && count($ov['sections'] ?? []) === 5 && count($ov['trend'] ?? []) === 7, 'bosh sahifa ma\'lumoti: ogohlantirishlar, 5 bo\'lim, 7 kunlik dinamika');
$al = $a->api('GET', '/alerts?status=active&limit=50')['json'];
ok(count($al['items']) === $al['counts']['active'], 'faol ogohlantirishlar ro\'yxati sonlar bilan mos (' . $al['counts']['active'] . ')');
$crit = $a->api('GET', '/alerts?severity=crit')['json'];
ok(count($crit['items']) >= 1 && count(array_filter($crit['items'], static fn($x) => $x['severity'] !== 'crit')) === 0, 'jiddiylik bo\'yicha filtr');
$q = $a->api('GET', '/alerts?q=Tigel-05')['json'];
ok(count($q['items']) >= 1 && count(array_filter($q['items'], static fn($x) => !str_contains($x['title'] . $x['entity_name'], 'Tigel-05'))) === 0, 'qidiruv');
$one = $a->api('GET', '/alerts/' . $crit['items'][0]['id'])['json'];
ok(!empty($one['why']) && !empty($one['action']) && !empty($one['log']) && !empty($one['people']), 'ogohlantirish tafsiloti: sabab, tavsiya, tarix, mas\'ullar');
$r = $a->api('POST', '/alerts/' . $one['id'] . '/seen');
ok($r['status'] === 200 && $r['json']['status'] === 'seen', 'CSRF bilan «Ko\'rdim» ishlaydi');
$r = $a->api('POST', '/alerts/999999/seen');
ok($r['status'] === 404, 'mavjud bo\'lmagan ogohlantirish — 404');
ok(count($a->api('GET', '/machines')['json']['items']) === 14, '/machines: 14 ta uskuna');
$md = $a->api('GET', '/machines/tg05')['json'];
ok(($md['machine']['state'] ?? '') === 'stop' && count($md['plan']) > 0 && count($md['timeline72']) > 0, 'uskuna sahifasi: holat, reja, 72 soatlik chiziq');
ok($a->api('GET', '/machines/yoq_uskuna')['status'] === 404, 'noma\'lum uskuna — 404');
$or = $a->api('GET', '/orders')['json'];
ok(count($or['items']) > 10 && !empty($or['items'][0]['ops']), '/orders: buyurtmalar bosqichlari bilan');
$pf = $a->api('GET', '/planfact?before=6&after=6')['json'];
ok(count($pf['machines']) === 14 && $pf['to'] - $pf['from'] === 12 * 3600, '/planfact: 14 uskuna, ±6 soat');
ok(count($a->api('GET', '/sources')['json']['items']) === 4, '/sources: 4 manba');
$t0 = $me['now'];
$r = $a->api('POST', '/demo/advance', ['minutes' => 60]);
ok($r['status'] === 200 && $r['json']['now'] === $t0 + 3600, 'namuna soati +1 soatga surildi');
$r = $a->api('PUT', '/rules/stop_long', ['params' => ['minutes' => 90]]);
$sl = array_values(array_filter($r['json']['rules'] ?? [], static fn($x) => $x['code'] === 'stop_long'))[0] ?? null;
ok($r['status'] === 200 && $sl && $sl['params'][0]['value'] == 90, 'administrator qoidani o\'zgartira oladi');
$r = $a->api('PUT', '/rules/stop_long', ['params' => ['minutes' => 1]]);
ok($r['status'] === 422, 'chegaradan tashqari qiymat — 422');
$a->api('PUT', '/rules/stop_long', ['params' => ['minutes' => 60]]);
$r = $a->api('POST', '/demo/reset');
ok($r['status'] === 200 && $r['json']['now'] === $t0, 'namuna dastlabki holatga qaytdi');

if (!$haveSso) {
    echo "  (portal kodi topilmadi — SSO sinovlari o'tkazib yuborildi)\n";
} else {
    echo "== Biwork portalidan «Ochish» (SSO)\n";
    $b = new Http($BASE);
    $tok = launchToken();
    $r = $b->get('/?bw_launch=' . $tok);
    ok($r['status'] === 303 && $r['location'] === '/', "token qabul qilindi va tokensiz manzilga yo'naltirildi");
    ok(($r['headers']['referrer-policy'] ?? '') === 'no-referrer', 'Referrer-Policy: no-referrer');
    $m = $b->me();
    ok(($m['user']['role'] ?? '') === 'user' && ($m['user']['name'] ?? '') === 'Sinov Foydalanuvchi' && !($m['can_admin'] ?? true), "namuna rejimida oddiy foydalanuvchi roli (qoidalarni o'zgartira olmaydi)");
    ok(($b->api('PUT', '/rules/stop_long', ['params' => ['minutes' => 70]])['status'] ?? 0) === 403, "oddiy foydalanuvchi qoidalarni o'zgartirsa — 403");
    $r = $b->api('POST', '/alerts/' . $one['id'] . '/comment', ['text' => 'Sinov izohi']);
    ok($r['status'] === 200, 'oddiy foydalanuvchi ogohlantirish bilan ishlay oladi (izoh)');
    $r = (new Http($BASE))->get('/?bw_launch=' . $tok);
    ok($r['status'] === 403 && str_contains(pageText($r), 'ishlatilgan'), 'ikkinchi marta ishlatilgan token rad etiladi: ' . pageText($r));
    $r = (new Http($BASE))->get('/?bw_launch=' . launchToken(['aud' => 'reja']));
    ok($r['status'] === 403 && str_contains(pageText($r), 'boshqa vosita'), 'boshqa vosita uchun token rad etiladi');
    $r = (new Http($BASE))->get('/?bw_launch=' . launchToken(['iat' => time() - 400, 'exp' => time() - 280]));
    ok($r['status'] === 403 && str_contains(pageText($r), 'eskirgan'), 'muddati o\'tgan token rad etiladi');
    $t = launchToken();
    $r = (new Http($BASE))->get('/?bw_launch=' . substr($t, 0, -4) . 'AAAA');
    ok($r['status'] === 403, 'imzosi buzilgan token rad etiladi');
    $r = (new Http($BASE))->get('/?bw_launch=' . launchToken(['cust' => ['id' => 9]]));
    ok($r['status'] === 403 && str_contains(pageText($r), 'boshqa korxona'), "boshqa korxona tokeni shaxsiy nusxaga kirmaydi");
    $c = new Http($BASE);
    $c->get('/?bw_launch=' . launchToken(['mode' => 'live', 'user' => ['id' => 78, 'name' => 'Rahbar', 'tool_role' => 'admin']]));
    $m = $c->me();
    ok(($m['user']['role'] ?? '') === 'admin' && ($m['can_admin'] ?? false), "haqiqiy (live) rejimda portal administratori — panel administratori");
    ok(($c->api('PUT', '/rules/stop_long', ['params' => ['minutes' => 60]])['status'] ?? 0) === 200, 'u qoidalarni o\'zgartira oladi');

    echo "== Umumiy namuna: har tashrifchiga alohida nusxa\n";
    $v1 = new Http($DEMO);
    $v2 = new Http($DEMO);
    $v1->get('/?bw_launch=' . launchToken(['cust' => ['id' => 1], 'user' => ['id' => 1, 'name' => 'Mehmon 1']]));
    $v2->get('/?bw_launch=' . launchToken(['cust' => ['id' => 2], 'user' => ['id' => 2, 'name' => 'Mehmon 2']]));
    $m1 = $v1->me();
    $m2 = $v2->me();
    ok(($m1['shared_demo'] ?? false) && ($m1['demo'] ?? false) && !($m1['can_admin'] ?? true), 'umumiy namuna: sozlash yopiq');
    $before = $m2['now'];
    $v1->api('POST', '/demo/advance', ['minutes' => 240]);
    $v1->api('POST', '/alerts/' . $one['id'] . '/close', ['note' => 'Mehmon yopdi']);
    ok($v1->me()['now'] === $before + 4 * 3600, '1-tashrifchi soatni 4 soatga surdi');
    ok($v2->me()['now'] === $before, "2-tashrifchining namunasi o'zgarmadi (alohida nusxa)");
    $st2 = $v2->api('GET', '/alerts/' . $one['id'])['json'];
    ok(($st2['status'] ?? '') !== 'closed', "1-tashrifchi yopgan ogohlantirish 2-tashrifchida ochiq qolgan");
    $r = (new Http($DEMO))->get('/?bw_launch=' . launchToken(['mode' => 'live', 'user' => ['id' => 5, 'tool_role' => 'admin']]));
    $x = new Http($DEMO);
    $x->get('/?bw_launch=' . launchToken(['mode' => 'live', 'user' => ['id' => 6, 'tool_role' => 'admin'], 'cust' => ['id' => 3]]));
    $x->me();
    ok(($x->api('PUT', '/rules/stop_long', ['params' => ['minutes' => 70]])['status'] ?? 0) === 403, 'umumiy namunada administrator ham qoidani o\'zgartira olmaydi');
    $files = glob($env('DEMO_DATA') . '/visitors/*.sqlite') ?: [];
    ok(count($files) >= 3, 'tashrifchilar nusxalari alohida fayllarda (' . count($files) . ')');
}

echo "== Majburiy litsenziya (litsenziyasiz ochilmaydi)\n";
$e = new Http($ENF);
$r = $e->get('/api/health');
ok($r['status'] === 200, 'health litsenziyasiz ham ochiq');
$login = $e->get('/login.php');
preg_match('/name="csrf" value="([a-f0-9]+)"/', $login['body'], $m);
$r = $e->req('POST', '/login.php', null, ['login' => $env('ADMIN_LOGIN'), 'password' => $env('ADMIN_PASS'), 'csrf' => $m[1] ?? '']);
ok($r['status'] === 303, 'litsenziyasiz ham parol bilan kirish mumkin (so\'ng litsenziya sahifasi ko\'rsatiladi)');
$me = $e->me();
$r = $e->get('/');
ok($r['status'] === 302 && str_contains((string)$r['location'], 'license.php'), "sahifa litsenziya sahifasiga yo'naltiradi");
$r = $e->api('GET', '/overview');
ok($r['status'] === 402 && ($r['json']['error']['code'] ?? '') === 'license_required', 'API 402 license_required qaytaradi');
ok(($e->get('/api/me')['status'] ?? 0) === 200, '/api/me litsenziyasiz ham javob beradi (sahifa o\'zi holatni bilishi uchun)');
ok($e->get('/license.php')['status'] === 200, 'litsenziya holati sahifasi ochiladi');

echo "\n" . ($fails ? "XATO: $fails / $total" : "Hammasi joyida: $total ta tekshiruv") . "\n";
exit($fails ? 1 : 0);
