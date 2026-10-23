<?php
declare(strict_types=1);

/**
 * Demo versiyalari sinovi (admin «Demo versiyalari»): manzil (URL) va zip paket versiyalari, joriy qilish, avvalgisiga qaytarish,
 * zip tekshiruvi (zip-slip, symlink, ildizda index yo'q, public/ tuzilma), huquqlar, yuklash chegarasi; ilova relizlarida
 * «Oxirgi qilish» (qaytarish), izoh, «Oxirgi»ni yashirganda avvalgisi o'rniga o'tishi.
 * Paket demosini veb-server (Apache/nginx) public/ dan bevosita beradi: sinov shuni routersiz php -S bilan takrorlaydi (DEMO_PORT).
 * Ishga tushirish: bash tests/run.sh   (BASE, STAFF_EMAIL, STAFF_PASS, DEMO_PORT; Db uchun BIWORK_DATA/BIWORK_CONFIG)
 */
require __DIR__ . '/../portal/src/bootstrap.php';

$BASE = rtrim(getenv('BASE') ?: 'http://127.0.0.1:8099', '/');
$STAFF = [getenv('STAFF_EMAIL') ?: 'admin@biwork.test', getenv('STAFF_PASS') ?: 'Admin-Parol-2026'];
$DEMO_PORT = (int)(getenv('DEMO_PORT') ?: 8086);
$DIRECT = 'http://127.0.0.1:' . $DEMO_PORT;
$fails = 0;
$total = 0;
require __DIR__ . '/helpers.php';

$work = sys_get_temp_dir() . '/bwdemo-' . bin2hex(random_bytes(4));
mkdir($work, 0700, true);

/** Zip yasaydi: $files — nom => matn; $links — nom => nishon (symlink sifatida belgilanadi). */
function mkzip(string $path, array $files, array $links = []): string
{
    $z = new ZipArchive();
    $z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $n => $c) {
        str_ends_with((string)$n, '/') ? $z->addEmptyDir(rtrim((string)$n, '/')) : $z->addFromString((string)$n, (string)$c);
    }
    foreach ($links as $n => $target) {
        $z->addFromString($n, $target);
        $z->setExternalAttributesName($n, ZipArchive::OPSYS_UNIX, (0120777 << 16));
    }
    $z->close();
    return $path;
}

// public/ ni bevosita beradigan server (Apache/nginx o'rnida): /demo/<slug>/index.php shu yerda bajariladi
$srv = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $DEMO_PORT, '-t', realpath(__DIR__ . '/../portal/public')], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
$slug = '';
register_shutdown_function(static function () use (&$srv, &$slug, $work): void {
    if (is_resource($srv)) {
        proc_terminate($srv);
    }
    // sinov joylashtirgan demo papkasi manba daraxtida qolmasin
    if ($slug !== '') {
        foreach (['', '.stage-', '.old-'] as $pre) {
            foreach (glob(BW_ROOT . '/public/demo/' . $pre . $slug . '*') ?: [] as $d) {
                for ($i = 0; $i < 10 && file_exists($d); $i++) { // tarmoq/FUSE disklarida o'chirish ba'zan birinchi urinishda o'tmaydi
                    exec('rm -rf ' . escapeshellarg($d) . ' 2>/dev/null');
                    file_exists($d) && usleep(300000);
                }
            }
        }
    }
    exec('rm -rf ' . escapeshellarg($work));
});
$direct = new Browser($DIRECT);
for ($i = 0; $i < 50 && $direct->get('/assets/admin-demos.css')['status'] !== 200; $i++) {
    usleep(100000);
}

$staff = new Browser($BASE);
$r = $staff->post('/login', '/login', ['email' => $STAFF[0], 'password' => $STAFF[1]]);
ok($r['status'] === 303, 'xodim kirdi');
$anon = new Browser($BASE);
$text = static fn(array $r): string => html_entity_decode($r['body'], ENT_QUOTES | ENT_HTML5);
/** Admin formasini yuboradi va yo'naltirishdan keyingi sahifani (flash xabari bilan) qaytaradi. */
$send = static function (string $path, array $fields, array $files = []) use ($staff): array {
    $fields['csrf'] = $staff->csrf('/admin/tools');
    $r = $staff->req('POST', $path, $fields + $files, ['multipart' => true]);
    $r['page'] = $r['status'] === 303 ? $staff->follow($r) : $r;
    return $r;
};
$flash = static fn(array $r): string => html_entity_decode((string)(preg_match_all('#<div class="flash (?:ok|err|info)"[^>]*>(.*?)</div>#s', $r['page']['body'], $m) ? strip_tags(implode(' | ', $m[1])) : ''), ENT_QUOTES | ENT_HTML5);
$count = static fn(int $tid): int => (int)Db::value('SELECT COUNT(*) FROM tool_demos WHERE tool_id = ?', [$tid]);
$toolRow = static fn(int $tid): array => Db::one('SELECT * FROM tools WHERE id = ?', [$tid]) ?? [];
$demoId = static fn(int $tid, string $v): int => (int)Db::value('SELECT id FROM tool_demos WHERE tool_id = ? AND version = ?', [$tid, $v]);

echo "== Tayyorgarlik: sinov mahsuloti va mijoz\n";
$name = 'Demo Sinov ' . substr(bin2hex(random_bytes(3)), 0, 5);
$r = $staff->req('POST', '/admin/tools/new', ['csrf' => $staff->csrf('/admin/tools'), 'name' => $name]);
preg_match('#^/admin/tools/(\d+)/edit$#', (string)$r['location'], $m);
$tid = (int)($m[1] ?? 0);
$slug = (string)($toolRow($tid)['slug'] ?? '');
ok($tid > 0 && $slug !== '', "mahsulot yaratildi: /{$slug}");
$r = $staff->req('POST', '/admin/tools/' . $tid . '/status', ['csrf' => $staff->csrf('/admin/tools'), 'do' => 'publish']);
Db::update('tools', $tid, ['trial_enabled' => 1]); // yangi mahsulot formasi sinovni o'chiq saqlaydi
ok(($toolRow($tid)['status'] ?? '') === 'active' && (int)$toolRow($tid)['trial_enabled'] === 1, "e'lon qilindi, sinov yoqiq");
$cEmail = 'demo-' . bin2hex(random_bytes(3)) . '@sinov.uz';
Accounts::register(['company' => 'Demo mijoz', 'name' => 'Mijoz', 'email' => $cEmail, 'password' => 'Mijoz-Parol-2026'], 'signup');
$cust = new Browser($BASE);
$r = $cust->post('/login', '/login', ['email' => $cEmail, 'password' => 'Mijoz-Parol-2026']);
ok($r['status'] === 303, 'mijoz kirdi');
$r = $cust->post('/app/tools/' . $slug, '/app/tools/' . $slug . '/trial');
ok(Db::value("SELECT COUNT(*) FROM licenses l JOIN users u ON u.customer_id = l.customer_id WHERE u.email = ? AND l.tool_id = ? AND l.type = 'trial'", [$cEmail, $tid]) == 1, 'mijoz sinovni boshladi');
$open = static fn(): string => (string)($cust->get('/app/open/' . $slug)['location'] ?? '');
ok(!str_contains($open(), 'bw_launch'), "versiya yo'q va manzil bo'sh: «Ochish» yo'naltirmaydi");

echo "== Kirish huquqi\n";
ok(in_array($anon->get('/admin/tools?demo=' . $tid)['status'], [302, 303], true), 'kirishsiz: admin sahifasi ochilmaydi');
$r = $anon->req('POST', '/admin/tools/' . $tid . '/demos', ['version' => '9.9', 'kind' => 'url', 'url' => 'https://x.example.uz/']);
ok($r['status'] >= 400 && $count($tid) === 0, 'kirishsiz POST versiya qo\'shmaydi: ' . $r['status']);
$r = $cust->req('POST', '/admin/tools/' . $tid . '/demos', ['csrf' => $cust->csrf('/app/profile'), 'version' => '9.9', 'kind' => 'url', 'url' => 'https://x.example.uz/']);
ok($r['status'] === 403 && $count($tid) === 0, 'mijoz (xodim emas) versiya qo\'sha olmaydi: ' . $r['status']);
$r = $staff->req('POST', '/admin/tools/' . $tid . '/demos', ['version' => '9.9', 'kind' => 'url', 'url' => 'https://x.example.uz/']);
ok($r['status'] === 419 && $count($tid) === 0, 'CSRF belgisiz yuborish rad etiladi: ' . $r['status']);

echo "== Manzil (URL) versiyalari: qo'shish, joriy qilish, qaytarish\n";
$u1 = 'https://demo1.example.uz/';
$u2 = 'https://demo2.example.uz/panel/#/boshla';
$r = $send('/admin/tools/' . $tid . '/demos', ['version' => '1.0', 'kind' => 'url', 'url' => $u1, 'notes' => "Birinchi demo\nikki qator", 'activate' => '1']);
ok($r['status'] === 303 && str_starts_with((string)$r['location'], '/admin/tools?demo=' . $tid), "saqlandi, kartaga qaytdi: " . $r['location']);
ok(str_contains($flash($r), 'joriy qilindi'), 'xabar: qo\'shildi va joriy qilindi');
$t = $toolRow($tid);
ok((int)$t['demo_id'] === $demoId($tid, '1.0') && $t['web_url'] === $u1, 'tools.demo_id va web_url yangilandi');
ok((Catalog::tool($slug)['web_url'] ?? '') === $u1, 'sayt (Catalog) yangi manzilni ko\'radi');
$cur = Demos::current($tid);
ok(($cur['version'] ?? '') === '1.0' && $cur['url'] === $u1 && $cur['kind'] === 'url' && $cur['notes'] === "Birinchi demo\nikki qator", 'Demos::current: versiya, manzil, tur, izoh');
ok(Demos::label($cur) === 'Demo versiyasi 1.0 · ' . date('d.m.Y', (int)$cur['created_at']), 'Demos::label: ' . Demos::label($cur));
$page = $text($r['page']);
ok(str_contains($page, 'Demo versiyalari: 1 ta · joriy: v1.0') && str_contains($page, 'Joriy') && str_contains($page, 'value="1.1"'), "admin kartasi: soni, joriy belgisi, keyingi raqam taklifi (1.1)");
ok(str_starts_with($open(), $u1 . '?bw_launch='), "«Ochish» yangi manzilga yo'naltiradi (token bilan)");
ok(str_contains($anon->get('/' . $slug)['body'], 'Sinab ko'), "mahsulot sahifasi ochiq va «Sinab ko'rish» bor");

$r = $send('/admin/tools/' . $tid . '/demos', ['version' => '1.1', 'kind' => 'url', 'url' => $u2, 'notes' => 'Yangi oyna', 'activate' => '1']);
ok($toolRow($tid)['web_url'] === $u2 && (Catalog::tool($slug)['web_url'] ?? '') === $u2, 'v1.1 joriy: web_url yangilandi');
$loc = $open();
ok(str_starts_with($loc, 'https://demo2.example.uz/panel/?bw_launch=') && str_ends_with($loc, '#/boshla'), "«Ochish»: token # dan oldin qo'yiladi");
ok(str_contains($text($r['page']), 'Shu versiyaga qaytarish'), "eski versiyada «Shu versiyaga qaytarish» tugmasi");
$r = $send('/admin/demos/' . $demoId($tid, '1.0') . '/activate', []);
ok(str_contains($flash($r), 'Avvalgi versiyaga qaytarildi') && $toolRow($tid)['web_url'] === $u1 && str_starts_with($open(), $u1 . '?bw_launch='), 'v1.0 ga qaytarildi: sayt va «Ochish» eski manzilda');
ok((Demos::current($tid)['version'] ?? '') === '1.0', 'joriy: v1.0');

// mahsulot formasidagi manzil versiyalar yoqilganda ustun emas
Db::update('tools', $tid, ['web_url' => 'https://qolda.example.uz/']);
ok((Catalog::tool($slug)['web_url'] ?? '') === $u1, "formadagi (qo'lda) manzil joriy versiyani almashtirmaydi");
ok(str_contains($text($staff->get('/admin/tools?demo=' . $tid)), 'hisobga olinmaydi'), "admin kartasida bu haqda yozilgan");
Db::update('tools', $tid, ['web_url' => $u1]);

echo "== Noto'g'ri qiymatlar rad etiladi\n";
$before = $count($tid);
foreach ([
    ['1 0', 'url', $u1, '', 'Versiya raqami'], ['', 'url', $u1, '', 'Versiya raqami'], [str_repeat('9', 21), 'url', $u1, '', 'Versiya raqami'], ['../2', 'url', $u1, '', 'Versiya raqami'],
    ['1.0', 'url', 'https://boshqa.example.uz/', '', 'allaqachon bor'], ['3.0', 'url', 'ftp://demo.example.uz/', '', 'http://'], ['3.0', 'url', 'javascript:alert(1)', '', 'http://'],
    ['3.0', 'url', '/ichki/yol', '', 'http://'], ['3.0', 'url', '', '', 'manzilini yozing'], ['3.0', 'url', $u1, str_repeat('ж', 501), '500 belgi'], ['3.0', 'boshqa', $u1, '', 'turini tanlang'],
    ['3.0', 'package', '', '', 'Zip faylni tanlang'],
] as [$v, $k, $u, $n, $want]) {
    $r = $send('/admin/tools/' . $tid . '/demos', ['version' => $v, 'kind' => $k, 'url' => $u, 'notes' => $n, 'activate' => '1']);
    ok(str_contains($flash($r), $want) && $count($tid) === $before, "rad etildi (" . mb_substr($v, 0, 12) . " / $k / " . mb_substr($u, 0, 30) . "): " . mb_substr($flash($r), 0, 90));
}
$r = $send('/admin/tools/' . $tid . '/demos', ['version' => '3.0', 'kind' => 'url', 'url' => 'ftp://saqlansin.example.uz/', 'notes' => 'qayta yozilmasin']);
ok(str_contains($r['page']['body'], 'value="ftp://saqlansin.example.uz/"') && str_contains($r['page']['body'], 'qayta yozilmasin'), "xatodan keyin kiritilgan qiymatlar formada qoladi");
$r = $send('/admin/demos/' . $demoId($tid, '1.0') . '/delete', []);
ok(str_contains($flash($r), "Joriy versiyani o'chirib bo'lmaydi") && $demoId($tid, '1.0') > 0, "joriy versiyani o'chirib bo'lmaydi");

echo "== Paket (zip): joylashtirish, almashtirish, qaytarish\n";
$deploy = BW_ROOT . '/public/demo/' . $slug;
$z20 = mkzip("$work/v20.zip", [
    'reja-demo/index.php' => '<?php echo "DEMO-V2|" . (ini_get("open_basedir") !== "" ? "cheklangan" : "cheklanmagan");',
    'reja-demo/assets/app.css' => 'body{color:#123}', 'reja-demo/only-v2.txt' => 'v2', 'reja-demo/data/' => '', 'reja-demo/data/info.txt' => 'namuna',
    '__MACOSX/reja-demo/._index.php' => 'keraksiz',
]);
$r = $send('/admin/tools/' . $tid . '/demos', ['version' => '2.0', 'kind' => 'package', 'notes' => 'Paket', 'activate' => '1'], ['file' => new CURLFile($z20, 'application/zip', 'reja-demo.zip')]);
$d20 = Db::one('SELECT * FROM tool_demos WHERE tool_id = ? AND version = ?', [$tid, '2.0']) ?? [];
ok(str_contains($flash($r), 'joriy qilindi') && ($d20['kind'] ?? '') === 'package', 'zip versiya saqlandi va joriy qilindi: ' . mb_substr($flash($r), 0, 100));
ok(($d20['file'] ?? '') === "demos/$tid/{$d20['id']}.zip" && is_file(Config::dataDir() . '/' . $d20['file']) && $d20['sha256'] === hash_file('sha256', $z20) && (int)$d20['size'] === filesize($z20), 'zip data/demos ga saqlandi (sha256, hajm)');
ok(is_file("$deploy/index.php") && is_file("$deploy/data/info.txt") && !is_dir("$deploy/reja-demo") && !is_dir("$deploy/__MACOSX"), "public/demo/{$slug}/ ga ochildi: umumiy papka tekislandi, __MACOSX tashlandi");
$ini = (string)@file_get_contents("$deploy/.user.ini");
ok(str_contains($ini, 'open_basedir') && str_contains($ini, realpath($deploy) . DIRECTORY_SEPARATOR), '.user.ini yozildi: open_basedir = demo papkasi');
ok(is_file(BW_ROOT . '/public/demo/.htaccess') && str_contains((string)file_get_contents(BW_ROOT . '/public/demo/.htaccess'), 'RewriteEngine On'), 'public/demo/.htaccess bor (Apache: portal qoidalari demoga tegmaydi)');
ok(!glob(BW_ROOT . '/public/demo/.stage-' . $slug . '*') && !glob(BW_ROOT . '/public/demo/.old-' . $slug . '*'), 'vaqtinchalik papkalar qolmadi');
$pkgUrl = '/demo/' . $slug . '/';
ok($toolRow($tid)['web_url'] === $pkgUrl && (Catalog::tool($slug)['web_url'] ?? '') === $pkgUrl && (Demos::current($tid)['url'] ?? '') === $pkgUrl, "web_url = {$pkgUrl}");
$g = $direct->get($pkgUrl);
ok($g['status'] === 200 && str_starts_with($g['body'], 'DEMO-V2'), "veb-server /demo/{$slug}/ ni beradi (200, index.php bajarildi): " . substr($g['body'], 0, 40));
ok($direct->get($pkgUrl . 'assets/app.css')['status'] === 200 && $direct->get($pkgUrl . 'only-v2.txt')['body'] === 'v2', "paket fayllari /demo/{$slug}/ ostida ochiladi");
ok($anon->get($pkgUrl . '.user.ini')['status'] === 403, '.user.ini veb orqali berilmaydi (403)');
$pg = $anon->get($pkgUrl);
ok($pg['status'] === 200 && str_starts_with($pg['body'], 'DEMO-V2'), "portal serveri (php -S + router.php, kompyuterdagi nusxa kabi) ham /demo/{$slug}/ ni beradi: " . $pg['status']);
$pg2 = $anon->get('/demo/' . $slug);
ok(in_array($pg2['status'], [200, 301, 302, 303, 307], true) && ($pg2['status'] === 200 || str_contains((string)$pg2['location'], '/demo/' . $slug . '/')), "slash'siz /demo/{$slug} ham ishlaydi yoki papka manziliga o'tkazadi: " . $pg2['status']);
ok($anon->get('/demo/yoq-demo-papka/')['status'] !== 200, "papkasi yo'q demo manzili 200 bermaydi");
$loc = $open();
ok(str_starts_with($loc, $pkgUrl . '?bw_launch='), "«Ochish» portal ichidagi demoga yo'naltiradi: " . substr($loc, 0, 40));
$g = $direct->get($loc);
ok($g['status'] === 200 && str_starts_with($g['body'], 'DEMO-V2'), "token bilan ochilgan manzil ishlaydi");
$r = $send('/admin/tools/' . $tid . '/edit', ['name' => $name, 'web_url' => $pkgUrl, 'trial_enabled' => '1', 'trial_days' => '30', 'do' => 'save']);
ok($r['status'] === 303 && $toolRow($tid)['web_url'] === $pkgUrl && (Catalog::tool($slug)['web_url'] ?? '') === $pkgUrl, "mahsulot formasi ichki /demo/{$slug}/ manzilini qabul qiladi (ToolEditor::webUrl)");

$z21 = mkzip("$work/v21.zip", ['index.html' => '<!doctype html><title>v2.1</title>DEMO-V21', 'assets/app.css' => 'body{}']);
$r = $send('/admin/tools/' . $tid . '/demos', ['version' => '2.1', 'kind' => 'package', 'activate' => '1'], ['file' => new CURLFile($z21, 'application/zip', 'v21.zip')]);
$g = $direct->get($pkgUrl);
ok(str_contains($flash($r), 'joriy qilindi') && $g['status'] === 200 && str_contains($g['body'], 'DEMO-V21'), 'v2.1 (index.html) joriy: yangi kontent');
ok($direct->get($pkgUrl . 'only-v2.txt')['status'] === 404 && !is_file("$deploy/index.php"), "eski versiya fayllari qolmadi (papka to'liq almashdi)");
$r = $send('/admin/demos/' . $demoId($tid, '2.0') . '/activate', []);
$g = $direct->get($pkgUrl);
ok(str_contains($flash($r), 'Avvalgi versiyaga qaytarildi') && str_starts_with($g['body'], 'DEMO-V2') && $direct->get($pkgUrl . 'only-v2.txt')['body'] === 'v2' && !is_file("$deploy/index.html"), "v2.0 ga qaytarildi: eski kontent zipdan qayta ochildi");
file_put_contents("$deploy/only-v2.txt", 'buzildi');
$r = $send('/admin/demos/' . $demoId($tid, '2.0') . '/activate', []);
ok(str_contains($flash($r), 'qaytadan joylashtirildi') && $direct->get($pkgUrl . 'only-v2.txt')['body'] === 'v2', "«Qayta joylashtirish»: demo boshlang'ich holatiga qaytdi");

exec('rm -rf ' . escapeshellarg($deploy));
ok((Catalog::tool($slug)['web_url'] ?? 'x') === '' && Demos::current($tid) === null, "paket papkasi yo'qolsa: sayt demoni ko'rsatmaydi (web_url bo'sh, current null)");
$h = $text($staff->get('/admin/tools?demo=' . $tid));
ok(str_contains($h, 'Joriy paket serverda ochilmagan') && str_contains($h, 'Qayta joylashtirish'), "admin kartasida ogohlantirish va «Qayta joylashtirish»");
$send('/admin/demos/' . $demoId($tid, '2.0') . '/activate', []);
ok(is_file("$deploy/index.php") && (Catalog::tool($slug)['web_url'] ?? '') === $pkgUrl, 'qayta joylashtirildi');

$r = $send('/admin/demos/' . $demoId($tid, '1.1') . '/activate', []);
ok($toolRow($tid)['web_url'] === $u2 && !is_dir($deploy) && $direct->get($pkgUrl . 'only-v2.txt')['status'] === 404, "manzil (URL) versiyasiga o'tilganda ochilgan paket olib tashlandi");
$zipFile = Config::dataDir() . '/' . $d20['file'];
$r = $send('/admin/demos/' . $d20['id'] . '/delete', []);
ok(str_contains($flash($r), "o'chirildi") && !is_file($zipFile) && $demoId($tid, '2.0') === 0, "joriy bo'lmagan paket versiyasi zipi bilan o'chirildi");
$r = $send('/admin/tools/' . $tid . '/demos', ['version' => '2.2', 'kind' => 'package'], ['file' => new CURLFile($z21, 'application/zip', 'v22.zip')]);
ok(str_contains($flash($r), 'hali joriy emas') && $toolRow($tid)['web_url'] === $u2 && $demoId($tid, '2.2') > 0, "«Darhol joriy qilish» belgisiz: saqlanadi, joriy o'zgarmaydi");

echo "== Zip tekshiruvi: xavfli va noto'g'ri paketlar rad etiladi\n";
$before = $count($tid);
$evil = BW_ROOT . '/public/demo/evil-' . $slug . '.php';
$bad = [
    'zip-slip (../)' => [mkzip("$work/slip.zip", ['index.php' => '<?php echo 1;', '../evil-' . $slug . '.php' => '<?php echo "PWN";']), "xavfli yo'l"],
    'chuqur zip-slip' => [mkzip("$work/slip2.zip", ['a/index.php' => '1', 'a/b/../../../x.php' => '1']), "xavfli yo'l"],
    'mutlaq yo\'l' => [mkzip("$work/abs.zip", ['index.php' => '1', '/tmp/abs-' . $slug . '.php' => '1']), "xavfli yo'l"],
    'Windows yo\'li' => [mkzip("$work/win.zip", ['index.php' => '1', 'C:\\x.php' => '1']), "xavfli yo'l"],
    'simvolik havola' => [mkzip("$work/link.zip", ['index.php' => '1'], ['kalit' => '../../data/keys']), 'simvolik havola'],
    'ildizda index yo\'q' => [mkzip("$work/noidx.zip", ['readme.txt' => 'x', 'app/main.php' => '1']), "index.php yoki index.html yo'q"],
    'public/ tuzilmasi' => [mkzip("$work/pub.zip", ['ilova/public/index.php' => '1', 'ilova/src/Db.php' => '1', 'ilova/data/.htaccess' => 'x']), 'subdomen'],
    '.user.ini paket ichida' => [mkzip("$work/ini.zip", ['index.php' => '1', 'sub/.user.ini' => 'open_basedir=']), '.user.ini'],
    'zip emas' => [(static function () use ($work): string { file_put_contents("$work/rand.zip", random_bytes(2048)); return "$work/rand.zip"; })(), 'zip arxiv emas'],
    'yozuvlar juda ko\'p' => [mkzip("$work/many.zip", ['index.php' => '1'] + array_fill_keys(array_map(static fn($i) => "f/$i.txt", range(1, Demos::MAX_ENTRIES)), 'x')), "juda ko'p"],
];
foreach ($bad as $label => [$file, $want]) {
    $r = $send('/admin/tools/' . $tid . '/demos', ['version' => '4.0', 'kind' => 'package', 'activate' => '1'], ['file' => new CURLFile($file, 'application/zip', 'p.zip')]);
    ok(str_contains($flash($r), $want) && $count($tid) === $before, "$label: " . mb_substr($flash($r), 0, 110));
}
ok(!file_exists($evil) && !file_exists('/tmp/abs-' . $slug . '.php') && !is_dir($deploy), 'rad etilgan paketlardan hech narsa yozilmadi');

$upMax = Media::iniBytes('upload_max_filesize');
$postMax = Media::iniBytes('post_max_size');
if ($upMax < $postMax && $upMax + 600000 < $postMax && $upMax < 64 * 1048576) {
    $big = mkzip("$work/big.zip", ['index.php' => '1', 'b.bin' => random_bytes($upMax + 300000)]);
    $r = $send('/admin/tools/' . $tid . '/demos', ['version' => '4.0', 'kind' => 'package'], ['file' => new CURLFile($big, 'application/zip', 'big.zip')]);
    ok(str_contains($flash($r), 'yuklash chegarasidan katta') && $count($tid) === $before, 'upload_max_filesize dan katta zip: tushunarli xabar: ' . mb_substr($flash($r), 0, 100));
} else {
    ok(true, "upload_max_filesize sinovi o'tkazib yuborildi (chegara: " . ini_get('upload_max_filesize') . ')');
}
if ($postMax <= 64 * 1048576) {
    $r = $staff->req('POST', '/admin/tools/' . $tid . '/demos', ['csrf' => $staff->csrf('/admin/tools'), 'version' => '4.0', 'kind' => 'package', 'pad' => str_repeat('0', $postMax + 1048576)], ['multipart' => true]);
    ok($r['status'] === 413 && str_contains($text($r), 'post_max_size') && $count($tid) === $before, 'post_max_size dan katta yuborish: 413 va tushunarli xabar');
}
$h = $text($staff->get('/admin/tools?demo=' . $tid));
ok(str_contains($h, 'data-demo-max="' . ($upMax === PHP_INT_MAX && $postMax === PHP_INT_MAX ? 0 : min($upMax, $postMax)) . '"') && str_contains($h, 'upload_max_filesize = ' . ini_get('upload_max_filesize')), 'formada server chegarasi ko\'rsatilgan');

echo "== Ilova relizlari: «Oxirgi qilish» (qaytarish), izoh, yashirish\n";
$pkg = "$work/app.zip";
file_put_contents($pkg, random_bytes(2048));
foreach (['1.0', '2.0'] as $v) {
    $staff->req('POST', '/admin/tools/' . $tid . '/releases', ['csrf' => $staff->csrf('/admin/tools'), 'version' => $v, 'kind' => 'server', 'notes' => "v$v izohi", 'latest' => '1', 'file' => new CURLFile($pkg, 'application/zip', 'app.zip')], ['multipart' => true]);
}
$rel = static fn(string $v): array => Db::one('SELECT * FROM tool_releases WHERE tool_id = ? AND version = ?', [$tid, $v]) ?? [];
ok((int)($rel('2.0')['is_latest'] ?? 0) === 1 && (int)($rel('1.0')['is_latest'] ?? 1) === 0, 'ikki reliz: v2.0 «Oxirgi»');
$cid = (int)Db::value('SELECT customer_id FROM users WHERE email = ?', [$cEmail]);
Licenses::issue(['customer_id' => $cid, 'tool_id' => $tid, 'type' => 'paid', 'plan_code' => 'standard', 'seats' => 1, 'valid_until' => Util::now() + 30 * 86400, 'features' => ['app', 'download']], 'sinov');
$order = static function () use ($cust, $slug): array {
    $b = $cust->get('/app/tools/' . $slug)['body'];
    $b = substr($b, (int)strpos($b, 'id="download"'));
    preg_match_all('#<b>v(\d\.\d)</b>( <span class="badge ok">Oxirgi</span>)?#', $b, $mm);
    return array_map(static fn($v, $l) => $v . ($l !== '' ? '*' : ''), $mm[1], $mm[2]);
};
ok($order() === ['2.0*', '1.0'], 'mijoz ro\'yxati: v2.0 (Oxirgi) birinchi: ' . implode(',', $order()));
$r = $cust->req('POST', '/admin/releases/' . $rel('1.0')['id'] . '/latest', ['csrf' => $cust->csrf('/app/profile')]);
ok($r['status'] === 403 && (int)$rel('1.0')['is_latest'] === 0, "mijoz relizni «Oxirgi» qila olmaydi (403)");
$r = $send('/admin/releases/' . $rel('1.0')['id'] . '/latest', []);
ok(str_contains($flash($r), '«Oxirgi» reliz') && (int)$rel('1.0')['is_latest'] === 1 && (int)$rel('2.0')['is_latest'] === 0, 'v1.0 «Oxirgi» qilindi (avvalgisiga qaytarish)');
ok($order() === ['1.0*', '2.0'], 'mijoz ro\'yxatida v1.0 birinchi, «Oxirgi» belgisi bilan: ' . implode(',', $order()));
ok($cust->get('/app/download/' . $rel('1.0')['id'])['status'] === 200, 'v1.0 yuklab olinadi');
$r = $send('/admin/releases/' . $rel('2.0')['id'] . '/notes', ['notes' => 'Xato topildi: eksport ishlamaydi']);
ok(($rel('2.0')['notes'] ?? '') === 'Xato topildi: eksport ishlamaydi' && str_contains($text($r['page']), 'Xato topildi: eksport ishlamaydi'), 'reliz izohi o\'zgartirildi va adminda ko\'rinadi');
$r = $send('/admin/releases/' . $rel('1.0')['id'] . '/toggle', []);
ok((int)$rel('1.0')['active'] === 0 && (int)$rel('2.0')['is_latest'] === 1 && str_contains($flash($r), 'Endi «Oxirgi»: v2.0'), "«Oxirgi» reliz yashirilsa, keyingi ko'rinadigani «Oxirgi» bo'ladi");
ok($order() === ['2.0*'], 'mijoz faqat ko\'rinadigan relizni ko\'radi: ' . implode(',', $order()));

echo "\n" . ($fails === 0 ? "HAMMASI O'TDI ($total)" : "$fails TA XATO ($total dan)") . "\n";
exit($fails === 0 ? 0 : 1);
