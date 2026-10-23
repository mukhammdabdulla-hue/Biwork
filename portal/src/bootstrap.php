<?php
declare(strict_types=1);

/**
 * Biwork Portal — bootstrap: autoload, timezone, errors, config.
 *
 * Sozlamalar: muhit o'zgaruvchilari, so'ng config.php (loyiha ildizida, veb orqali ochilmaydi):
 *   BIWORK_DATA   data papkasi (standart: <ildiz>/data)             config kaliti: data_dir
 *   BIWORK_URL    portalning ochiq manzili (https://biwork.uz)       config kaliti: base_url
 *   BIWORK_NOW    sinov uchun soxta "hozir" (unix sekund yoki ISO)
 *   BIWORK_CONFIG config.php yo'li
 */

date_default_timezone_set('Asia/Tashkent');
mb_internal_encoding('UTF-8');

if (!defined('BW_ROOT')) {
    define('BW_ROOT', dirname(__DIR__));
}

spl_autoload_register(static function (string $class): void {
    $file = __DIR__ . '/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

require_once __DIR__ . '/Util.php';

set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

/** Foydalanuvchiga ko'rsatish mumkin bo'lgan xato (HTTP holati bilan). */
final class HttpError extends RuntimeException
{
    public function __construct(public readonly int $status, string $message, public readonly string $errorCode = 'error')
    {
        parent::__construct($message);
    }
}

/** Litsenziya xatosi: kod (API uchun) + o'zbekcha xabar + HTTP holati. */
final class LicenseError extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 403, public readonly array $extra = [])
    {
        parent::__construct($message);
    }
}

final class Config
{
    private const KEYS = ['BIWORK_DATA' => 'data_dir', 'BIWORK_URL' => 'base_url'];
    private static ?array $file = null;

    public static function env(string $name, ?string $default = null): ?string
    {
        $v = getenv($name);
        return ($v === false || $v === '') ? $default : $v;
    }

    public static function get(string $name, ?string $default = null): ?string
    {
        $v = self::env($name);
        if ($v !== null) {
            return $v;
        }
        $k = self::KEYS[$name] ?? null;
        $f = self::file();
        if ($k !== null && isset($f[$k]) && is_scalar($f[$k]) && (string)$f[$k] !== '') {
            return (string)$f[$k];
        }
        return $default;
    }

    public static function file(): array
    {
        if (self::$file === null) {
            $p = self::env('BIWORK_CONFIG') ?? BW_ROOT . '/config.php';
            $data = is_file($p) ? (static fn() => include $p)() : [];
            self::$file = is_array($data) ? $data : [];
        }
        return self::$file;
    }

    /** config.php ichidagi ixtiyoriy kalit (massiv yoki skalyar). */
    public static function raw(string $key, mixed $default = null): mixed
    {
        return self::file()[$key] ?? $default;
    }

    public static function dataDir(): string
    {
        $d = self::get('BIWORK_DATA', BW_ROOT . '/data');
        return rtrim(str_starts_with($d, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $d) ? $d : BW_ROOT . '/' . $d, '/\\');
    }

    public static function dbPath(): string
    {
        return self::dataDir() . '/biwork.sqlite';
    }
}
