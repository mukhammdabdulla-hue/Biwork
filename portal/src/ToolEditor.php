<?php
declare(strict_types=1);

/**
 * Admin formasi orqali mahsulot qo'shish va tahrirlash (manifest.json yuklamasdan).
 * Forma maydonlari: asosiy (nom, tavsif, belgi, brauzer manzili, sinov), sahifa matni (Marketing::fromForm), rasmlar, tariflar.
 */
final class ToolEditor
{
    /** Belgi tanlash ro'yxati: kalit (Ui::icon) => o'zbekcha nom. */
    public const ICONS = ['plan' => 'Reja, jadval', 'users' => 'Odamlar, xodimlar', 'grid' => "Bo'limlar, loyihalar", 'inbox' => 'Savdo, buyurtma', 'chart' => 'Hisobot, moliya',
        'shield' => 'Himoya, huquq', 'building' => 'Korxona', 'key' => 'Kalit', 'laptop' => 'Kompyuter', 'globe' => 'Internet', 'excel' => 'Jadval', 'megaphone' => 'Reklama',
        'device' => 'Qurilma', 'list' => "Ro'yxat", 'clock' => 'Vaqt', 'target' => 'Maqsad', 'phone' => 'Telefon', 'tool' => 'Asbob'];
    private const CYR = ['а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'yo', 'ж' => 'j', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l',
        'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'x', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sh',
        'ъ' => '', 'ы' => 'i', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya', 'ў' => 'o', 'қ' => 'q', 'ғ' => 'g', 'ҳ' => 'h'];

    /** Brauzer matn maydonlarini CRLF bilan yuboradi: hamma joyda \n ga keltiramiz (abzatslar bo'sh qator bilan ajratiladi). */
    public static function normalize(array $post): array
    {
        foreach ($post as $k => $v) {
            $post[$k] = is_array($v) ? self::normalize($v) : (is_string($v) ? str_replace(["\r\n", "\r"], "\n", $v) : $v);
        }
        return $post;
    }

    // ------------------------------------------------------------------ manzil va kod

    /** "Biwork HR" -> "hr", "Savdo hisobi" -> "savdo-hisobi", "Ишлаб чиқариш" -> "ishlab-chiqarish". */
    public static function slugFromName(string $name): string
    {
        $s = mb_strtolower(trim($name));
        $s = str_replace(["'", '‘', '’', 'ʻ', 'ʼ', '`'], '', $s);
        $s = strtr($s, self::CYR);
        $s = preg_replace('/^biwork[\s-]+/', '', $s) ?? $s;
        $s = substr(Util::slug($s), 0, 30);
        return strlen($s) >= 2 ? trim($s, '-') : 'mahsulot';
    }

    /** Litsenziya kodi uchun 3 belgi (bo'sh joy yo'q ekani tekshiriladi). */
    public static function suggestCode(string $slug, ?int $exceptId = null): string
    {
        $letters = strtoupper(preg_replace('/[^a-z0-9]/', '', $slug) ?? '');
        $base = substr(str_pad($letters, 3, 'X'), 0, 3);
        for ($i = 0; $i < 100; $i++) {
            $try = $i === 0 ? $base : substr($base, 0, 1) . str_pad((string)$i, 2, '0', STR_PAD_LEFT);
            $ex = Db::one('SELECT id FROM tools WHERE code = ?', [$try]);
            if (!$ex || (int)$ex['id'] === $exceptId) {
                return $try;
            }
        }
        return $base;
    }

    /** Manzil/kod o'zgarishini taqiqlovchi sabab (yoki null). */
    public static function identityLock(int $id): ?string
    {
        if ($id < 1) {
            return null;
        }
        if ((int)Db::value('SELECT COUNT(*) FROM licenses WHERE tool_id = ?', [$id]) > 0) {
            return 'bu mahsulotga litsenziya berilgan';
        }
        if ((int)Db::value('SELECT COUNT(*) FROM tool_releases WHERE tool_id = ?', [$id]) > 0) {
            return 'bu mahsulotga reliz yuklangan';
        }
        return null;
    }

    private static function checkIdentity(int $id, string $slug, string $code): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,30}$/', $slug)) {
            throw new HttpError(422, "Sayt manzili 2-31 belgi bo'lsin: kichik lotin harf, raqam va chiziqcha (masalan: savdo-hisobi)");
        }
        if (in_array($slug, Catalog::RESERVED_SLUGS, true)) {
            throw new HttpError(422, "«{$slug}» manzili saytning boshqa sahifasiga band, boshqasini tanlang");
        }
        $ex = Db::one('SELECT id FROM tools WHERE slug = ?', [$slug]);
        $hist = Db::one('SELECT tool_id FROM tool_slug_history WHERE slug = ?', [$slug]);
        if (($ex && (int)$ex['id'] !== $id) || ($hist && (int)$hist['tool_id'] !== $id)) {
            throw new HttpError(422, "«{$slug}» manzili boshqa mahsulotda bor");
        }
        if (!preg_match('/^[A-Z0-9]{3}$/', $code)) {
            throw new HttpError(422, "Litsenziya kodi aynan 3 ta bosh harf yoki raqam bo'lsin (masalan: SAV)");
        }
        $cx = Db::one('SELECT id FROM tools WHERE code = ?', [$code]);
        if ($cx && (int)$cx['id'] !== $id) {
            throw new HttpError(422, "«{$code}» kodi boshqa mahsulotda bor");
        }
    }

    // ------------------------------------------------------------------ yaratish va saqlash

    /** Yangi mahsulot (qoralama): nom, manzil, kod; boshlang'ich tariflar. @return int tools.id */
    public static function create(array $post): int
    {
        $post = self::normalize($post);
        $name = mb_substr(trim(is_string($post['name'] ?? null) ? $post['name'] : ''), 0, 80);
        if ($name === '') {
            throw new HttpError(422, 'Mahsulot nomini yozing');
        }
        $slug = strtolower(trim(is_string($post['slug'] ?? null) ? $post['slug'] : ''));
        $slug = $slug !== '' ? $slug : self::slugFromName($name);
        $code = strtoupper(trim(is_string($post['code'] ?? null) ? $post['code'] : ''));
        $code = $code !== '' ? $code : self::suggestCode($slug);
        self::checkIdentity(0, $slug, $code);
        return Db::tx(static function () use ($name, $slug, $code): int {
            $id = Db::insert('tools', ['slug' => $slug, 'code' => $code, 'name' => $name, 'status' => 'hidden', 'icon' => 'tool', 'sort' => 100,
                'marketing' => '{}', 'created_at' => Util::now(), 'updated_at' => Util::now()]);
            foreach ([['standard', 'Standart', 1, 10], ['business', 'Korxona', 5, 20]] as [$code2, $pn, $seats, $sort]) {
                Db::insert('plans', ['tool_id' => $id, 'code' => $code2, 'name' => $pn, 'type' => 'paid', 'seats' => $seats, 'days' => 365, 'limits' => '{}', 'features' => '[]', 'active' => 1, 'sort' => $sort]);
            }
            return $id;
        });
    }

    /** Brauzer (demo) manzili: bo'sh, https://… yoki sayt ichidagi /yo'l; aks holda HttpError(422). */
    public static function webUrl(string $web): string
    {
        $web = trim($web);
        if ($web !== '' && !preg_match('#^https?://\S+$#i', $web) && !(str_starts_with($web, '/') && !str_starts_with($web, '//'))) {
            throw new HttpError(422, "Brauzer manzili https:// bilan boshlansin (masalan: https://hr.biwork.uz/)");
        }
        return $web;
    }

    /** Asosiy maydonlar (tools ustunlari); noto'g'ri qiymatda HttpError(422). */
    public static function basics(array $post, array $t): array
    {
        $s = static fn(string $k, int $max): string => mb_substr(trim(is_string($post[$k] ?? null) ? $post[$k] : ''), 0, $max);
        $name = $s('name', 80);
        if ($name === '') {
            throw new HttpError(422, 'Mahsulot nomini yozing');
        }
        $web = self::webUrl($s('web_url', 300));
        $icon = is_string($post['icon'] ?? null) && isset(self::ICONS[$post['icon']]) ? $post['icon'] : (string)($t['icon'] ?? 'tool');
        return [
            'name' => $name, 'tagline' => $s('tagline', 200), 'description' => $s('description', 4000), 'category' => $s('category', 60), 'icon' => $icon,
            'web_url' => $web, 'trial_enabled' => !empty($post['trial_enabled']) ? 1 : 0, 'trial_days' => max(1, min(90, (int)($post['trial_days'] ?? Catalog::trialDays()))),
            'sort' => max(1, min(9999, (int)($post['sort'] ?? $t['sort'] ?? 100))),
        ];
    }

    /**
     * Formani saqlaydi. $do: save | publish | soon | hide.
     * @return list<string> ogohlantirishlar (masalan yuklanmagan rasm)
     */
    public static function apply(int $id, array $post, array $files, string $do): array
    {
        $t = Db::one('SELECT * FROM tools WHERE id = ?', [$id]) ?? throw new HttpError(404, 'Mahsulot topilmadi');
        $post = self::normalize($post);
        $cols = self::basics($post, $t);
        $warn = [];

        // manzil va kod
        $slug = strtolower(trim(is_string($post['slug'] ?? null) ? $post['slug'] : ''));
        $slug = $slug !== '' ? $slug : (string)$t['slug'];
        $code = strtoupper(trim(is_string($post['code'] ?? null) ? $post['code'] : ''));
        $code = $code !== '' ? $code : (string)$t['code'];
        $renamed = $slug !== $t['slug'];
        if ($renamed || $code !== $t['code']) {
            if (($why = self::identityLock($id)) !== null) {
                throw new HttpError(422, "Manzil va kodni endi o'zgartirib bo'lmaydi: {$why}");
            }
            self::checkIdentity($id, $slug, $code);
        }

        // sahifa matni va rasmlar
        $old = $t['marketing'] ? (json_decode((string)$t['marketing'], true) ?: []) : [];
        $m = Marketing::fromForm($post);
        $count = count(Marketing::clean($m)['shots']);
        foreach (Media::normalize($files['new_shots'] ?? null) as $f) {
            if ($count >= 12) {
                $warn[] = "Rasmlar soni 12 tadan oshmasin, ortiqchasi qo'shilmadi.";
                break;
            }
            try {
                $m['shots'][] = ['file' => Media::store($id, $f), 'caption' => '', 'alt' => ''];
                $count++;
            } catch (HttpError $e) {
                $warn[] = $e->getMessage() . '.';
            }
        }
        $mc = Marketing::clean($m);
        // ro'yxatdan chiqarilgan, hech qayerda ishlatilmayotgan yuklangan rasmlarni o'chiramiz
        $keep = array_merge(array_column($mc['shots'], 'file'), array_column($mc['features'], 'shot'), array_column($mc['guide']['steps'], 'shot'));
        foreach (array_unique(array_merge(array_column($old['shots'] ?? [], 'file'), array_column($old['features'] ?? [], 'shot'), array_column($old['guide']['steps'] ?? [], 'shot'))) as $f) {
            if (!in_array($f, $keep, true)) {
                Media::remove($id, (string)$f);
            }
        }

        $status = ['publish' => 'active', 'soon' => 'soon', 'hide' => 'hidden'][$do] ?? $t['status'];
        Db::tx(static function () use ($id, $t, $cols, $mc, $status, $slug, $code, $renamed, $post): void {
            if ($renamed) {
                Db::exec('INSERT OR REPLACE INTO tool_slug_history (slug, tool_id) VALUES (?, ?)', [$t['slug'], $id]);
                Db::exec('DELETE FROM tool_slug_history WHERE slug = ?', [$slug]);
                Media::adoptBundled($id, $t['slug']);
            }
            Db::update('tools', $id, $cols + ['slug' => $slug, 'code' => $code, 'status' => $status,
                'marketing' => json_encode($mc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}', 'updated_at' => Util::now()]);
            self::savePlans($id, $post);
        });

        // ulashish rasmi: paket bilan og.png kelmagan bo'lsa, birinchi ekran rasmidan yasaymiz
        $fresh = Catalog::toolById($id);
        if ($fresh && !is_file(Marketing::assetDir($slug) . '/og.png')) {
            $first = $mc['shots'][0]['file'] ?? '';
            $src = $first !== '' ? (Media::path($id, $first) ?? (is_file(Marketing::assetDir($slug) . '/' . $first) ? Marketing::assetDir($slug) . '/' . $first : null)) : null;
            if ($src !== null) {
                Media::buildOg($id, $src);
            } elseif (is_file(Media::dir($id) . '/og.jpg')) {
                @unlink(Media::dir($id) . '/og.jpg');
            }
        }
        return $warn;
    }

    private static function savePlans(int $toolId, array $post): void
    {
        $s = static fn(mixed $v, int $max): string => mb_substr(trim(is_string($v) ? $v : ''), 0, $max);
        $num = static fn(mixed $v): ?int => ($d = preg_replace('/\D/', '', is_string($v) ? $v : '')) === '' ? null : (int)$d;
        $fields = static fn(array $p): array => [
            'blurb' => $s($p['blurb'] ?? '', 120), 'price_text' => $s($p['price_text'] ?? '', 60), 'price' => $num($p['price'] ?? ''),
            'seats' => max(1, min(999, (int)($p['seats'] ?? 1))), 'days' => ($d = $num($p['days'] ?? '')) !== null && $d > 0 ? min($d, 3650) : null,
        ];
        foreach (is_array($post['plans'] ?? null) ? $post['plans'] : [] as $pid => $p) {
            $pl = is_array($p) ? Db::one("SELECT * FROM plans WHERE id = ? AND tool_id = ? AND type = 'paid'", [(int)$pid, $toolId]) : null;
            if ($pl) {
                Db::update('plans', (int)$pl['id'], ['name' => $s($p['name'] ?? '', 60) ?: $pl['name'], 'active' => !empty($p['active']) ? 1 : 0] + $fields($p));
            }
        }
        $sort = (int)Db::value('SELECT COALESCE(MAX(sort), 0) FROM plans WHERE tool_id = ?', [$toolId]);
        foreach (is_array($post['new_plans'] ?? null) ? $post['new_plans'] : [] as $p) {
            $name = is_array($p) ? $s($p['name'] ?? '', 60) : '';
            if ($name === '') {
                continue;
            }
            $base = substr(Util::slug(strtr(mb_strtolower($name), self::CYR)), 0, 24) ?: 'tarif';
            $code = $base;
            for ($i = 2; Db::one('SELECT id FROM plans WHERE tool_id = ? AND code = ?', [$toolId, $code]); $i++) {
                $code = $base . '-' . $i;
            }
            $sort += 10;
            Db::insert('plans', ['tool_id' => $toolId, 'code' => $code, 'name' => $name, 'type' => 'paid', 'currency' => 'UZS', 'limits' => '{}', 'features' => '[]', 'active' => 1, 'sort' => $sort] + $fields($p));
        }
    }

    // ------------------------------------------------------------------ oldindan ko'rish va tayyorlik

    /** Saqlanmagan forma qiymatlaridan sahifa uchun [mahsulot, marketing]. Yangi yuklangan rasmlar ko'rinmaydi. */
    public static function previewOf(int $id, array $post): array
    {
        $post = self::normalize($post);
        $t = $id > 0 ? (Catalog::toolById($id) ?? throw new HttpError(404, 'Mahsulot topilmadi')) : [
            'id' => 0, 'slug' => 'yangi-mahsulot', 'code' => 'XXX', 'status' => 'hidden', 'icon' => 'tool', 'web_url' => '', 'trial_enabled' => 1, 'trial_days' => Catalog::trialDays(),
            'sort' => 100, 'highlights' => [], 'updated_at' => Util::now(), 'created_at' => Util::now(), 'name' => '', 'tagline' => '', 'description' => '', 'category' => '',
        ];
        try {
            $t = self::basics($post, $t) + $t;
        } catch (HttpError) {
            $t['name'] = $t['name'] !== '' ? $t['name'] : 'Yangi mahsulot';
        }
        $t['marketing'] = Marketing::clean(Marketing::fromForm($post));
        return $t;
    }

    /**
     * "E'lon qilishdan oldin" ro'yxati: [ok, must (zarur), label, hint].
     * @return list<array{ok:bool,must:bool,label:string,hint:string}>
     */
    public static function readiness(array $t, array $plans): array
    {
        $m = $t['marketing'] ?? [];
        $shots = array_filter($m['shots'] ?? [], static fn($s) => Marketing::hasShot($t, (string)$s['file']));
        $active = ($t['status'] ?? '') === 'active';
        return [
            ['ok' => mb_strlen(trim((string)$t['tagline'])) >= 10, 'must' => true, 'label' => 'Qisqa tavsif', 'hint' => "Katalogda va qidiruvda nom yonida chiqadi (bir jumla)"],
            ['ok' => mb_strlen(trim((string)$t['description'])) >= 80 || mb_strlen((string)($m['lead'] ?? '')) >= 40, 'must' => true, 'label' => 'Batafsil tavsif', 'hint' => 'Mahsulot nima qiladi, kimga kerak (kamida bir-ikki jumla)'],
            ['ok' => count($shots) >= 1, 'must' => false, 'label' => 'Ekran rasmlari', 'hint' => "Rasmsiz sahifa ishonch uyg'otmaydi: kamida 3 ta tavsiya"],
            ['ok' => count($m['features'] ?? []) >= 2, 'must' => false, 'label' => 'Imkoniyatlar', 'hint' => 'Kamida 2-3 ta imkoniyatni yozing'],
            ['ok' => count($m['audience'] ?? []) >= 1, 'must' => false, 'label' => 'Kimga kerak', 'hint' => 'Qaysi korxonalar uchun ekanini yozing'],
            ['ok' => count($m['faq'] ?? []) >= 1, 'must' => false, 'label' => 'Savol-javob', 'hint' => "Mijozlar ko'p so'raydigan 3-5 savol"],
            ['ok' => count($m['guide']['steps'] ?? []) >= 3, 'must' => false, 'label' => "Qo'llanma", 'hint' => "«Qanday ishlatiladi»: kamida 3 qadam, har biriga ekran rasmi"],
            ['ok' => trim((string)$t['web_url']) !== '', 'must' => $active, 'label' => "Brauzerda sinab ko'rish manzili", 'hint' => "Bo'sh bo'lsa saytda «Sinab ko'rish» tugmasi chiqmaydi"],
            ['ok' => count(array_filter($plans, static fn($p) => $p['type'] === 'paid' && (int)$p['active'])) >= 1, 'must' => $active, 'label' => 'Tariflar', 'hint' => 'Kamida bitta faol tarif bo\'lsin'],
        ];
    }

    /** @return list<string> tayyor bo'lmagan zarur bandlar nomi */
    public static function missing(array $t, array $plans): array
    {
        return array_values(array_map(static fn($r) => $r['label'], array_filter(self::readiness($t, $plans), static fn($r) => !$r['ok'] && $r['must'])));
    }
}
