<?php
declare(strict_types=1);

/** Vositalar katalogi: vositalar, tariflar, relizlar. */
final class Catalog
{
    public const STATUS_UZ = ['active' => 'Faol', 'soon' => 'Tez orada', 'hidden' => 'Yashirin'];
    public const KIND_UZ = ['server' => 'Server paketi (zip)', 'windows' => 'Windows o\'rnatuvchi', 'source' => 'Manba kod'];
    /** Mahsulot ochiq saytda `/<slug>` manzilida turadi — bu nomlar boshqa sahifalarga tegishli. */
    public const RESERVED_SLUGS = ['admin', 'app', 'api', 'login', 'logout', 'register', 'setup', 'tools', 'mahsulotlar', 'sorov', 'rahmat',
        'sitemap', 'robots', 'assets', 'media', 'health', 'index', 'sw', 'manifest', 'favicon', 'public', 'static', 'www', 'blog', 'narxlar', 'aloqa', 'demo'];

    /**
     * Bepul sinov muddati (kun): butun platforma uchun bitta qoida, standart 30. Manifestdagi `trial.days` hisobga olinmaydi
     * (mahsulot jamoalari eski 14 ni yozgan); mahsulot formasida har mahsulot uchun alohida o'zgartirish mumkin.
     */
    public static function trialDays(): int
    {
        $d = (int)Config::raw('trial_days', 30);
        return $d >= 1 && $d <= 90 ? $d : 30;
    }

    /** @return list<array> */
    public static function tools(bool $withHidden = false): array
    {
        // Ochiq ro'yxatda «tez orada» mahsulotlar oxirida turadi.
        $rows = Db::all('SELECT * FROM tools' . ($withHidden ? ' ORDER BY sort, id' : " WHERE status != 'hidden' ORDER BY (status = 'soon'), sort, id"));
        return array_map([self::class, 'decorate'], $rows);
    }

    public static function tool(string $slug): ?array
    {
        $r = Db::one('SELECT * FROM tools WHERE slug = ?', [$slug]);
        return $r ? self::decorate($r) : null;
    }

    /**
     * Paket ichidagi tayyor mahsulot ta'riflari: integrations/<slug>/manifest.json (portal papkasidan bir daraja yuqorida).
     * @return array<string,array> slug => manifest (sort bo'yicha)
     */
    public static function bundled(): array
    {
        $out = [];
        foreach (glob(dirname(BW_ROOT) . '/integrations/*/manifest.json') ?: [] as $f) {
            $slug = basename(dirname($f));
            if (!preg_match('/^[a-z0-9][a-z0-9-]{1,30}$/', $slug) || (int)@filesize($f) > 262144) {
                continue;
            }
            $m = json_decode((string)@file_get_contents($f), true);
            if (is_array($m) && ($m['slug'] ?? null) === $slug && is_string($m['name'] ?? null) && is_string($m['code'] ?? null)) {
                $out[$slug] = self::withContent($m);
            }
        }
        uasort($out, static fn($a, $b) => [(int)($a['sort'] ?? 100), $a['slug']] <=> [(int)($b['sort'] ?? 100), $b['slug']]);
        return $out;
    }

    /**
     * Portal tomonidagi qo'shimcha matn: portal/content/<slug>.json (hozircha faqat «Qanday ishlatiladi» ko'rsatmasi: guide).
     * Manifestning o'zida ko'rsatma bo'lsa, unga tegilmaydi.
     */
    public static function withContent(array $m): array
    {
        $slug = (string)($m['slug'] ?? '');
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,30}$/', $slug) || !empty($m['marketing']['guide']['steps'])) {
            return $m;
        }
        $ov = json_decode((string)@file_get_contents(BW_ROOT . '/content/' . $slug . '.json'), true);
        if (is_array($ov) && is_array($ov['guide'] ?? null)) {
            $m['marketing'] = is_array($m['marketing'] ?? null) ? $m['marketing'] : [];
            $m['marketing']['guide'] = $ov['guide'];
        }
        return $m;
    }

    public static function toolById(int $id): ?array
    {
        $r = Db::one('SELECT * FROM tools WHERE id = ?', [$id]);
        return $r ? self::decorate($r) : null;
    }

    private static function decorate(array $t): array
    {
        $t['highlights'] = json_decode((string)$t['highlights'], true) ?: [];
        $t['marketing'] = json_decode((string)($t['marketing'] ?? '{}'), true) ?: [];
        // joriy demo versiyasi (admin «Demo versiyalari») formadagi manzildan ustun; paket serverda ochilmagan bo'lsa — demo yo'q
        if (!empty($t['demo_id'])) {
            $t['web_url'] = Demos::urlFor((int)$t['demo_id'], (string)$t['slug']) ?? '';
        }
        // dev/test uchun manzilni config.php dan almashtirish: 'tool_urls' => ['reja' => 'http://localhost:8080/']
        $over = Config::raw('tool_urls', []);
        if (is_array($over) && isset($over[$t['slug']]) && is_string($over[$t['slug']])) {
            $t['web_url'] = $over[$t['slug']];
        }
        return $t;
    }

    /** @return list<array> */
    public static function plans(int $toolId, bool $onlyActive = true): array
    {
        $rows = Db::all('SELECT * FROM plans WHERE tool_id = ?' . ($onlyActive ? ' AND active = 1' : '') . ' ORDER BY sort, id', [$toolId]);
        foreach ($rows as &$p) {
            $p['limits'] = json_decode((string)$p['limits'], true) ?: [];
            $p['features'] = json_decode((string)$p['features'], true) ?: [];
        }
        return $rows;
    }

    /** Sotib olish mumkin bo'lgan (pullik) tariflar. */
    public static function paidPlans(int $toolId): array
    {
        return array_values(array_filter(self::plans($toolId), static fn($p) => $p['type'] === 'paid'));
    }

    /** @return list<array> */
    public static function releases(int $toolId, bool $onlyActive = true): array
    {
        return Db::all('SELECT * FROM tool_releases WHERE tool_id = ?' . ($onlyActive ? ' AND active = 1' : '') . ' ORDER BY published_at DESC, id DESC', [$toolId]);
    }

    public static function release(int $id): ?array
    {
        return Db::one('SELECT r.*, t.slug AS tool_slug FROM tool_releases r JOIN tools t ON t.id = r.tool_id WHERE r.id = ?', [$id]);
    }

    /** Reliz faylini data/releases/<tool>/<versiya>/ ga saqlaydi va ro'yxatga oladi. */
    public static function addRelease(int $toolId, string $version, string $kind, string $srcPath, string $filename, string $notes = '', bool $latest = true, bool $move = false): int
    {
        $tool = Db::one('SELECT slug FROM tools WHERE id = ?', [$toolId]) ?? throw new HttpError(404, 'Vosita topilmadi');
        if (!preg_match('/^[0-9A-Za-z][0-9A-Za-z._-]{0,30}$/', $version)) {
            throw new HttpError(422, "Versiya noto'g'ri (masalan 1.8 yoki 2.0.1)");
        }
        if (!isset(Catalog::KIND_UZ[$kind])) {
            throw new HttpError(422, "Reliz turi noto'g'ri");
        }
        if (!is_file($srcPath)) {
            throw new HttpError(422, 'Fayl topilmadi');
        }
        $filename = preg_replace('/[^0-9A-Za-z._-]+/', '_', basename($filename)) ?: 'package.zip';
        $rel = $tool['slug'] . '/' . $version . '/' . $kind . '-' . $filename;
        $dest = Config::dataDir() . '/releases/' . $rel;
        if (!is_dir(dirname($dest))) {
            @mkdir(dirname($dest), 0775, true);
        }
        $ok = $move ? @rename($srcPath, $dest) || (@copy($srcPath, $dest) && @unlink($srcPath)) : @copy($srcPath, $dest);
        if (!$ok) {
            throw new HttpError(500, "Faylni saqlab bo'lmadi");
        }
        $sha = hash_file('sha256', $dest);
        return Db::tx(static function () use ($toolId, $version, $kind, $filename, $rel, $dest, $sha, $notes, $latest): int {
            Db::exec('DELETE FROM tool_releases WHERE tool_id = ? AND version = ? AND kind = ?', [$toolId, $version, $kind]);
            if ($latest) {
                Db::exec('UPDATE tool_releases SET is_latest = 0 WHERE tool_id = ? AND kind = ?', [$toolId, $kind]);
            }
            return Db::insert('tool_releases', [
                'tool_id' => $toolId, 'version' => $version, 'kind' => $kind, 'filename' => $filename, 'path' => $rel,
                'size' => (int)filesize($dest), 'sha256' => $sha, 'notes' => mb_substr($notes, 0, 1000),
                'is_latest' => $latest ? 1 : 0, 'active' => 1, 'published_at' => Util::now(),
            ]);
        });
    }

    public static function releasePath(array $rel): string
    {
        $p = Config::dataDir() . '/releases/' . $rel['path'];
        $real = realpath($p);
        $base = realpath(Config::dataDir() . '/releases');
        if ($real === false || $base === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            throw new HttpError(404, 'Fayl topilmadi');
        }
        return $real;
    }

    /** manifest.json (docs/TOOL_CONTRACT.md §1) bo'yicha vositani qo'shadi/yangilaydi. */
    public static function registerManifest(array $m): int
    {
        foreach (['slug', 'code', 'name'] as $k) {
            if (!isset($m[$k]) || !is_string($m[$k]) || $m[$k] === '') {
                throw new HttpError(422, "Manifestda '$k' yo'q");
            }
        }
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,30}$/', $m['slug']) || !preg_match('/^[A-Z0-9]{3}$/', $m['code'])) {
            throw new HttpError(422, "slug kichik lotin harf/raqam, code — 3 ta bosh harf/raqam bo'lishi kerak");
        }
        if (in_array($m['slug'], self::RESERVED_SLUGS, true)) {
            throw new HttpError(422, "'{$m['slug']}' nomi saytning boshqa sahifasiga band — boshqa slug tanlang");
        }
        if (!Db::one('SELECT id FROM tools WHERE slug = ?', [$m['slug']]) && Db::one('SELECT tool_id FROM tool_slug_history WHERE slug = ?', [$m['slug']])) {
            throw new HttpError(422, "'{$m['slug']}' manzili admin panelida o'zgartirilgan mahsulotning eski manzili. Manifestdagi slug ni yangi manzilga almashtiring");
        }
        $m = self::withContent($m);
        $mk = Marketing::clean(is_array($m['marketing'] ?? null) ? $m['marketing'] : []);
        if (!array_filter($mk['theme'])) { // manifestda rang/ko'rinish yozilmagan bo'lsa, admin tanlagani saqlanib qoladi
            $old = Db::one('SELECT marketing FROM tools WHERE slug = ?', [$m['slug']]);
            $oldTheme = $old ? (json_decode((string)$old['marketing'], true)['theme'] ?? null) : null;
            if (is_array($oldTheme) && array_filter($oldTheme)) {
                $mk['theme'] = Marketing::clean(['theme' => $oldTheme])['theme'];
            }
        }
        $row = [
            'name' => $m['name'], 'tagline' => (string)($m['tagline'] ?? ''), 'description' => (string)($m['description'] ?? ''),
            'category' => (string)($m['category'] ?? ''), 'icon' => (string)($m['icon'] ?? 'tool'),
            'status' => in_array($m['status'] ?? 'active', ['active', 'soon', 'hidden'], true) ? ($m['status'] ?? 'active') : 'active',
            'web_url' => (string)($m['web']['url'] ?? ''),
            'trial_days' => self::trialDays(), 'trial_enabled' => (int)(($m['trial']['enabled'] ?? true) ? 1 : 0),
            'highlights' => json_encode(array_values($m['highlights'] ?? []), JSON_UNESCAPED_UNICODE),
            'sort' => (int)($m['sort'] ?? 100),
            'marketing' => json_encode($mk, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            'updated_at' => Util::now(),
        ];
        return Db::tx(static function () use ($m, $row): int {
            $ex = Db::one('SELECT id FROM tools WHERE slug = ?', [$m['slug']]);
            if ($ex) {
                $id = (int)$ex['id'];
                Db::update('tools', $id, $row);
            } else {
                $id = Db::insert('tools', ['slug' => $m['slug'], 'code' => $m['code'], 'created_at' => Util::now()] + $row);
            }
            foreach (array_values($m['plans'] ?? []) as $i => $p) {
                $prow = [
                    'name' => (string)($p['name'] ?? $p['code']), 'type' => ($p['type'] ?? 'paid') === 'trial' ? 'trial' : 'paid',
                    'seats' => max(1, (int)($p['seats'] ?? 1)), 'days' => isset($p['days']) ? (int)$p['days'] : null,
                    'price' => isset($p['price']) ? (int)$p['price'] : null, 'currency' => (string)($p['currency'] ?? 'UZS'),
                    'limits' => json_encode($p['limits'] ?? new stdClass(), JSON_UNESCAPED_UNICODE),
                    'features' => json_encode(array_values($p['features'] ?? []), JSON_UNESCAPED_UNICODE),
                    'active' => 1, 'sort' => ($i + 1) * 10,
                ];
                $pe = Db::one('SELECT id FROM plans WHERE tool_id = ? AND code = ?', [$id, $p['code']]);
                if ($pe) {
                    // narx va tarif sozlamalarini xodim o'zgartirgan bo'lishi mumkin — mavjud tarifga tegmaymiz
                    continue;
                }
                Db::insert('plans', ['tool_id' => $id, 'code' => (string)$p['code']] + $prow);
            }
            return $id;
        });
    }
}
