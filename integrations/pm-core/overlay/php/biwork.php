<?php
/**
 * Biwork ulanishi — PM (boshqaruv tizimi) ni Biwork mahsulotlari ("Biwork HR", "Biwork Savdo", ...) sifatida ulaydi.
 * Shartnoma: biwork/docs/TOOL_CONTRACT.md. Umumiy tavsif: integrations/pm-core/README.md.
 *
 * Bu fayl index.php dan db.php'DAN OLDIN ulanadi (demo rejimida DATA_DIR ni shu yerda tanlash kerak) va faqat
 * `biwork-config.php` mavjud bo'lsagina ishlaydi — fayl yo'q bo'lsa PM avvalgidek mustaqil ishlaydi.
 *
 * Ikki rejim (biwork-config.php, 'mode'):
 *   demo — Biwork serveridagi namuna: faqat portaldan "Ochish" bilan kiriladi; har (korxona × mahsulot) uchun alohida
 *          sandbox (data/demo/c<id>-<slug>/ — shablondan ko'chiriladi), faqat shu mahsulotning xayoliy ma'lumoti.
 *   app  — mijoz o'zida o'rnatgan nusxa: har mahsulot alohida litsenziya kaliti bilan (data/biwork/lic/<slug>/),
 *          ochilgan modullar = faol litsenziyalar modullari birlashmasi. Litsenziyasiz — /licenziya sahifasi, API 402.
 *
 * Modul niqobi: user_perms() natijasi (php/auth.php dagi YAGONA hisoblash nuqtasi) mahsulotlarning `modules` ro'yxatiga
 * kesiladi. Server (state_for_user, korxona_qirq, endpointlar) ham, mijoz (me.bolimlar -> yorliqlar) ham shu natijadan oladi.
 * DIQQAT: bu litsenziya darvozasi va ko'rinish chegarasi, mutlaq himoya emas (PHP manba kod sifatida yetkaziladi).
 */
declare(strict_types=1);

if (defined('BIWORK_LOADED')) return;
define('BIWORK_LOADED', true);
define('BIWORK_ROOT', dirname(__DIR__));
require_once __DIR__ . '/BiworkConnect.php';
const BIWORK_SBX_COOKIE = 'bw_sbx';
const BIWORK_PRODUCT_VERSION = '1.0';

// ---------------------------------------------------------------------------------------------- sozlama va mahsulotlar

function biwork_cfg(): array {
    static $c = null;
    if ($c === null) {
        $f = BIWORK_ROOT . '/biwork-config.php';
        $x = is_file($f) ? (include $f) : [];
        $c = is_array($x) ? $x : [];
    }
    return $c;
}
function biwork_on(): bool { return (string)(biwork_cfg()['server'] ?? '') !== ''; }
function biwork_demo(): bool { return biwork_on() && (biwork_cfg()['mode'] ?? 'app') === 'demo'; }
/** Biwork o'z fayllari uchun papka — HAR DOIM asosiy data/ (demo sandbox ichida emas). */
function biwork_dir(string $sub = ''): string {
    $d = BIWORK_ROOT . '/data/biwork' . ($sub !== '' ? '/' . $sub : '');
    if (!is_dir($d)) @mkdir($d, 0775, true);
    return $d;
}
function biwork_e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** @return array<string,array> slug => mahsulot (biwork/products/<slug>.json) */
function biwork_products(): array {
    static $p = null;
    if ($p !== null) return $p;
    $p = [];
    foreach (glob(BIWORK_ROOT . '/biwork/products/*.json') ?: [] as $f) {
        $j = json_decode((string)file_get_contents($f), true);
        if (is_array($j) && preg_match('/^[a-z0-9][a-z0-9-]{1,30}$/', (string)($j['slug'] ?? ''))) $p[$j['slug']] = $j;
    }
    $only = biwork_cfg()['products'] ?? null;
    if (is_array($only) && $only) $p = array_intersect_key($p, array_flip(array_map('strval', $only)));
    ksort($p);
    return $p;
}
function biwork_public_keys(): string { return BIWORK_ROOT . '/biwork-public.json'; }

function biwork_client(string $slug, string $dataDir): BiworkConnect {
    return new BiworkConnect([
        'server' => (string)biwork_cfg()['server'], 'tool' => $slug, 'data_dir' => $dataDir,
        'public_keys' => biwork_public_keys(), 'app_version' => (string)(biwork_cfg()['app_version'] ?? BIWORK_PRODUCT_VERSION),
    ]);
}
function biwork_lic_client(string $slug): BiworkConnect {
    static $cl = [];
    return $cl[$slug] ??= biwork_client($slug, biwork_dir('lic/' . $slug));
}

// ---------------------------------------------------------------------------------------------- sandbox cookie (demo)

function biwork_secret(): string {
    static $s = null;
    if ($s !== null) return $s;
    $f = biwork_dir() . '/secret.key';
    if (!is_file($f)) { @file_put_contents($f, bin2hex(random_bytes(32)), LOCK_EX); @chmod($f, 0600); }
    return $s = trim((string)@file_get_contents($f)) ?: bin2hex(random_bytes(32));
}
function biwork_sign(string $v): string { return substr(hash_hmac('sha256', $v, biwork_secret()), 0, 32); }
function biwork_https(): bool {
    return (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443 || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}
function biwork_set_cookie(string $name, string $val, int $days = 30): void {
    setcookie($name, $val, ['expires' => $val === '' ? time() - 3600 : time() + $days * 86400, 'path' => '/',
        'httponly' => true, 'samesite' => 'Lax', 'secure' => biwork_https()]);
}
function biwork_sandbox_cookie_value(int $cust, string $slug): string {
    $v = $cust . '.' . $slug;
    return $v . '.' . biwork_sign($v);
}
/** @return ?array{cust:int,slug:string,dir:string} */
function biwork_sandbox_from_cookie(): ?array {
    $c = $_COOKIE[BIWORK_SBX_COOKIE] ?? null;
    if (!is_string($c) || !preg_match('/^(\d{1,9})\.([a-z0-9-]{2,31})\.([a-f0-9]{32})$/', $c, $m)) return null;
    if (!hash_equals(biwork_sign($m[1] . '.' . $m[2]), $m[3])) return null;
    $dir = biwork_sandbox_dir((int)$m[1], $m[2]);
    return is_file($dir . '/pm.db') && isset(biwork_products()[$m[2]]) ? ['cust' => (int)$m[1], 'slug' => $m[2], 'dir' => $dir] : null;
}
function biwork_sandbox_dir(int $cust, string $slug): string { return BIWORK_ROOT . '/data/demo/c' . $cust . '-' . $slug; }
function biwork_template_dir(string $slug): string { return BIWORK_ROOT . '/data/templates/' . $slug; }

function biwork_copy_dir(string $from, string $to): void {
    if (!is_dir($to) && !@mkdir($to, 0775, true)) throw new BiworkException('storage', "Namuna papkasini yaratib bo'lmadi");
    foreach (scandir($from) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        is_dir("$from/$f") ? biwork_copy_dir("$from/$f", "$to/$f") : copy("$from/$f", "$to/$f");
    }
}
function biwork_rm_dir(string $d): void {
    if (!is_dir($d)) return;
    foreach (scandir($d) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        is_dir("$d/$f") && !is_link("$d/$f") ? biwork_rm_dir("$d/$f") : @unlink("$d/$f");
    }
    @rmdir($d);
}
/** Shablondan (korxona × mahsulot) sandboxini tayyorlaydi. $reset = true: boshidan. */
function biwork_sandbox_prepare(int $cust, string $slug, bool $reset = false): string {
    $dir = biwork_sandbox_dir($cust, $slug);
    $tpl = biwork_template_dir($slug);
    if ($reset) biwork_rm_dir($dir);
    if (!is_file($dir . '/pm.db')) {
        if (!is_file($tpl . '/pm.db')) throw new BiworkException('no_template', "Bu mahsulotning namuna ma'lumoti hali tayyor emas.");
        biwork_copy_dir($tpl, $dir);
    }
    @touch($dir . '/pm.db');
    if (random_int(1, 12) === 1) biwork_sandbox_gc();
    return $dir;
}
/** Ishlatilmagan sandboxlarni o'chiradi (demo_ttl_days, standart 30). */
function biwork_sandbox_gc(): void {
    $ttl = max(1, (int)(biwork_cfg()['demo_ttl_days'] ?? 30)) * 86400;
    foreach (glob(BIWORK_ROOT . '/data/demo/c*-*', GLOB_ONLYDIR) ?: [] as $d) {
        $t = @filemtime($d . '/pm.db');
        if ($t !== false && time() - $t > $ttl) biwork_rm_dir($d);
    }
}

// ---------------------------------------------------------------------------------------------- sahifalar (PM dan mustaqil)

function biwork_page(int $code, string $title, string $body, string $extraHead = ''): never {
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    echo '<!doctype html><html lang="uz"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow"><title>' . biwork_e($title) . ' · Biwork</title>' . $extraHead . '<style>'
        . ':root{--bg:#f4f7f9;--card:#fff;--ink:#041C31;--mut:#5b6b78;--line:#dbe3e9;--g:#0A9F73;--r:#c43b3b}'
        . '@media(prefers-color-scheme:dark){:root{--bg:#07131d;--card:#0d1f2d;--ink:#e8f1f6;--mut:#93a6b3;--line:#1e3446;--g:#2FD39B;--r:#ff8a8a}}'
        . '*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:20px;background:var(--bg);color:var(--ink);font:15px/1.5 Inter,system-ui,sans-serif}'
        . '.c{width:100%;max-width:560px;background:var(--card);border:1px solid var(--line);border-radius:14px;padding:28px}'
        . 'h1{font-size:20px;margin:18px 0 6px}p{margin:8px 0}.m{color:var(--mut)}a{color:var(--g)}code{font-size:13px}'
        . '.b{display:inline-block;margin-top:14px;padding:10px 16px;border-radius:9px;background:var(--g);color:#fff;text-decoration:none;border:0;font:inherit;cursor:pointer}'
        . '.e{color:var(--r)}.p{border:1px solid var(--line);border-radius:10px;padding:12px 14px;margin:10px 0}.t{font-weight:600}'
        . 'input[type=text]{width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:9px;background:var(--bg);color:var(--ink);font:inherit;margin-top:6px}'
        . '</style></head><body><div class="c"><div style="font-weight:700;font-size:24px;letter-spacing:-.02em;color:var(--g)">Biwork</div>'
        . $body . '</div></body></html>';
    exit;
}

/** Demo rejimida sandbox cookie'si yo'q joyga kelgan odam: portalga yo'naltiriladi. */
function biwork_open_from_portal(): never {
    $srv = rtrim((string)biwork_cfg()['server'], '/');
    if (str_starts_with((string)($_SERVER['REQUEST_URI'] ?? ''), '/api/')) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo '{"error":"Namunani Biwork portalidan oching"}';
        exit;
    }
    biwork_page(401, 'Namunani portaldan oching', '<h1>Namunani Biwork portalidan oching</h1>'
        . '<p class="m">Bu namuna faqat Biwork portalidagi «Brauzerda ochish» tugmasi orqali ochiladi. Hisobingizga kiring va mahsulot sahifasidan boshlang.</p>'
        . '<a class="b" href="' . biwork_e($srv) . '">Biwork portaliga o\'tish</a>');
}

// ---------------------------------------------------------------------------------------------- erta yuklash (db.php'dan oldin)

/**
 * Demo rejimida: "Ochish" tokenini tekshiradi, sandboxni tayyorlaydi va DATA_DIR ni belgilaydi; token bo'lmasa cookie bo'yicha.
 * Sandbox topilmasa PM umuman ishga tushmaydi (asosiy data/pm.db yaratilmasin).
 */
function biwork_boot(): void {
    if (!biwork_demo() || defined('DATA_DIR')) return;
    try {
        $tok = $_GET['bw_launch'] ?? null;
        if (is_string($tok) && $tok !== '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $keys = BiworkConnect::loadKeys(biwork_public_keys());
            $aud = (string)(BiworkConnect::verifyToken('bwl1', $tok, $keys)['aud'] ?? '');
            $prod = biwork_products()[$aud] ?? null;
            if ($prod === null) throw new BiworkException('wrong_audience', 'Token bu serverdagi mahsulotlardan biriga berilmagan');
            $claims = biwork_client($aud, biwork_dir('launch'))->verifyLaunch($tok);
            $cust = (int)($claims['cust']['id'] ?? 0);
            if ($cust <= 0 || (int)($claims['user']['id'] ?? 0) <= 0) throw new BiworkException('bad_token', "Token foydalanuvchi ma'lumotini o'z ichiga olmagan");
            define('DATA_DIR', biwork_sandbox_prepare($cust, $aud));
            $GLOBALS['BW'] = ['product' => $prod, 'slug' => $aud, 'cust' => $cust, 'launch' => $claims];
            return;
        }
        $sbx = biwork_sandbox_from_cookie();
        if ($sbx !== null) {
            define('DATA_DIR', $sbx['dir']);
            $GLOBALS['BW'] = ['product' => biwork_products()[$sbx['slug']], 'slug' => $sbx['slug'], 'cust' => $sbx['cust']];
            return;
        }
    } catch (BiworkException $e) {
        biwork_page(403, 'Kirish amalga oshmadi', '<h1>Kirish amalga oshmadi</h1><p class="e">' . biwork_e($e->getMessage()) . '</p>'
            . '<a class="b" href="' . biwork_e(rtrim((string)biwork_cfg()['server'], '/')) . '">Biwork portaliga qaytish</a>');
    }
    biwork_open_from_portal();
}

// ---------------------------------------------------------------------------------------------- modul niqobi

/** Mahsulot `modules` yozuvlari -> ['bo'lim' yoki 'bo'lim.qism' => 'edit'|'view']. */
function biwork_module_caps(array $specs): array {
    $caps = [];
    foreach ($specs as $s) {
        if (!is_string($s) || !preg_match('/^([a-z]+(?:\.[a-z]+)?)(?::(view|edit))?$/', $s, $m)) continue;
        $cap = $m[2] ?? 'edit';
        if (!isset($caps[$m[1]]) || $cap === 'edit') $caps[$m[1]] = $cap;
    }
    return $caps;
}
/** Hozirgi so'rovda ochiq modullar: null = niqob yo'q (Biwork o'chiq), [] = hech narsa. */
function biwork_modules(): ?array {
    static $m = false;
    if ($m !== false) return $m;
    if (!biwork_on()) return $m = null;
    $prods = biwork_products();
    if (biwork_demo()) {
        $specs = $GLOBALS['BW']['product']['modules'] ?? [];
        return $m = biwork_module_caps($specs);
    }
    $enforce = (bool)(biwork_cfg()['enforce'] ?? true);
    $caps = [];
    foreach ($prods as $slug => $p) {
        if ($enforce && !biwork_lic_client($slug)->state()['allowed']) continue;
        foreach (biwork_module_caps($p['modules'] ?? []) as $k => $c) {
            if (!isset($caps[$k]) || $c === 'edit') $caps[$k] = $c;
        }
    }
    return $m = $caps;
}
/** Bo'lim (modul) shu so'rovda ochiqmi: Biwork o'chiq bo'lsa doim true (PM o'zgarishsiz). */
function biwork_has(string $section): bool {
    $m = biwork_modules();
    return $m === null || isset($m[$section]);
}
function biwork_cap(string $v, ?string $cap): string {
    if ($cap === null) return 'none';
    return perm_rank($v) <= perm_rank($cap) ? $v : $cap;
}
/** user_perms() natijasini ochiq modullarga kesadi; bo'lim darajasi qismlardan perms_hisobla() qoidasi bilan qayta hisoblanadi. */
function biwork_mask_perms(array $p): array {
    $mods = biwork_modules();
    if ($mods === null) return $p;
    $out = $p;
    foreach (SECTIONS as $s) {
        $parts = SECTION_PARTS[$s] ?? null;
        if ($parts === null) {
            $out[$s] = biwork_cap((string)($p[$s] ?? 'none'), $mods[$s] ?? null);
            continue;
        }
        $min = null;
        foreach ($parts as $q => $_n) {
            $k = $s . '.' . $q;
            $ck = $mods[$k] ?? null;
            $cs = $mods[$s] ?? null;   // qism va butun bo'lim ruxsatidan KUCHLISI olinadi (HR 'kontakt'=edit + Loyiha 'kontakt.royxat:view' → edit)
            $cap = ($ck !== null && $cs !== null) ? (perm_rank($ck) >= perm_rank($cs) ? $ck : $cs) : ($ck ?? $cs);
            $v = biwork_cap((string)($p[$k] ?? 'none'), $cap);
            $out[$k] = $v;
            if (!in_array($k, SECTION_PART_ADDITIV, true) && ($min === null || perm_rank($v) < perm_rank($min))) $min = $v;
        }
        $out[$s] = $min ?? 'none';
    }
    $out['biznes'] = $out['moliya.biznes'] ?? 'none';
    return $out;
}

// ---------------------------------------------------------------------------------------------- brend

function biwork_brand_name(): string {
    if (biwork_demo()) return (string)($GLOBALS['BW']['product']['name'] ?? 'Biwork');
    $names = [];
    foreach (biwork_products() as $slug => $p) {
        if (!(bool)(biwork_cfg()['enforce'] ?? true) || biwork_lic_client($slug)->state()['allowed']) $names[] = (string)$p['name'];
    }
    return count($names) === 1 ? $names[0] : 'Biwork';
}
function biwork_brand_sub(): string {
    return biwork_demo() ? (string)($GLOBALS['BW']['product']['sub'] ?? 'Boshqaruv tizimi') : 'Boshqaruv tizimi';
}
/** PM sahifalaridagi Avtosanoat Invest brendini Biwork mahsuloti bilan almashtiradi. */
function biwork_brand(string $html): string {
    $n = biwork_e(biwork_brand_name());
    $html = preg_replace('#(<img class="brand-icon" src=")data:image/[a-z]+;base64,[^"]+#', '$1/assets/logo.png', $html) ?? $html;
    $html = str_replace(
        ['Avtosanoat Invest &mdash; boshqaruv tizimi', 'Avtosanoat Invest — Boshqaruv tizimi', 'Kirish — Avtosanoat Invest', 'Boshqaruv — Avtosanoat Invest',
         '<div class="brand-mark">Avtosanoat Invest</div>', 'ASI Boshqaruv', '<div class="brand-sub">Boshqaruv tizimi</div>'],
        [$n, $n, 'Kirish — ' . $n, 'Boshqaruv — ' . $n, '<div class="brand-mark">' . $n . '</div>', $n,
         '<div class="brand-sub">' . biwork_e(biwork_brand_sub()) . '</div>'],
        $html
    );
    // qolgan matnlar (tarjima lug'ati: «Вход — Avtosanoat Invest», ...) ham shu brend bilan
    return str_replace('Avtosanoat Invest', $n, $html);
}
/** Asosiy sahifa (Main.html): brend + demo belgisi. */
function biwork_html(string $html): string {
    if (!biwork_on()) return $html;
    $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;   // ichki izohlar (haqiqiy tashkilot nomlari bor) mijozga chiqmasin
    $html = biwork_brand($html);
    $css = (string)(($GLOBALS['BW']['product']['css'] ?? '') ?: '');
    $extra = ($css !== '' ? '<style id="bwProductCss">' . $css . '</style>' : '');
    if (biwork_demo()) $extra .= biwork_demo_banner();
    return $extra === '' ? $html : (str_contains($html, '</body>') ? str_replace('</body>', $extra . '</body>', $html) : $html . $extra);
}
function biwork_demo_banner(): string {
    $srv = biwork_e(rtrim((string)biwork_cfg()['server'], '/'));
    $name = biwork_e(biwork_brand_name());
    return '<div id="bwDemoBar" role="status"><span><b>' . $name . '</b> · namuna (xayoliy ma\'lumot)</span>'
        . '<button type="button" id="bwReset" title="Namunani boshlang\'ich holatiga qaytarish">Boshidan</button>'
        . '<a href="' . $srv . '/app">Biwork portaliga qaytish</a></div>'
        . '<style>#bwDemoBar{position:fixed;left:12px;bottom:12px;z-index:2147483000;display:flex;gap:10px;align-items:center;'
        . 'padding:7px 12px;border-radius:999px;background:#041C31;color:#e8f1f6;font:12.5px/1.2 Inter,system-ui,sans-serif;box-shadow:0 4px 18px rgba(0,0,0,.25)}'
        . '#bwDemoBar b{color:#2FD39B;font-weight:600}#bwDemoBar a,#bwDemoBar button{color:#2FD39B;background:none;border:0;padding:0;font:inherit;cursor:pointer;text-decoration:underline}'
        . '@media(max-width:640px){#bwDemoBar{left:6px;right:6px;bottom:6px;border-radius:12px;flex-wrap:wrap}}@media print{#bwDemoBar{display:none}}</style>'
        . '<script>document.getElementById("bwReset").addEventListener("click",function(){if(!confirm("Namuna boshlang\'ich holatiga qaytarilsinmi? Kiritgan o\'zgarishlaringiz o\'chadi."))return;'
        . 'fetch("/bw/reset",{method:"POST",headers:{"X-Biwork":"1"},credentials:"same-origin"}).then(function(){location.href="/bw/relogin"})});</script>';
}

// ---------------------------------------------------------------------------------------------- foydalanuvchi va sessiya

/** Portal foydalanuvchisiga mos PM hisobini topadi/yaratadi (parolsiz: tasodifiy noma'lum parol). */
function biwork_user_for(array $claims, string $role): array {
    $u = $claims['user'] ?? [];
    $login = 'bw-' . (int)($claims['cust']['id'] ?? 0) . '-' . (int)($u['id'] ?? 0);
    $name = mb_substr(trim((string)($u['name'] ?? '')) ?: $login, 0, 100);
    $row = find_user($login);
    if ($row && !(int)$row['active']) throw new BiworkException('disabled', "Bu foydalanuvchi tizimda o'chirilgan. Administratorga murojaat qiling.");
    if (!$row) {
        $row = create_user($login, bin2hex(random_bytes(24)), $role, $name);
        /* namunada: majburiy parol almashtirish va Navigator qo'llanmasi (butun PM haqida) o'chiq */
        db()->prepare('UPDATE users SET parol_ozgartir = 0, tanishuv = ?, yolboshchi = ? WHERE id = ?')->execute([biwork_demo() ? 99 : 0, biwork_demo() ? 0 : 1, (int)$row['id']]);
    } elseif ($row['role'] !== $role || $row['full_name'] !== $name) {
        db()->prepare('UPDATE users SET role = ?, full_name = ? WHERE id = ?')->execute([norm_role($role), $name, (int)$row['id']]);
    }
    return find_user($login);
}
function biwork_login_redirect(array $user, string $home): never {
    $token = create_session((int)$user['id']);
    audit($user['username'], 'login', null, null, 'Biwork portali orqali');
    send_session_cookie($token);
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    header('Location: /' . $home, true, 303);
    exit;
}
function biwork_finish_launch_demo(): never {
    $b = $GLOBALS['BW'];
    $c = $b['launch'];
    // namuna: umumiy xayoliy ma'lumotda ishlash uchun "rahbar" (modul niqobi bilan chegaralanadi), tizim sozlamalarisiz
    $user = biwork_user_for($c, 'rahbar');
    biwork_set_cookie(BIWORK_SBX_COOKIE, biwork_sandbox_cookie_value((int)$b['cust'], (string)$b['slug']));
    biwork_login_redirect($user, (string)($b['product']['home'] ?? ''));
}
function biwork_finish_launch_app(): never {
    try {
        $tok = (string)($_GET['bw_launch'] ?? '');
        $keys = BiworkConnect::loadKeys(biwork_public_keys());
        $aud = (string)(BiworkConnect::verifyToken('bwl1', $tok, $keys)['aud'] ?? '');
        if (!isset(biwork_products()[$aud])) throw new BiworkException('wrong_audience', 'Token bu o\'rnatmadagi mahsulotlardan biriga berilmagan');
        $claims = biwork_lic_client($aud)->verifyLaunch($tok);
        $role = ($claims['mode'] ?? 'demo') === 'live' && ($claims['user']['tool_role'] ?? 'user') === 'admin' ? 'admin' : 'user';
        $user = biwork_user_for($claims, $role);
    } catch (BiworkException $e) {
        biwork_page(403, 'Kirish amalga oshmadi', '<h1>Kirish amalga oshmadi</h1><p class="e">' . biwork_e($e->getMessage()) . '</p>'
            . '<a class="b" href="' . biwork_e(rtrim((string)biwork_cfg()['server'], '/')) . '">Biwork portaliga qaytish</a>');
    }
    biwork_login_redirect($user, '');
}

// ---------------------------------------------------------------------------------------------- litsenziya (app rejimi)

/** null = ruxsat; aks holda: ['none' => ...] barcha mahsulotlar bo'yicha holatlar. */
function biwork_gate(): ?array {
    if (!biwork_on() || biwork_demo() || !(bool)(biwork_cfg()['enforce'] ?? true)) return null;
    $states = [];
    foreach (biwork_products() as $slug => $_p) {
        $st = biwork_lic_client($slug)->ensure();
        if ($st['allowed']) return null;
        $states[$slug] = $st;
    }
    return $states ?: ['-' => ['allowed' => false, 'state' => 'none', 'message' => "Bu o'rnatmada mahsulot belgilanmagan"]];
}
function biwork_csrf(): string { return hash_hmac('sha256', (string)($_COOKIE[COOKIE_NAME] ?? ''), biwork_secret()); }
const BIWORK_STATE_UZ = ['valid' => 'Faol', 'grace' => 'Faol (onlayn tekshiruv kerak)', 'none' => 'Faollashtirilmagan', 'expired' => 'Muddati tugagan',
    'locked' => 'Onlayn tekshiruv kerak', 'blocked' => 'Bloklangan', 'invalid' => 'Yaroqsiz', 'clock' => "Soat noto'g'ri"];

function biwork_license_page(string $method): never {
    if (biwork_demo() || !biwork_on()) { http_response_code(404); exit('Topilmadi'); }
    $user = auth_get_user();
    if (!$user) { header('Location: /login.html', true, 302); exit; }
    $isAdmin = in_array($user['role'], ['admin', 'superadmin'], true);
    $msg = $err = '';
    if ($method === 'POST') {
        $op = (string)($_POST['op'] ?? 'activate');
        $slugOp = (string)($_POST['slug'] ?? '');
        if (!$isAdmin) $err = 'Litsenziyani faqat administrator boshqaradi';
        elseif (!hash_equals(biwork_csrf(), (string)($_POST['csrf'] ?? ''))) $err = "Sahifa eskirgan. Qayta urinib ko'ring.";
        else {
            try {
                if ($op === 'activate') {
                    $key = strtoupper(preg_replace('/\s+/', '', trim((string)($_POST['key'] ?? ''))) ?? '');
                    if (!preg_match('/^BW-([A-Z0-9]{3})-/', $key, $m)) throw new BiworkException('invalid_key', "Kalit shakli noto'g'ri (BW-XXX-XXXX-XXXX-XXXX)");
                    $slug = '';
                    foreach (biwork_products() as $s => $p) if (strcasecmp((string)($p['code'] ?? ''), $m[1]) === 0) $slug = $s;
                    if ($slug === '') throw new BiworkException('tool_mismatch', 'Bu kalit shu o\'rnatmadagi mahsulotlarga tegishli emas');
                    biwork_lic_client($slug)->activate($key);
                    $msg = biwork_products()[$slug]['name'] . ' litsenziyasi faollashtirildi.';
                } elseif (isset(biwork_products()[$slugOp])) {
                    $cl = biwork_lic_client($slugOp);
                    if ($op === 'deactivate') { $cl->deactivate(); $msg = "Litsenziya shu qurilmadan olib tashlandi. O'rindiq bo'shatildi."; }
                    else { $cl->refresh(); $msg = 'Litsenziya serverda tekshirildi.'; }
                }
                audit($user['username'], 'biwork-litsenziya', null, null, $op . ' ' . $slugOp);
            } catch (BiworkException $e) { $err = $e->getMessage(); }
        }
    }
    $rows = '';
    foreach (biwork_products() as $slug => $p) {
        $cl = biwork_lic_client($slug);
        $st = $cl->state();
        $lic = $st['license'] ?? null;
        $det = '';
        if ($lic) {
            $det = '<div class="m">Korxona: <b>' . biwork_e($lic['cust']['name'] ?? '') . '</b> · Tarif: ' . biwork_e($lic['plan'] ?? '') . ' · O\'rindiq: ' . (int)($lic['seats'] ?? 0)
                . '<br>Muddat: ' . (!empty($lic['exp']) ? biwork_e(date('d.m.Y', (int)$lic['exp'])) . ($st['days_left'] !== null ? ' (' . (int)$st['days_left'] . ' kun)' : '') : 'muddatsiz') . '</div>';
        }
        $btn = '';
        if ($isAdmin && $lic) {
            foreach (['refresh' => 'Hozir tekshirish', 'deactivate' => 'Qurilmadan olib tashlash'] as $o => $l) {
                $btn .= '<form method="post" action="/licenziya" style="display:inline" ' . ($o === 'deactivate' ? 'onsubmit="return confirm(\'Litsenziya shu qurilmadan olib tashlansinmi?\')"' : '') . '>'
                    . '<input type="hidden" name="csrf" value="' . biwork_e(biwork_csrf()) . '"><input type="hidden" name="op" value="' . $o . '"><input type="hidden" name="slug" value="' . biwork_e($slug) . '">'
                    . '<button class="b" style="margin:8px 8px 0 0;padding:6px 12px;font-size:13px" type="submit">' . $l . '</button></form>';
            }
        }
        $rows .= '<div class="p"><div class="t">' . biwork_e($p['name']) . ' — ' . biwork_e(BIWORK_STATE_UZ[$st['state']] ?? $st['state']) . '</div><div class="m">' . biwork_e($st['message']) . '</div>' . $det . $btn . '</div>';
    }
    $form = $isAdmin
        ? '<form method="post" action="/licenziya"><input type="hidden" name="csrf" value="' . biwork_e(biwork_csrf()) . '"><input type="hidden" name="op" value="activate">'
          . '<label class="t" style="display:block;margin-top:14px">Litsenziya kaliti</label><input type="text" name="key" placeholder="BW-XXX-XXXX-XXXX-XXXX" autocomplete="off" spellcheck="false" required>'
          . '<button class="b" type="submit">Faollashtirish</button></form>'
        : '<p class="m">Litsenziyani faollashtirish uchun administrator hisobi bilan kiring.</p>';
    $ok = biwork_gate() === null;
    biwork_page(200, 'Litsenziya', '<h1>Litsenziya</h1><p class="m">' . ($ok ? 'Quyida mahsulotlar litsenziyasi.' : 'Tizimni ishlatish uchun kamida bitta mahsulot litsenziyasi faol bo\'lishi kerak.') . '</p>'
        . ($msg !== '' ? '<p><b>' . biwork_e($msg) . '</b></p>' : '') . ($err !== '' ? '<p class="e" role="alert">' . biwork_e($err) . '</p>' : '') . $rows . $form
        . ($ok ? '<p><a href="/">← Tizimga qaytish</a></p>' : ''));
}

// ---------------------------------------------------------------------------------------------- marshrut (PM yuklangach)

function biwork_serve_file(string $path, string $mime): never {
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=3600');
    header('Content-Length: ' . (string)filesize($path));
    readfile($path);
    exit;
}

/** index.php dan: $method belgilangach, /api/ va statik marshrutlardan OLDIN. */
function biwork_route(string $uri, string $method): void {
    if (!biwork_on()) return;
    // 1) portaldan "Ochish"
    if (!empty($GLOBALS['BW']['launch'])) biwork_finish_launch_demo();
    if (!biwork_demo() && $method === 'GET' && isset($_GET['bw_launch']) && !empty(biwork_cfg()['sso'])) biwork_finish_launch_app();

    // 2) demo: namunani boshidan boshlash (sandbox shablondan qayta ko'chiriladi)
    if ($uri === '/bw/reset' && $method === 'POST' && biwork_demo()) {
        if (($_SERVER['HTTP_X_BIWORK'] ?? '') !== '1') { http_response_code(403); exit; }
        $b = $GLOBALS['BW'];
        // sandbox bazasi almashadi -> sessiya ham yo'qoladi: foydalanuvchini belgilab qo'yamiz, /bw/relogin uni yangi bazada qayta kiritadi
        $cu = function_exists('auth_get_user') ? auth_get_user() : null;
        if ($cu && preg_match('/^bw-\d+-(\d+)$/', (string)$cu['username'], $m)) {
            $v = $m[1] . '|' . (string)($cu['full_name'] ?? '');
            biwork_set_cookie('bw_rl', base64_encode($v) . '.' . biwork_sign($v), 1);
        }
        try { biwork_sandbox_prepare((int)$b['cust'], (string)$b['slug'], true); } catch (BiworkException $e) { http_response_code(503); exit; }
        header('Content-Type: application/json');
        echo '{"ok":true}';
        exit;
    }
    if ($uri === '/bw/relogin' && $method === 'GET' && biwork_demo()) {
        $b = $GLOBALS['BW'];
        $raw = (string)($_COOKIE['bw_rl'] ?? '');
        [$enc, $sig] = array_pad(explode('.', $raw, 2), 2, '');
        $v = (string)base64_decode($enc, true);
        biwork_set_cookie('bw_rl', '', -1);
        if ($v !== '' && hash_equals(biwork_sign($v), $sig)) {
            [$uid, $name] = array_pad(explode('|', $v, 2), 2, '');
            $user = biwork_user_for(['cust' => ['id' => (int)$b['cust']], 'user' => ['id' => (int)$uid, 'name' => $name]], 'rahbar');
            biwork_login_redirect($user, (string)($b['product']['home'] ?? ''));
        }
        header('Location: /', true, 303);
        exit;
    }

    // 3) brend: statik fayllar
    $brand = BIWORK_ROOT . '/biwork/brand/';
    $map = ['/assets/logo.png' => ['logo.png', 'image/png'], '/assets/ikona-192.png' => ['icon-192.png', 'image/png'], '/assets/ikona-512.png' => ['icon-512.png', 'image/png'],
        '/favicon.ico' => ['icon-192.png', 'image/png'], '/apple-touch-icon.png' => ['icon-192.png', 'image/png'], '/apple-touch-icon-precomposed.png' => ['icon-192.png', 'image/png'],
        '/assets/bino.webp' => ['bino.webp', 'image/webp']];
    if ($method === 'GET' && isset($map[$uri]) && is_file($brand . $map[$uri][0])) biwork_serve_file($brand . $map[$uri][0], $map[$uri][1]);
    if ($method === 'GET' && $uri === '/manifest.webmanifest') {
        $mf = json_decode((string)@file_get_contents(BIWORK_ROOT . '/assets/manifest.webmanifest'), true);
        if (is_array($mf)) {
            $mf['name'] = biwork_brand_name() . ' — boshqaruv tizimi';
            $mf['short_name'] = biwork_brand_name();
            header('Content-Type: application/manifest+json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode($mf, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }
    if ($method === 'GET' && ($uri === '/login' || $uri === '/login.html') && is_file(BIWORK_ROOT . '/pages/login.html')) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo biwork_brand(preg_replace('/<!--.*?-->/s', '', (string)file_get_contents(BIWORK_ROOT . '/pages/login.html')) ?? '');
        exit;
    }
    if ($method === 'GET' && ($uri === '/admin' || $uri === '/admin.html') && is_file(BIWORK_ROOT . '/pages/admin.html')) {
        $u = auth_get_user();
        if ($u && in_array($u['role'], ['admin', 'superadmin', 'kadmin'], true)) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
            echo biwork_brand(preg_replace('/<!--.*?-->/s', '', (string)file_get_contents(BIWORK_ROOT . '/pages/admin.html')) ?? '');
            exit;
        }
    }

    // 4) yopiq modulning FONDA so'raladigan endpointlari: 403 o'rniga bo'sh javob (brauzer konsoli va jurnal toza qolsin)
    $mods = biwork_modules();
    if ($method === 'GET' && $mods !== null && !isset($mods['hujjat']) && $uri === '/api/kutubxona') {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo '{"hujjatlar":[],"yonalishlar":[],"boshqaradi":false,"cheklov":[],"umumiyYonalish":false,"bolimlar":{},"mening":{"bolim":""}}';
        exit;
    }

    // 5) litsenziya sahifasi va darvozasi (app rejimi)
    if ($uri === '/licenziya') biwork_license_page($method);
    if (biwork_demo()) return;
    $gate = biwork_gate();
    if ($gate === null) return;
    if (str_starts_with($uri, '/api/')) {
        if (preg_match('#^/api/(login|logout|me|health)(/.*)?$#', $uri)) return;
        http_response_code(402);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => false, 'error' => ['code' => 'license_required', 'message' => 'Faol Biwork litsenziyasi kerak. Administrator /licenziya sahifasida kalitni kiritadi.']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($method === 'GET' && in_array($uri, ['/', '/Main.html', '/index.html', '/index.php'], true) && auth_get_user()) {
        header('Location: /licenziya', true, 302);
        exit;
    }
}

biwork_boot();
