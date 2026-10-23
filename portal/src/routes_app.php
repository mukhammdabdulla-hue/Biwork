<?php
declare(strict_types=1);

/** Mijoz maydoni (/app): Mening mahsulotlarim, sinov, so'rov, ochish, yuklab olish, qurilmalar, jamoa, profil. */

/** Mahsulot sahifasi uchun ma'lumotlar. */
$toolOr404 = static function (string $slug): array {
    $t = Catalog::tool($slug);
    if (!$t || $t['status'] === 'hidden') {
        throw new HttpError(404, 'Mahsulot topilmadi');
    }
    return $t;
};
$canDownload = static fn(?array $l): bool => $l !== null && $l['type'] !== 'trial' && $l['state'] === 'active';

Router::get('/app', static function (): void {
    $u = Guard::customer();
    $cid = (int)$u['customer_id'];
    $rows = [];
    foreach (Catalog::tools() as $t) {
        $rows[] = ['tool' => $t, 'lic' => Licenses::best($cid, (int)$t['id'])];
    }
    // avval litsenziyasi borlar, keyin qolganlari
    usort($rows, static fn($a, $b) => [$a['lic'] ? 0 : 1, $a['tool']['sort']] <=> [$b['lic'] ? 0 : 1, $b['tool']['sort']]);
    View::page('app_home', ['rows' => $rows, 'user' => $u], ['area' => 'app', 'nav' => 'app', 'title' => 'Mening mahsulotlarim']);
});

Router::get('/app/tools/{slug:[a-z0-9-]+}', static function (array $p) use ($toolOr404, $canDownload): void {
    $u = Guard::customer();
    $t = $toolOr404($p['slug']);
    $cid = (int)$u['customer_id'];
    $all = Licenses::forCustomer($cid, (int)$t['id']);
    $best = Licenses::best($cid, (int)$t['id']);
    $devices = [];
    foreach ($all as $l) {
        if ($l['state'] === 'active' && $l['type'] !== 'trial') {
            foreach (Licenses::activations((int)$l['id']) as $a) {
                $devices[] = $a + ['license_key' => $l['license_key']];
            }
        }
    }
    $requests = Db::all('SELECT r.*, u.name AS user_name FROM purchase_requests r LEFT JOIN users u ON u.id = r.user_id WHERE r.customer_id = ? AND r.tool_id = ? ORDER BY r.created_at DESC LIMIT 10', [$cid, (int)$t['id']]);
    $releases = $canDownload($best) ? Catalog::releases((int)$t['id']) : [];
    // xodim «Oxirgi» deb belgilagan reliz (avvalgisiga qaytarilgan bo'lsa ham) ro'yxatda birinchi; qolgani sanasi bo'yicha
    usort($releases, static fn(array $a, array $b): int => (int)$b['is_latest'] <=> (int)$a['is_latest']);
    View::page('app_tool', [
        't' => $t, 'user' => $u, 'all' => $all, 'best' => $best, 'devices' => $devices, 'requests' => $requests,
        'plans' => Catalog::paidPlans((int)$t['id']), 'releases' => $releases,
        'canTrial' => $t['status'] === 'active' && (int)$t['trial_enabled'] && !$all, // sinov faqat hali hech qanday litsenziyasi yo'q korxonaga
    ], ['area' => 'app', 'nav' => 'app', 'title' => $t['name']]);
});

Router::post('/app/tools/{slug:[a-z0-9-]+}/trial', static function (array $p) use ($toolOr404): void {
    $u = Guard::customer();
    $t = $toolOr404($p['slug']);
    try {
        Licenses::startTrial((int)$u['customer_id'], (int)$t['id'], 'user:' . $u['id'], (int)$u['id']);
        Auth::flash('ok', "Sinov boshlandi. Endi mahsulotni brauzerda ochishingiz mumkin.");
    } catch (HttpError $e) {
        Auth::flash('err', $e->getMessage());
    }
    Util::redirect('/app/tools/' . $t['slug']);
});

Router::post('/app/tools/{slug:[a-z0-9-]+}/request', static function (array $p) use ($toolOr404): void {
    $u = Guard::owner();
    $t = $toolOr404($p['slug']);
    $cid = (int)$u['customer_id'];
    $plan = (string)($_POST['plan'] ?? '');
    if ($plan !== '' && !Db::value('SELECT 1 FROM plans WHERE tool_id = ? AND code = ? AND active = 1', [(int)$t['id'], $plan])) {
        throw new HttpError(422, 'Tarif topilmadi');
    }
    if ((int)Db::value("SELECT COUNT(*) FROM purchase_requests WHERE customer_id = ? AND tool_id = ? AND status = 'new'", [$cid, (int)$t['id']]) >= 3) {
        Auth::flash('info', "Sizning ochiq so'rovlaringiz allaqachon bor. Biwork xodimi tez orada bog'lanadi.");
        Util::redirect('/app/tools/' . $t['slug']);
    }
    $rid = Db::insert('purchase_requests', [
        'customer_id' => $cid, 'user_id' => (int)$u['id'], 'tool_id' => (int)$t['id'], 'plan_code' => $plan,
        'seats' => max(1, min(999, (int)($_POST['seats'] ?? 1))), 'comment' => mb_substr(trim((string)($_POST['comment'] ?? '')), 0, 1000), 'created_at' => Util::now(),
    ]);
    Licenses::event(null, $cid, 'user:' . $u['id'], 'purchase_request', $t['name'] . ' · so\'rov #' . $rid);
    Auth::flash('ok', "So'rov yuborildi. Biwork xodimi siz bilan bog'lanib, hisob-faktura yuboradi.");
    Util::redirect('/app/tools/' . $t['slug']);
});

/** Mahsulotni brauzerda ochish: imzolangan "Ochish" tokeni bilan yo'naltiradi. */
Router::get('/app/open/{slug:[a-z0-9-]+}', static function (array $p) use ($toolOr404): void {
    $u = Guard::customer();
    $t = $toolOr404($p['slug']);
    $lic = Licenses::best((int)$u['customer_id'], (int)$t['id']);
    if ($lic === null) {
        Auth::flash('info', "Avval sinovni boshlang yoki litsenziya oling.");
        Util::redirect('/app/tools/' . $t['slug']);
    }
    try {
        $url = Launch::url($u, $lic, $t);
    } catch (HttpError $e) {
        Auth::flash('err', $e->getMessage());
        Util::redirect('/app/tools/' . $t['slug']);
    }
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    Util::redirect($url, 302);
});

Router::get('/app/download/{id:\d+}', static function (array $p) use ($canDownload): void {
    $u = Guard::customer();
    $rel = Catalog::release((int)$p['id']);
    if (!$rel || !(int)$rel['active']) {
        throw new HttpError(404, 'Fayl topilmadi');
    }
    $lic = Licenses::best((int)$u['customer_id'], (int)$rel['tool_id']);
    if (!$canDownload($lic)) {
        throw new HttpError(403, "Yuklab olish uchun faol pullik litsenziya kerak");
    }
    if (!RateLimit::hit('dl:' . $lic['id'], 30, 3600)) {
        throw new HttpError(429, "Yuklab olish soni cheklangan. Bir soatdan so'ng qayta urinib ko'ring");
    }
    $file = Catalog::releasePath($rel);
    Licenses::event((int)$lic['id'], (int)$lic['customer_id'], 'user:' . $u['id'], 'download', $rel['tool_slug'] . ' v' . $rel['version'] . ' (' . $rel['kind'] . ')');
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($file));
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^0-9A-Za-z._-]/', '_', $rel['tool_slug'] . '-' . $rel['version'] . '-' . $rel['filename']) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header('X-Sha256: ' . $rel['sha256']);
    readfile($file);
    exit;
});

Router::post('/app/devices/{id:\d+}/release', static function (array $p): void {
    $u = Guard::owner();
    $a = Db::one('SELECT a.id, l.customer_id, t.slug FROM activations a JOIN licenses l ON l.id = a.license_id JOIN tools t ON t.id = l.tool_id WHERE a.id = ?', [(int)$p['id']]);
    if (!$a || (int)$a['customer_id'] !== (int)$u['customer_id']) {
        throw new HttpError(404, 'Qurilma topilmadi');
    }
    Licenses::releaseActivation((int)$a['id'], 'user:' . $u['id']);
    Auth::flash('ok', "Qurilma bo'shatildi. O'rindiq yangi qurilma uchun bo'sh.");
    Util::redirect('/app/tools/' . $a['slug']);
});

// ---------------------------------------------------------------- jamoa

Router::get('/app/team', static function (): void {
    $u = Guard::owner();
    $members = Db::all('SELECT * FROM users WHERE customer_id = ? ORDER BY role = \'owner\' DESC, name COLLATE NOCASE', [(int)$u['customer_id']]);
    View::page('app_team', ['members' => $members, 'me' => $u], ['area' => 'app', 'nav' => 'team', 'title' => 'Jamoa']);
});

Router::post('/app/team/add', static function (): void {
    $u = Guard::owner();
    try {
        Accounts::addMember((int)$u['customer_id'], (string)($_POST['email'] ?? ''), (string)($_POST['name'] ?? ''), (string)($_POST['password'] ?? ''), 'member', 'user:' . $u['id']);
        Auth::flash('ok', "Xodim qo'shildi. Unga email va vaqtinchalik parolni yetkazing; birinchi kirishda parol almashtiriladi.");
    } catch (HttpError $e) {
        Auth::flash('err', $e->getMessage());
    }
    Util::redirect('/app/team');
});

Router::post('/app/team/{id:\d+}/toggle', static function (array $p): void {
    $u = Guard::owner();
    $m = Db::one('SELECT * FROM users WHERE id = ? AND customer_id = ?', [(int)$p['id'], (int)$u['customer_id']]);
    if (!$m || (int)$m['id'] === (int)$u['id']) {
        throw new HttpError(404, 'Xodim topilmadi');
    }
    Db::update('users', (int)$m['id'], ['active' => (int)$m['active'] ? 0 : 1]);
    Auth::flash('ok', (int)$m['active'] ? 'Xodim o\'chirildi (kira olmaydi)' : 'Xodim qayta yoqildi');
    Util::redirect('/app/team');
});

// ---------------------------------------------------------------- profil (mijoz va xodim uchun umumiy)

$profilePost = static function (): void {
    $u = Guard::user();
    $cur = (string)($_POST['current'] ?? '');
    $new = (string)($_POST['password'] ?? '');
    $row = Auth::row((int)$u['id']);
    if (!password_verify($cur, $row['password_hash'])) {
        Auth::flash('err', "Joriy parol noto'g'ri");
    } elseif (($pp = Auth::passwordProblem($new, $u['email'])) !== null) {
        Auth::flash('err', $pp);
    } else {
        Accounts::setPassword((int)$u['id'], $new);
        Auth::refresh();
        Auth::flash('ok', 'Parol almashtirildi');
    }
    Util::redirect($u['role'] === 'staff' ? '/admin/profile' : '/app/profile');
};
Router::get('/app/profile', static function (): void {
    $u = Guard::customer();
    View::page('profile', ['me' => $u], ['area' => 'app', 'nav' => 'profile', 'title' => 'Profil']);
});
Router::post('/app/profile', $profilePost);
Router::get('/admin/profile', static function (): void {
    $u = Guard::staff();
    View::page('profile', ['me' => $u], ['area' => 'admin', 'nav' => 'profile', 'title' => 'Profil']);
});
Router::post('/admin/profile', $profilePost);
