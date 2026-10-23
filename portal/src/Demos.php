<?php
declare(strict_types=1);

/**
 * Brauzer demosining versiyalari (admin: Mahsulotlar → «Demo versiyalari»). Hujjat: docs/DEMO_VERSIYALAR.md
 *   url     — demo boshqa joyda ishlaydi (alohida subdomen/hosting); joriy qilinganda «Sinab ko'rish» shu manzilni ochadi;
 *   package — zip data/demos/<tool_id>/<id>.zip da saqlanadi, joriy qilinganda public/demo/<slug>/ ga ochiladi (/demo/<slug>/).
 * Joriy versiya — tools.demo_id (NULL: eski usul, faqat web_url). Avvalgisiga qaytarish = eski versiyani qayta joriy qilish:
 * paket o'z zipidan qaytadan ochiladi (eski nusxa saqlanmaydi).
 */
final class Demos
{
    public const KIND_UZ = ['url' => 'Manzil (URL)', 'package' => 'Paket (zip)'];
    public const MAX_ENTRIES = 5000;
    public const MAX_UNPACKED = 300 * 1048576;
    private const VERSION_RX = '/^[0-9A-Za-z][0-9A-Za-z._-]{0,19}$/';
    private const SLUG_RX = '/^[a-z0-9][a-z0-9-]{0,40}$/';

    /** public/demo/.htaccess (Apache): portalning «hammasi index.php ga» qoidasi demo papkalariga tegmaydi. */
    public const HTACCESS = <<<'TXT'
# Biwork: admin «Demo versiyalari» joylashtirgan demolar (/demo/<slug>/). Fayllarni qo'lda o'zgartirmang:
# versiya joriy qilinganda papka zipdan qaytadan ochiladi.
Options -Indexes
DirectoryIndex index.php index.html
<IfModule mod_rewrite.c>
  # o'z qoidalari: portalning public/.htaccess dagi qayta yozish qoidalari bu papkada ishlamaydi
  RewriteEngine On
  RewriteRule (^|/)\. - [F,L]
</IfModule>
<FilesMatch "^\.">
  <IfModule mod_authz_core.c>
    Require all denied
  </IfModule>
</FilesMatch>

TXT;

    /** @var array<int, ?array> demo_id => [kind, url] (qator o'zgarmaydi, so'rov davomida kesh) */
    private static array $rows = [];

    // ---------------------------------------------------------------- o'qish

    /** @return list<array> yangisi birinchi */
    public static function list(int $toolId): array
    {
        return Db::all('SELECT d.*, u.name AS author FROM tool_demos d LEFT JOIN users u ON u.id = d.created_by
                        WHERE d.tool_id = ? ORDER BY d.created_at DESC, d.id DESC', [$toolId]);
    }

    public static function get(int $id): ?array
    {
        return Db::one('SELECT d.*, t.slug AS tool_slug, t.name AS tool_name, t.demo_id AS tool_demo_id
                        FROM tool_demos d JOIN tools t ON t.id = d.tool_id WHERE d.id = ?', [$id]);
    }

    /**
     * Ochiq sayt uchun joriy versiya: id, tool_id, version, notes, created_at, kind, url (ochiladigan manzil:
     * https://… yoki portal ichidagi /demo/<slug>/). Versiya yo'q yoki paket serverda ochilmagan bo'lsa — null.
     */
    public static function current(int $toolId): ?array
    {
        $r = Db::one('SELECT d.id, d.tool_id, d.version, d.notes, d.created_at, d.kind, d.url, t.slug
                      FROM tools t JOIN tool_demos d ON d.id = t.demo_id WHERE t.id = ?', [$toolId]);
        $url = $r ? self::openUrl($r, (string)$r['slug']) : null;
        if ($url === null) {
            return null;
        }
        unset($r['slug']);
        return ['url' => $url] + $r;
    }

    /** «Demo versiyasi 1.2 · 20.10.2026» */
    public static function label(array $demo): string
    {
        return 'Demo versiyasi ' . $demo['version'] . ' · ' . Util::date((int)$demo['created_at']);
    }

    /** Catalog::decorate uchun: joriy versiyaning ochiladigan manzili (null — paket serverda ochilmagan). */
    public static function urlFor(int $demoId, string $slug): ?string
    {
        if (!array_key_exists($demoId, self::$rows)) {
            self::$rows[$demoId] = Db::one('SELECT kind, url FROM tool_demos WHERE id = ?', [$demoId]);
        }
        return self::$rows[$demoId] !== null ? self::openUrl(self::$rows[$demoId], $slug) : null;
    }

    private static function openUrl(array $d, string $slug): ?string
    {
        if ($d['kind'] === 'url') {
            return $d['url'] !== '' ? (string)$d['url'] : null;
        }
        return self::deployed($slug) ? self::packageUrl($slug) : null;
    }

    /** Paket demosining manzili (portal ichida; Launch/Util::redirect papka prefiksini o'zi qo'shadi). */
    public static function packageUrl(string $slug): string
    {
        return '/demo/' . $slug . '/';
    }

    /** public/demo/<slug>/ da ochilgan paket bormi. */
    public static function deployed(string $slug): bool
    {
        if (!preg_match(self::SLUG_RX, $slug)) {
            return false;
        }
        $d = BW_ROOT . '/public/demo/' . $slug;
        return is_file($d . '/index.php') || is_file($d . '/index.html');
    }

    /** Keyingi versiya raqami taklifi: 1.2 → 1.3, 2.0.9 → 2.0.10; birinchisi 1.0. */
    public static function nextVersion(int $toolId): string
    {
        $taken = array_column(Db::all('SELECT version FROM tool_demos WHERE tool_id = ? ORDER BY created_at DESC, id DESC', [$toolId]), 'version');
        $cand = $taken[0] ?? '';
        if ($cand === '') {
            return '1.0';
        }
        for ($i = 0; $i < 100; $i++) {
            $cand = preg_match('/^(.*?)(\d{1,9})$/', $cand, $m) ? $m[1] . ((int)$m[2] + 1) : $cand . '.1';
            if (!preg_match(self::VERSION_RX, $cand)) {
                return '';
            }
            if (!in_array($cand, $taken, true)) {
                return $cand;
            }
        }
        return '';
    }

    // ---------------------------------------------------------------- qo'shish, joriy qilish, o'chirish

    /**
     * Yangi versiya (hali joriy emas). $in: version, notes, kind (url|package), url; $file — $_FILES['file'] (paket uchun).
     * @return int tool_demos.id
     */
    public static function add(int $toolId, array $in, ?array $file, ?int $userId): int
    {
        Db::one('SELECT id FROM tools WHERE id = ?', [$toolId]) ?? throw new HttpError(404, 'Mahsulot topilmadi');
        $str = static fn(string $k): string => trim(str_replace(["\r\n", "\r"], "\n", is_string($in[$k] ?? null) ? $in[$k] : ''));
        $version = $str('version');
        if (!preg_match(self::VERSION_RX, $version)) {
            throw new HttpError(422, "Versiya raqami 1–20 belgi bo'lsin: lotin harf, raqam, nuqta, chiziqcha yoki pastki chiziq (masalan: 1.2)");
        }
        if (Db::value('SELECT 1 FROM tool_demos WHERE tool_id = ? AND version = ?', [$toolId, $version])) {
            $next = self::nextVersion($toolId);
            throw new HttpError(422, "«{$version}» versiyasi allaqachon bor. Boshqa raqam yozing" . ($next !== '' ? " (masalan: {$next})" : ''));
        }
        $notes = $str('notes');
        if (mb_strlen($notes) > 500) {
            throw new HttpError(422, 'Izoh 500 belgidan oshmasin');
        }
        $row = ['tool_id' => $toolId, 'version' => $version, 'notes' => $notes, 'created_by' => $userId, 'created_at' => Util::now()];
        $kind = $str('kind');
        if ($kind === 'url') {
            return Db::insert('tool_demos', $row + ['kind' => 'url', 'url' => self::checkUrl($str('url'))]);
        }
        if ($kind !== 'package') {
            throw new HttpError(422, "Demo turini tanlang: manzil (URL) yoki paket (zip)");
        }
        $tmp = self::uploadedZip($file);
        self::inspect($tmp);
        $dir = Config::dataDir() . '/demos/' . $toolId;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            throw new HttpError(500, "data/demos papkasini yaratib bo'lmadi: data papkasiga yozish huquqini tekshiring");
        }
        $id = Db::insert('tool_demos', $row + ['kind' => 'package']);
        $rel = 'demos/' . $toolId . '/' . $id . '.zip';
        $dest = Config::dataDir() . '/' . $rel;
        $ok = is_uploaded_file($tmp) ? @move_uploaded_file($tmp, $dest) : @copy($tmp, $dest);
        if (!$ok) {
            Db::exec('DELETE FROM tool_demos WHERE id = ?', [$id]);
            throw new HttpError(500, "Paketni saqlab bo'lmadi: data/demos papkasiga yozish huquqini tekshiring");
        }
        Db::update('tool_demos', $id, ['file' => $rel, 'size' => (int)filesize($dest), 'sha256' => (string)hash_file('sha256', $dest)]);
        return $id;
    }

    /**
     * Versiyani joriy qiladi (eski versiyaga qaytish ham shu). Paket bo'lsa zipdan public/demo/<slug>/ ga qaytadan ochadi,
     * manzil bo'lsa avval ochilgan paketni olib tashlaydi. tools.web_url ham yangilanadi. @return array versiya qatori
     */
    public static function activate(int $id): array
    {
        $d = self::get($id) ?? throw new HttpError(404, 'Demo versiyasi topilmadi');
        $toolId = (int)$d['tool_id'];
        $slug = (string)$d['tool_slug'];
        $lock = self::lock($toolId);
        try {
            if ($d['kind'] === 'package') {
                self::deploy($d, $slug);
                $url = self::packageUrl($slug);
            } else {
                $url = (string)$d['url'];
                self::undeploy($slug);
            }
            // mahsulot manzili (slug) o'zgargan bo'lsa, eski manzildagi paket ochiq qolmasin
            foreach (Db::all('SELECT slug FROM tool_slug_history WHERE tool_id = ?', [$toolId]) as $h) {
                self::undeploy((string)$h['slug']);
            }
            Db::update('tools', $toolId, ['demo_id' => $id, 'web_url' => $url, 'updated_at' => Util::now()]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $d;
    }

    /** Joriy bo'lmagan versiyani (va uning zipini) o'chiradi. */
    public static function delete(int $id): array
    {
        $d = self::get($id) ?? throw new HttpError(404, 'Demo versiyasi topilmadi');
        if ((int)$d['tool_demo_id'] === $id) {
            throw new HttpError(422, "Joriy versiyani o'chirib bo'lmaydi. Avval boshqa versiyani joriy qiling");
        }
        Db::exec('DELETE FROM tool_demos WHERE id = ?', [$id]);
        if ($d['file'] !== '' && preg_match('#^demos/\d+/\d+\.zip$#', (string)$d['file'])) {
            @unlink(Config::dataDir() . '/' . $d['file']);
        }
        unset(self::$rows[$id]);
        return $d;
    }

    /** Xato bo'lganda formaga kiritilganini bir marta qaytarish (sessiyada). */
    public static function keepForm(int $toolId, array $in): void
    {
        Auth::start();
        $_SESSION['bw_demo_form'] = ['tool' => $toolId, 'in' => array_intersect_key($in, array_flip(['version', 'notes', 'kind', 'url']))];
    }

    public static function takeForm(int $toolId): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE || ($_SESSION['bw_demo_form']['tool'] ?? null) !== $toolId) {
            return [];
        }
        $in = $_SESSION['bw_demo_form']['in'] ?? [];
        unset($_SESSION['bw_demo_form']);
        return is_array($in) ? $in : [];
    }

    // ---------------------------------------------------------------- tekshirish

    private static function checkUrl(string $url): string
    {
        if ($url === '') {
            throw new HttpError(422, 'Demo manzilini yozing (masalan: https://reja-demo.biwork.uz/)');
        }
        if (mb_strlen($url) > 300 || !preg_match('#^https?://[^\s/?\#]+#i', $url)) {
            throw new HttpError(422, "Demo manzili http:// yoki https:// bilan boshlansin (masalan: https://reja-demo.biwork.uz/)");
        }
        return ToolEditor::webUrl($url);
    }

    private static function uploadedZip(?array $f): string
    {
        $err = is_array($f) ? (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            throw new HttpError(422, 'Zip serverning yuklash chegarasidan katta (bitta fayl: ' . ini_get('upload_max_filesize') . ", butun yuborish: " . ini_get('post_max_size')
                . "). Hostingda chegarani oshiring yoki demoni alohida manzilga o'rnatib, «Manzil (URL)» turini tanlang");
        }
        if ($err === UPLOAD_ERR_NO_FILE || !is_array($f)) {
            throw new HttpError(422, 'Zip faylni tanlang');
        }
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string)($f['tmp_name'] ?? ''))) {
            throw new HttpError(422, "Faylni yuklab bo'lmadi (xato kodi {$err}). Qayta urinib ko'ring");
        }
        return (string)$f['tmp_name'];
    }

    private static function zip(): ZipArchive
    {
        if (!class_exists('ZipArchive')) {
            throw new HttpError(422, "Serverda PHP «zip» kengaytmasi (ZipArchive) yoqilmagan, shuning uchun paket rejimi ishlamaydi. "
                . "Hosting panelida zip kengaytmasini yoqing yoki demoni alohida manzilga o'rnatib, «Manzil (URL)» turini tanlang");
        }
        return new ZipArchive();
    }

    /**
     * Zipni ochmasdan tekshiradi va ochish rejasini qaytaradi: yozuvlar (indeks, nisbiy yo'l, papkami).
     * Yuqorida bitta umumiy papka bo'lsa, u tushirib qoldiriladi (reja/index.php → index.php).
     * @return list<array{i:int, rel:string, dir:bool}>
     */
    private static function inspect(string $path): array
    {
        $z = self::zip();
        if ($z->open($path, ZipArchive::RDONLY) !== true) {
            throw new HttpError(422, 'Fayl zip arxiv emas yoki buzilgan');
        }
        try {
            if ($z->numFiles > self::MAX_ENTRIES) {
                throw new HttpError(422, "Paketda fayllar juda ko'p ({$z->numFiles} ta, eng ko'pi " . self::MAX_ENTRIES . " ta)");
            }
            $items = [];
            $bytes = 0;
            for ($i = 0; $i < $z->numFiles; $i++) {
                $st = $z->statIndex($i);
                $raw = is_array($st) ? (string)$st['name'] : '';
                $name = str_replace('\\', '/', $raw);
                while (str_starts_with($name, './')) {
                    $name = substr($name, 2);
                }
                $show = mb_substr(mb_convert_encoding($raw, 'UTF-8', 'UTF-8'), 0, 80);
                if ($name === '' || str_starts_with($name, '/') || preg_match('#^[A-Za-z]:#', $name) || preg_match('#(^|/)\.\.?(/|$)#', $name) || preg_match('/[\x00-\x1f\x7f]/', $name)) {
                    throw new HttpError(422, "Paketda xavfli yo'l bor: «{$show}». Fayllar arxiv papkasidan tashqariga chiqmasin (.. yoki / bilan boshlanadigan yo'l bo'lmasin)");
                }
                if (str_starts_with($name, '__MACOSX/') || basename($name) === '.DS_Store') {
                    continue; // macOS arxivlovchisi qo'shadigan keraksiz fayllar
                }
                if ($z->getExternalAttributesIndex($i, $os, $attr) && $os === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
                    throw new HttpError(422, "Paketda simvolik havola (symlink) bor: «{$show}». Uni oddiy fayl bilan almashtiring");
                }
                $dir = str_ends_with($name, '/');
                $name = trim($name, '/');
                if (preg_match('#(^|/)(\.user\.ini|php\.ini)$#i', $name)) {
                    throw new HttpError(422, "Paketda .user.ini yoki php.ini bo'lmasin (portal o'zi yozadi): «{$show}»");
                }
                $bytes += $dir ? 0 : max(0, (int)($st['size'] ?? 0));
                if ($bytes > self::MAX_UNPACKED) {
                    throw new HttpError(422, 'Paket ochilganda juda katta (eng ko\'pi ' . Util::bytes(self::MAX_UNPACKED) . ')');
                }
                $items[] = ['i' => $i, 'name' => $name, 'dir' => $dir];
            }
            if (!$items) {
                throw new HttpError(422, "Arxiv bo'sh");
            }
            // yuqorida bitta umumiy papka (masalan reja/...) — tekislaymiz
            $tops = [];
            foreach ($items as $it) {
                $tops[explode('/', $it['name'])[0]] = true;
            }
            $prefix = '';
            if (count($tops) === 1) {
                $top = (string)array_key_first($tops);
                $fileAtTop = (bool)array_filter($items, static fn($it) => $it['name'] === $top && !$it['dir']);
                $prefix = $fileAtTop ? '' : $top . '/';
            }
            $plan = [];
            $files = [];
            foreach ($items as $it) {
                $rel = $prefix === '' ? $it['name'] : (str_starts_with($it['name'] . '/', $prefix) ? substr($it['name'], strlen($prefix)) : '');
                if ($rel === '' || $rel === false) {
                    continue;
                }
                $plan[] = ['i' => $it['i'], 'rel' => $rel, 'dir' => $it['dir']];
                if (!$it['dir']) {
                    $files[$rel] = true;
                }
            }
            if (!isset($files['index.php']) && !isset($files['index.html'])) {
                $pub = array_values(array_filter(array_keys($files), static fn($f) => (bool)preg_match('#(^|/)public/index\.(php|html)$#', $f)));
                if ($pub) {
                    throw new HttpError(422, "Paket ildizida index.php yoki index.html yo'q: kirish fayli «{$pub[0]}» da (hujjatlar ildizi public/ bo'lgan ilova). "
                        . "Bunday ilovani portal ichiga ochib bo'lmaydi: src/, data/ kabi papkalar ham veb orqali ochilib qoladi. "
                        . "Ilovani alohida subdomenga o'rnatib, «Manzil (URL)» turidagi versiya qo'shing");
                }
                throw new HttpError(422, "Paket ildizida index.php yoki index.html yo'q. Zip ochilganda shu fayl eng yuqorida tursin (bitta umumiy papka ichida bo'lsa ham bo'ladi)");
            }
            return $plan;
        } finally {
            $z->close();
        }
    }

    // ---------------------------------------------------------------- joylashtirish

    /** public/demo papkasi (yo'q bo'lsa yaratiladi, .htaccess bilan). */
    private static function root(): string
    {
        $root = BW_ROOT . '/public/demo';
        if (!is_dir($root) && !@mkdir($root, 0755, true)) {
            throw new HttpError(500, "portal/public/demo papkasini yaratib bo'lmadi: hostingda portal/public papkasiga yozish huquqini bering");
        }
        if (!is_file($root . '/.htaccess')) {
            @file_put_contents($root . '/.htaccess', self::HTACCESS);
        }
        if (!is_writable($root)) {
            throw new HttpError(500, "portal/public/demo papkasiga yozib bo'lmaydi: hostingda shu papkaga yozish huquqini bering (755 yoki 775)");
        }
        $root = realpath($root) ?: $root;
        // avvalgi joylashtirishlardan o'chmay qolgan vaqtinchalik papkalar (1 soatdan eski) tozalanadi
        foreach (array_merge(glob($root . '/.stage-*', GLOB_ONLYDIR) ?: [], glob($root . '/.old-*', GLOB_ONLYDIR) ?: []) as $left) {
            if ((int)@filemtime($left) < time() - 3600) {
                self::rmTree($left, $root);
            }
        }
        return $root;
    }

    /** Zipni vaqtinchalik papkaga ochib, so'ng bir harakatda almashtiradi (ishlayotgan demo yarim ochilgan holda qolmaydi). */
    private static function deploy(array $d, string $slug): void
    {
        if (!preg_match(self::SLUG_RX, $slug)) {
            throw new HttpError(422, "Mahsulot manzili (slug) demo papkasi uchun yaroqsiz");
        }
        $zipPath = Config::dataDir() . '/' . $d['file'];
        if ($d['file'] === '' || !is_file($zipPath)) {
            throw new HttpError(422, "v{$d['version']} paket fayli serverda topilmadi. Bu versiyani o'chirib, zipni qayta yuklang");
        }
        $plan = self::inspect($zipPath);
        $root = self::root();
        $target = $root . '/' . $slug;
        $stage = $root . '/.stage-' . $slug . '-' . bin2hex(random_bytes(4));
        if (!@mkdir($stage, 0755)) {
            throw new HttpError(500, "portal/public/demo ichida vaqtinchalik papka yaratib bo'lmadi: yozish huquqini tekshiring");
        }
        try {
            $z = self::zip();
            if ($z->open($zipPath, ZipArchive::RDONLY) !== true) {
                throw new HttpError(422, "v{$d['version']} paketini ochib bo'lmadi (zip buzilgan)");
            }
            try {
                $written = 0;
                foreach ($plan as $e) {
                    $dest = $stage . '/' . $e['rel'];
                    if ($e['dir']) {
                        is_dir($dest) || mkdir($dest, 0755, true);
                        continue;
                    }
                    is_dir(dirname($dest)) || mkdir(dirname($dest), 0755, true);
                    $in = $z->getStream((string)$z->getNameIndex($e['i']));
                    $out = fopen($dest, 'wb');
                    try {
                        if ($in === false || $out === false) {
                            throw new HttpError(422, "Paketdagi «{$e['rel']}» faylini ochib bo'lmadi");
                        }
                        while (!feof($in)) {
                            $buf = fread($in, 65536);
                            if ($buf === false) {
                                throw new HttpError(422, "Paketdagi «{$e['rel']}» fayli buzilgan");
                            }
                            $written += strlen($buf);
                            if ($written > self::MAX_UNPACKED) {
                                throw new HttpError(422, 'Paket ochilganda juda katta (eng ko\'pi ' . Util::bytes(self::MAX_UNPACKED) . ')');
                            }
                            fwrite($out, $buf);
                        }
                    } finally {
                        is_resource($in) && fclose($in);
                        is_resource($out) && fclose($out);
                    }
                }
            } finally {
                $z->close();
            }
            file_put_contents($stage . '/.user.ini', self::userIni($target));
            // almashtirish: eski papka chetga, yangisi o'rniga, so'ng eski o'chiriladi
            $old = null;
            if (is_dir($target) || is_link($target)) {
                $old = $root . '/.old-' . $slug . '-' . bin2hex(random_bytes(4));
                if (!@rename($target, $old)) {
                    throw new HttpError(500, "Eski demo papkasini almashtirib bo'lmadi (demo/{$slug}): yozish huquqini tekshiring");
                }
            }
            if (!@rename($stage, $target)) {
                if ($old !== null) {
                    @rename($old, $target);
                }
                throw new HttpError(500, "Yangi demo papkasini joyiga qo'yib bo'lmadi (demo/{$slug})");
            }
            if ($old !== null) {
                self::rmTree($old, $root);
            }
        } catch (HttpError $e) {
            self::rmTree($stage, $root);
            throw $e;
        } catch (Throwable $e) {
            self::rmTree($stage, $root);
            throw new HttpError(500, "Paketni ochib bo'lmadi: " . $e->getMessage());
        }
    }

    /** public/demo/<slug>/ ni olib tashlaydi (bo'lsa). */
    private static function undeploy(string $slug): void
    {
        $base = BW_ROOT . '/public/demo';
        if (!preg_match(self::SLUG_RX, $slug) || !is_dir($base . '/' . $slug)) {
            return;
        }
        $root = realpath($base) ?: $base;
        $old = $root . '/.old-' . $slug . '-' . bin2hex(random_bytes(4));
        if (@rename($root . '/' . $slug, $old)) {
            self::rmTree($old, $root);
        }
    }

    /**
     * .user.ini: demo kodi faqat o'z papkasini va vaqtinchalik papkalarni o'qiy oladi (portal kalitlari, bazasi yopiq).
     * PHP-FPM / CGI da ishlaydi; Apache mod_php va php -S bu faylni o'qimaydi.
     */
    private static function userIni(string $target): string
    {
        $paths = [$target . DIRECTORY_SEPARATOR];
        // sessiya papkasi php.ini dagi qiymatdan (portal o'z sessiyalarini data/sessions ga yo'naltirgan — u ro'yxatga tushmasin)
        $all = extension_loaded('session') ? @ini_get_all('session') : false;
        $sess = is_array($all) ? (string)($all['session.save_path']['global_value'] ?? '') : '';
        $sess = str_contains($sess, ';') ? substr($sess, (int)strrpos($sess, ';') + 1) : $sess;
        $own = array_filter([realpath(BW_ROOT), realpath(Config::dataDir())]);
        foreach ([sys_get_temp_dir(), $sess, (string)ini_get('upload_tmp_dir')] as $p) {
            $real = $p !== '' ? realpath($p) : false;
            if ($real === false || !is_dir($real)) {
                continue;
            }
            $real = rtrim($real, '/\\') . DIRECTORY_SEPARATOR;
            foreach ($own as $o) {
                // portal (yoki uning data papkasi) ichidagi yoki uni o'z ichiga olgan papka qo'shilmaydi
                if (str_starts_with($real, $o . DIRECTORY_SEPARATOR) || str_starts_with($o . DIRECTORY_SEPARATOR, $real)) {
                    continue 2;
                }
            }
            $paths[] = $real;
        }
        $paths = array_values(array_unique(array_filter($paths, static fn($p) => !preg_match('/["\r\n]/', $p))));
        return "; Biwork portali yozgan (demo versiyasi joriy qilinganda). Demo kodi faqat shu papkani va vaqtinchalik papkalarni o'qiy oladi.\n"
            . "; PHP-FPM / CGI da ishlaydi; Apache mod_php va php -S bu faylni o'qimaydi (docs/DEMO_VERSIYALAR.md).\n"
            . 'open_basedir = "' . implode(PATH_SEPARATOR, $paths) . "\"\n";
    }

    /** Papkani ichidagilari bilan o'chiradi (faqat $root ichida; simvolik havolalar ortidan bormaydi). */
    private static function rmTree(string $path, string $root): void
    {
        if (!str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !file_exists($path)) {
            return;
        }
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            /** @var SplFileInfo $f */
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($path);
    }

    /** @return resource bir mahsulot uchun joylashtirishlar navbat bilan */
    private static function lock(int $toolId)
    {
        $dir = Config::dataDir() . '/demos/' . $toolId;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $h = @fopen($dir . '/.lock', 'c');
        if ($h === false) {
            throw new HttpError(500, "data/demos papkasiga yozib bo'lmadi: data papkasiga yozish huquqini tekshiring");
        }
        flock($h, LOCK_EX);
        return $h;
    }
}
