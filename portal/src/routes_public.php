<?php
declare(strict_types=1);

// ---------------------------------------------------------------- ochiq sayt: bosh sahifa, katalog, mahsulot sahifalari

/** Sahifaga SEO/ulashish ma'lumoti: description, canonical, image, type, jsonld[], noindex. */
$pub = static fn(string $title, array $seo = []): array => ['area' => 'public', 'title' => $title, 'seo' => $seo + ['canonical' => Util::absUrl('/'), 'image' => Marketing::ogImage()]];

$orgLd = static fn(): array => ['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => 'Biwork', 'url' => Util::absUrl('/'),
    'logo' => Util::absUrl('/assets/icon-512.png'), 'slogan' => 'Smart data. Better decisions.'];

/** Bosh sahifa; $err/$in — murojaat shaklidagi xato va kiritilgan qiymatlar (qayta chizish uchun). */
$renderHome = static function (string $err = '', array $in = [], int $status = 200) use ($pub, $orgLd): never {
    Marketing::captureSource();
    $tools = Catalog::tools();
    $names = array_map(static fn($t) => $t['name'], array_slice($tools, 0, 4));
    $desc = 'Biwork — korxonalar uchun tayyor dasturlar oilasi' . ($names ? ': ' . implode(', ', $names) : '') . ". Brauzerda bepul sinab ko'ring, sotib olsangiz o'z kompyuteringizga o'rnating. Interfeys o'zbek tilida.";
    View::page('home', ['tools' => $tools, 'leadErr' => $err, 'in' => $in],
        $pub('Biwork — korxonalar uchun avtomatlashtirish dasturlari', [
            'title' => 'Biwork — korxonalar uchun tayyor dasturlar | Bepul sinab ko\'ring', 'description' => $desc, 'type' => 'website',
            'jsonld' => [$orgLd(), ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => 'Biwork', 'url' => Util::absUrl('/'), 'inLanguage' => 'uz']],
        ]), $status);
};

Router::get('', static function () use ($renderHome): void {
    $renderHome();
});

Router::get('/mahsulotlar', static function () use ($pub): void {
    Marketing::captureSource();
    $tools = Catalog::tools();
    View::page('tools', ['tools' => $tools, 'user' => Auth::user()], $pub('Biwork mahsulotlari', [
        'title' => 'Biwork mahsulotlari — korxona dasturlari katalogi', 'canonical' => Util::absUrl('/mahsulotlar'),
        'description' => "Biwork mahsulotlari katalogi: har bir ish yo'nalishi uchun alohida dastur. Brauzerda sinab ko'ring yoki litsenziya olib o'z korxonangizga o'rnating.",
        'type' => 'website',
        'jsonld' => [['@context' => 'https://schema.org', '@type' => 'ItemList', 'itemListElement' => array_map(static fn($t, $i) => [
            '@type' => 'ListItem', 'position' => $i + 1, 'name' => $t['name'], 'url' => Util::absUrl('/' . $t['slug'])], $tools, array_keys($tools))]],
    ]));
});

// eski manzillar (qidiruv tizimi va ulashilgan havolalar uchun doimiy yo'naltirish)
Router::get('/tools', static function (): void {
    Util::redirect('/mahsulotlar', 301);
});
Router::get('/tools/{slug:[a-z0-9-]+}', static function (array $p): void {
    Util::redirect('/' . $p['slug'], 301);
});

/** Mahsulot reklama sahifasi: /<slug>. Yashirin mahsulotni faqat xodim ko'radi (oldindan ko'rish). */
$renderProduct = static function (string $slug, string $err = '', array $in = [], int $status = 200): never {
    $t = Catalog::tool($slug);
    $staff = (Auth::user()['role'] ?? '') === 'staff';
    if (!$t || ($t['status'] === 'hidden' && !$staff)) {
        throw new HttpError(404, 'Mahsulot topilmadi');
    }
    if ($t['status'] !== 'hidden') {
        Marketing::captureSource();
    }
    ProductPage::render($t, $err, $in, $status, $t['status'] === 'hidden' ? 'hidden' : 'live');
};

/** Admin yuklagan mahsulot rasmlari (data/uploads/tools/<id>/): yashirin mahsulotniki faqat xodimga. */
Router::get('/media/{id:\d+}/{file}', static function (array $p): void {
    $t = Catalog::toolById((int)$p['id']);
    $staff = (Auth::user()['role'] ?? '') === 'staff';
    $path = $t && ($t['status'] !== 'hidden' || $staff) ? Media::path((int)$t['id'], (string)$p['file']) : null;
    if ($path === null) {
        throw new HttpError(404, 'Rasm topilmadi');
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $mtime = (int)filemtime($path);
    $etag = '"' . dechex($mtime) . '-' . dechex((int)filesize($path)) . '"';
    header('Content-Type: ' . Media::mime($path));
    header('X-Content-Type-Options: nosniff');
    header('ETag: ' . $etag);
    header('Cache-Control: ' . ($t['status'] === 'hidden' ? 'private, no-cache' : 'public, max-age=31536000, immutable'));
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        exit;
    }
    header('Content-Length: ' . filesize($path));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        readfile($path);
    }
    exit;
});

Router::fallback(static function (string $method, string $path) use ($renderProduct): bool {
    if ($method !== 'GET' && $method !== 'HEAD') {
        return false;
    }
    if (!preg_match('#^/([a-z0-9][a-z0-9-]*)/?$#', $path, $mm) || in_array($mm[1], Catalog::RESERVED_SLUGS, true)) {
        return false;
    }
    $t = Catalog::tool($mm[1]);
    if (!$t) {
        // mahsulot nomi/manzili o'zgargan bo'lsa, eski havola yangisiga doimiy yo'naltiriladi
        $new = Db::one('SELECT t.slug, t.status FROM tool_slug_history h JOIN tools t ON t.id = h.tool_id WHERE h.slug = ?', [$mm[1]]);
        if ($new && $new['status'] !== 'hidden') {
            Util::redirect('/' . $new['slug'], 301);
        }
        return false;
    }
    if ($t['status'] === 'hidden' && (Auth::user()['role'] ?? '') !== 'staff') {
        return false;
    }
    $renderProduct($mm[1]);
});

// ---------------------------------------------------------------- murojaat qoldirish (hisobsiz)

Router::post('/sorov', static function () use ($renderHome, $renderProduct): void {
    $in = array_map(static fn($v) => is_string($v) ? $v : '', $_POST);
    $slug = preg_match('/^[a-z0-9-]{1,40}$/', $in['tool'] ?? '') ? $in['tool'] : '';
    $tool = $slug !== '' ? Catalog::tool($slug) : null;
    try {
        Leads::create($in);
    } catch (HttpError $e) {
        unset($in['csrf']);
        if ($tool && $tool['status'] !== 'hidden') {
            $renderProduct($tool['slug'], $e->getMessage(), $in, $e->status);
        }
        $renderHome($e->getMessage(), $in, $e->status);
    }
    Util::redirect('/rahmat' . ($tool ? '?t=' . $tool['slug'] : ''));
});

Router::get('/rahmat', static function (): void {
    $t = isset($_GET['t']) && is_string($_GET['t']) ? Catalog::tool($_GET['t']) : null;
    View::page('thanks', ['t' => $t], ['area' => 'public', 'title' => 'Rahmat', 'seo' => ['noindex' => true]]);
});

// ---------------------------------------------------------------- qidiruv tizimlari uchun

Router::get('/robots.txt', static function (): void {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    $bp = Util::basePath();
    echo "User-agent: *\nAllow: /\n";
    foreach (['/app', '/admin', '/api', '/login', '/register', '/setup', '/sorov', '/rahmat'] as $d) {
        echo 'Disallow: ' . $bp . $d . "\n";
    }
    echo "\nSitemap: " . Util::absUrl('/sitemap.xml') . "\n";
    exit;
});

Router::get('/sitemap.xml', static function (): void {
    header('Content-Type: application/xml; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    $x = static fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $urls = [[Util::absUrl('/'), null], [Util::absUrl('/mahsulotlar'), null]];
    $last = 0;
    foreach (Catalog::tools() as $t) {
        $urls[] = [Util::absUrl('/' . $t['slug']), (int)$t['updated_at']];
        $last = max($last, (int)$t['updated_at']);
    }
    $urls[0][1] = $urls[1][1] = $last ?: null;
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($urls as [$loc, $ts]) {
        echo '  <url><loc>' . $x($loc) . '</loc>' . ($ts ? '<lastmod>' . gmdate('Y-m-d', $ts) . '</lastmod>' : '') . "</url>\n";
    }
    echo "</urlset>\n";
    exit;
});

// ---------------------------------------------------------------- kirish / chiqish

/** Kirgandan keyin qayerga. */
$home = static function (array $u, string $next = ''): never {
    Util::redirect($u['role'] === 'staff' ? '/admin' : (Guard::safeNext($next) ?: '/app'));
};

Router::get('/login', static function () use ($home): void {
    if (Accounts::staffCount() === 0 && Auth::user() === null) {
        Util::redirect('/setup');
    }
    if ($u = Auth::user()) {
        $home($u, (string)($_GET['next'] ?? ''));
    }
    View::page('login', ['error' => '', 'email' => '', 'next' => Guard::safeNext($_GET['next'] ?? null)], ['area' => 'auth', 'title' => 'Kirish']);
});

Router::post('/login', static function () use ($home): void {
    $email = (string)($_POST['email'] ?? '');
    $next = Guard::safeNext($_POST['next'] ?? null);
    $res = Auth::attempt($email, (string)($_POST['password'] ?? ''));
    if ($res === 'ok') {
        $home(Auth::user(), $next);
    }
    $msg = $res === 'locked' ? "Juda ko'p urinish. " . intdiv(Auth::LOCK_SEC, 60) . " daqiqadan so'ng qayta urinib ko'ring" : "Email yoki parol noto'g'ri";
    View::page('login', ['error' => $msg, 'email' => mb_substr($email, 0, 120), 'next' => $next], ['area' => 'auth', 'title' => 'Kirish'], $res === 'locked' ? 429 : 401);
});

Router::post('/logout', static function (): void {
    Auth::logout();
    Util::redirect('/');
});

// ---------------------------------------------------------------- ro'yxatdan o'tish

Router::get('/register', static function () use ($home): void {
    Marketing::captureSource();
    if ($u = Auth::user()) {
        $home($u);
    }
    $tool = isset($_GET['tool']) ? Catalog::tool((string)$_GET['tool']) : null;
    View::page('register', ['error' => '', 'in' => [], 'tool' => $tool], ['area' => 'auth', 'title' => "Ro'yxatdan o'tish"]);
});

Router::post('/register', static function (): void {
    $in = array_map(static fn($v) => is_string($v) ? $v : '', $_POST);
    $tool = ($in['tool'] ?? '') !== '' ? Catalog::tool($in['tool']) : null;
    $fail = static function (string $msg, int $status) use ($in, $tool): never {
        unset($in['password']);
        View::page('register', ['error' => $msg, 'in' => $in, 'tool' => $tool], ['area' => 'auth', 'title' => "Ro'yxatdan o'tish"], $status);
    };
    if (!RateLimit::hit('register:' . Util::ip(), 5, 3600)) {
        $fail("Juda ko'p urinish. Keyinroq qayta urinib ko'ring", 429);
    }
    try {
        $uid = Accounts::register($in);
    } catch (HttpError $e) {
        $fail($e->getMessage(), $e->status);
    }
    if (($src = Marketing::source()) !== '') { // reklama qaysi manbadan kelganini korxona yozuviga qo'yamiz
        Db::exec('UPDATE customers SET notes = ? WHERE id = (SELECT customer_id FROM users WHERE id = ?)', ['Manba: ' . $src, $uid]);
    }
    Auth::login($uid);
    Auth::flash('ok', 'Hisob yaratildi. Xush kelibsiz!');
    Util::redirect($tool ? '/app/tools/' . $tool['slug'] : '/app');
});

// ---------------------------------------------------------------- birinchi xodim (bir martalik sozlash)

$setupKeyFile = static fn(): string => Config::dataDir() . '/setup_key.txt';
$setupKey = static function () use ($setupKeyFile): string {
    $f = $setupKeyFile();
    if (!is_file($f)) {
        @mkdir(dirname($f), 0775, true);
        file_put_contents($f, bin2hex(random_bytes(10)) . "\n", LOCK_EX);
        @chmod($f, 0600);
    }
    return trim((string)file_get_contents($f));
};

Router::get('/setup', static function () use ($setupKey): void {
    if (Accounts::staffCount() > 0) {
        Util::redirect('/login');
    }
    $setupKey();
    View::page('setup', ['error' => '', 'in' => []], ['area' => 'auth', 'title' => 'Birinchi sozlash']);
});

Router::post('/setup', static function () use ($setupKey, $setupKeyFile): void {
    if (Accounts::staffCount() > 0) {
        Util::redirect('/login');
    }
    $in = array_map(static fn($v) => is_string($v) ? $v : '', $_POST);
    $fail = static function (string $msg) use ($in): never {
        unset($in['password'], $in['key']);
        View::page('setup', ['error' => $msg, 'in' => $in], ['area' => 'auth', 'title' => 'Birinchi sozlash'], 422);
    };
    if (!RateLimit::hit('setup:' . Util::ip(), 8, 900)) {
        $fail("Juda ko'p urinish. 15 daqiqadan so'ng qayta urinib ko'ring");
    }
    if (!hash_equals($setupKey(), trim($in['key'] ?? ''))) {
        $fail("O'rnatish kaliti noto'g'ri");
    }
    try {
        $uid = Accounts::addStaff($in['email'] ?? '', $in['name'] ?? '', $in['password'] ?? '');
    } catch (HttpError $e) {
        $fail($e->getMessage());
    }
    @unlink($setupKeyFile());
    Auth::login($uid);
    Auth::flash('ok', 'Administrator yaratildi.');
    Util::redirect('/admin');
});
