<?php
declare(strict_types=1);

/** Admin (/admin): faqat Biwork xodimlari. */

$adminPage = static function (string $tpl, array $vars, string $nav, string $title): never {
    View::page($tpl, $vars, ['area' => 'admin', 'nav' => $nav, 'title' => $title]);
};
$actor = static fn(array $u): string => 'user:' . $u['id'];
$str = static fn(string $k, int $max = 500): string => mb_substr(trim(is_string($_POST[$k] ?? null) ? $_POST[$k] : ''), 0, $max);

// ---------------------------------------------------------------- boshqaruv paneli

Router::get('/admin', static function () use ($adminPage): void {
    Guard::staff();
    $now = Util::now();
    $stats = [
        'active' => (int)Db::value("SELECT COUNT(*) FROM licenses l JOIN customers c ON c.id = l.customer_id WHERE l.type != 'trial' AND l.status = 'active' AND c.status = 'active' AND l.valid_from <= ? AND (l.valid_until IS NULL OR l.valid_until >= ?)", [$now, $now]),
        'trials' => (int)Db::value("SELECT COUNT(*) FROM licenses WHERE type = 'trial' AND status = 'active' AND valid_until >= ?", [$now]),
        'expiring' => (int)Db::value("SELECT COUNT(*) FROM licenses WHERE type != 'trial' AND status = 'active' AND valid_until BETWEEN ? AND ?", [$now, $now + 30 * 86400]),
        'customers' => (int)Db::value('SELECT COUNT(*) FROM customers'),
        'tools' => (int)Db::value("SELECT COUNT(*) FROM tools WHERE status = 'active'"),
        'requests' => (int)Db::value("SELECT COUNT(*) FROM purchase_requests WHERE status = 'new'"),
        'leads' => Leads::newCount(),
        'devices' => (int)Db::value("SELECT COUNT(*) FROM activations a JOIN licenses l ON l.id = a.license_id WHERE a.status = 'active' AND l.status = 'active'"),
    ];
    $expiring = array_map(['Licenses', 'decorate'], Db::all(
        "SELECT l.*, t.slug AS tool_slug, t.code AS tool_code, t.name AS tool_name, c.name AS customer_name, c.status AS customer_status
         FROM licenses l JOIN tools t ON t.id = l.tool_id JOIN customers c ON c.id = l.customer_id
         WHERE l.type != 'trial' AND l.status = 'active' AND l.valid_until BETWEEN ? AND ? ORDER BY l.valid_until LIMIT 8", [$now, $now + 30 * 86400]));
    $requests = Db::all("SELECT r.*, c.name AS customer_name, t.name AS tool_name FROM purchase_requests r JOIN customers c ON c.id = r.customer_id JOIN tools t ON t.id = r.tool_id WHERE r.status = 'new' ORDER BY r.created_at DESC LIMIT 6");
    $events = Db::all('SELECT e.*, c.name AS customer_name FROM license_events e LEFT JOIN customers c ON c.id = e.customer_id ORDER BY e.id DESC LIMIT 10');
    $adminPage('admin_dash', compact('stats', 'expiring', 'requests', 'events'), 'dash', 'Boshqaruv');
});

// ---------------------------------------------------------------- murojaatlar (ochiq saytdan)

Router::get('/admin/leads', static function () use ($adminPage): void {
    Guard::staff();
    $st = isset(Leads::STATUS_UZ[$_GET['status'] ?? '']) ? (string)$_GET['status'] : (($_GET['status'] ?? '') === 'all' ? '' : 'new');
    $tool = (int)($_GET['tool'] ?? 0);
    $counts = [];
    foreach (Db::all('SELECT status, COUNT(*) AS n FROM leads GROUP BY status') as $r) {
        $counts[$r['status']] = (int)$r['n'];
    }
    $adminPage('admin_leads', ['rows' => Leads::all($st, $tool), 'status' => $st === '' ? 'all' : $st, 'toolId' => $tool, 'counts' => $counts, 'tools' => Catalog::tools(true)], 'leads', 'Murojaatlar');
});

Router::post('/admin/leads/{id:\d+}', static function (array $p) use ($actor, $str): void {
    $u = Guard::staff();
    Db::one('SELECT id FROM leads WHERE id = ?', [(int)$p['id']]) ?? throw new HttpError(404, 'Murojaat topilmadi');
    Leads::update((int)$p['id'], $str('status', 20), $str('note', 1000));
    Licenses::event(null, null, $actor($u), 'lead_updated', '#' . $p['id'] . ' → ' . $str('status', 20));
    Auth::flash('ok', 'Murojaat yangilandi');
    $back = Guard::safeNext(is_string($_POST['back'] ?? null) ? $_POST['back'] : null);
    Util::redirect($back !== '' && str_starts_with($back, '/admin/leads') ? $back : '/admin/leads');
});

// ---------------------------------------------------------------- so'rovlar

Router::get('/admin/requests', static function () use ($adminPage): void {
    Guard::staff();
    $st = in_array($_GET['status'] ?? 'new', ['new', 'handled', 'rejected', 'all'], true) ? (string)($_GET['status'] ?? 'new') : 'new';
    $rows = Db::all('SELECT r.*, c.name AS customer_name, t.name AS tool_name, u.name AS user_name, u.email AS user_email, u.phone AS user_phone
                     FROM purchase_requests r JOIN customers c ON c.id = r.customer_id JOIN tools t ON t.id = r.tool_id LEFT JOIN users u ON u.id = r.user_id'
        . ($st === 'all' ? '' : ' WHERE r.status = ?') . ' ORDER BY r.created_at DESC LIMIT 200', $st === 'all' ? [] : [$st]);
    $adminPage('admin_requests', ['rows' => $rows, 'status' => $st], 'requests', "Sotib olish so'rovlari");
});

Router::post('/admin/requests/{id:\d+}/status', static function (array $p) use ($actor): void {
    $u = Guard::staff();
    $st = (string)($_POST['status'] ?? '');
    if (!in_array($st, ['new', 'handled', 'rejected'], true)) {
        throw new HttpError(422, 'Holat noto\'g\'ri');
    }
    $r = Db::one('SELECT * FROM purchase_requests WHERE id = ?', [(int)$p['id']]) ?? throw new HttpError(404, "So'rov topilmadi");
    Db::update('purchase_requests', (int)$r['id'], ['status' => $st, 'handled_by' => $st === 'new' ? null : (int)$u['id'], 'handled_at' => $st === 'new' ? null : Util::now()]);
    Licenses::event(null, (int)$r['customer_id'], $actor($u), 'request_handled', "#{$r['id']} → $st");
    Auth::flash('ok', "So'rov yangilandi");
    Util::redirect('/admin/requests' . ($st === 'new' ? '?status=handled' : ''));
});

// ---------------------------------------------------------------- korxonalar

Router::get('/admin/customers', static function () use ($adminPage): void {
    Guard::staff();
    $q = trim((string)($_GET['q'] ?? ''));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 25;
    $where = '';
    $args = [];
    if ($q !== '') {
        $where = ' WHERE c.name LIKE ? OR c.email LIKE ? OR c.stir LIKE ? OR c.phone LIKE ?';
        $args = array_fill(0, 4, '%' . $q . '%');
    }
    $total = (int)Db::value('SELECT COUNT(*) FROM customers c' . $where, $args);
    $rows = Db::all('SELECT c.*, (SELECT COUNT(*) FROM licenses l WHERE l.customer_id = c.id) AS lic_count,
                            (SELECT COUNT(*) FROM users u WHERE u.customer_id = c.id) AS user_count
                     FROM customers c' . $where . ' ORDER BY c.created_at DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per), $args);
    $adminPage('admin_customers', ['rows' => $rows, 'q' => $q, 'page' => $page, 'pages' => (int)ceil($total / $per), 'total' => $total], 'customers', 'Korxonalar');
});

Router::get('/admin/customers/new', static function () use ($adminPage): void {
    Guard::staff();
    $adminPage('admin_customer_new', ['error' => '', 'in' => []], 'customers', 'Yangi korxona');
});

Router::post('/admin/customers/new', static function () use ($adminPage, $actor): void {
    $u = Guard::staff();
    $in = array_map(static fn($v) => is_string($v) ? $v : '', $_POST);
    try {
        $uid = Accounts::register($in, 'staff');
        $cid = (int)Db::value('SELECT customer_id FROM users WHERE id = ?', [$uid]);
        Db::update('users', $uid, ['must_change_password' => 1]);
        Licenses::event(null, $cid, $actor($u), 'customer_created', $in['company']);
    } catch (HttpError $e) {
        unset($in['password']);
        View::page('admin_customer_new', ['error' => $e->getMessage(), 'in' => $in], ['area' => 'admin', 'nav' => 'customers', 'title' => 'Yangi korxona'], $e->status);
    }
    Auth::flash('ok', "Korxona yaratildi. Egasiga email va vaqtinchalik parolni yetkazing.");
    Util::redirect('/admin/customers/' . $cid);
});

Router::get('/admin/customers/{id:\d+}', static function (array $p) use ($adminPage): void {
    Guard::staff();
    $c = Db::one('SELECT * FROM customers WHERE id = ?', [(int)$p['id']]) ?? throw new HttpError(404, 'Korxona topilmadi');
    $adminPage('admin_customer', [
        'c' => $c,
        'users' => Db::all('SELECT * FROM users WHERE customer_id = ? ORDER BY role = \'owner\' DESC, id', [(int)$c['id']]),
        'licenses' => Licenses::forCustomer((int)$c['id']),
        'requests' => Db::all('SELECT r.*, t.name AS tool_name FROM purchase_requests r JOIN tools t ON t.id = r.tool_id WHERE r.customer_id = ? ORDER BY r.created_at DESC LIMIT 20', [(int)$c['id']]),
        'events' => Db::all('SELECT * FROM license_events WHERE customer_id = ? ORDER BY id DESC LIMIT 15', [(int)$c['id']]),
    ], 'customers', $c['name']);
});

Router::post('/admin/customers/{id:\d+}', static function (array $p) use ($str, $actor): void {
    $u = Guard::staff();
    $c = Db::one('SELECT * FROM customers WHERE id = ?', [(int)$p['id']]) ?? throw new HttpError(404, 'Korxona topilmadi');
    $name = $str('name', 120);
    if ($name === '') {
        Auth::flash('err', 'Korxona nomi bo\'sh bo\'lmasin');
        Util::redirect('/admin/customers/' . $c['id']);
    }
    $status = ($_POST['status'] ?? 'active') === 'blocked' ? 'blocked' : 'active';
    Db::update('customers', (int)$c['id'], [
        'name' => $name, 'stir' => $str('stir', 20), 'city' => $str('city', 60), 'phone' => $str('phone', 40), 'email' => $str('email', 120),
        'notes' => $str('notes', 2000), 'status' => $status,
    ]);
    if ($status !== $c['status']) {
        Licenses::event(null, (int)$c['id'], $actor($u), $status === 'blocked' ? 'suspended' : 'resumed', 'Korxona ' . ($status === 'blocked' ? 'bloklandi' : 'qayta yoqildi'));
    }
    Auth::flash('ok', 'Saqlandi');
    Util::redirect('/admin/customers/' . $c['id']);
});

Router::post('/admin/users/{id:\d+}/reset', static function (array $p) use ($actor): void {
    $u = Guard::staff();
    $m = Db::one("SELECT * FROM users WHERE id = ? AND role != 'staff'", [(int)$p['id']]) ?? throw new HttpError(404, 'Foydalanuvchi topilmadi');
    $alpha = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $pw = '';
    for ($i = 0; $i < 12; $i++) {
        $pw .= $alpha[random_int(0, strlen($alpha) - 1)];
    }
    Db::update('users', (int)$m['id'], ['password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'must_change_password' => 1]);
    Licenses::event(null, (int)$m['customer_id'], $actor($u), 'settings', 'Parol tiklandi: ' . $m['email']);
    Auth::flash('ok', "Yangi vaqtinchalik parol ({$m['email']}): $pw — uni faqat hozir ko'rasiz, foydalanuvchiga yetkazing.");
    Util::redirect('/admin/customers/' . $m['customer_id']);
});

Router::post('/admin/users/{id:\d+}/toggle', static function (array $p): void {
    Guard::staff();
    $m = Db::one("SELECT * FROM users WHERE id = ? AND role != 'staff'", [(int)$p['id']]) ?? throw new HttpError(404, 'Foydalanuvchi topilmadi');
    Db::update('users', (int)$m['id'], ['active' => (int)$m['active'] ? 0 : 1]);
    Auth::flash('ok', (int)$m['active'] ? "Foydalanuvchi o'chirildi" : 'Foydalanuvchi yoqildi');
    Util::redirect('/admin/customers/' . $m['customer_id']);
});

// ---------------------------------------------------------------- litsenziyalar

Router::get('/admin/licenses', static function () use ($adminPage): void {
    Guard::staff();
    $now = Util::now();
    $q = trim((string)($_GET['q'] ?? ''));
    $f = (string)($_GET['f'] ?? '');
    $tool = (int)($_GET['tool'] ?? 0);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 25;
    $w = [];
    $a = [];
    if ($q !== '') {
        $w[] = '(l.license_key LIKE ? OR c.name LIKE ?)';
        array_push($a, '%' . strtoupper($q) . '%', '%' . $q . '%');
    }
    if ($tool > 0) {
        $w[] = 'l.tool_id = ?';
        $a[] = $tool;
    }
    $live = "l.status = 'active' AND c.status = 'active' AND l.valid_from <= ? AND (l.valid_until IS NULL OR l.valid_until >= ?)";
    switch ($f) {
        case 'active': $w[] = "l.type != 'trial' AND $live"; array_push($a, $now, $now); break;
        case 'trial': $w[] = "l.type = 'trial'"; break;
        case 'expiring': $w[] = "l.type != 'trial' AND l.status = 'active' AND l.valid_until BETWEEN ? AND ?"; array_push($a, $now, $now + 30 * 86400); break;
        case 'expired': $w[] = "l.status = 'active' AND l.valid_until < ?"; $a[] = $now; break;
        case 'suspended': $w[] = "l.status = 'suspended'"; break;
        case 'revoked': $w[] = "l.status = 'revoked'"; break;
        default: $f = '';
    }
    $where = $w ? ' WHERE ' . implode(' AND ', $w) : '';
    $from = ' FROM licenses l JOIN tools t ON t.id = l.tool_id JOIN customers c ON c.id = l.customer_id' . $where;
    $total = (int)Db::value('SELECT COUNT(*)' . $from, $a);
    $rows = array_map(['Licenses', 'decorate'], Db::all('SELECT l.*, t.slug AS tool_slug, t.code AS tool_code, t.name AS tool_name, c.name AS customer_name, c.status AS customer_status' . $from . ' ORDER BY l.created_at DESC, l.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per), $a));
    $adminPage('admin_licenses', ['rows' => $rows, 'q' => $q, 'f' => $f, 'tool' => $tool, 'tools' => Catalog::tools(true), 'page' => $page, 'pages' => (int)ceil($total / $per), 'total' => $total], 'licenses', 'Litsenziyalar');
});

Router::get('/admin/licenses/new', static function () use ($adminPage): void {
    Guard::staff();
    $req = isset($_GET['request']) ? Db::one('SELECT * FROM purchase_requests WHERE id = ?', [(int)$_GET['request']]) : null;
    $adminPage('admin_license_new', [
        'error' => '', 'customers' => Db::all('SELECT id, name FROM customers ORDER BY name COLLATE NOCASE'),
        'tools' => Catalog::tools(true), 'plans' => Db::all('SELECT * FROM plans WHERE active = 1 ORDER BY tool_id, sort'),
        'in' => [
            'customer' => (string)($_GET['customer'] ?? ($req['customer_id'] ?? '')), 'tool' => (string)($_GET['tool'] ?? ($req['tool_id'] ?? '')),
            'plan' => (string)($req['plan_code'] ?? ''), 'seats' => (string)($req['seats'] ?? ''), 'request' => (string)($req['id'] ?? ''),
            'note' => $req && $req['comment'] !== '' ? 'So\'rov izohi: ' . $req['comment'] : '',
        ],
    ], 'licenses', 'Litsenziya berish');
});

Router::post('/admin/licenses/new', static function () use ($actor, $str): void {
    $u = Guard::staff();
    $in = array_map(static fn($v) => is_string($v) ? $v : '', $_POST);
    try {
        $in += ['customer' => '', 'tool' => '', 'plan' => '', 'seats' => '', 'request' => '', 'until' => ''];
        $cid = (int)$in['customer'];
        $tid = (int)$in['tool'];
        $plan = $in['plan'];
        $seats = $in['seats'] !== '' ? max(1, (int)$in['seats']) : null;
        $until = $in['until'];
        $inst = $str('instance_url', 300);
        if ($inst !== '' && !preg_match('#^https?://#i', $inst)) {
            throw new HttpError(422, "Nusxa manzili http:// yoki https:// bilan boshlansin");
        }
        if (in_array($plan, ['_paid', '_internal'], true)) {
            $untilTs = $until !== '' ? (Util::endOfDay($until) ?? throw new HttpError(422, "Sana noto'g'ri")) : null;
            $lic = Licenses::issue([
                'customer_id' => $cid, 'tool_id' => $tid, 'plan_code' => $plan === '_paid' ? 'custom' : 'internal', 'type' => $plan === '_paid' ? 'paid' : 'internal',
                'seats' => $seats ?? 1, 'valid_until' => $untilTs, 'features' => ['web_demo', 'app', 'download'], 'note' => $str('note'), 'instance_url' => $inst,
            ], $actor($u), (int)$u['id']);
        } else {
            $pl = Db::one('SELECT * FROM plans WHERE tool_id = ? AND code = ?', [$tid, $plan]);
            if (!$pl) {
                throw new HttpError(422, 'Tarifni tanlang');
            }
            $lic = Licenses::issueFromPlan($cid, $tid, $plan, $seats, $actor($u), (int)$u['id'], $str('note'), $until !== '' ? $until : null, $inst);
        }
        if ($in['request'] !== '' && ($r = Db::one('SELECT id FROM purchase_requests WHERE id = ? AND customer_id = ?', [(int)$in['request'], $cid]))) {
            Db::update('purchase_requests', (int)$r['id'], ['status' => 'handled', 'license_id' => (int)$lic['id'], 'handled_by' => (int)$u['id'], 'handled_at' => Util::now()]);
        }
    } catch (HttpError $e) {
        unset($in['until']);
        View::page('admin_license_new', [
            'error' => $e->getMessage(), 'customers' => Db::all('SELECT id, name FROM customers ORDER BY name COLLATE NOCASE'),
            'tools' => Catalog::tools(true), 'plans' => Db::all('SELECT * FROM plans WHERE active = 1 ORDER BY tool_id, sort'), 'in' => $in + ['until' => ''],
        ], ['area' => 'admin', 'nav' => 'licenses', 'title' => 'Litsenziya berish'], $e->status);
    }
    Auth::flash('ok', 'Litsenziya berildi: ' . $lic['license_key']);
    Util::redirect('/admin/licenses/' . $lic['id']);
});

Router::get('/admin/licenses/{id:\d+}', static function (array $p) use ($adminPage): void {
    Guard::staff();
    $l = Licenses::get((int)$p['id']);
    if (!$l) {
        throw new HttpError(404, 'Litsenziya topilmadi');
    }
    $l = Licenses::decorate($l);
    $adminPage('admin_license', [
        'l' => $l, 'devices' => Licenses::activations((int)$l['id'], false),
        'events' => Db::all('SELECT * FROM license_events WHERE license_id = ? ORDER BY id DESC LIMIT 60', [(int)$l['id']]),
    ], 'licenses', $l['license_key']);
});

$licenseRedirect = static fn(int $id): never => Util::redirect('/admin/licenses/' . $id);

Router::post('/admin/licenses/{id:\d+}/status', static function (array $p) use ($actor, $str, $licenseRedirect): void {
    $u = Guard::staff();
    try {
        Licenses::setStatus((int)$p['id'], (string)($_POST['status'] ?? ''), $actor($u), $str('reason', 200));
        Auth::flash('ok', 'Holat yangilandi');
    } catch (HttpError $e) {
        Auth::flash('err', $e->getMessage());
    }
    $licenseRedirect((int)$p['id']);
});

Router::post('/admin/licenses/{id:\d+}/until', static function (array $p) use ($actor, $licenseRedirect): void {
    $u = Guard::staff();
    try {
        Licenses::setUntil((int)$p['id'], trim((string)($_POST['until'] ?? '')), $actor($u));
        Auth::flash('ok', 'Muddat yangilandi. Mahsulot keyingi tekshiruvda yangi muddatni oladi.');
    } catch (HttpError $e) {
        Auth::flash('err', $e->getMessage());
    }
    $licenseRedirect((int)$p['id']);
});

Router::post('/admin/licenses/{id:\d+}/seats', static function (array $p) use ($actor, $licenseRedirect): void {
    $u = Guard::staff();
    try {
        Licenses::setSeats((int)$p['id'], (int)($_POST['seats'] ?? 1), $actor($u));
        Auth::flash('ok', "O'rindiqlar yangilandi");
    } catch (HttpError $e) {
        Auth::flash('err', $e->getMessage());
    }
    $licenseRedirect((int)$p['id']);
});

Router::post('/admin/licenses/{id:\d+}/settings', static function (array $p) use ($actor, $str, $licenseRedirect): void {
    $u = Guard::staff();
    $l = Licenses::get((int)$p['id']) ?? throw new HttpError(404, 'Litsenziya topilmadi');
    $inst = $str('instance_url', 300);
    if ($inst !== '' && !preg_match('#^https?://#i', $inst)) {
        Auth::flash('err', 'Nusxa manzili http:// yoki https:// bilan boshlansin');
        $licenseRedirect((int)$l['id']);
    }
    Db::update('licenses', (int)$l['id'], [
        'note' => $str('note'), 'instance_url' => $inst,
        'check_days' => max(1, min(90, (int)($_POST['check_days'] ?? 14))), 'grace_days' => max(0, min(60, (int)($_POST['grace_days'] ?? 7))),
        'updated_at' => Util::now(),
    ]);
    Licenses::event((int)$l['id'], (int)$l['customer_id'], $actor($u), 'settings', 'Sozlamalar yangilandi');
    Auth::flash('ok', 'Saqlandi');
    $licenseRedirect((int)$l['id']);
});

Router::post('/admin/devices/{id:\d+}/release', static function (array $p) use ($actor): void {
    $u = Guard::staff();
    $a = Db::one('SELECT license_id FROM activations WHERE id = ?', [(int)$p['id']]) ?? throw new HttpError(404, 'Qurilma topilmadi');
    Licenses::releaseActivation((int)$p['id'], $actor($u));
    Auth::flash('ok', "Qurilma bo'shatildi");
    Util::redirect('/admin/licenses/' . $a['license_id']);
});

// ---------------------------------------------------------------- mahsulotlar, tariflar, relizlar

Router::get('/admin/tools', static function () use ($adminPage): void {
    Guard::staff();
    $tools = Catalog::tools(true);
    foreach ($tools as &$t) {
        $t['plans'] = Catalog::plans((int)$t['id'], false);
        $t['releases'] = Catalog::releases((int)$t['id'], false);
        $t['lic_count'] = (int)Db::value('SELECT COUNT(*) FROM licenses WHERE tool_id = ?', [$t['id']]);
        $t['missing'] = ToolEditor::missing($t, $t['plans']);
        $t['ready'] = array_filter(ToolEditor::readiness($t, $t['plans']), static fn($r) => $r['ok']);
        $t['ready_total'] = count(ToolEditor::readiness($t, $t['plans']));
    }
    unset($t);
    $have = array_column($tools, 'slug');
    $adminPage('admin_tools', ['tools' => $tools, 'bundled' => array_diff_key(Catalog::bundled(), array_flip($have))], 'tools', 'Mahsulotlar');
});

/** Mahsulot formasi (yangi yoki mavjud). $in — xato bo'lganda foydalanuvchi kiritgan qiymatlar. */
$toolEditPage = static function (?array $t, array $in = [], string $err = '') use ($adminPage): never {
    $id = (int)($t['id'] ?? 0);
    $view = $id > 0 ? $t : ToolEditor::previewOf(0, []);
    if ($id === 0) {
        $view['name'] = is_string($in['name'] ?? null) ? trim($in['name']) : '';
    }
    if ($id > 0) {
        $view['web_url'] = (string)Db::value('SELECT web_url FROM tools WHERE id = ?', [$id]); // config.php 'tool_urls' (sinov) almashtirishini formaga saqlab qo'ymaymiz
    }
    if ($in) {
        $view = ToolEditor::previewOf($id, $in) + $view;
        foreach (['slug' => 'strtolower', 'code' => 'strtoupper'] as $k => $fn) {
            if ($id === 0 || ToolEditor::identityLock($id) === null) {
                $view[$k] = is_string($in[$k] ?? null) ? $fn(trim($in[$k])) : $view[$k];
            }
        }
    }
    $plans = $id > 0 ? array_values(array_filter(Catalog::plans($id, false), static fn($p) => $p['type'] === 'paid')) : [];
    $adminPage('admin_tool_edit', [
        't' => $view, 'saved' => $t, 'plans' => $plans, 'in' => $in, 'err' => $err, 'lock' => ToolEditor::identityLock($id),
        'ready' => $t ? ToolEditor::readiness($t, Catalog::plans($id, false)) : [], 'gd' => Media::gd(),
    ], 'tools', $id > 0 ? $view['name'] . ': tahrirlash' : 'Yangi mahsulot');
};

Router::get('/admin/tools/new', static function () use ($toolEditPage): void {
    Guard::staff();
    $toolEditPage(null);
});

Router::post('/admin/tools/new', static function () use ($toolEditPage): void {
    Guard::staff();
    $post = $_POST;
    $post['name'] = is_string($post['name'] ?? null) ? $post['name'] : '';
    try {
        $id = ToolEditor::create($post);
    } catch (HttpError $e) {
        unset($post['csrf']);
        $toolEditPage(null, $post, $e->getMessage());
    }
    // yangi mahsulotni darhol to'liq formadagi qiymatlar bilan saqlaymiz (qoralama)
    try {
        $warn = ToolEditor::apply($id, $post, $_FILES, 'save');
    } catch (HttpError $e) {
        Auth::flash('err', $e->getMessage());
        Util::redirect('/admin/tools/' . $id . '/edit');
    }
    Auth::flash('ok', "Mahsulot qoralama sifatida saqlandi. Saytda ko'rinishi uchun to'ldirib, «E'lon qilish»ni bosing.");
    if ($warn) {
        Auth::flash('err', implode(' ', $warn));
    }
    Util::redirect('/admin/tools/' . $id . '/edit');
});

Router::get('/admin/tools/{id:\d+}/edit', static function (array $p) use ($toolEditPage): void {
    Guard::staff();
    $toolEditPage(Catalog::toolById((int)$p['id']) ?? throw new HttpError(404, 'Mahsulot topilmadi'));
});

Router::post('/admin/tools/{id:\d+}/edit', static function (array $p) use ($toolEditPage): void {
    Guard::staff();
    $id = (int)$p['id'];
    $t = Catalog::toolById($id) ?? throw new HttpError(404, 'Mahsulot topilmadi');
    $do = in_array($_POST['do'] ?? '', ['publish', 'soon', 'hide'], true) ? (string)$_POST['do'] : 'save';
    try {
        $warn = ToolEditor::apply($id, $_POST, $_FILES, $do);
    } catch (HttpError $e) {
        $post = $_POST;
        unset($post['csrf']);
        $toolEditPage($t, $post, $e->getMessage());
    }
    $fresh = Catalog::toolById($id) ?? $t;
    $msg = ['publish' => "Mahsulot e'lon qilindi: endi saytda ochiq", 'soon' => "«Tez orada» deb e'lon qilindi: sahifa saytda, so'rovlar kutish ro'yxatiga tushadi",
        'hide' => "Mahsulot yashirildi: saytda ko'rinmaydi"][$do] ?? 'Saqlandi';
    Auth::flash('ok', $msg);
    if ($do === 'publish' || $do === 'soon') {
        $missing = ToolEditor::missing($fresh, Catalog::plans($id, false));
        if ($missing) {
            Auth::flash('info', "Eslatma: hali to'ldirilmagan: " . implode(', ', $missing) . '.');
        }
    }
    if ($warn) {
        Auth::flash('err', 'Rasm haqida: ' . implode(' ', $warn));
    }
    Util::redirect('/admin/tools/' . $id . '/edit');
});

/** Paketdagi tayyor mahsulot ta'riflarini (integrations/<slug>/manifest.json) bir bosishda qo'shish: slug yoki «all». */
Router::post('/admin/tools/bundled', static function (): void {
    Guard::staff();
    $bundled = Catalog::bundled();
    $want = is_string($_POST['slug'] ?? null) ? $_POST['slug'] : '';
    $slugs = $want === 'all' ? array_keys($bundled) : ($want !== '' ? [$want] : []);
    $added = [];
    $addedSoon = [];
    $skipped = [];
    foreach ($slugs as $slug) {
        if (!isset($bundled[$slug])) {
            Auth::flash('err', "«{$slug}» paketda topilmadi");
            continue;
        }
        if (Db::one('SELECT id FROM tools WHERE slug = ?', [$slug])) {
            if ($want !== 'all') {
                $skipped[] = $bundled[$slug]['name'];
            }
            continue;
        }
        try {
            Catalog::registerManifest($bundled[$slug]);
            if (($bundled[$slug]['status'] ?? 'active') === 'soon') {
                $addedSoon[] = (string)$bundled[$slug]['name'];
            } else {
                $added[] = (string)$bundled[$slug]['name'];
            }
            $miss = array_filter(array_column($bundled[$slug]['marketing']['shots'] ?? [], 'file'), static fn($f) => !is_file(Marketing::assetDir($slug) . '/' . $f));
            if ($miss) {
                Auth::flash('info', "«{$bundled[$slug]['name']}»: ekran rasmlari topilmadi (" . implode(', ', array_slice($miss, 0, 3)) . "…). Paketdagi assets/products/{$slug} papkasi to'liq yuklanganini tekshiring.");
            }
        } catch (HttpError $e) {
            Auth::flash('err', "«{$bundled[$slug]['name']}»: " . $e->getMessage());
        }
    }
    if ($added) {
        Auth::flash('ok', "Qo'shildi: " . implode(', ', $added) . ". Sahifalari saytda ochiq. «Sinab ko'rish» tugmasi brauzer manzili kiritilgach chiqadi.");
    }
    if ($addedSoon) {
        Auth::flash('ok', "«Tez orada» holatida qo'shildi: " . implode(', ', $addedSoon) . ". Sahifasida «Sinab ko'rish» yo'q, faqat xabar olish shakli turadi. Tayyor bo'lganda mahsulot formasida «Saqlash va e'lon qilish»ni bosing.");
    }
    if ($skipped) {
        Auth::flash('info', "Allaqachon bor, o'zgartirilmadi: " . implode(', ', $skipped));
    }
    Util::redirect('/admin/tools');
});

/** Bitta demo manzilni bir nechta mahsulotga qo'yish (masalan, hammasi bitta demo hostda). */
Router::post('/admin/tools/web-url', static function (): void {
    Guard::staff();
    try {
        $url = ToolEditor::webUrl(is_string($_POST['url'] ?? null) ? mb_substr(trim($_POST['url']), 0, 300) : '');
        if ($url === '') {
            throw new HttpError(422, 'Brauzer manzilini yozing');
        }
        $ids = array_values(array_filter(array_map('intval', is_array($_POST['ids'] ?? null) ? $_POST['ids'] : []), static fn($i) => $i > 0));
        if (!$ids) {
            throw new HttpError(422, 'Kamida bitta mahsulotni belgilang');
        }
        $names = [];
        foreach ($ids as $i) {
            if ($t = Db::one('SELECT id, name FROM tools WHERE id = ?', [$i])) {
                Db::update('tools', (int)$t['id'], ['web_url' => $url, 'updated_at' => Util::now()]);
                $names[] = $t['name'];
            }
        }
        Auth::flash('ok', 'Brauzer manzili qo\'yildi (' . implode(', ', $names) . '): endi bu sahifalarda «Sinab ko\'rish» tugmasi chiqadi.');
    } catch (HttpError $e) {
        Auth::flash('err', $e->getMessage());
    }
    Util::redirect('/admin/tools');
});

/** Ro'yxatdan tez holat o'zgartirish (formani ochmasdan). */
Router::post('/admin/tools/{id:\d+}/status', static function (array $p): void {
    Guard::staff();
    $id = (int)$p['id'];
    $t = Catalog::toolById($id) ?? throw new HttpError(404, 'Mahsulot topilmadi');
    $do = in_array($_POST['do'] ?? '', ['publish', 'soon', 'hide'], true) ? (string)$_POST['do'] : '';
    if ($do === '') {
        throw new HttpError(422, "Noto'g'ri amal");
    }
    Db::update('tools', $id, ['status' => ['publish' => 'active', 'soon' => 'soon', 'hide' => 'hidden'][$do], 'updated_at' => Util::now()]);
    Auth::flash('ok', ['publish' => "«{$t['name']}» e'lon qilindi: endi saytda ochiq", 'soon' => "«{$t['name']}» «Tez orada» deb e'lon qilindi",
        'hide' => "«{$t['name']}» yashirildi: saytda ko'rinmaydi"][$do]);
    $missing = $do === 'hide' ? [] : ToolEditor::missing(Catalog::toolById($id) ?? $t, Catalog::plans($id, false));
    if ($missing) {
        Auth::flash('info', "Eslatma: hali to'ldirilmagan: " . implode(', ', $missing) . '.');
    }
    Util::redirect('/admin/tools');
});

/** Saqlamasdan oldindan ko'rish (yangi oynada ochiladi). */
Router::post('/admin/tools/preview', static function (): void {
    Guard::staff();
    $t = ToolEditor::previewOf((int)($_POST['id'] ?? 0), $_POST);
    ProductPage::render($t, '', [], 200, 'preview');
});

Router::post('/admin/tools/register', static function (): void {
    Guard::staff();
    $f = $_FILES['manifest'] ?? null;
    try {
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
            throw new HttpError(422, 'manifest.json faylini tanlang');
        }
        if ((int)$f['size'] > 262144) {
            throw new HttpError(422, 'Manifest juda katta (256 KB dan oshmasin)');
        }
        $m = json_decode((string)file_get_contents((string)$f['tmp_name']), true);
        if (!is_array($m)) {
            throw new HttpError(422, "Manifest JSON formatida emas");
        }
        if (is_string($m['slug'] ?? null) && Db::one('SELECT id FROM tools WHERE slug = ?', [$m['slug']]) && empty($_POST['overwrite'])) {
            throw new HttpError(422, "«{$m['slug']}» mahsuloti allaqachon bor. Manifest yuklansa, sahifa matnlari, rasm nomlari va brauzer manzili manifestdagilar bilan almashadi. Davom etish uchun «Mavjud mahsulotni almashtirish» belgisini qo'ying");
        }
        Catalog::registerManifest($m);
        Auth::flash('ok', "Mahsulot ro'yxatga olindi: " . (string)$m['name']);
    } catch (HttpError $e) {
        Auth::flash('err', $e->getMessage());
    }
    Util::redirect('/admin/tools');
});

Router::post('/admin/tools/{id:\d+}/releases', static function (array $p) use ($str): void {
    Guard::staff();
    $t = Db::one('SELECT * FROM tools WHERE id = ?', [(int)$p['id']]) ?? throw new HttpError(404, 'Mahsulot topilmadi');
    $f = $_FILES['file'] ?? null;
    try {
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
            $err = is_array($f) ? (int)($f['error'] ?? 0) : 0;
            throw new HttpError(422, in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'Fayl serverning yuklash chegarasidan katta (php.ini: upload_max_filesize / post_max_size)' : 'Faylni tanlang');
        }
        Catalog::addRelease((int)$t['id'], $str('version', 30), (string)($_POST['kind'] ?? 'server'), (string)$f['tmp_name'], (string)$f['name'], $str('notes', 1000), !empty($_POST['latest']), true);
        Auth::flash('ok', 'Reliz qo\'shildi');
    } catch (HttpError $e) {
        Auth::flash('err', $e->getMessage());
    }
    Util::redirect('/admin/tools');
});

$releaseBack = static fn(int $toolId): never => Util::redirect('/admin/tools?rel=' . $toolId . '#rel-' . $toolId);

Router::post('/admin/releases/{id:\d+}/toggle', static function (array $p) use ($releaseBack): void {
    Guard::staff();
    $r = Db::one('SELECT * FROM tool_releases WHERE id = ?', [(int)$p['id']]) ?? throw new HttpError(404, 'Reliz topilmadi');
    $promoted = null;
    Db::tx(static function () use ($r, &$promoted): void {
        Db::update('tool_releases', (int)$r['id'], ['active' => (int)$r['active'] ? 0 : 1, 'is_latest' => (int)$r['active'] ? 0 : (int)$r['is_latest']]);
        if ((int)$r['active'] && (int)$r['is_latest']) {
            // «Oxirgi» reliz yashirilsa, shu turdagi eng yangi ko'rinadigan reliz «Oxirgi» bo'ladi (avvalgisiga qaytish)
            $promoted = Db::one('SELECT * FROM tool_releases WHERE tool_id = ? AND kind = ? AND active = 1 AND id != ? ORDER BY published_at DESC, id DESC LIMIT 1', [$r['tool_id'], $r['kind'], $r['id']]);
            if ($promoted) {
                Db::update('tool_releases', (int)$promoted['id'], ['is_latest' => 1]);
            }
        }
    });
    Auth::flash('ok', ((int)$r['active'] ? 'Reliz mijozlardan yashirildi' : 'Reliz yoqildi') . ($promoted ? ". Endi «Oxirgi»: v{$promoted['version']}" : ''));
    $releaseBack((int)$r['tool_id']);
});

/** Relizni «Oxirgi» qilish (shu turdagi boshqalaridan belgi olinadi): yangisida xato chiqsa, avvalgisiga qaytarish shu. */
Router::post('/admin/releases/{id:\d+}/latest', static function (array $p) use ($releaseBack): void {
    $u = Guard::staff();
    $r = Db::one('SELECT r.*, t.name AS tool_name FROM tool_releases r JOIN tools t ON t.id = r.tool_id WHERE r.id = ?', [(int)$p['id']]) ?? throw new HttpError(404, 'Reliz topilmadi');
    Db::tx(static function () use ($r): void {
        Db::exec('UPDATE tool_releases SET is_latest = 0 WHERE tool_id = ? AND kind = ?', [$r['tool_id'], $r['kind']]);
        Db::update('tool_releases', (int)$r['id'], ['is_latest' => 1, 'active' => 1]);
    });
    Licenses::event(null, null, 'user:' . $u['id'], 'settings', "Reliz: {$r['tool_name']} v{$r['version']} ({$r['kind']}) «Oxirgi» qilindi");
    Auth::flash('ok', "v{$r['version']} endi «Oxirgi» reliz: mijozlarning yuklab olish ro'yxatida birinchi turadi.");
    $releaseBack((int)$r['tool_id']);
});

Router::post('/admin/releases/{id:\d+}/notes', static function (array $p) use ($releaseBack, $str): void {
    Guard::staff();
    $r = Db::one('SELECT * FROM tool_releases WHERE id = ?', [(int)$p['id']]) ?? throw new HttpError(404, 'Reliz topilmadi');
    Db::update('tool_releases', (int)$r['id'], ['notes' => $str('notes', 1000)]);
    Auth::flash('ok', "v{$r['version']} izohi saqlandi");
    $releaseBack((int)$r['tool_id']);
});

// ---------------------------------------------------------------- jurnal

Router::get('/admin/events', static function () use ($adminPage): void {
    Guard::staff();
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 50;
    $act = (string)($_GET['action'] ?? '');
    $w = $act !== '' && isset(Ui::ACTION_UZ[$act]) ? ' WHERE e.action = ?' : '';
    $a = $w ? [$act] : [];
    $total = (int)Db::value('SELECT COUNT(*) FROM license_events e' . $w, $a);
    $rows = Db::all('SELECT e.*, c.name AS customer_name, l.license_key FROM license_events e LEFT JOIN customers c ON c.id = e.customer_id LEFT JOIN licenses l ON l.id = e.license_id'
        . $w . ' ORDER BY e.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per), $a);
    $adminPage('admin_events', ['rows' => $rows, 'page' => $page, 'pages' => (int)ceil($total / $per), 'action' => $w ? $act : ''], 'events', 'Jurnal');
});
