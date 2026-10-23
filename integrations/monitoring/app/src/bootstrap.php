<?php
declare(strict_types=1);

/**
 * Biwork Monitoring — bootstrap: avtoyuklash, vaqt mintaqasi, sozlamalar, yordamchilar.
 *
 * Sozlamalar muhit o'zgaruvchilari yoki config.php (ildizda, veb orqali ochilmaydi) orqali:
 *   MON_CONFIG  config.php yo'li (standart: <ildiz>/config.php)
 *   MON_DB      SQLite fayli (standart: data/monitoring.sqlite)
 *   MON_NOW     sinov uchun soxta "hozir", 'YYYY-MM-DDTHH:MM'
 * config.php kalitlari: db, demo_data (namuna korxona va simulyatsiya soati), reja_url, biwork[...]
 */

date_default_timezone_set('Asia/Tashkent');
mb_internal_encoding('UTF-8');

if (!defined('MON_ROOT')) {
    define('MON_ROOT', dirname(__DIR__));
}
const MON_VERSION = '0.1';

spl_autoload_register(static function (string $class): void {
    $file = __DIR__ . '/' . $class . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

final class ApiError extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}

final class Config
{
    private static ?array $file = null;

    public static function env(string $name, ?string $default = null): ?string
    {
        $v = getenv($name);
        return ($v === false || $v === '') ? $default : $v;
    }

    public static function file(): array
    {
        if (self::$file === null) {
            $p = self::env('MON_CONFIG') ?? MON_ROOT . '/config.php';
            $data = is_file($p) ? (static fn() => include $p)() : [];
            self::$file = is_array($data) ? $data : [];
        }
        return self::$file;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::file()[$key] ?? $default;
    }

    /** Namuna korxona rejimi: sozlamada aniq berilmasa — yoqilgan (haqiqiy ma'lumot manbalari 2-bosqichda). */
    public static function demoData(): bool
    {
        return (bool)self::get('demo_data', true);
    }

    public static function abs(string $p): string
    {
        return ($p !== '' && ($p[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $p))) ? $p : MON_ROOT . '/' . $p;
    }

    public static function dbPath(): string
    {
        return self::abs(self::env('MON_DB') ?? (string)self::get('db', 'data/monitoring.sqlite'));
    }

    public static function dataDir(): string
    {
        return MON_ROOT . '/data';
    }
}

final class Util
{
    /** Sayt papkadagi manzil prefiksi (php -S da bo'sh; Plesk'da /monitoring bo'lishi mumkin). */
    public static function basePath(): string
    {
        if (defined('MON_BASE')) {
            return (string)MON_BASE;
        }
        $s = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $d = rtrim(str_replace('\\', '/', dirname($s)), '/');
        return ($d === '' || $d === '.') ? '' : $d;
    }

    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public static function body(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }
        $d = json_decode($raw, true);
        if (!is_array($d)) {
            throw new ApiError(400, 'bad_json', "So'rov tanasi noto'g'ri");
        }
        return $d;
    }

    public static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    /** Daqiqani "1 soat 50 daqiqa" ko'rinishida yozadi. */
    public static function dur(float|int $min): string
    {
        $m = (int)round($min);
        if ($m < 1) {
            return '1 daqiqadan kam';
        }
        $h = intdiv($m, 60);
        $r = $m % 60;
        if ($h >= 48) {
            $d = intdiv($h, 24);
            $hh = $h % 24;
            return $d . ' kun' . ($hh ? ' ' . $hh . ' soat' : '');
        }
        if ($h === 0) {
            return $m . ' daqiqa';
        }
        return $h . ' soat' . ($r ? ' ' . $r . ' daqiqa' : '');
    }

    public static function hm(int $ts): string
    {
        return date('H:i', $ts);
    }

    public static function dm(int $ts): string
    {
        return date('d.m', $ts);
    }

    public static function dmhm(int $ts): string
    {
        return date('d.m H:i', $ts);
    }
}

/** Birinchi so'rovda bazani yaratadi va (namuna korxona rejimida) namuna ma'lumotni tayyorlaydi. */
final class Boot
{
    public static function ready(): void
    {
        Schema::ensure();
        if (!Config::demoData() || Db::meta('demo') !== null) {
            return;
        }
        $lock = fopen(Config::dataDir() . '/seed.lock', 'c');
        if ($lock === false) {
            Demo::seed();
            return;
        }
        flock($lock, LOCK_EX);
        try {
            Db::close();
            if (Db::meta('demo') === null) {
                Demo::seed();
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
