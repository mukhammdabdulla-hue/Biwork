<?php
declare(strict_types=1);

/** HTML-ga xavfsiz chiqarish. */
function e(mixed $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

final class Util
{
    /** Hozirgi vaqt (unix sekund). Sinovlarda BIWORK_NOW bilan almashtiriladi. */
    public static function now(): int
    {
        $f = getenv('BIWORK_NOW');
        if ($f !== false && $f !== '') {
            if (ctype_digit($f)) {
                return (int)$f;
            }
            $t = strtotime($f);
            if ($t !== false) {
                return $t;
            }
        }
        return time();
    }

    public static function basePath(): string
    {
        if (defined('BW_BASE')) {
            return (string)BW_BASE;
        }
        $sn = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $dir = $sn === '' ? '' : dirname($sn);
        $dir = rtrim(str_replace('\\', '/', $dir), '/.');
        return preg_match('#^(/[^/\\x00-\\x1f"\'<>\\\\]+)*$#u', $dir) ? $dir : '';
    }

    /** Portal ichidagi manzil (papka ichida ham ishlaydi). */
    public static function url(string $path = '/'): string
    {
        return self::basePath() . $path;
    }

    /** Ochiq mutlaq manzil: sozlamadagi base_url yoki so'rovdan. */
    public static function absUrl(string $path = '/'): string
    {
        $base = Config::get('BIWORK_URL');
        if ($base === null) {
            $https = Auth::isHttps();
            $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
            $base = ($https ? 'https://' : 'http://') . $host . self::basePath();
        }
        return rtrim($base, '/') . $path;
    }

    public static function ip(): string
    {
        return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
    }

    public static function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $s): string|false
    {
        $s = strtr($s, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad) {
            $s .= str_repeat('=', 4 - $pad);
        }
        return base64_decode($s, true);
    }

    /** Berilgan sananing (YYYY-MM-DD) Toshkent vaqti bilan oxiri, unix sekund. */
    public static function endOfDay(string $ymd): ?int
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, new DateTimeZone('Asia/Tashkent'));
        if (!$d || $d->format('Y-m-d') !== $ymd) {
            return null;
        }
        return $d->setTime(23, 59, 59)->getTimestamp();
    }

    public static function date(?int $ts): string
    {
        return $ts ? date('d.m.Y', $ts) : '—';
    }

    public static function dateTime(?int $ts): string
    {
        return $ts ? date('d.m.Y H:i', $ts) : '—';
    }

    public static function ymd(?int $ts): string
    {
        return $ts ? date('Y-m-d', $ts) : '';
    }

    /** Qolgan kunlar (butun, yuqoriga yaxlitlangan); null = muddatsiz. */
    public static function daysLeft(?int $until): ?int
    {
        return $until === null ? null : (int)ceil(($until - self::now()) / 86400);
    }

    public static function bytes(int $n): string
    {
        if ($n >= 1048576) {
            return number_format($n / 1048576, 1, '.', '') . ' MB';
        }
        return $n >= 1024 ? number_format($n / 1024, 0, '.', '') . ' KB' : $n . ' B';
    }

    public static function money(?int $amount, string $cur = 'UZS'): string
    {
        return $amount === null ? '—' : number_format($amount, 0, '.', ' ') . ' ' . ($cur === 'UZS' ? "so'm" : $cur);
    }

    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public static function redirect(string $path, int $status = 303): never
    {
        $loc = preg_match('#^https?://#i', $path) ? $path : self::url($path);
        header('Location: ' . $loc, true, $status);
        exit;
    }

    public static function emailOk(string $s): bool
    {
        return strlen($s) <= 120 && filter_var($s, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function slug(string $s): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)) ?? '', '-');
    }
}
