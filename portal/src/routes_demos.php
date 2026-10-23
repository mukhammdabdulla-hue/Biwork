<?php
declare(strict_types=1);

/** Admin: mahsulot demosining versiyalari (Demos). Ko'rinishi — /admin/tools dagi har mahsulot kartasida (views/_admin_demos.php). */

$demoBack = static fn(int $toolId): never => Util::redirect('/admin/tools?demo=' . $toolId . '#demo-' . $toolId);

/** Yangi versiya: manzil (URL) yoki zip paket; «Darhol joriy qilish» belgilansa — joriy ham qilinadi. */
Router::post('/admin/tools/{id:\d+}/demos', static function (array $p) use ($demoBack): void {
    $u = Guard::staff();
    $t = Db::one('SELECT id, name FROM tools WHERE id = ?', [(int)$p['id']]) ?? throw new HttpError(404, 'Mahsulot topilmadi');
    $in = array_map(static fn($v) => is_string($v) ? $v : '', array_intersect_key($_POST, array_flip(['version', 'notes', 'kind', 'url'])));
    try {
        $id = Demos::add((int)$t['id'], $in, is_array($_FILES['file'] ?? null) ? $_FILES['file'] : null, (int)$u['id']);
    } catch (HttpError $e) {
        Demos::keepForm((int)$t['id'], $in);
        Auth::flash('err', $e->getMessage());
        $demoBack((int)$t['id']);
    }
    $d = Demos::get($id) ?? throw new HttpError(404, 'Demo versiyasi topilmadi');
    Licenses::event(null, null, 'user:' . $u['id'], 'settings', "Demo: {$t['name']} v{$d['version']} qo'shildi (" . (Demos::KIND_UZ[$d['kind']] ?? $d['kind']) . ')');
    if (empty($_POST['activate'])) {
        Auth::flash('ok', "v{$d['version']} saqlandi, lekin hali joriy emas. Saytda ochilishi uchun ro'yxatda «Joriy qilish»ni bosing.");
        $demoBack((int)$t['id']);
    }
    try {
        Demos::activate($id);
        Licenses::event(null, null, 'user:' . $u['id'], 'settings', "Demo: {$t['name']} v{$d['version']} joriy qilindi");
        Auth::flash('ok', "v{$d['version']} qo'shildi va joriy qilindi: saytdagi «Sinab ko'rish» endi shu versiyani ochadi.");
    } catch (HttpError $e) {
        Auth::flash('err', "v{$d['version']} saqlandi, lekin joriy qilib bo'lmadi: " . $e->getMessage());
    }
    $demoBack((int)$t['id']);
});

/** Joriy qilish, eski versiyaga qaytarish yoki joriy paketni qayta ochish (namuna ma'lumot boshlang'ich holatiga qaytadi). */
Router::post('/admin/demos/{id:\d+}/activate', static function (array $p) use ($demoBack): void {
    $u = Guard::staff();
    $d = Demos::get((int)$p['id']) ?? throw new HttpError(404, 'Demo versiyasi topilmadi');
    $cur = (int)($d['tool_demo_id'] ?? 0) > 0 ? Demos::get((int)$d['tool_demo_id']) : null;
    try {
        Demos::activate((int)$d['id']);
        if ($cur !== null && (int)$cur['id'] === (int)$d['id']) {
            $msg = "v{$d['version']} qaytadan joylashtirildi (paket zipdan qayta ochildi)";
        } elseif ($cur !== null && [(int)$d['created_at'], (int)$d['id']] < [(int)$cur['created_at'], (int)$cur['id']]) {
            $msg = "Avvalgi versiyaga qaytarildi: endi saytda v{$d['version']} ochiladi (v{$cur['version']} o'rniga)";
        } else {
            $msg = "v{$d['version']} joriy qilindi: endi saytda shu versiya ochiladi";
        }
        Licenses::event(null, null, 'user:' . $u['id'], 'settings', "Demo: {$d['tool_name']} — " . $msg);
        Auth::flash('ok', $msg . '.');
    } catch (HttpError $e) {
        Auth::flash('err', "v{$d['version']} ni joriy qilib bo'lmadi: " . $e->getMessage());
    }
    $demoBack((int)$d['tool_id']);
});

Router::post('/admin/demos/{id:\d+}/delete', static function (array $p) use ($demoBack): void {
    $u = Guard::staff();
    $d = Demos::get((int)$p['id']) ?? throw new HttpError(404, 'Demo versiyasi topilmadi');
    try {
        Demos::delete((int)$d['id']);
        Licenses::event(null, null, 'user:' . $u['id'], 'settings', "Demo: {$d['tool_name']} v{$d['version']} o'chirildi");
        Auth::flash('ok', "v{$d['version']} o'chirildi");
    } catch (HttpError $e) {
        Auth::flash('err', $e->getMessage());
    }
    $demoBack((int)$d['tool_id']);
});

/**
 * /demo/<slug>/... portalning o'ziga kelib qolsa (veb-server papkani bevosita bermadi, masalan php -S yoki nginx sozlanmagan):
 * bo'sh 404 o'rniga sababini aytamiz. Odatda Apache/nginx bu so'rovni portalgacha yetkazmaydi.
 */
Router::get('/demo/{slug:[a-z0-9-]+}{rest:(?:/.*)?}', static function (array $p): void {
    $slug = (string)$p['slug'];
    if (!Demos::deployed($slug)) {
        throw new HttpError(404, 'Demo topilmadi');
    }
    $staff = (Auth::user()['role'] ?? '') === 'staff';
    throw new HttpError(503, $staff
        ? "Demo fayllari serverda bor (portal/public/demo/{$slug}/), lekin veb-server ularni bevosita bermayapti va so'rov portalga tushdi. "
            . "Apache: portal/public/demo/.htaccess borligini tekshiring; php -S: router.php /demo/ yo'llarini o'tkazib yuborishi kerak (docs/DEMO_VERSIYALAR.md)."
        : "Demo hozir ochilmadi. Birozdan so'ng qayta urinib ko'ring yoki Biwork bilan bog'laning.");
});
