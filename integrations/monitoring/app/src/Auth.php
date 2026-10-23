<?php
declare(strict_types=1);

/**
 * Kirish: sessiya, rollar, CSRF.
 *   admin — hamma narsa (qoidalar, foydalanuvchilar)
 *   user  — ogohlantirishlar bilan ishlaydi (ko'rdim, mas'ul, izoh, yopish)
 * Foydalanuvchi yo'q va so'rov shu kompyuterdan bo'lsa — loginsiz ochiladi (mahalliy sinov); tarmoqdan — "Tizim sozlanmagan".
 */
final class Auth
{
    private static bool $started = false;
    private const NAME = 'monsid';

    public static function start(): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }
        $dir = Config::dataDir() . '/sessions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            session_save_path($dir);
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_name(self::NAME);
        session_set_cookie_params(['lifetime' => 0, 'path' => Util::basePath() ?: '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
        ini_set('session.gc_maxlifetime', '43200');
        session_start();
        self::$started = true;
    }

    public static function local(): bool
    {
        return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    }

    public static function hasUsers(): bool
    {
        Schema::ensure();
        return (int)Db::val('SELECT COUNT(*) FROM users WHERE active = 1') > 0;
    }

    /** 'ok' | 'login' | 'setup' */
    public static function state(): string
    {
        self::start();
        if (self::user() !== null) {
            return 'ok';
        }
        if (!self::hasUsers()) {
            return self::local() ? 'ok' : 'setup';
        }
        return 'login';
    }

    public static function user(): ?array
    {
        self::start();
        $id = (int)($_SESSION['uid'] ?? 0);
        if ($id > 0) {
            $u = self::mainDb(static fn() => Db::one('SELECT id, login, name, role FROM users WHERE id = ? AND active = 1', [$id]));
            if ($u) {
                return $u;
            }
            unset($_SESSION['uid']);
        }
        if (!self::hasUsers() && self::local()) {
            return ['id' => 0, 'login' => 'local', 'name' => 'Mahalliy foydalanuvchi', 'role' => 'admin'];
        }
        return null;
    }

    /** Foydalanuvchilar asosiy bazada; demo tashrifchi nusxasi faol bo'lsa ham shu yerdan olinadi. */
    private static function mainDb(callable $fn): mixed
    {
        $cur = Db::path();
        $main = Config::dbPath();
        if ($cur === $main) {
            return $fn();
        }
        Db::use(null);
        try {
            return $fn();
        } finally {
            Db::use($cur);
        }
    }

    public static function can(string $role): bool
    {
        $u = self::user();
        if (!$u) {
            return false;
        }
        return $role === 'admin' ? $u['role'] === 'admin' : in_array($u['role'], ['admin', 'user'], true);
    }

    public static function loginAs(array $row): void
    {
        self::start();
        session_regenerate_id(true);
        $_SESSION = ['uid' => (int)$row['id'], 'csrf' => bin2hex(random_bytes(16))];
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /** Parol bilan kirish; 15 daqiqada 5 noto'g'ri urinishdan keyin qulflanadi. */
    public static function attempt(string $login, string $password): ?array
    {
        Schema::ensure();
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $now = time();
        Db::exec('DELETE FROM login_attempts WHERE at < ?', [$now - 900]);
        $n = (int)Db::val('SELECT COUNT(*) FROM login_attempts WHERE login = ? AND ip = ?', [$login, $ip]);
        if ($n >= 5) {
            throw new ApiError(429, 'locked', "Juda ko'p noto'g'ri urinish. 15 daqiqadan keyin qayta urinib ko'ring.");
        }
        $u = Db::one('SELECT * FROM users WHERE login = ? AND active = 1', [$login]);
        if (!$u || !password_verify($password, (string)$u['password_hash'])) {
            Db::insert('login_attempts', ['ip' => $ip, 'login' => $login, 'at' => $now]);
            return null;
        }
        Db::exec('DELETE FROM login_attempts WHERE login = ? AND ip = ?', [$login, $ip]);
        return $u;
    }

    public static function createUser(string $login, string $name, string $role, ?string $password): int
    {
        Schema::ensure();
        return Db::insert('users', ['login' => $login, 'name' => $name, 'role' => $role,
            'password_hash' => password_hash($password ?? bin2hex(random_bytes(24)), PASSWORD_DEFAULT), 'active' => 1, 'created_at' => time()]);
    }

    public static function csrfToken(): string
    {
        self::start();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        return (string)$_SESSION['csrf'];
    }

    public static function checkCsrf(?string $t): bool
    {
        self::start();
        return $t !== null && !empty($_SESSION['csrf']) && hash_equals((string)$_SESSION['csrf'], $t);
    }

    public static function pageHeaders(): void
    {
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    }
}
