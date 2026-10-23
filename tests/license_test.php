<?php
declare(strict_types=1);

/**
 * Litsenziya yadrosi va SDK testi (jarayon ichida, vaqt sakrashi bilan).
 *   php tests/license_test.php
 * Vaqtinchalik baza va kalitlar ishlatiladi.
 */
date_default_timezone_set('Asia/Tashkent');
$tmp = sys_get_temp_dir() . '/bw-test-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
putenv('BIWORK_DATA=' . $tmp . '/portal');
putenv('BIWORK_NOW=' . strtotime('2026-10-20 12:00:00'));
putenv('BIWORK_CONFIG=/nonexistent');

require __DIR__ . '/../portal/src/bootstrap.php';
require __DIR__ . '/../sdk/php/BiworkConnect.php';

$fails = 0;
$total = 0;
function ok(bool $cond, string $name): void
{
    global $fails, $total;
    $total++;
    if (!$cond) {
        $fails++;
        echo "  ✗ $name\n";
    } else {
        echo "  ✓ $name\n";
    }
}
function throws(callable $fn, string $code, string $name): void
{
    try {
        $fn();
        ok(false, "$name (xato kutilgan edi: $code)");
    } catch (LicenseError | BiworkException | HttpError $e) {
        $got = $e instanceof HttpError ? $e->errorCode : $e->errorCode;
        ok($got === $code, "$name → $code" . ($got === $code ? '' : " (olingan: $got — {$e->getMessage()})"));
    }
}
function at(int $ts): void
{
    putenv('BIWORK_NOW=' . $ts);
}
$T0 = strtotime('2026-10-20 12:00:00');
$DAY = 86400;

// SDK ni to'g'ridan-to'g'ri Licenses bilan bog'laymiz (server vaqti = BIWORK_NOW)
$http = static function (string $method, string $url, ?array $body) {
    $op = basename($url);
    try {
        if ($op === 'deactivate') {
            Licenses::deactivate($body['key'], $body['device_id']);
            return [200, ['ok' => true]];
        }
        $meta = ['device_name' => $body['device_name'] ?? '', 'platform' => $body['platform'] ?? '', 'app_version' => $body['app_version'] ?? ''];
        $r = $op === 'activate' ? Licenses::activate($body['key'], $body['device_id'], $meta, $body['tool'] ?? null)
                                : Licenses::check($body['key'], $body['device_id'], $meta, $body['tool'] ?? null);
        return [200, ['ok' => true, 'server_time' => Util::now()] + $r];
    } catch (LicenseError $e) {
        return [$e->status, ['ok' => false, 'error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]]];
    }
};
$keys = Tokens::publicKeys();
$mk = static function (string $name) use ($tmp, $http, $keys): BiworkConnect {
    $d = $tmp . '/' . $name;
    return new BiworkConnect([
        'server' => 'http://x', 'tool' => 'reja', 'data_dir' => $d, 'public_keys' => $keys, 'app_version' => '1.8', 'device_name' => $name,
        'http' => $http, 'now' => static fn() => Util::now(),
    ]);
};

echo "== Tayyorlash\n";
Catalog::registerManifest(json_decode((string)file_get_contents(__DIR__ . '/../integrations/reja/manifest.json'), true));
$tool = Catalog::tool('reja');
$uid = Accounts::register(['company' => 'Sinov MChJ', 'name' => 'Ega', 'email' => 'ega@sinov.uz', 'password' => 'Kuchli-Parol-1']);
$cust = (int)Db::value('SELECT customer_id FROM users WHERE id = ?', [$uid]);
ok($tool !== null && $cust > 0, 'vosita va korxona yaratildi');

echo "== Sinov litsenziyasi\n";
$trial = Licenses::startTrial($cust, (int)$tool['id'], 'user:' . $uid, $uid);
ok($trial['type'] === 'trial' && $trial['state'] === 'active', 'sinov berildi');
ok($trial['days_left'] === 30 || $trial['days_left'] === 31, 'sinov 30 kun (' . $trial['days_left'] . ')');
throws(fn() => Licenses::startTrial($cust, (int)$tool['id'], 'user:' . $uid, $uid), 'error', 'ikkinchi sinov rad etiladi');
throws(fn() => Licenses::activate($trial['license_key'], str_repeat('a', 32), [], 'reja'), 'trial_no_app', 'sinov litsenziyasi ilovani faollashtirmaydi');

echo "== Pullik litsenziya va faollashtirish\n";
$lic = Licenses::issueFromPlan($cust, (int)$tool['id'], 'standard', 2, 'system', null);
ok($lic['type'] === 'paid' && (int)$lic['seats'] === 2, "pullik litsenziya, 2 o'rindiq");
ok(preg_match('/^BW-REJ-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $lic['license_key']) === 1, 'kalit formati: ' . $lic['license_key']);
$A = $mk('A');
$st = $A->state();
ok($st['state'] === 'none' && !$st['allowed'], 'faollashtirishdan oldin: none');
$st = $A->activate(strtolower(str_replace('-', ' ', $lic['license_key'])));
ok($st['allowed'] && $st['state'] === 'valid', 'A faollashtirildi (kalit erkin yozilgan ko\'rinishda ham)');
ok($st['days_left'] >= 364 && $st['days_left'] <= 366, 'muddat ~365 kun (' . $st['days_left'] . ')');
ok(($st['license']['cust']['name'] ?? '') === 'Sinov MChJ' && ($st['license']['seats'] ?? 0) === 2, 'token ichida korxona va o\'rindiqlar');
$B = $mk('B');
ok($B->activate($lic['license_key'])['allowed'], 'B faollashtirildi (2-o\'rindiq)');
$C = $mk('C');
throws(fn() => $C->activate($lic['license_key']), 'seats_full', "3-qurilma: o'rindiqlar band");
ok($A->activate($lic['license_key'])['allowed'] && (int)Db::value("SELECT COUNT(*) FROM activations WHERE license_id = ? AND status = 'active'", [$lic['id']]) === 2, 'A qayta faollashtirsa o\'rindiq ikki marta sanalmaydi');
throws(fn() => $A->activate('BW-REJ-AAAA-BBBB-CCCC'), 'invalid_key', "noto'g'ri kalit");
throws(fn() => $A->activate('XYZ'), 'invalid_key', 'kalit formati yaroqsiz');
Licenses::releaseActivation((int)Db::value("SELECT id FROM activations WHERE device_name = 'B'"), 'system');
throws(fn() => $B->refresh(), 'not_activated', "bo'shatilgan qurilma tekshiruvdan o'tmaydi");
ok($B->state()['state'] === 'blocked', 'B darhol bloklandi');
ok($C->activate($lic['license_key'])['allowed'], "C bo'shagan o'rindiqqa faollashtirildi");

echo "== Oflayn tekshiruv va imtiyoz\n";
at($T0 + 10 * $DAY);
$s = $A->state();
ok($s['state'] === 'valid' && !$s['needs_refresh'] || $s['state'] === 'valid', '+10 kun: faol (internetsiz)');
at($T0 + 14 * $DAY + 3600);
$s = $A->state();
ok($s['state'] === 'grace' && $s['allowed'], '+14 kun: imtiyoz davri, ishlayveradi (' . $s['state'] . ': ' . $s['message'] . ')');
at($T0 + 22 * $DAY);
$s = $A->state();
ok($s['state'] === 'locked' && !$s['allowed'], '+22 kun internetsiz: bloklandi');
$s = $A->ensure();
ok($s['state'] === 'valid' && $s['allowed'], 'internet bor: ensure() yangilab, qayta ochdi');
$s = $A->state();
ok($s['days_left'] >= 342 && $s['days_left'] <= 344, 'yangi token: muddat o\'zgarmadi (' . $s['days_left'] . ')');

echo "== To'xtatish / bekor qilish / uzaytirish\n";
Licenses::setStatus((int)$lic['id'], 'suspended', 'system', 'sinov');
at($T0 + 22 * $DAY + 20 * 3600);
throws(fn() => $A->refresh(), 'suspended', "to'xtatilgan litsenziya yangilanmaydi");
ok($A->state()['state'] === 'blocked' && !$A->state()['allowed'], 'ilova darhol bloklandi');
$s = $A->ensure();
ok($s['state'] === 'blocked', "to'xtatilgan: ensure() 15 daqiqa ichida qayta so'ramaydi");
Licenses::setStatus((int)$lic['id'], 'active', 'system');
$s = $A->ensure();
ok($s['state'] === 'blocked', 'qayta yoqilgan, lekin 15 daqiqa o\'tmagan: hali bloklangan');
at(Util::now() + 16 * 60);
$s = $A->ensure();
ok($s['allowed'] && $s['state'] === 'valid', "15 daqiqadan keyin ensure() tiklangan litsenziyani o'zi ochadi");
Licenses::setStatus((int)$lic['id'], 'suspended', 'system', 'sinov');
throws(fn() => $A->refresh(), 'suspended', "yana to'xtatildi");
Licenses::setStatus((int)$lic['id'], 'active', 'system');
$s = $A->activate($lic['license_key']);
ok($s['allowed'], 'qayta yoqilgach kalit bilan ham qayta ochiladi');
Licenses::setStatus((int)$lic['id'], 'revoked', 'system');
throws(fn() => $A->refresh(), 'revoked', 'bekor qilingan');
Licenses::setStatus((int)$lic['id'], 'active', 'system');
$A->activate($lic['license_key']);
Licenses::setUntil((int)$lic['id'], date('Y-m-d', Util::now() + 400 * $DAY), 'system');
$s = $A->refresh();
ok($s['days_left'] >= 399 && $s['days_left'] <= 401, 'muddat uzaytirildi: yangi token ' . $s['days_left'] . ' kun');

echo "== Muddat tugashi va soat orqaga surilishi\n";
Licenses::setUntil((int)$lic['id'], date('Y-m-d', Util::now() + 30 * $DAY), 'system');
$A->refresh();
at(Util::now() + 40 * $DAY);
$s = $A->state();
ok($s['state'] === 'expired' && !$s['allowed'], 'muddat tugagach: expired');
throws(fn() => $A->refresh(), 'expired', 'server ham expired deydi');
at($T0 + 100 * $DAY);
$A2 = $mk('A');
Licenses::setUntil((int)$lic['id'], date('Y-m-d', Util::now() + 200 * $DAY), 'system');
$A2->activate($lic['license_key']);
at($T0 + 100 * $DAY - 5 * $DAY);
ok($A2->state()['state'] === 'clock', 'soat 5 kun orqaga surilsa: clock');
at($T0 + 100 * $DAY - 3600);
ok($A2->state()['allowed'], "1 soatlik farq (soat sozlamasi) kechiriladi");

echo "== Boshqa vosita / buzilgan token\n";
at($T0 + 100 * $DAY);
throws(fn() => Licenses::activate($lic['license_key'], str_repeat('b', 32), [], 'xyz'), 'tool_mismatch', 'boshqa vosita kodi');
$f = $tmp . '/A/biwork-license.json';
$j = json_decode((string)file_get_contents($f), true);
$j['token'] = substr($j['token'], 0, -4) . 'AAAA';
file_put_contents($f, json_encode($j));
ok($A2->state()['state'] === 'invalid', 'imzosi buzilgan token rad etiladi');
$pay = explode('.', $j['token']);
$forged = json_decode(base64_decode(strtr($pay[1], '-_', '+/')), true);
$forged['exp'] = null;
$pay[1] = rtrim(strtr(base64_encode(json_encode($forged)), '+/', '-_'), '=');
$j['token'] = implode('.', $pay);
file_put_contents($f, json_encode($j));
ok($A2->state()['state'] === 'invalid', 'payload o\'zgartirilsa (muddatni olib tashlash) rad etiladi');

echo "== 'Ochish' tokeni\n";
$user = Auth::row($uid);
$lic2 = Licenses::best($cust, (int)$tool['id']);
$url = Launch::url($user, $lic2, array_merge($tool, ['web_url' => 'http://localhost:8080/']));
ok(str_starts_with($url, 'http://localhost:8080/?bw_launch='), 'Ochish manzili');
parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
$sdk = $mk('launch');
$claims = $sdk->verifyLaunch($q['bw_launch']);
ok($claims['user']['email'] === 'ega@sinov.uz' && $claims['cust']['name'] === 'Sinov MChJ' && $claims['aud'] === 'reja', 'token tarkibi: foydalanuvchi, korxona, auditoriya');
ok(!isset($claims['lic']['key']) && !str_contains($q['bw_launch'], 'BW-REJ'), 'litsenziya kaliti tokenga kirmagan');
throws(fn() => $sdk->verifyLaunch($q['bw_launch']), 'replay', 'token ikkinchi marta ishlamaydi');
$url2 = Launch::url($user, $lic2, array_merge($tool, ['web_url' => 'http://localhost:8080/']));
parse_str((string)parse_url($url2, PHP_URL_QUERY), $q2);
$other = new BiworkConnect(['server' => 'http://x', 'tool' => 'boshqa', 'data_dir' => $tmp . '/o', 'public_keys' => $keys]);
throws(fn() => $other->verifyLaunch($q2['bw_launch']), 'wrong_audience', 'boshqa vosita tokenni qabul qilmaydi');
at(Util::now() + 300);
throws(fn() => $sdk->verifyLaunch($q2['bw_launch']), 'expired', '2 daqiqadan keyin token eskiradi');
at($T0 + 100 * $DAY);
$licTok = Licenses::buildToken(Licenses::byKey($lic['license_key']), str_repeat('c', 32));
throws(fn() => $sdk->verifyLaunch($licTok), 'bad_token', "litsenziya tokeni 'Ochish' o'rnida o'tmaydi (tur ajratilgan)");
$launchTok = Tokens::sign(Tokens::LAUNCH, ['aud' => 'reja', 'jti' => 'x', 'iat' => Util::now(), 'exp' => Util::now() + 60]);
throws(fn() => BiworkConnect::verifyToken('bwk1', $launchTok, $keys), 'bad_token', "'Ochish' tokeni litsenziya o'rnida o'tmaydi");
Tokens::generate();
$newKeys = Tokens::publicKeys();
ok(count($newKeys) === 2, 'kalit almashtirildi, eski ochiq kalit saqlanadi');
ok(Tokens::verify(Tokens::LICENSE, $licTok) !== null, 'eski kalit bilan imzolangan token hamon tekshiriladi');

echo "== Ochiq kalit ro'yxati\n";
ok(count(array_filter($newKeys, fn($k) => $k['current'])) === 1, 'aniq bitta joriy kalit');

echo "\n" . ($fails === 0 ? "HAMMASI O'TDI ($total)" : "$fails TA XATO ($total dan)") . "\n";
exec('rm -rf ' . escapeshellarg($tmp));
exit($fails === 0 ? 0 : 1);
