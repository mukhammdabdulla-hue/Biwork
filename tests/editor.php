<?php
declare(strict_types=1);

/**
 * Admin mahsulot formasi sinovi: manifest.json yuklamasdan mahsulot qo'shish va tahrirlash (matn, rasm, tarif, brauzer manzili),
 * e'lon qilish / yashirish / tez orada, oldindan ko'rish, manzil o'zgarishi (301) va qulflanishi, xatolar, rasm tekshiruvi,
 * Reja manifesti formadan o'tganda o'zgarmasligi.
 * Ishga tushirish: bash tests/run.sh   (BASE, STAFF_EMAIL, STAFF_PASS kerak; Db uchun BIWORK_DATA/BIWORK_CONFIG)
 */
require __DIR__ . '/../portal/src/bootstrap.php';

$BASE = rtrim(getenv('BASE') ?: 'http://127.0.0.1:8099', '/');
$STAFF = [getenv('STAFF_EMAIL') ?: 'admin@biwork.test', getenv('STAFF_PASS') ?: 'Admin-Parol-2026'];
$fails = 0;
$total = 0;
require __DIR__ . '/helpers.php';

/** ['a' => ['b' => 'x']] -> ['a[b]' => 'x'] (curl multipart uchun). */
function flat(array $a, string $prefix = ''): array
{
    $o = [];
    foreach ($a as $k => $v) {
        $key = $prefix === '' ? (string)$k : $prefix . '[' . $k . ']';
        if (is_array($v)) {
            $o += flat($v, $key);
        } else {
            $o[$key] = $v;
        }
    }
    return $o;
}

/** Brauzer kabi: formadagi maydonlarni (fayl va yuborish tugmalarisiz) o'qiydi; matn maydonlari CRLF bilan yuboriladi. */
function formFields(string $html): array
{
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
    $x = new DOMXPath($dom);
    $pairs = [];
    foreach ($x->query('//form[@id="ed"]//*[self::input or self::textarea or self::select][not(ancestor::template)]') as $n) {
        /** @var DOMElement $n */
        $name = $n->getAttribute('name');
        if ($name === '' || $n->hasAttribute('disabled')) {
            continue;
        }
        if ($n->nodeName === 'input') {
            $type = strtolower($n->getAttribute('type') ?: 'text');
            if (in_array($type, ['file', 'submit', 'button', 'image'], true) || (in_array($type, ['checkbox', 'radio'], true) && !$n->hasAttribute('checked'))) {
                continue;
            }
            $v = $n->hasAttribute('value') ? $n->getAttribute('value') : ($type === 'checkbox' ? 'on' : '');
        } elseif ($n->nodeName === 'textarea') {
            $v = str_replace("\n", "\r\n", $n->textContent);
        } else {
            $v = '';
            $first = true;
            foreach ($x->query('.//option', $n) as $o) {
                /** @var DOMElement $o */
                if ($first || $o->hasAttribute('selected')) {
                    $v = $o->hasAttribute('value') ? $o->getAttribute('value') : $o->textContent;
                }
                $first = false;
            }
        }
        $pairs[] = rawurlencode($name) . '=' . rawurlencode($v);
    }
    parse_str(implode('&', $pairs), $out);
    return $out;
}

function pngFile(int $w, int $h, int $seed): string
{
    $f = tempnam(sys_get_temp_dir(), 'bwpng') ?: '';
    if (Media::gd()) {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 20 + $seed * 40, 60, 120));
        imagefilledrectangle($im, 40, 40, $w - 40, 120, imagecolorallocate($im, 240, 240, 240));
        imagepng($im, $f);
    } else {
        file_put_contents($f, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    }
    return $f;
}

$staff = new Browser($BASE);
$r = $staff->post('/login', '/login', ['email' => $STAFF[0], 'password' => $STAFF[1]]);
ok($r['status'] === 303, 'xodim kirdi');
$anon = new Browser($BASE);
$send = static function (string $path, array $fields, array $files = []) use ($staff): array {
    $fields['csrf'] = $staff->csrf('/admin/tools');
    return $staff->req('POST', $path, flat($fields) + $files, ['multipart' => true]);
};
$text = static fn(array $r): string => html_entity_decode($r['body'], ENT_QUOTES | ENT_HTML5);
$tool = static fn(int $id): array => Db::one('SELECT * FROM tools WHERE id = ?', [$id]) ?? [];
$mk = static fn(int $id): array => json_decode((string)($tool($id)['marketing'] ?? '{}'), true) ?: [];

echo "== Kirish huquqi\n";
ok(in_array($anon->get('/admin/tools/new')['status'], [302, 303], true), "kirishsiz: /admin/tools/new ochilmaydi");
$r = $anon->req('POST', '/admin/tools/new', ['name' => 'Yomon']);
ok(!in_array($r['status'], [200], true) && Db::one("SELECT id FROM tools WHERE name = 'Yomon'") === null, "kirishsiz POST mahsulot yaratmaydi");
$r = $staff->req('POST', '/admin/tools/new', ['name' => 'Csrfsiz']);
ok($r['status'] >= 400 && Db::one("SELECT id FROM tools WHERE name = 'Csrfsiz'") === null, "CSRF belgisiz yuborish rad etiladi: " . $r['status']);
ok($staff->get('/admin/tools')['status'] === 200 && str_contains($staff->get('/admin/tools/new')['body'], 'Yangi mahsulot'), "ro'yxat va «Yangi mahsulot» formasi ochiladi");

echo "== Yangi mahsulot (qoralama)\n";
$r = $send('/admin/tools/new', ['name' => 'Biwork Namuna Dastur']);
ok($r['status'] === 303 && preg_match('#^/admin/tools/(\d+)/edit$#', (string)$r['location'], $m) === 1, "yaratildi, tahrirlash sahifasiga o'tdi: " . ($r['location'] ?? ''));
$id = (int)($m[1] ?? 0);
$row = $tool($id);
ok(($row['slug'] ?? '') === 'namuna-dastur' && preg_match('/^[A-Z0-9]{3}$/', (string)($row['code'] ?? '')) === 1 && ($row['status'] ?? '') === 'hidden', "manzil nomdan olindi ('biwork' qismi tushdi), kod 3 belgi, holat yashirin: {$row['slug']} {$row['code']} {$row['status']}");
$plans = Db::all('SELECT * FROM plans WHERE tool_id = ? ORDER BY sort', [$id]);
ok(count($plans) === 2 && $plans[0]['code'] === 'standard', "2 ta boshlang'ich tarif qo'shildi");
ok(str_contains($staff->follow($r)['body'], 'qoralama'), 'qoralama xabari ko\'rsatildi');
ok($anon->get('/namuna-dastur')['status'] === 404, 'yashirin mahsulot ochiq saytda 404');
$h = $staff->get('/namuna-dastur');
ok($h['status'] === 200 && str_contains($h['body'], 'Yashirin sahifa') && str_contains($h['body'], 'noindex'), "xodim yashirin sahifani ko'radi (belgi va noindex bilan)");
ok(!str_contains($anon->get('/mahsulotlar')['body'], 'Namuna Dastur') && !str_contains($anon->get('/sitemap.xml')['body'], 'namuna-dastur'), "yashirin mahsulot katalogda va sitemap da yo'q");
$r = $send('/admin/tools/new', ['name' => '']);
ok(str_contains($text($r), 'nomini yozing'), "nomsiz mahsulot rad etiladi");
$r = $send('/admin/tools/new', ['name' => 'Boshqa', 'slug' => 'reja']);
ok(str_contains($text($r), "boshqa mahsulotda bor") && Db::one("SELECT id FROM tools WHERE name = 'Boshqa'") === null, "band manzil rad etiladi, mahsulot yaratilmaydi");

echo "== To'ldirish va e'lon qilish\n";
$p1 = (int)$plans[0]['id'];
$p2 = (int)$plans[1]['id'];
$fields = [
    'id' => $id, 'name' => 'Biwork Namuna Dastur', 'tagline' => 'Namuna dastur: ish jarayonini bitta oynada yuritish',
    'description' => "Birinchi abzats: dastur nima qiladi va kimga kerak.\r\n\r\nIkkinchi abzats: qanday ishlaydi.", 'category' => 'Namuna', 'icon' => 'chart',
    'web_url' => 'https://namuna.example.uz/', 'trial_enabled' => '1', 'trial_days' => '7',
    'headline' => 'Ish jarayoni bir joyda', 'lead' => 'Sarlavha ostidagi izoh matni namuna uchun.', 'badges' => "Brauzerda ishlaydi\r\nO'zbek tilida",
    'audience' => [['title' => 'Ishlab chiqarish', 'text' => 'Sexlar uchun'], ['title' => '', 'text' => '']],
    'problems' => [['title' => 'Hisobot kech chiqadi', 'text' => "Qo'lda yig'iladi"]],
    'features' => [['title' => 'Avtomatik hisobot', 'text' => 'Tugmani bossangiz tayyor', 'points' => "Excelga chiqarish\r\nPDF", 'shot' => '']],
    'faq' => [['q' => 'Narxi qancha?', 'a' => 'Kelishiladi']],
    'seo' => ['title' => 'Namuna dastur: sinov', 'description' => 'Namuna tavsif matni', 'keywords' => 'namuna, dastur'],
    'cta' => ['lead' => "Narx so'rash", 'placeholder' => 'Masalan: nechta xodim bor?'],
    'plans' => [
        $p1 => ['name' => 'Standart', 'price' => '1 500 000', 'price_text' => "1 500 000 so'mdan", 'blurb' => 'Kichik jamoa uchun', 'seats' => '2', 'days' => '365', 'active' => '1'],
        $p2 => ['name' => 'Korxona', 'price' => '', 'price_text' => '', 'blurb' => '', 'seats' => '5', 'days' => '365'],
    ],
    'new_plans' => [['name' => 'Katta', 'price' => '', 'price_text' => 'Kelishilgan narx', 'blurb' => '', 'seats' => '20', 'days' => '']],
    'do' => 'publish',
];
$img1 = pngFile(1400, 800, 1);
$img2 = pngFile(900, 500, 2);
$r = $send('/admin/tools/' . $id . '/edit', $fields, ['new_shots[0]' => new CURLFile($img1, 'image/png', 'a.png'), 'new_shots[1]' => new CURLFile($img2, 'image/png', 'b.png')]);
$body = $text($staff->follow($r));
ok($r['status'] === 303 && str_contains($body, "e'lon qilindi"), "«E'lon qilish» ishladi");
$row = $tool($id);
$m = $mk($id);
ok($row['status'] === 'active' && $row['web_url'] === 'https://namuna.example.uz/' && (int)$row['trial_days'] === 7 && $row['icon'] === 'chart' && $row['category'] === 'Namuna', "asosiy maydonlar saqlandi (holat, brauzer manzili, sinov, belgi, bo'lim)");
ok(!str_contains($row['description'], "\r") && str_contains($row['description'], "\n\n"), "tavsif: brauzerning CRLF i LF ga keltirildi, abzatslar saqlandi");
ok(count($m['audience']) === 1 && count($m['problems']) === 1 && count($m['faq']) === 1 && $m['features'][0]['points'] === ['Excelga chiqarish', 'PDF'] && $m['badges'] === ["Brauzerda ishlaydi", "O'zbek tilida"], "ro'yxatlar saqlandi (bo'sh qator tashlandi)");
ok($m['seo']['keywords'] === ['namuna', 'dastur'] && $m['cta']['placeholder'] === 'Masalan: nechta xodim bor?', 'SEO va tugma matnlari saqlandi');
ok(count($m['shots']) === 2 && preg_match('/^ekran-[a-f0-9]{8}\.(webp|png)$/', $m['shots'][0]['file']) === 1, 'ikki rasm yuklandi: ' . implode(', ', array_column($m['shots'], 'file')));
$pl = Db::one('SELECT * FROM plans WHERE id = ?', [$p1]);
ok((int)$pl['price'] === 1500000 && $pl['price_text'] === "1 500 000 so'mdan" && (int)$pl['seats'] === 2 && $pl['blurb'] === 'Kichik jamoa uchun', "tarif: narx (bo'sh joylar tashlandi), narx matni, izoh, qurilmalar");
ok((int)Db::value('SELECT active FROM plans WHERE id = ?', [$p2]) === 0 && Db::one("SELECT id FROM plans WHERE tool_id = ? AND name = 'Katta'", [$id]) !== null, "belgisi olingan tarif yashirildi, yangi tarif qo'shildi");

$pg = $anon->get('/namuna-dastur');
$ph = html_entity_decode($pg['body'], ENT_QUOTES | ENT_HTML5);
ok($pg['status'] === 200 && !str_contains($ph, 'Yashirin sahifa') && !str_contains($ph, 'noindex'), "ochiq saytda sahifa ochildi (belgisiz, indekslanadi)");
foreach (['Ish jarayoni bir joyda', 'Sarlavha ostidagi izoh', 'Hisobot kech chiqadi', 'Avtomatik hisobot', 'Excelga chiqarish', 'Narxi qancha?', "1 500 000 so'mdan", 'Kichik jamoa uchun', 'Kelishilgan narx', "O'zbek tilida", "Narx so'rash", 'nechta xodim bor', 'Namuna dastur: sinov'] as $needle) {
    ok(str_contains($ph, $needle), "sahifada: $needle");
}
ok(substr_count($pg['body'], 'class="card plan"') === 2, 'sahifada 2 ta faol tarif (yashirilgani yo\'q)');
ok(str_contains($ph, "Sinab ko'rish") && str_contains($pg['body'], '/register?tool=namuna-dastur'), "brauzer manzili bor: «Sinab ko'rish» tugmasi chiqdi");
preg_match('#/media/' . $id . '/(ekran-[a-f0-9]{8}\.(?:webp|png))#', $pg['body'], $mm);
$im = $mm[1] ?? '';
$ir = $im !== '' ? $anon->get('/media/' . $id . '/' . $im) : ['status' => 0, 'headers' => []];
ok($ir['status'] === 200 && str_starts_with($ir['headers']['content-type'] ?? '', 'image/') && isset($ir['headers']['cache-control']), "yuklangan rasm /media orqali beriladi (kesh sarlavhasi bilan): $im");
if (Media::gd()) {
    [$iw] = getimagesizefromstring($ir['body']) ?: [0];
    ok($iw > 0 && $iw <= Media::MAX_W, "rasm $iw px ga kichraytirildi (≤ " . Media::MAX_W . ')');
    $og = $anon->get('/media/' . $id . '/og.jpg');
    ok($og['status'] === 200 && ($og['headers']['content-type'] ?? '') === 'image/jpeg' && str_contains($pg['body'], '/media/' . $id . '/og.jpg'), "ulashish rasmi (og.jpg) birinchi ekran rasmidan yasalgan va sahifada ko'rsatilgan");
    $oi = getimagesizefromstring($og['body']) ?: [0, 0];
    ok($oi[0] === 1200 && $oi[1] === 630, 'ulashish rasmi 1200x630');
}
ok($anon->get('/media/' . $id . '/yoq.webp')['status'] === 404 && in_array($anon->get('/media/' . $id . '/..%2f..%2fconfig.php')['status'], [403, 404], true) && $anon->get('/media/' . $id . '/..%2f..%2fconfig.php')['body'] === '' || !str_contains($anon->get('/media/' . $id . '/..%2f..%2fconfig.php')['body'], 'tracking_html'), "/media: yo'q fayl 404, yo'l o'tish (../) rad etiladi");
ok($anon->get('/media/99999/' . $im)['status'] === 404, "/media: yo'q mahsulot — 404");
ok(str_contains($anon->get('/mahsulotlar')['body'], 'Namuna Dastur') && str_contains($anon->get('/sitemap.xml')['body'], '/namuna-dastur') && str_contains($anon->get('/')['body'], 'Namuna Dastur'), "katalog, bosh sahifa va sitemap avtomatik yangilandi");

echo "== Holatlar: yashirish, tez orada, qayta e'lon\n";
$r = $send('/admin/tools/' . $id . '/status', ['do' => 'hide']);
ok($r['status'] === 303 && $tool($id)['status'] === 'hidden' && $anon->get('/namuna-dastur')['status'] === 404 && !str_contains($anon->get('/sitemap.xml')['body'], 'namuna-dastur'), "ro'yxatdan «Yashirish»: sahifa 404, sitemap dan chiqdi");
$r = $send('/admin/tools/' . $id . '/status', ['do' => 'xxx']);
ok($r['status'] >= 400 && $tool($id)['status'] === 'hidden', "noto'g'ri amal rad etiladi");
$f = formFields($staff->get('/admin/tools/' . $id . '/edit')['body']);
$r = $send('/admin/tools/' . $id . '/edit', $f + ['do' => 'soon']);
$soon = $anon->get('/namuna-dastur');
ok($tool($id)['status'] === 'soon' && $soon['status'] === 200 && str_contains($soon['body'], 'Chiqqanda xabar bering') && !str_contains($soon['body'], '/register?tool=namuna-dastur'), "«Tez orada»: sahifa ochiq, sinash tugmasi o'rniga xabar berish");
$f = formFields($staff->get('/admin/tools/' . $id . '/edit')['body']);
$r = $send('/admin/tools/' . $id . '/edit', $f + ['do' => 'publish']);
ok($tool($id)['status'] === 'active' && $anon->get('/namuna-dastur')['status'] === 200, "qayta e'lon qilindi");
// brauzer manzili bo'sh: «Sinab ko'rish» chiqmaydi
$f2 = $f;
$f2['web_url'] = '';
$send('/admin/tools/' . $id . '/edit', $f2 + ['do' => 'save']);
$nb = $anon->get('/namuna-dastur')['body'];
$own = explode('Boshqa Biwork mahsulotlari', explode('<main id="asos">', html_entity_decode($nb, ENT_QUOTES | ENT_HTML5), 2)[1] ?? '')[0];
ok(!str_contains($nb, '/register?tool=namuna-dastur') && $own !== '' && !str_contains($own, "Sinab ko'rish"), "brauzer manzili bo'sh: mahsulot sahifasida «Sinab ko'rish» va'da qilinmaydi");
$send('/admin/tools/' . $id . '/edit', $f + ['do' => 'save']);

echo "== Rasmlar: tartib, o'chirish, tekshiruv\n";
$f = formFields($staff->get('/admin/tools/' . $id . '/edit')['body']);
ok(count($f['shots'] ?? []) === 2 && isset($f['shots'][0]['file']), "forma ikki rasmni ko'rsatadi");
$fileA = $f['shots'][0]['file'];
$fileB = $f['shots'][1]['file'];
$f['shots'] = [$f['shots'][1], $f['shots'][0]]; // tartibni almashtirish
$send('/admin/tools/' . $id . '/edit', $f + ['do' => 'save']);
$m = $mk($id);
ok(array_column($m['shots'], 'file') === [$fileB, $fileA], 'rasm tartibi almashtirildi');
$f = formFields($staff->get('/admin/tools/' . $id . '/edit')['body']);
$f['features'][0]['shot'] = $fileB; // imkoniyat rasmi
$f['shots'][0]['remove'] = '1';      // birinchi rasmni (B) ro'yxatdan chiqarish
$f['shots'][1]['remove'] = '1';
$send('/admin/tools/' . $id . '/edit', $f + ['do' => 'save']);
$m = $mk($id);
ok($m['shots'] === [] && Media::path($id, $fileA) === null, "o'chirilgan rasm diskdan ham o'chdi");
ok($m['features'][0]['shot'] === $fileB && Media::path($id, $fileB) !== null, "imkoniyatda ishlatilayotgan rasm ro'yxatdan chiqsa ham saqlanib qoldi");
ok(!is_file(Media::dir($id) . '/og.jpg'), "rasm qolmaganda ulashish rasmi ham olib tashlandi");
$bad = tempnam(sys_get_temp_dir(), 'bwbad') ?: '';
file_put_contents($bad, '<?php echo 1; // rasm emas');
$f = formFields($staff->get('/admin/tools/' . $id . '/edit')['body']);
$r = $send('/admin/tools/' . $id . '/edit', $f + ['do' => 'save'], ['new_shots[0]' => new CURLFile($bad, 'image/png', 'soxta.png'), 'new_shots[1]' => new CURLFile($img2, 'image/png', 'ok.png')]);
$body = $text($staff->follow($r));
$m = $mk($id);
ok(str_contains($body, 'faqat PNG, JPG yoki WEBP') && count($m['shots']) === 1 && glob(Media::dir($id) . '/*.php') === [], "rasm bo'lmagan fayl rad etildi (xabar bilan), to'g'ri rasm yuklandi, .php saqlanmadi");
$files = Media::files($id);
ok(count(array_filter($files, static fn($x) => !preg_match('/^ekran-[a-f0-9]{8}\.(webp|png)$/', $x))) === 0, "papkada faqat tekshirilgan rasmlar");

echo "== Xatolar va tekshiruvlar\n";
$f = formFields($staff->get('/admin/tools/' . $id . '/edit')['body']);
$cases = [
    ['slug', 'reja', "boshqa mahsulotda bor"], ['slug', 'admin', 'band'], ['slug', 'Yomon Nom!', '2-31 belgi'], ['code', 'ab', 'aynan 3 ta'], ['code', 'REJ', 'boshqa mahsulotda bor'],
    ['name', '', 'nomini yozing'], ['web_url', 'ftp://x', 'https://'],
];
foreach ($cases as [$k, $v, $needle]) {
    $r = $send('/admin/tools/' . $id . '/edit', ['headline' => 'Saqlanmagan sarlavha'] + [$k => $v] + $f + ['do' => 'save']);
    $b = $text($r);
    ok($r['status'] === 200 && str_contains($b, $needle) && str_contains($b, 'Saqlanmagan sarlavha') && $tool($id)['slug'] === 'namuna-dastur', "$k='$v' rad etildi («{$needle}»), kiritilgan matn formada qoldi");
}
$r = $send('/admin/tools/' . $id . '/edit', ['name' => str_repeat('N', 200)] + $f + ['do' => 'save']);
ok(mb_strlen($tool($id)['name']) === 80, 'juda uzun nom 80 belgiga kesiladi');
$send('/admin/tools/' . $id . '/edit', ['name' => 'Biwork Namuna Dastur'] + $f + ['do' => 'save']);
$r = $send('/admin/tools/' . $id . '/edit', ['features' => array_fill(0, 12, ['title' => 'F', 'text' => ''])] + $f + ['do' => 'save']);
ok(count($mk($id)['features']) === 8, "imkoniyatlar 8 tadan oshmaydi");
$send('/admin/tools/' . $id . '/edit', $f + ['do' => 'save']);
$r = $staff->req('POST', '/admin/tools/' . $id . '/edit', flat(['csrf' => $staff->csrf('/admin/tools'), 'name' => 'X', 'junk' => str_repeat('a', 10)]) + ['new_shots[0]' => new CURLFile($img1, 'image/png', 'a.png')] + ['pad' => str_repeat('0', (int)min(Media::iniBytes('post_max_size'), 64 * 1048576) + 1048576)], ['multipart' => true]);
ok(Media::iniBytes('post_max_size') > 64 * 1048576 || ($r['status'] === 413 && str_contains($text($r), 'post_max_size')), "post_max_size dan katta yuborish tushunarli xabar (413) bilan rad etiladi: " . $r['status']);

echo "== Oldindan ko'rish\n";
$r = $send('/admin/tools/preview', ['id' => 0, 'name' => 'Oldindan Sinov', 'tagline' => 'Faqat ko\'rish uchun mahsulot', 'headline' => "Saqlanmaydigan sarlavha", 'features' => [['title' => "Ko'rinadigan imkoniyat", 'text' => 'Matn']]]);
$b = $text($r);
ok($r['status'] === 200 && str_contains($b, 'Saqlanmaydigan sarlavha') && str_contains($b, "Ko'rinadigan imkoniyat") && str_contains($b, "Oldindan ko'rish") && str_contains($b, 'noindex'), "yangi mahsulot oldindan ko'rinishi: saqlamasdan, noindex bilan");
ok(Db::one("SELECT id FROM tools WHERE name = 'Oldindan Sinov'") === null, 'oldindan ko\'rish bazaga yozmaydi');
ok(str_contains($b, '<fieldset disabled') || str_contains($b, 'disabled'), "oldindan ko'rishda so'rov formasi o'chirilgan (haqiqiy murojaat tushmaydi)");
$r = $send('/admin/tools/preview', ['id' => $id, 'name' => 'Vaqtincha Nom', 'headline' => 'Boshqa sarlavha'] + $f);
ok(str_contains($text($r), 'Vaqtincha Nom') && $tool($id)['name'] === 'Biwork Namuna Dastur', "mavjud mahsulot: o'zgarishlar ko'rinadi, lekin saqlanmaydi");
$r = $anon->req('POST', '/admin/tools/preview', ['id' => $id, 'name' => 'Begona']);
ok($r['status'] !== 200 || !str_contains($r['body'], 'Begona'), "kirishsiz oldindan ko'rish yo'q");

echo "== Manzil o'zgarishi va qulf\n";
$f = formFields($staff->get('/admin/tools/' . $id . '/edit')['body']);
$r = $send('/admin/tools/' . $id . '/edit', ['slug' => 'namuna-yangi'] + $f + ['do' => 'save']);
$old = $anon->get('/namuna-dastur');
ok($tool($id)['slug'] === 'namuna-yangi' && $old['status'] === 301 && str_ends_with((string)$old['location'], '/namuna-yangi') && $anon->get('/namuna-yangi')['status'] === 200, "manzil o'zgardi: eski havola yangisiga 301 bilan o'tadi");
ok(str_contains($anon->get('/namuna-yangi')['body'], '/media/' . $id . '/'), "rasmlar yangi manzilda ham ishlaydi");
$mf = tempnam(sys_get_temp_dir(), 'bwmf') ?: '';
file_put_contents($mf, json_encode(['slug' => 'namuna-dastur', 'code' => 'ZZZ', 'name' => 'Eski manzil']));
$r = $staff->req('POST', '/admin/tools/register', ['csrf' => $staff->csrf('/admin/tools'), 'manifest' => new CURLFile($mf, 'application/json', 'manifest.json')], ['multipart' => true]);
ok(str_contains($text($staff->follow($r)), 'eski manzili') && Db::one("SELECT id FROM tools WHERE code = 'ZZZ'") === null, "manifest eski (o'zgartirilgan) manzil bilan yangi mahsulot yaratolmaydi");
@unlink($mf);
$f = formFields($staff->get('/admin/tools/' . $id . '/edit')['body']);
$send('/admin/tools/' . $id . '/edit', ['slug' => 'namuna-dastur'] + $f + ['do' => 'save']);
ok($tool($id)['slug'] === 'namuna-dastur' && $anon->get('/namuna-dastur')['status'] === 200 && $anon->get('/namuna-yangi')['status'] === 301, "eski manzilga qaytarish mumkin");
$cid = trim((string)shell_exec('php ' . escapeshellarg(__DIR__ . '/../tools/cli.php') . ' customer:add "Namuna MChJ" namuna@kor.uz "Egasi" "Parol-Sinov-2026" 2>&1'));
preg_match('/id (\d+)/', $cid, $cm);
$lic = trim((string)shell_exec('php ' . escapeshellarg(__DIR__ . '/../tools/cli.php') . ' license:issue ' . (int)($cm[1] ?? 0) . ' namuna-dastur standard 2>&1'));
ok(Db::value('SELECT COUNT(*) FROM licenses WHERE tool_id = ?', [$id]) == 1, 'sinov litsenziyasi berildi: ' . substr($lic, 0, 60));
$edit = $staff->get('/admin/tools/' . $id . '/edit')['body'];
ok(!str_contains($edit, 'name="slug"') && !str_contains($edit, 'name="code"') && str_contains(html_entity_decode($edit, ENT_QUOTES | ENT_HTML5), "bu mahsulotga litsenziya berilgan"), "litsenziya berilgach manzil va kod qulflanadi (formada o'zgartirib bo'lmaydi)");
$f = formFields($edit);
$r = $send('/admin/tools/' . $id . '/edit', ['slug' => 'boshqa-manzil'] + $f + ['do' => 'save']);
ok(str_contains($text($r), "endi o'zgartirib bo'lmaydi") && $tool($id)['slug'] === 'namuna-dastur', "qulflangan manzilni so'rov orqali o'zgartirib bo'lmaydi");
$r = $send('/admin/tools/' . $id . '/edit', ['name' => 'Biwork Namuna 2'] + $f + ['do' => 'save']);
ok($tool($id)['name'] === 'Biwork Namuna 2' && $tool($id)['slug'] === 'namuna-dastur', "nomni o'zgartirish mumkin (manzil o'zgarmaydi)");

echo "== Reja manifesti formadan o'tganda o'zgarmaydi\n";
$reja = Db::one("SELECT * FROM tools WHERE slug = 'reja'");
$rid = (int)$reja['id'];
$before = ['m' => json_decode((string)$reja['marketing'], true), 'p' => Db::all('SELECT id, code, name, price, seats, days, active, price_text, blurb FROM plans WHERE tool_id = ? ORDER BY id', [$rid])];
$assetsBefore = substr_count($anon->get('/reja')['body'], '/assets/products/reja/');
$rf = formFields($staff->get('/admin/tools/' . $rid . '/edit')['body']);
ok(count($rf['shots'] ?? []) === count($before['m']['shots']) && count($rf['features'] ?? []) >= count($before['m']['features']), "forma Reja ma'lumotini to'liq ko'rsatadi");
$r = $send('/admin/tools/' . $rid . '/edit', $rf + ['do' => 'save']);
$after = Db::one('SELECT * FROM tools WHERE id = ?', [$rid]);
ok($r['status'] === 303 && json_decode((string)$after['marketing'], true) === $before['m'], "sahifa matnlari (marketing) formadan o'tgach aynan o'sha-o'sha");
$diff = array_keys(array_filter(['name' => 1, 'tagline' => 1, 'description' => 1, 'status' => 1, 'web_url' => 1, 'slug' => 1, 'code' => 1, 'icon' => 1, 'category' => 1, 'trial_days' => 1, 'trial_enabled' => 1, 'sort' => 1], static fn($v, $k) => (string)$after[$k] !== (string)$reja[$k], ARRAY_FILTER_USE_BOTH));
ok($diff === [], "nom, tavsif, holat, manzil, kod, belgi, sinov, tartib o'zgarmadi" . ($diff ? ' (farq: ' . implode(', ', $diff) . ': ' . json_encode(array_map(static fn($k) => [$reja[$k], $after[$k]], $diff), JSON_UNESCAPED_UNICODE) . ')' : ''));
ok($before['p'] === Db::all('SELECT id, code, name, price, seats, days, active, price_text, blurb FROM plans WHERE tool_id = ? ORDER BY id', [$rid]), "tariflar o'zgarmadi");
ok(substr_count($anon->get('/reja')['body'], '/assets/products/reja/') === $assetsBefore && $assetsBefore > 0, "Reja rasmlari (paket bilan kelgan) sahifada o'sha-o'sha ko'rinadi");

echo "\n" . ($fails === 0 ? "HAMMASI O'TDI ($total)" : "$fails TA XATO ($total dan)") . "\n";
@unlink($img1);
@unlink($img2);
@unlink($bad);
exit($fails === 0 ? 0 : 1);
