<?php
declare(strict_types=1);

/**
 * Biwork Portal — buyruq qatori (faqat serverda, veb orqali ochilmaydi).
 *   php tools/cli.php staff:add <email> <ism> [parol]       Biwork xodimi (admin) yaratish
 *   php tools/cli.php tool:register <manifest.json>         Mahsulotni katalogga qo'shish/yangilash
 *   php tools/cli.php release:add <vosita> <versiya> <tur> <fayl> [izoh]   (tur: server|windows|source)
 *   php tools/cli.php customer:add <nomi> <email> <ism> [parol]
 *   php tools/cli.php license:issue <korxona-id> <vosita> <tarif> [YYYY-MM-DD] [o'rindiqlar]
 *   php tools/cli.php keys:show | keys:rotate               Imzolash kalitlari
 *   php tools/cli.php seed:demo                             Reja vositasi + namuna korxona (ishlab chiqish uchun)
 * Muhit: BIWORK_DATA=/yo'l/data  (standart: portal/data)
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../portal/src/bootstrap.php';

$argv = $_SERVER['argv'];
array_shift($argv);
$cmd = array_shift($argv) ?? '';

function out(string $s): void
{
    fwrite(STDOUT, $s . "\n");
}
function fail(string $s): never
{
    fwrite(STDERR, "XATO: $s\n");
    exit(1);
}
function randPassword(): string
{
    $a = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $p = '';
    for ($i = 0; $i < 14; $i++) {
        $p .= $a[random_int(0, strlen($a) - 1)];
    }
    return $p;
}
function toolBySlug(string $slug): array
{
    return Db::one('SELECT * FROM tools WHERE slug = ?', [$slug]) ?? fail("Mahsulot topilmadi: $slug");
}

try {
    switch ($cmd) {
        case 'staff:add':
            [$email, $name] = [$argv[0] ?? fail('email kerak'), $argv[1] ?? fail('ism kerak')];
            $pw = $argv[2] ?? randPassword();
            Accounts::addStaff($email, $name, $pw);
            out("Xodim yaratildi: $email");
            if (!isset($argv[2])) {
                out("Parol: $pw");
            }
            break;

        case 'tool:register':
            $f = $argv[0] ?? fail('manifest.json yo\'li kerak');
            $m = json_decode((string)@file_get_contents($f), true);
            is_array($m) || fail("Manifest o'qilmadi yoki JSON noto'g'ri: $f");
            $id = Catalog::registerManifest($m);
            out("Mahsulot ro'yxatga olindi: {$m['name']} (id $id)");
            break;

        case 'release:add':
            [$slug, $ver, $kind, $file] = [$argv[0] ?? fail('vosita kerak'), $argv[1] ?? fail('versiya kerak'), $argv[2] ?? fail('tur kerak'), $argv[3] ?? fail('fayl kerak')];
            $t = toolBySlug($slug);
            $id = Catalog::addRelease((int)$t['id'], $ver, $kind, $file, basename($file), $argv[4] ?? '');
            $r = Catalog::release($id);
            out("Reliz qo'shildi: {$slug} v{$ver} ({$r['kind']}), " . Util::bytes((int)$r['size']) . ", sha256 {$r['sha256']}");
            break;

        case 'customer:add':
            [$company, $email, $name] = [$argv[0] ?? fail('nomi kerak'), $argv[1] ?? fail('email kerak'), $argv[2] ?? fail('ism kerak')];
            $pw = $argv[3] ?? randPassword();
            $uid = Accounts::register(['company' => $company, 'name' => $name, 'email' => $email, 'password' => $pw], 'staff');
            Db::update('users', $uid, ['must_change_password' => 1]);
            out('Korxona yaratildi, id ' . Db::value('SELECT customer_id FROM users WHERE id = ?', [$uid]) . ". Login: $email");
            if (!isset($argv[3])) {
                out("Vaqtinchalik parol: $pw");
            }
            break;

        case 'license:issue':
            [$cid, $slug, $plan] = [(int)($argv[0] ?? fail('korxona-id kerak')), $argv[1] ?? fail('vosita kerak'), $argv[2] ?? fail('tarif kerak')];
            $t = toolBySlug($slug);
            $l = Licenses::issueFromPlan($cid, (int)$t['id'], $plan, isset($argv[4]) ? (int)$argv[4] : null, 'system', null, 'CLI', $argv[3] ?? null);
            out("Litsenziya: {$l['license_key']} (id {$l['id']}), tugashi: " . ($l['valid_until'] ? Util::date((int)$l['valid_until']) : 'muddatsiz') . ", o'rindiq: {$l['seats']}");
            break;

        case 'keys:show':
            foreach (Tokens::publicKeys() as $k) {
                out(($k['current'] ? '* ' : '  ') . $k['kid'] . '  ' . $k['alg'] . '  ' . $k['public']);
            }
            break;

        case 'keys:rotate':
            $kid = Tokens::generate();
            out("Yangi imzolash kaliti: $kid. Eski ochiq kalitlar saqlanadi (eski tokenlar tekshirilaveradi).");
            out("Vositalar yangi ochiq kalitni /api/v1/pubkey dan oladi yoki paketga qayta joylang.");
            break;

        case 'seed:demo':
            $id = Catalog::registerManifest(json_decode((string)file_get_contents(__DIR__ . '/../integrations/reja/manifest.json'), true));
            out("Mahsulot: Biwork Reja (id $id)");
            $zip = realpath(__DIR__ . '/../../ngd-panel-dist/ngd-panel-hosting.zip');
            if ($zip && !Db::value('SELECT 1 FROM tool_releases WHERE tool_id = ?', [$id])) {
                Catalog::addRelease($id, '1.8', 'server', $zip, 'ngd-panel-hosting.zip', 'Hosting uchun server paketi (PHP, Python shart emas)');
                out('Reliz: v1.8 server paketi');
            }
            if (!Db::value("SELECT 1 FROM customers WHERE name = 'Namuna korxona'")) {
                $pw = randPassword();
                $uid = Accounts::register(['company' => 'Namuna korxona', 'name' => 'Namuna Egasi', 'email' => 'demo@namuna.uz', 'password' => $pw, 'city' => 'Toshkent'], 'staff');
                out("Namuna korxona: demo@namuna.uz / $pw");
            }
            break;

        default:
            out(trim(substr((string)file_get_contents(__FILE__), strpos((string)file_get_contents(__FILE__), '/**') + 4, 1100)));
            exit($cmd === '' || $cmd === 'help' ? 0 : 1);
    }
} catch (HttpError $e) {
    fail($e->getMessage());
}
