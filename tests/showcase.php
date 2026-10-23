<?php
declare(strict_types=1);

/**
 * Ochiq sayt (reklama vitrinasi) sinovi: mahsulot sahifalari, SEO/ulashish teglari, sitemap/robots, rasmlar, murojaat (lead) oqimi,
 * reklama manbasi (utm), admin murojaatlar ro'yxati, Telegram xabari, bot himoyasi.
 * Ishga tushirish: bash tests/run.sh   (BASE, STAFF_EMAIL, STAFF_PASS, TG_LOG kerak; config.php da tests/run.sh dagi sozlamalar)
 */
require __DIR__ . '/../portal/src/bootstrap.php';

$BASE = rtrim(getenv('BASE') ?: 'http://127.0.0.1:8099', '/');
$STAFF = [getenv('STAFF_EMAIL') ?: 'admin@biwork.test', getenv('STAFF_PASS') ?: 'Admin-Parol-2026'];
$TG_LOG = getenv('TG_LOG') ?: '';
$fails = 0;
$total = 0;
require __DIR__ . '/helpers.php';

/** HTML dan <meta ...> qiymati: meta('name','description') */
function meta(string $html, string $attr, string $key): ?string
{
    if (preg_match('#<meta ' . $attr . '="' . preg_quote($key, '#') . '" content="([^"]*)"#', $html, $m)) {
        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
    }
    return null;
}
function jsonld(string $html): array
{
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
    return array_values(array_filter(array_map(static fn($j) => json_decode($j, true), $m[1])));
}
function manifestUpload(Browser $staff, array $manifest): string
{
    $mf = tempnam(sys_get_temp_dir(), 'bwmf');
    file_put_contents($mf, json_encode($manifest, JSON_UNESCAPED_UNICODE));
    $r = $staff->req('POST', '/admin/tools/register', ['csrf' => $staff->csrf('/admin/tools'), 'overwrite' => '1', 'manifest' => new CURLFile($mf, 'application/json', 'manifest.json')], ['multipart' => true]);
    @unlink($mf);
    return $staff->follow($r)['body'];
}

echo "== Birlik: reklama ma'lumotini tozalash, telefon\n";
$c = Marketing::clean(['headline' => str_repeat('x', 500), 'badges' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'], 'features' => [['title' => 'F', 'shot' => '../../etc/passwd'], ['title' => ''], 'junk'],
    'shots' => [['file' => 'ok.webp', 'caption' => 'c'], ['file' => 'x/y.webp'], ['file' => 'a.php'], ['file' => 'ok2.PNG']], 'evil' => '<script>', 'faq' => [['q' => 'Q', 'a' => 'A'], ['q' => '', 'a' => 'bo\'sh']]]);
ok(mb_strlen($c['headline']) === 160, 'sarlavha 160 belgigacha kesiladi');
ok(count($c['badges']) === 6, 'belgilar 6 tagacha');
ok(count($c['features']) === 1 && $c['features'][0]['shot'] === '', "xususiyat: yo'l o'tish (../) rasm nomi tashlanadi, bo'sh sarlavhali o'tkazib yuboriladi");
ok(count($c['shots']) === 1 && $c['shots'][0]['file'] === 'ok.webp', "rasm: faqat oddiy nomli .webp/.png/.jpg qabul qilinadi (yo'l, .php, katta harf — yo'q)");
ok(!isset($c['evil']) && count($c['faq']) === 1, "begona kalit tashlanadi, savolsiz FAQ yozuvi tashlanadi");
ok(Leads::phone('+998 (90) 123-45-67') === '+998901234567' && Leads::phone('901234567') === '+998901234567' && Leads::phone('12') === '' && Leads::phone('abc') === '', 'telefon raqami normallashtiriladi');

echo "== Mahsulot reklama sahifasi: /reja\n";
$anon = new Browser($BASE);
$r = $anon->get('/reja');
$h = $r['body'];
ok($r['status'] === 200, 'GET /reja 200');
ok(str_contains(meta($h, 'name', 'robots') ?? '', 'index'), 'qidiruv tizimi uchun ochiq (robots: index)');
preg_match('#<title>(.*?)</title>#', $h, $tm);
$title = html_entity_decode($tm[1] ?? '');
ok(str_starts_with($title, 'Biwork Reja') && mb_strlen($title) <= 70, "sarlavha (title): '$title'");
$desc = meta($h, 'name', 'description') ?? '';
ok(mb_strlen($desc) >= 60 && mb_strlen($desc) <= 180, 'tavsif (description) 60–180 belgi: ' . mb_strlen($desc));
ok(preg_match('#<link rel="canonical" href="' . preg_quote($BASE, '#') . '/reja">#', $h) === 1, 'canonical = qisqa manzil /reja');
ok(meta($h, 'property', 'og:title') === $title && meta($h, 'property', 'og:description') === $desc && meta($h, 'property', 'og:url') === $BASE . '/reja', 'Open Graph: title, description, url');
ok(meta($h, 'property', 'og:type') === 'website' && meta($h, 'property', 'og:locale') === 'uz_UZ' && meta($h, 'property', 'og:site_name') === 'Biwork', 'Open Graph: type, locale, site_name');
$og = (string)meta($h, 'property', 'og:image');
ok($og === $BASE . '/assets/products/reja/og.png' && meta($h, 'name', 'twitter:image') === $og, "ulashish rasmi mutlaq manzil: $og");
ok(meta($h, 'name', 'twitter:card') === 'summary_large_image' && meta($h, 'name', 'twitter:title') === $title, 'Twitter karta: summary_large_image');
$ld = jsonld($h);
$types = array_column($ld, '@type');
ok(in_array('SoftwareApplication', $types, true) && in_array('BreadcrumbList', $types, true) && in_array('FAQPage', $types, true), 'JSON-LD: SoftwareApplication, BreadcrumbList, FAQPage (' . implode(',', $types) . ')');
$faq = $ld[array_search('FAQPage', $types, true)];
ok(count($faq['mainEntity']) >= 3 && $faq['mainEntity'][0]['@type'] === 'Question', 'FAQ JSON-LD savol-javoblarni o\'z ichiga oladi');
ok(str_contains($h, 'Sinab ko&#039;rish') && str_contains($h, 'href="#sorov"') && str_contains($h, 'id="sorov"'), '"Sinab ko\'rish" va "So\'rov qoldirish" tugmalari, murojaat shakli bor');
ok(str_contains($h, 'action="/sorov#sorov"') && str_contains($h, 'name="website"') && str_contains($h, 'name="t"'), 'shakl: /sorov ga, bot tuzoqlari bilan');
ok(preg_match('#<h1[^>]*>[^<]{10,}</h1>#', $h) === 1 && substr_count($h, '<h1') === 1, 'sahifada bitta <h1>');
ok(str_contains($h, '/register?tool=reja'), "'Sinab ko'rish' ro'yxatdan o'tishga (mahsulot bilan) olib boradi");
ok(str_contains($h, 'Qaysi muammolarni hal qiladi') && str_contains($h, 'Nimalar qila oladi') && str_contains($h, 'Kimga kerak') && str_contains($h, "Ko'p so'raladigan savollar"), 'bo\'limlar: muammolar, imkoniyatlar, kimga kerak, savollar');
ok(preg_match_all('#<img src="[^"]*/assets/products/reja/[a-z]+\.webp\?v=\d+" width="\d+" height="\d+" alt="[^"]+"#', $h) >= 4, "ekran rasmlari o'lcham va alt bilan");
ok(str_contains($h, 'data-zoom'), 'rasmlar kattalashtiriladi (data-zoom)');
ok(!str_contains($h, 'EuroPrint'), "sahifada EuroPrint nomi yo'q");
ok(($r['headers']['x-content-type-options'] ?? '') === 'nosniff', 'X-Content-Type-Options');

echo "== Rasmlar\n";
foreach (['gantt', 'fifo', 'uskuna', 'tahlil', 'bosh'] as $n) {
    $x = $anon->get("/assets/products/reja/$n.webp");
    $sz = @getimagesizefromstring($x['body']);
    ok($x['status'] === 200 && $sz && $sz[0] >= 1200 && strlen($x['body']) < 400000, "$n.webp: {$sz[0]}x{$sz[1]}, " . intdiv(strlen($x['body']), 1024) . ' KB');
}
foreach (['/assets/products/reja/og.png', '/assets/og-default.png'] as $p) {
    $x = $anon->get($p);
    $sz = @getimagesizefromstring($x['body']);
    ok($x['status'] === 200 && $sz && $sz[0] === 1200 && $sz[1] === 630 && strlen($x['body']) < 600000, "$p: 1200x630, " . intdiv(strlen($x['body']), 1024) . ' KB');
}

echo "== Bosh sahifa va katalog\n";
$r = $anon->get('/');
$h = $r['body'];
ok($r['status'] === 200 && str_contains(meta($h, 'name', 'robots') ?? '', 'index'), 'bosh sahifa ochiq');
ok(meta($h, 'property', 'og:image') === $BASE . '/assets/og-default.png' && meta($h, 'name', 'twitter:card') === 'summary_large_image', 'bosh sahifa: umumiy ulashish rasmi');
ok(in_array('Organization', array_column(jsonld($h), '@type'), true) && in_array('WebSite', array_column(jsonld($h), '@type'), true), 'JSON-LD: Organization va WebSite');
ok(str_contains($h, 'href="/reja"') && str_contains($h, 'Biwork Reja'), 'bosh sahifada mahsulot kartasi /reja ga olib boradi');
ok(str_contains($h, 'id="sorov"'), 'bosh sahifada murojaat shakli');
$r = $anon->get('/mahsulotlar');
ok($r['status'] === 200 && str_contains($r['body'], 'Biwork mahsulotlari') && str_contains($r['body'], 'href="/reja"'), 'katalog /mahsulotlar');
ok(in_array('ItemList', array_column(jsonld($r['body']), '@type'), true), 'katalog: ItemList JSON-LD');
ok(preg_match('#<link rel="canonical" href="' . preg_quote($BASE, '#') . '/mahsulotlar">#', $r['body']) === 1, 'katalog canonical');

echo "== Manzillar: yo'naltirish, band nomlar, noindex\n";
$r = $anon->get('/tools');
ok($r['status'] === 301 && str_ends_with((string)$r['location'], '/mahsulotlar'), "/tools → 301 /mahsulotlar");
$r = $anon->get('/tools/reja');
ok($r['status'] === 301 && str_ends_with((string)$r['location'], '/reja'), "/tools/reja → 301 /reja");
ok($anon->get('/reja/')['status'] === 200, "/reja/ (oxirida chiziq) ham ochiladi");
ok($anon->get('/yoq-mahsulot')['status'] === 404, 'noma\'lum manzil 404');
ok(str_contains((string)$anon->get('/admin')['location'], '/login'), '/admin mahsulot sahifasi deb adashmaydi');
foreach (['/login', '/register', '/rahmat'] as $p) {
    $x = $anon->get($p);
    ok(str_contains(meta($x['body'], 'name', 'robots') ?? '', 'noindex') && !str_contains($x['body'], 'og:title'), "$p: noindex, ulashish teglari yo'q");
}
$x = $anon->get('/yoq-mahsulot');
ok(str_contains(meta($x['body'], 'name', 'robots') ?? '', 'noindex'), '404 sahifasi noindex');

echo "== sitemap.xml va robots.txt\n";
$r = $anon->get('/sitemap.xml');
$xml = @simplexml_load_string($r['body']);
ok($r['status'] === 200 && str_contains($r['headers']['content-type'] ?? '', 'xml') && $xml !== false, 'sitemap.xml to\'g\'ri XML');
$locs = $xml ? array_map('strval', array_column(json_decode(json_encode($xml), true)['url'] ?? [], 'loc')) : [];
ok(in_array($BASE . '/', $locs, true) && in_array($BASE . '/mahsulotlar', $locs, true) && in_array($BASE . '/reja', $locs, true), 'sitemap: bosh sahifa, katalog, /reja');
ok(!array_filter($locs, static fn($l) => str_contains($l, '/admin') || str_contains($l, '/app') || str_contains($l, '/login') || str_contains($l, '/rahmat')), 'sitemap: yopiq sahifalar yo\'q');
ok(preg_match('#<lastmod>\d{4}-\d{2}-\d{2}</lastmod>#', $r['body']) === 1, 'sitemap: lastmod');
$r = $anon->get('/robots.txt');
ok($r['status'] === 200 && str_contains($r['body'], 'Sitemap: ' . $BASE . '/sitemap.xml') && str_contains($r['body'], 'Disallow: /admin') && str_contains($r['body'], 'Disallow: /app') && str_contains($r['body'], 'Allow: /'), 'robots.txt: Sitemap, /admin va /app yopiq');

echo "== Murojaat qoldirish (lead)\n";
$ad = new Browser($BASE);
$ad->get('/reja?utm_source=facebook&utm_campaign=bahor&utm_medium=cpc');
$token = $ad->csrf('/reja');
$old = time() - 10;
$r = $ad->req('POST', '/sorov', ['name' => 'Sinov', 'phone' => '+998901112233', 'tool' => 'reja', 't' => $old]);
ok($r['status'] === 419, 'CSRFsiz murojaat rad etiladi (419)');
$r = $ad->req('POST', '/sorov', ['csrf' => $token, 'name' => 'A', 'phone' => '+998901112233', 'tool' => 'reja', 't' => $old]);
ok($r['status'] === 422 && str_contains($r['body'], 'Ismingizni yozing'), 'ism juda qisqa: 422 va xabar');
$r = $ad->req('POST', '/sorov', ['csrf' => $token, 'name' => 'Sinov Egasi', 'company' => 'Sinov MChJ', 'tool' => 'reja', 't' => $old]);
ok($r['status'] === 422 && str_contains($r['body'], 'telefon raqami yoki email') && str_contains($r['body'], 'value="Sinov Egasi"') && str_contains($r['body'], 'Biwork Reja'), "aloqa yo'q: 422, kiritilgan qiymatlar saqlanadi, mahsulot sahifasi qayta chiziladi");
ok(str_contains($r['body'], 'name="t" value="' . $old . '"'), "xato bilan qayta chizilgan shaklda vaqt belgisi (t) saqlanadi — tuzatib tez yuborgan odam bot deb hisoblanmaydi");
$r = $ad->req('POST', '/sorov', ['csrf' => $token, 'name' => 'Sinov Egasi', 'phone' => '12-34', 'tool' => 'reja', 't' => $old]);
ok($r['status'] === 422 && str_contains($r['body'], "Telefon raqami noto&#039;g&#039;ri"), "telefon noto'g'ri: 422");
$r = $ad->req('POST', '/sorov', ['csrf' => $token, 'name' => 'Sinov Egasi', 'email' => 'bu-email-emas', 'tool' => 'reja', 't' => $old]);
ok($r['status'] === 422 && str_contains($r['body'], 'Email manzili'), "email noto'g'ri: 422");
$r = $ad->req('POST', '/sorov', ['csrf' => $token, 'name' => 'Bot', 'phone' => '+998901119999', 'website' => 'http://spam.example', 'tool' => 'reja', 't' => $old]);
ok($r['status'] === 303 && str_starts_with((string)$r['location'], '/rahmat'), 'yashirin maydon to\'ldirilgan (bot): "muvaffaqiyatli" ko\'rinadi');
$r = $ad->req('POST', '/sorov', ['csrf' => $token, 'name' => 'Tezkor Bot', 'phone' => '+998901118888', 'tool' => 'reja', 't' => time()]);
ok($r['status'] === 303, 'forma 2 soniyadan tez yuborildi (bot): "muvaffaqiyatli" ko\'rinadi');
$r = $ad->req('POST', '/sorov', ['csrf' => $token, 'name' => '<script>alert(7)</script> Sinov', 'company' => 'Sinov MChJ', 'phone' => '+998 (90) 111-22-33', 'email' => 'Lead@Sinov.UZ', 'message' => "Salom\n<b>sex</b> haqida", 'tool' => 'reja', 't' => $old]);
ok($r['status'] === 303 && $r['location'] === '/rahmat?t=reja', "to'g'ri murojaat: 303 → /rahmat?t=reja");
$page = $ad->follow($r);
ok($page['status'] === 200 && str_contains($page['body'], 'Rahmat') && str_contains($page['body'], 'Biwork Reja') && str_contains($page['body'], '/register?tool=reja'), 'rahmat sahifasi (reklama konversiyasi manzili)');
$r = $ad->req('POST', '/sorov', ['csrf' => $token, 'name' => 'Umumiy Mijoz', 'phone' => '901234567', 'tool' => 'yoq-mahsulot', 't' => $old]);
ok($r['status'] === 303 && $r['location'] === '/rahmat', "mahsulotsiz (umumiy) murojaat ham qabul qilinadi");

echo "== Admin: murojaatlar\n";
$staff = new Browser($BASE);
$r = $staff->post('/login', '/login', ['email' => $STAFF[0], 'password' => $STAFF[1]]);
ok($r['status'] === 303, 'xodim kirdi');
ok(str_contains((string)$anon->get('/admin/leads')['location'], '/login'), '/admin/leads kirishsiz yopiq');
$cust = new Browser($BASE);
$cust->post('/register', '/register', ['company' => 'Murojaat Sinov', 'name' => 'Kim Kim', 'email' => 'kim@murojaat.uz', 'password' => 'Kuchli-Parol-9']);
ok($cust->get('/admin/leads')['status'] === 403, 'mijoz /admin/leads ga kira olmaydi (403)');
$pg = $staff->get('/admin/leads');
$b = $pg['body'];
ok($pg['status'] === 200 && str_contains($b, '&lt;script&gt;alert(7)&lt;/script&gt; Sinov') && !str_contains($b, '<script>alert(7)'), 'murojaat ro\'yxati; XSS ekranlanadi');
ok(str_contains($b, 'tel:+998901112233'), "telefon '+998 (90) 111-22-33' → +998901112233 shaklga keltirilgan");
ok(!str_contains($b, '+998901119999') && !str_contains($b, '+998901118888') && !str_contains($b, 'Tezkor Bot'), "bot murojaatlari (yashirin maydon, juda tez yuborish) saqlanmagan");
ok(str_contains($b, 'mailto:lead@sinov.uz'), 'email kichik harfga o\'tkazilgan');
ok(str_contains($b, 'utm_source=facebook') && str_contains($b, 'utm_campaign=bahor'), 'reklama manbasi (utm) murojaatga yozilgan');
ok(str_contains($b, 'Umumiy Mijoz') && str_contains($b, '+998901234567') && str_contains($b, 'umumiy'), 'mahsulotsiz (umumiy) murojaat ro\'yxatda');
ok(substr_count($b, 'class="card lead-row"') === 2, 'jami 2 ta haqiqiy murojaat');
ok(str_contains($staff->get('/admin')['body'], 'Yangi murojaatlar') && str_contains($staff->get('/admin')['body'], 'Murojaatlar'), 'boshqaruv panelida murojaatlar soni va menyu');
preg_match('#/admin/leads/(\d+)"#', $b, $lm);
$lid = $lm[1] ?? '0';
$r = $staff->req('POST', "/admin/leads/$lid", ['csrf' => $staff->csrf('/admin/leads'), 'status' => 'yoq', 'note' => 'x']);
ok($r['status'] === 422, "noto'g'ri holat rad etiladi");
$r = $staff->req('POST', "/admin/leads/$lid", ['csrf' => $staff->csrf('/admin/leads'), 'status' => 'contacted', 'note' => 'Qo\'ng\'iroq qilindi, ertaga demo', 'back' => '/admin/leads?status=all']);
ok($r['status'] === 303 && str_starts_with((string)$r['location'], '/admin/leads'), 'holat saqlandi');
$b = $staff->get('/admin/leads?status=contacted')['body'];
ok(str_contains($b, "Bog&#039;lanildi") && str_contains($b, 'Qo&#039;ng&#039;iroq qilindi, ertaga demo'), "holat va ichki izoh saqlangan");
$b = $staff->get('/admin/leads')['body'];
ok(substr_count($b, 'class="card lead-row"') === 1, '"Yangi" filtri: bog\'lanilgani yo\'qoldi');
$r = $staff->req('POST', '/admin/leads/99999', ['csrf' => $staff->csrf('/admin/leads'), 'status' => 'won', 'note' => '']);
ok($r['status'] === 404, 'mavjud bo\'lmagan murojaat 404');
$r = $staff->req('POST', "/admin/leads/$lid", ['csrf' => $staff->csrf('/admin/leads'), 'status' => 'won', 'note' => '', 'back' => 'https://evil.example/admin/leads']);
ok($r['status'] === 303 && !str_contains((string)$r['location'], 'evil'), "'back' orqali tashqi yo'naltirish yo'q");

echo "== Reklama manbasi: ro'yxatdan o'tish\n";
$reg = new Browser($BASE);
$reg->get('/?utm_source=instagram&utm_campaign=kuz');
$r = $reg->post('/register', '/register', ['company' => 'Manba Sinov MChJ', 'name' => 'Manba Egasi', 'email' => 'manba@sinov.uz', 'password' => 'Kuchli-Parol-5']);
ok($r['status'] === 303, "ro'yxatdan o'tdi");
$cl = $staff->get('/admin/customers?q=Manba')['body'];
preg_match('#/admin/customers/(\d+)#', $cl, $cm);
$cp = $staff->get('/admin/customers/' . ($cm[1] ?? '0'))['body'];
ok(str_contains($cp, 'utm_source=instagram'), "korxona yozuvida reklama manbasi: Manba: utm_source=instagram…");
$bad = new Browser($BASE);
$bad->get('/?utm_source=' . rawurlencode('<script>x</script>'));
$bad->get('/?utm_source=' . str_repeat('a', 60));
$r = $bad->post('/register', '/register', ['company' => 'Yomon Manba', 'name' => 'Yomon Manba', 'email' => 'yomon@sinov.uz', 'password' => 'Kuchli-Parol-6']);
$cl = $staff->get('/admin/customers?q=Yomon')['body'];
preg_match('#/admin/customers/(\d+)#', $cl, $cm);
$cp = $staff->get('/admin/customers/' . ($cm[1] ?? '0'))['body'];
ok(!str_contains($cp, 'Manba:') && !str_contains($cp, '<script>x'), "noto'g'ri utm qiymatlar (HTML, juda uzun) e'tiborga olinmaydi");

echo "== Sozlamalar: kuzatuv kodi, aloqa, Telegram\n";
$h = $anon->get('/reja')['body'];
ok(str_contains($h, '<script>/* bw-track */</script>'), "config tracking_html ochiq sahifa <head> iga qo'yiladi (Meta/Google/Yandex pikseli uchun)");
ok(!str_contains($anon->get('/login')['body'], 'bw-track') && !str_contains($staff->get('/admin')['body'], 'bw-track'), "kuzatuv kodi kirish va admin sahifalarida yo'q");
ok(str_contains($h, 'href="tel:+998712000000"') && str_contains($h, 'https://t.me/biwork_uz') && str_contains($h, 'mailto:salom@biwork.uz'), "pastki qismda config contact (telefon, Telegram, email)");
if ($TG_LOG !== '') {
    $lines = is_file($TG_LOG) ? array_filter(explode("\n", (string)file_get_contents($TG_LOG))) : [];
    $msgs = array_map(static fn($l) => json_decode($l, true), $lines);
    ok(count($msgs) === 2, 'Telegramga 2 ta xabar ketdi (bot murojaatlari — yo\'q): ' . count($msgs));
    $m0 = $msgs[0] ?? [];
    ok(str_contains($m0['uri'] ?? '', '/bot123456:') && str_ends_with($m0['uri'] ?? '', '/sendMessage') && ($m0['post']['chat_id'] ?? '') === '-1001234567', 'Telegram: bot manzili va chat_id');
    ok(str_contains($m0['post']['text'] ?? '', 'Yangi murojaat — Biwork Reja') && str_contains($m0['post']['text'] ?? '', '+998901112233') && str_contains($m0['post']['text'] ?? '', 'utm_source=facebook') && str_contains($m0['post']['text'] ?? '', '/admin/leads'), 'Telegram xabari: mahsulot, telefon, manba, admin havolasi');
}

echo "== Mahsulot holatlari: tez orada, yashirin, band nom, xavfli matn\n";
$base = ['contract' => 1, 'code' => 'SNS', 'status' => 'soon', 'icon' => 'users', 'sort' => 90, 'trial' => ['enabled' => false],
    'slug' => 'sinov-soon', 'name' => 'Biwork Sinov', 'tagline' => 'Tez orada chiqadigan sinov mahsuloti', 'description' => 'Tavsif',
    'marketing' => ['headline' => '<img src=x onerror=alert(1)> Sarlavha', 'lead' => 'Lead matn', 'seo' => ['title' => 'Biwork Sinov — kutilmoqda', 'description' => 'Sinov mahsuloti tavsifi, kamida oltmish belgidan iborat bo\'lishi uchun uzunroq matn yozildi.'],
        'features' => [['title' => 'Imkoniyat', 'shot' => 'yoq-rasm.webp', 'text' => '<b>qalin</b>']], 'shots' => [['file' => 'yoq-rasm.webp', 'caption' => 'x']]]];
$res = manifestUpload($staff, $base);
ok(str_contains($res, 'ro&#039;yxatga olindi') || str_contains($res, "ro'yxatga olindi") || str_contains($res, 'Biwork Sinov'), 'tez orada mahsuloti manifest orqali qo\'shildi');
$r = $anon->get('/sinov-soon');
$h = $r['body'];
ok($r['status'] === 200 && str_contains($h, 'Tez orada') && str_contains($h, 'Biwork Sinov chiqqanda xabar beramiz'), '"Tez orada" sahifasi: kutish ro\'yxati sarlavhasi');
ok(!str_contains($h, '/register?tool=sinov-soon') && !str_contains($h, 'id="olish"'), "'Tez orada' mahsulotda sinab ko'rish tugmasi va 'qanday olinadi' yo'q");
ok(!str_contains($h, '<img src=x') && str_contains($h, '&lt;img src=x'), 'sarlavhadagi HTML ekranlanadi (XSS yo\'q)');
ok(!str_contains($h, 'yoq-rasm.webp') && !str_contains($h, '<b>qalin</b>'), "diskda yo'q rasm sahifaga chiqmaydi, matndagi HTML ekranlanadi");
ok(in_array($BASE . '/sinov-soon', array_map('strval', array_column(json_decode(json_encode(simplexml_load_string($anon->get('/sitemap.xml')['body'])), true)['url'], 'loc')), true), 'tez orada mahsuloti sitemapda');
$r = $anon->req('POST', '/sorov', ['csrf' => $anon->csrf('/sinov-soon'), 'name' => 'Kutuvchi', 'email' => 'kut@sinov.uz', 'tool' => 'sinov-soon', 't' => time() - 10]);
ok($r['status'] === 303 && $r['location'] === '/rahmat?t=sinov-soon', "kutish ro'yxatiga yozildi");
ok(!str_contains($anon->follow($r)['body'], '/register?tool=sinov-soon'), "rahmat sahifasi 'tez orada' mahsulot uchun sinov tugmasini ko'rsatmaydi");
$base['slug'] = 'sinov-yashirin';
$base['code'] = 'SNY';
$base['status'] = 'hidden';
manifestUpload($staff, $base);
ok($anon->get('/sinov-yashirin')['status'] === 404, 'yashirin mahsulot sahifasi 404');
ok(!str_contains($anon->get('/sitemap.xml')['body'], 'sinov-yashirin'), 'sitemap: yashirin mahsulot yo\'q');
// faol mahsulot, lekin brauzer manzili yo'q: "sinab ko'rish" va'da qilinmaydi, faqat murojaat
$base['slug'] = 'sinov-aktiv';
$base['code'] = 'SNA';
$base['status'] = 'active';
$base['trial'] = ['enabled' => true, 'days' => 30];
$base['marketing'] = ['lead' => 'Faol mahsulot, brauzer manzili hali yo\'q.'];
manifestUpload($staff, $base);
$full = $anon->get('/sinov-aktiv')['body'];
$h = explode('Boshqa Biwork mahsulotlari', $full)[0]; // "boshqa mahsulotlar" kartalarida (Reja) sinov yozuvi bo'lishi mumkin
ok(!str_contains($h, '/register?tool=sinov-aktiv') && !str_contains($h, 'kun bepul sinov') && !str_contains($h, '<b>Brauzerda sinab ko\'ring</b>') && !str_contains($h, '<b>Brauzerda sinab ko&#039;ring</b>')
    && !preg_match('/name="description" content="[^"]*[Bb]rauzerda sinab/', $full), "brauzer manzili yo'q mahsulotda sinov va'da qilinmaydi (tugma, qadam, meta tavsif)");
ok(str_contains($h, 'href="#sorov"') && str_contains($h, "So'rov qoldiring"), "brauzer manzili yo'q mahsulotda murojaat asosiy harakat");
ok(str_contains($h, 'Masalan: qaysi jarayonni') && !str_contains($h, 'sexda nechta uskuna'), "murojaat shakli: boshqa mahsulotning namunasi ko'rinmaydi");
$r = $anon->req('POST', '/sorov', ['csrf' => $anon->csrf('/sinov-aktiv'), 'name' => 'Faol', 'phone' => '+998901112299', 'tool' => 'sinov-aktiv', 't' => time() - 10]);
ok(!str_contains($anon->follow($r)['body'], '/register?tool=sinov-aktiv'), "rahmat sahifasida ham ishlamaydigan sinov tugmasi yo'q");
ok(str_contains($anon->get('/reja')['body'], 'sexda nechta uskuna bor'), "Reja sahifasida o'z namuna savoli (manifest cta.placeholder)");
foreach (['admin', 'login', 'sitemap', 'mahsulotlar'] as $rs) {
    $res = manifestUpload($staff, ['slug' => $rs, 'code' => 'RSV', 'name' => 'Band'] + $base);
    ok(str_contains($res, 'boshqa sahifasiga band'), "band nom '$rs' manifestda rad etiladi");
}

echo "== Bot cheklovi\n";
$rl = new Browser($BASE);
$tk = $rl->csrf('/mahsulotlar');
$codes = [];
for ($i = 0; $i < 7; $i++) {
    $r = $rl->req('POST', '/sorov', ['csrf' => $tk, 'name' => 'Ko\'p ' . $i, 'phone' => '+99890555000' . $i, 't' => time() - 10]);
    $codes[] = $r['status'];
}
ok(in_array(429, $codes, true) && $codes[0] === 303, "bir IP dan soatiga 5 tadan ko'p murojaat 429 bilan to'xtatiladi: " . implode(',', $codes));

echo "\n" . ($fails === 0 ? "HAMMASI O'TDI ($total)" : "$fails TA XATO ($total dan)") . "\n";
exit($fails === 0 ? 0 : 1);
