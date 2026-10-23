<?php
declare(strict_types=1);

/** Sessiya orqali kirish (email + parol), CSRF, rollar. */
final class Auth
{
    public const SESSION_NAME = 'bw_sess';
    public const IDLE_SECONDS = 12 * 3600;
    public const MAX_FAILS = 5;
    public const LOCK_SEC = 900;
    public const ROLE_UZ = ['staff' => 'Biwork xodimi', 'owner' => 'Korxona egasi', 'member' => 'Korxona xodimi'];

    private static ?array $user = null;
    private static bool $loaded = false;

    public static function isHttps(): bool
    {
        $h = strtolower((string)($_SERVER['HTTPS'] ?? ''));
        return ($h !== '' && $h !== 'off')
            || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
            || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
    }

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $dir = Config::dataDir() . '/sessions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            session_save_path($dir);
            ini_set('session.gc_maxlifetime', (string)self::IDLE_SECONDS);
            ini_set('session.gc_probability', '1');
            ini_set('session.gc_divisor', '100');
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name(self::SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => (Util::basePath() ?: '') . '/',
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /** Joriy foydalanuvchi qatori (customers bilan: customer_name, customer_status) yoki null. */
    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$user;
        }
        self::$loaded = true;
        // sessiya cookie'si bo'lmasa, sessiya ochmaymiz (statik fayllar va API uchun)
        if (!isset($_COOKIE[self::SESSION_NAME])) {
            return self::$user = null;
        }
        self::start();
        $uid = $_SESSION['bw_uid'] ?? null;
        $fp = $_SESSION['bw_fp'] ?? null;
        $seen = (int)($_SESSION['bw_seen'] ?? 0);
        $row = is_int($uid) ? self::row($uid) : null;
        if (!$row || !(int)$row['active'] || !is_string($fp) || !hash_equals(self::fingerprint($row), $fp)
            || Util::now() - $seen > self::IDLE_SECONDS) {
            if ($uid !== null) {
                $_SESSION = [];
            }
            return self::$user = null;
        }
        $_SESSION['bw_seen'] = Util::now();
        return self::$user = $row;
    }

    public static function row(int $id): ?array
    {
        return Db::one('SELECT u.*, c.name AS customer_name, c.status AS customer_status
                        FROM users u LEFT JOIN customers c ON c.id = u.customer_id WHERE u.id = ?', [$id]);
    }

    public static function byEmail(string $email): ?array
    {
        return Db::one('SELECT u.*, c.name AS customer_name, c.status AS customer_status
                        FROM users u LEFT JOIN customers c ON c.id = u.customer_id WHERE u.email = ? COLLATE NOCASE', [$email]);
    }

    /** Parol almashganda eski sessiyalar bekor bo'lishi uchun. */
    private static function fingerprint(array $row): string
    {
        return substr(hash('sha256', $row['id'] . "\0" . $row['email'] . "\0" . $row['password_hash']), 0, 24);
    }

    /** @return string 'ok' | 'bad' | 'locked' */
    public static function attempt(string $email, string $password): string
    {
        $email = mb_strtolower(trim($email));
        $bucket = 'login:' . hash('sha1', $email);
        $ipBucket = 'loginip:' . Util::ip();
        if (RateLimit::count($bucket, self::LOCK_SEC) >= self::MAX_FAILS || RateLimit::count($ipBucket, self::LOCK_SEC) >= self::MAX_FAILS * 4) {
            return 'locked';
        }
        $row = $email !== '' ? self::byEmail($email) : null;
        $hash = $row['password_hash'] ?? password_hash(random_bytes(8), PASSWORD_DEFAULT);
        $ok = password_verify($password, $hash) && $row !== null && (int)$row['active'] === 1
            && ($row['role'] === 'staff' || $row['customer_status'] === 'active');
        if (!$ok) {
            RateLimit::hit($bucket, self::MAX_FAILS, self::LOCK_SEC);
            RateLimit::hit($ipBucket, self::MAX_FAILS * 4, self::LOCK_SEC);
            usleep(400000);
            return RateLimit::count($bucket, self::LOCK_SEC) >= self::MAX_FAILS ? 'locked' : 'bad';
        }
        RateLimit::reset($bucket);
        if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
            Db::exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $row['id']]);
        }
        Db::exec('UPDATE users SET last_login_at = ? WHERE id = ?', [Util::now(), $row['id']]);
        self::login((int)$row['id']);
        Licenses::event(null, $row['customer_id'] !== null ? (int)$row['customer_id'] : null, 'user:' . $row['id'], 'login', $row['email']);
        return 'ok';
    }

    public static function login(int $userId): void
    {
        $row = self::row($userId);
        self::start();
        session_regenerate_id(true);
        $_SESSION = [
            'bw_uid' => (int)$row['id'],
            'bw_fp' => self::fingerprint($row),
            'bw_seen' => Util::now(),
            'bw_csrf' => bin2hex(random_bytes(16)),
        ];
        self::$user = $row;
        self::$loaded = true;
    }

    /** O'z parolini almashtirgach joriy sessiyani yangi parolga bog'laydi. */
    public static function refresh(): void
    {
        if (self::$user === null) {
            return;
        }
        $row = self::row((int)self::$user['id']);
        if ($row) {
            self::start();
            session_regenerate_id(true);
            $_SESSION['bw_fp'] = self::fingerprint($row);
            self::$user = $row;
        }
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'],
            'httponly' => true, 'samesite' => 'Lax',
        ]);
        session_destroy();
        self::$user = null;
        self::$loaded = true;
    }

    public static function csrfToken(): string
    {
        self::start();
        if (!is_string($_SESSION['bw_csrf'] ?? null)) {
            $_SESSION['bw_csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['bw_csrf'];
    }

    public static function checkCsrf(?string $token): bool
    {
        self::start();
        return is_string($token) && $token !== '' && is_string($_SESSION['bw_csrf'] ?? null)
            && hash_equals($_SESSION['bw_csrf'], $token);
    }

    public static function passwordProblem(string $password, string $email = ''): ?string
    {
        if (mb_strlen($password) < 10) {
            return "Parol kamida 10 belgidan iborat bo'lsin";
        }
        if (strlen($password) > 200) {
            return 'Parol juda uzun';
        }
        if ($email !== '' && mb_strtolower($password) === mb_strtolower($email)) {
            return "Parol email bilan bir xil bo'lmasin";
        }
        return null;
    }

    public static function flash(string $kind, string $msg): void
    {
        self::start();
        $_SESSION['bw_flash'][] = [$kind, $msg];
    }

    public static function takeFlash(): array
    {
        if (!isset($_COOKIE[self::SESSION_NAME]) && session_status() !== PHP_SESSION_ACTIVE) {
            return [];
        }
        self::start();
        $f = $_SESSION['bw_flash'] ?? [];
        unset($_SESSION['bw_flash']);
        return is_array($f) ? $f : [];
    }
}
