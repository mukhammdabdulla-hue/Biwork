<?php
declare(strict_types=1);

/**
 * Paketdagi tayyor mahsulotlar (integrations/<slug>/manifest.json: HR, Loyiha, Savdo, Moliya, Yuridik; «tez orada»: Monitoring) sinovi:
 * ta'rif va rasmlar bir-biriga mos, admin «Qo'shish» bilan ro'yxatga olinadi, sahifalar to'g'ri chiqadi, brauzer manzili bo'sh bo'lsa
 * «Sinab ko'rish» yo'q, bitta demo manzilni ko'p mahsulotga qo'yish.
 * Ishga tushirish: bash tests/run.sh   (BASE, STAFF_EMAIL, STAFF_PASS kerak; Db uchun BIWORK_DATA/BIWORK_CONFIG)
 */
require __DIR__ . '/../portal/src/bootstrap.php';

$BASE = rtrim(getenv('BASE') ?: 'http://127.0.0.1:8099', '/');
$STAFF = [getenv('STAFF_EMAIL') ?: 'admin@biwork.test', getenv('STAFF_PASS') ?: 'Admin-Parol-2026'];
$fails = 0;
$total = 0;
require __DIR__ . '/helpers.php';

$SLUGS = ['hr', 'loyiha', 'savdo', 'moliya', 'yuridik'];   // faol
$SOON = ['monitoring'];                                      // «tez orada»: sinab ko'rish va tariflar yo'q, xabar olish shakli bor
$ALL = array_merge($SLUGS, $SOON);
$text = static fn(array $r): string => html_entity_decode($r['body'], ENT_QUOTES | ENT_HTML5);
function jsonldOf(string $html): array
{
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
    return array_values(array_filter(array_map(static fn($j) => json_decode($j, true), $m[1])));
}
function walkTypes(mixed $n, string $type): array
{
    $out = [];
    if (is_array($n)) {
        if (($n['@type'] ?? null) === $type) {
            $out[] = $n;
        }
        foreach ($n as $v) {
            $out = array_merge($out, walkTypes($v, $type));
        }
    }
    return $out;
}

echo "== Paketdagi ta'riflar va rasmlar\n";
$bundled = Catalog::bundled();
ok(array_diff($ALL, array_keys($bundled)) === [], "paketda tayyor ta'riflar bor: " . implode(', ', array_keys($bundled)));
foreach ($ALL as $s) {
    $m = $bundled[$s] ?? null;
    if (!$m) {
        continue;
    }
    ok(($m['status'] ?? 'active') === (in_array($s, $SOON, true) ? 'soon' : 'active') && (empty($m['trial']['enabled']) || !in_array($s, $SOON, true)), "$s: manifest holati " . ($m['status'] ?? 'active') . (in_array($s, $SOON, true) ? ' (sinov yoqilmagan)' : ''));
    $mk = $m['marketing'] ?? [];
    $c = Marketing::clean($mk);
    $trunc = array_filter(['badges', 'problems', 'audience', 'features', 'shots', 'faq'], static fn($k) => count($c[$k]) !== count($mk[$k] ?? []));
    ok($trunc === [] && $c['headline'] === ($mk['headline'] ?? '') && $c['lead'] === ($mk['lead'] ?? ''), "$s: marketing tozalashdan keyin kesilmaydi" . ($trunc ? ' (kesildi: ' . implode(',', $trunc) . ')' : ''));
    $dir = Marketing::assetDir($s);
    $files = array_column($mk['shots'] ?? [], 'file');
    $missing = array_filter($files, static fn($f) => !is_file($dir . '/' . $f) || !@getimagesize($dir . '/' . $f));
    ok($files !== [] && $missing === [], "$s: " . count($files) . ' ta ekran rasmi assets/products/' . $s . ' da bor' . ($missing ? ' (yo\'q: ' . implode(',', $missing) . ')' : ''));
    $featShots = array_filter(array_column($mk['features'] ?? [], 'shot'));
    ok(array_diff($featShots, $files) === [], "$s: imkoniyatlardagi rasmlar ro'yxatda bor");
    $og = @getimagesize($dir . '/og.png');
    ok($og && $og[0] === 1200 && $og[1] === 630, "$s: ulashish rasmi og.png 1200x630");
    ok(mb_strlen($mk['seo']['title'] ?? '') <= 70 && mb_strlen($mk['seo']['description'] ?? '') >= 100 && mb_strlen($mk['seo']['description'] ?? '') <= 180, "$s: SEO sarlavha ≤70 va tavsif 100–180 belgi");
    ok(trim((string)($m['web']['url'] ?? '')) === '' && array_filter($m['plans'] ?? [], static fn($p) => isset($p['price'])) === [], "$s: manifestda to'qilgan narx va demo manzil yo'q (halol da'vo)");
    foreach (glob($dir . '/*.webp') ?: [] as $f) {
        $sz = @getimagesize($f);
        if (!$sz || $sz[0] > 1600) {
            ok(false, "$s: " . basename($f) . ' juda katta yoki buzuq');
        }
    }
}

echo "== Admin: «Tayyor mahsulotlar» orqali qo'shish\n";
$staff = new Browser($BASE);
$r = $staff->post('/login', '/login', ['email' => $STAFF[0], 'password' => $STAFF[1]]);
ok($r['status'] === 303, 'xodim kirdi');
$list = $text($staff->get('/admin/tools'));
ok(str_contains($list, 'Tayyor mahsulotlar: ') && str_contains($list, 'Biwork HR') && str_contains($list, 'Biwork Yuridik') && str_contains($list, 'Biwork Monitoring') && str_contains($list, 'Tez orada') && !str_contains($list, 'name="slug" value="reja"'), "ro'yxatda tayyor mahsulotlar ko'rinadi (Reja allaqachon qo'shilgani uchun yo'q)");
$anon = new Browser($BASE);
ok($anon->get('/hr')['status'] === 404, "qo'shilmagan mahsulot sahifasi 404");
$r = $staff->post('/admin/tools', '/admin/tools/bundled', ['slug' => 'hr']);
ok(str_contains($text($staff->follow($r)), "Qo'shildi: Biwork HR") && Db::one("SELECT id FROM tools WHERE slug = 'hr'") !== null, "«Qo'shish» (HR) ishladi");
$r = $staff->post('/admin/tools', '/admin/tools/bundled', ['slug' => 'hr']);
$name1 = (string)Db::value("SELECT name FROM tools WHERE slug = 'hr'");
ok(str_contains($text($staff->follow($r)), "Allaqachon bor") && Db::value("SELECT COUNT(*) FROM tools WHERE slug = 'hr'") == 1, "qayta qo'shish: o'zgartirilmaydi, takrorlanmaydi");
$r = $staff->post('/admin/tools', '/admin/tools/bundled', ['slug' => 'yoq-mahsulot']);
ok(str_contains($text($staff->follow($r)), 'topilmadi'), "paketda yo'q mahsulot rad etiladi");
$r = $staff->post('/admin/tools', '/admin/tools/bundled', ['slug' => 'all']);
$body = $text($staff->follow($r));
ok(str_contains($body, 'Qo\'shildi:') && str_contains($body, "«Tez orada» holatida qo'shildi: Biwork Monitoring") && Db::value("SELECT COUNT(*) FROM tools WHERE slug IN ('hr','loyiha','savdo','moliya','yuridik','monitoring')") == 6, "«Hammasini qo'shish»: 6 ta mahsulot bazada, Monitoring «tez orada» deb xabar qilindi");
ok(Db::value("SELECT status FROM tools WHERE slug = 'monitoring'") === 'soon' && Db::value("SELECT trial_days FROM tools WHERE slug = 'monitoring'") !== null, "Monitoring bazada «tez orada» holatida");
ok(!str_contains($body, 'Tayyor mahsulotlar: '), "hammasi qo'shilgach «Tayyor mahsulotlar» bloki yo'qoladi");
$cnt = Db::value('SELECT COUNT(*) FROM tools');
Db::exec("DELETE FROM tools WHERE slug = 'yuridik'");
$anonB = $anon->post('/login', '/admin/tools/bundled', ['slug' => 'all']);
ok($anonB['status'] !== 200 && Db::value("SELECT COUNT(*) FROM tools WHERE slug = 'yuridik'") == 0, "kirishsiz «qo'shish» ishlamaydi");
$staff->post('/admin/tools', '/admin/tools/bundled', ['slug' => 'yuridik']);
ok(Db::value('SELECT COUNT(*) FROM tools') == $cnt, "o'chirilgan mahsulot qayta qo'shildi");

echo "== Sahifalar\n";
$cat = $anon->get('/mahsulotlar')['body'];
$sitemap = $anon->get('/sitemap.xml')['body'];
// «tez orada» mahsulotlar (Monitoring) ochiq ro'yxatda oxirida turadi, qolganlari manifestdagi tartibda
$names = array_map(static fn($s) => $bundled[$s]['name'], array_values(array_diff(array_keys($bundled), ['reja'])));
$isSoon = static fn(string $n) => (($bundled[array_search($n, array_map(static fn($b) => $b['name'], $bundled), true)]['status'] ?? 'active') === 'soon');
$order = array_merge(['Biwork Reja'], array_values(array_filter($names, static fn($n) => !$isSoon($n))), array_values(array_filter($names, $isSoon)));
$pos = array_map(static fn($n) => strpos($cat, $n), $order);
ok(!in_array(false, $pos, true) && $pos === array_values(array_unique($pos)) && $pos == (static function (array $p) { sort($p); return $p; })($pos), "katalogda " . count($order) . " ta mahsulot, manifestdagi tartibda («tez orada» oxirida): " . implode(' < ', $order));
foreach ($ALL as $s) {
    $m = $bundled[$s];
    $isSoon = in_array($s, $SOON, true);
    $mk = $m['marketing'];
    $r = $anon->get('/' . $s);
    $h = $text($r);
    preg_match('#<title>(.*?)</title>#', $r['body'], $tm);
    ok($r['status'] === 200 && str_starts_with(html_entity_decode($tm[1] ?? ''), $m['name']) && mb_strlen(html_entity_decode($tm[1] ?? '')) <= 70, "/$s ochiladi, sarlavha: " . html_entity_decode($tm[1] ?? ''));
    ok(str_contains($h, $mk['headline']) && str_contains($h, $mk['features'][0]['title']) && str_contains($h, $mk['faq'][0]['q']) && str_contains($h, $mk['problems'][0]['title']), "/$s: sarlavha, imkoniyat, muammo va savol sahifada");
    $faq = walkTypes(jsonldOf($r['body']), 'FAQPage');
    ok(count($faq) === 1 && count($faq[0]['mainEntity'] ?? []) === count($mk['faq']), "/$s: JSON-LD FAQPage da " . count($mk['faq']) . ' ta savol');
    ok(!str_contains($r['body'], '/register?tool=' . $s) && str_contains($h, $isSoon ? 'Chiqqanda xabar bering' : "So'rov qoldirish") && $isSoon === str_contains($r['body'], 'class="soon-tag"'), "/$s: demo manzil yo'q — «Sinab ko'rish» chiqmaydi, " . ($isSoon ? '«Chiqqanda xabar bering» va «Tez orada» belgisi bor' : '«So\'rov qoldirish» bor'));
    $n = preg_match_all('#/assets/products/' . $s . '/([a-z0-9-]+\.webp)#', $r['body'], $mm);
    $first = $mm[0][0] ?? '';
    $ir = $first !== '' ? $anon->get(preg_replace('#^.*?(/assets/)#', '$1', $first)) : ['status' => 0, 'headers' => []];
    ok($n >= count($mk['shots']) && $ir['status'] === 200 && str_contains((string)($ir['headers']['content-type'] ?? ''), 'webp'), "/$s: " . count($mk['shots']) . " ta ekran rasmi sahifada va ochiladi");
    ok(str_contains($r['body'], '/assets/products/' . $s . '/og.png') && $anon->get('/assets/products/' . $s . '/og.png')['status'] === 200, "/$s: ulashish rasmi (og.png) ko'rsatilgan");
    ok(str_contains($sitemap, '/' . $s . '</loc>'), "/$s: sitemap da");
    if ($isSoon) {
        ok(substr_count($r['body'], 'class="card plan"') === 0 && !str_contains($r['body'], 'id="olish"') && str_contains($h, $m['marketing']['cta']['lead_title']), "/$s: «tez orada»: tariflar va «qanday olinadi» yo'q, xabar olish shakli o'z sarlavhasi bilan");
        $catSoon = $anon->get('/mahsulotlar')['body'];
        ok(preg_match('#<article class="card tool-card soon">(?:(?!</article>).)*?' . preg_quote($m['name'], '#') . '#s', $catSoon) === 1 && str_contains(html_entity_decode($catSoon), 'Xabar olish'), "katalogda $s kartasi «tez orada» ko'rinishida («Xabar olish»)");
        continue;
    }
    ok(substr_count($r['body'], 'class="card plan"') === count($m['plans']) && str_contains($h, "Narx so'rov bo'yicha"), "/$s: tariflar narxsiz («Narx so'rov bo'yicha»), to'qib chiqarilgan narx yo'q");
}

echo "== Bitta demo manzilni bir nechta mahsulotga qo'yish\n";
$ids = array_map(static fn($s) => (int)Db::value('SELECT id FROM tools WHERE slug = ?', [$s]), $SLUGS);
$adm = $staff->get('/admin/tools')['body'];
$monId = (int)Db::value("SELECT id FROM tools WHERE slug = 'monitoring'");
ok(str_contains(html_entity_decode($adm), "Bitta demo manzilni bir nechta mahsulotga qo'yish") && !str_contains($adm, 'name="ids[]" value="' . $monId . '"'), "bir nechta mahsulotda manzil yo'q: tegishli blok ko'rinadi, «tez orada» Monitoring unda yo'q");
$r = $staff->post('/admin/tools', '/admin/tools/web-url', ['url' => 'ftp://yomon', 'ids' => $ids]);
$withUrl = static fn() => (int)Db::value("SELECT COUNT(*) FROM tools WHERE web_url != '' AND id IN (" . implode(',', $ids) . ')');
ok(str_contains($text($staff->follow($r)), 'https://') && $withUrl() === 0, "noto'g'ri manzil rad etiladi");
$r = $staff->post('/admin/tools', '/admin/tools/web-url', ['url' => 'https://demo.example.uz/', 'ids' => []]);
ok(str_contains($text($staff->follow($r)), 'Kamida bitta') && $withUrl() === 0, "mahsulot belgilanmasa rad etiladi");
$r = $staff->post('/admin/tools', '/admin/tools/web-url', ['url' => 'https://demo.example.uz/', 'ids' => $ids]);
ok($r['status'] === 303 && Db::value("SELECT COUNT(*) FROM tools WHERE web_url = 'https://demo.example.uz/'") == 5, "manzil 5 ta mahsulotga qo'yildi");
foreach ($SLUGS as $s) {
    $r = $anon->get('/' . $s);
    ok(str_contains($r['body'], '/register?tool=' . $s) && str_contains($text($r), "Sinab ko'rish"), "/$s: endi «Sinab ko'rish» tugmasi chiqdi");
}
ok(Db::value("SELECT COUNT(*) FROM tools WHERE slug = 'reja' AND web_url = 'https://demo.example.uz/'") == 0, "belgilanmagan mahsulot (Reja) o'zgarmadi");
foreach ($SLUGS as $s) {
    Db::exec("UPDATE tools SET web_url = '' WHERE slug = ?", [$s]);
}

echo "\n" . ($fails === 0 ? "HAMMASI O'TDI ($total)" : "$fails TA XATO ($total dan)") . "\n";
exit($fails === 0 ? 0 : 1);
