<?php
declare(strict_types=1);

/**
 * Biwork platformasiga ulanish (shartnoma: biwork/docs/TOOL_CONTRACT.md). config.php dagi 'biwork' bo'limi bilan yoqiladi;
 * bo'lim yo'q bo'lsa Monitoring mustaqil ishlaydi.
 *   'biwork' => [
 *       'server' => 'https://biwork.uz', 'tool' => 'monitoring',
 *       'sso' => true,            // portaldan "Ochish" (bw_launch) bilan kirish
 *       'enforce' => false,       // true: faol litsenziyasiz ishlamaydi (o'rnatilgan ilova)
 *       'customer_id' => 12,      // shaxsiy nusxa faqat shu korxonaning tokenini qabul qiladi
 *       'demo' => true,           // umumiy namuna: har tashrifchiga alohida nusxa, sozlash yopiq
 *   ]
 */
require_once __DIR__ . '/BiworkConnect.php';

final class Biwork
{
    private static ?BiworkConnect $client = null;

    public static function config(): array
    {
        $c = Config::get('biwork', []);
        return is_array($c) ? $c : [];
    }

    private static function keysFile(): string
    {
        return MON_ROOT . '/biwork-public.json';
    }

    public static function ssoEnabled(): bool
    {
        $c = self::config();
        return !empty($c['sso']) && (!empty($c['public_keys']) || is_file(self::keysFile()));
    }

    public static function enforced(): bool
    {
        return !empty(self::config()['enforce']);
    }

    public static function sharedDemo(): bool
    {
        return !empty(self::config()['demo']);
    }

    public static function client(): BiworkConnect
    {
        if (self::$client === null) {
            $c = self::config();
            self::$client = new BiworkConnect([
                'server' => (string)($c['server'] ?? ''), 'tool' => (string)($c['tool'] ?? 'monitoring'), 'data_dir' => Config::dataDir(),
                'public_keys' => $c['public_keys'] ?? self::keysFile(), 'app_version' => (string)($c['app_version'] ?? MON_VERSION),
            ]);
        }
        return self::$client;
    }

    public static function portalUrl(): string
    {
        return (string)(self::config()['server'] ?? '');
    }

    // ------------------------------------------------------------------ "Ochish" (SSO)

    /** ?bw_launch=<token>: tokenni tekshiradi, sessiya ochadi va tokensiz manzilga yo'naltiradi. */
    public static function handleLaunch(): never
    {
        $base = Util::basePath();
        try {
            $claims = self::client()->verifyLaunch((string)($_GET['bw_launch'] ?? ''));
            $bound = (int)(self::config()['customer_id'] ?? 0);
            if ($bound > 0 && (int)($claims['cust']['id'] ?? 0) !== $bound) {
                throw new BiworkException('wrong_customer', "Bu nusxa boshqa korxona uchun mo'ljallangan. Portaldan o'z nusxangizni oching.");
            }
            $row = self::userFor($claims);
            Auth::loginAs($row);
            if (self::sharedDemo()) {
                $_SESSION['demo_db'] = self::visitorCopy();
            }
        } catch (BiworkException $e) {
            self::page(403, 'Kirish amalga oshmadi', $e->getMessage(), self::portalUrl());
        }
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        header('Location: ' . ($base ?: '') . '/', true, 303);
        exit;
    }

    private static function userFor(array $claims): array
    {
        $u = $claims['user'] ?? [];
        $uid = (int)($u['id'] ?? 0);
        $cid = (int)($claims['cust']['id'] ?? 0);
        if ($uid <= 0 || $cid <= 0) {
            throw new BiworkException('bad_token', "Token foydalanuvchi ma'lumotini o'z ichiga olmagan");
        }
        // demo rejimda cheklangan rol: qoidalarni o'zgartirish va foydalanuvchilarni boshqarish yo'q
        $role = ($claims['mode'] ?? 'demo') === 'live' && ($u['tool_role'] ?? 'user') === 'admin' && !self::sharedDemo() ? 'admin' : 'user';
        $login = 'bw-' . $cid . '-' . $uid;
        $name = mb_substr((string)($u['name'] ?? $login), 0, 100);
        Schema::ensure();
        $row = Db::one('SELECT * FROM users WHERE login = ?', [$login]);
        if (!$row) {
            $id = Auth::createUser($login, $name, $role, null); // noma'lum parol: faqat portal orqali kiriladi
            return Db::one('SELECT * FROM users WHERE id = ?', [$id]);
        }
        if (!(int)$row['active']) {
            throw new BiworkException('disabled', "Bu foydalanuvchi o'chirilgan. Administratorga murojaat qiling.");
        }
        if ($row['role'] !== $role || $row['name'] !== $name) {
            Db::exec('UPDATE users SET role = ?, name = ? WHERE id = ?', [$role, $name, $row['id']]);
            $row = Db::one('SELECT * FROM users WHERE id = ?', [$row['id']]);
        }
        return $row;
    }

    // ------------------------------------------------------------------ umumiy namuna: har tashrifchiga alohida nusxa

    private static function visitorDir(): string
    {
        return Config::dataDir() . '/visitors';
    }

    /** Namuna bazasidan tashrifchi uchun nusxa yaratadi (eskilari 1 kundan keyin o'chiriladi). */
    private static function visitorCopy(): string
    {
        $dir = self::visitorDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        foreach (glob($dir . '/*.sqlite*') ?: [] as $f) {
            if (@filemtime($f) < time() - 86400) {
                @unlink($f);
            }
        }
        Db::use(null);
        $name = 'v' . bin2hex(random_bytes(6)) . '.sqlite';
        $dst = $dir . '/' . $name;
        Db::pdo()->exec("VACUUM INTO '" . str_replace("'", "''", $dst) . "'");
        return $name;
    }

    /** Tashrifchi nusxasiga o'tish (agar sessiyada bo'lsa). Autentifikatsiyadan keyin chaqiriladi. */
    public static function useVisitorDb(): void
    {
        $n = (string)($_SESSION['demo_db'] ?? '');
        if ($n !== '' && preg_match('/^v[0-9a-f]{12}\.sqlite$/', $n) && is_file(self::visitorDir() . '/' . $n)) {
            Db::use(self::visitorDir() . '/' . $n);
        }
    }

    // ------------------------------------------------------------------ litsenziya nazorati

    /** null = ruxsat; aks holda litsenziya holati. */
    public static function gate(): ?array
    {
        if (!self::enforced()) {
            return null;
        }
        try {
            $st = self::client()->ensure();
        } catch (Throwable $e) {
            error_log('[Monitoring] biwork: ' . $e::class . ': ' . $e->getMessage());
            return ['allowed' => false, 'state' => 'config', 'license' => null, 'days_left' => null, 'needs_refresh' => false,
                'message' => 'Litsenziya tekshiruvi ishlamayapti (sozlama xatosi). Administratorga murojaat qiling.'];
        }
        return $st['allowed'] ? null : $st;
    }

    public static function warning(): ?string
    {
        if (!self::enforced()) {
            return null;
        }
        try {
            $st = self::client()->state();
        } catch (Throwable) {
            return null;
        }
        return ($st['allowed'] && $st['state'] === 'grace') ? $st['message'] : null;
    }

    public static function blockPage(array $st): never
    {
        header('Location: ' . (Util::basePath() ?: '') . '/license.php', true, 302);
        exit;
    }

    public static function blockApi(array $st): never
    {
        http_response_code(402);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['error' => ['code' => 'license_required', 'message' => $st['message'], 'state' => $st['state'],
            'license_url' => (Util::basePath() ?: '') . '/license.php']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function page(int $status, string $title, string $message, string $link = ''): never
    {
        http_response_code($status);
        Auth::pageHeaders();
        $b = Util::e(Util::basePath());
        echo '<!doctype html><html lang="uz"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex"><title>' . Util::e($title) . ' · Biwork Monitoring</title>'
            . '<link rel="icon" type="image/svg+xml" href="' . $b . '/assets/favicon.svg"><link rel="stylesheet" href="' . $b . '/assets/app.css?v=' . MON_VERSION . '"></head>'
            . '<body><div class="lg-wrap"><main class="lg-main"><div class="lg-card">'
            . '<img src="' . $b . '/assets/biwork-logo.png" width="150" height="40" alt="Biwork" style="margin-bottom:24px">'
            . '<h1>' . Util::e($title) . '</h1><div class="lg-info"><p>' . Util::e($message) . '</p>'
            . ($link !== '' ? '<p><a class="btn btn-primary" href="' . Util::e($link) . '">Biwork portaliga qaytish</a></p>' : '')
            . '</div></div></main></div></body></html>';
        exit;
    }
}
