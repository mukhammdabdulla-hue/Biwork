<?php
declare(strict_types=1);

/** Kichik router: '/admin/licenses/{id}' kabi andozalar; POST uchun CSRF avtomatik. */
final class Router
{
    /** @var list<array{string,string,callable,bool}> */
    private static array $routes = [];

    public static function get(string $pattern, callable $h): void
    {
        self::$routes[] = ['GET', self::rx($pattern), $h, false];
    }

    public static function post(string $pattern, callable $h): void
    {
        self::$routes[] = ['POST', self::rx($pattern), $h, false];
    }

    /** @var ?callable(string):bool hech bir andoza mos kelmaganda chaqiriladi; true qaytarsa — so'rov hal qilingan */
    private static $fallback = null;

    public static function fallback(callable $h): void
    {
        self::$fallback = $h;
    }

    private static function rx(string $pattern): string
    {
        $rx = preg_replace_callback('/\{(\w+)(?::([^}]+))?\}/', static fn($m) => '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')', $pattern);
        return '#^' . $rx . '/?$#';
    }

    public static function dispatch(string $method, string $path): void
    {
        $matched = false;
        foreach (self::$routes as [$m, $rx, $h]) {
            if (!preg_match($rx, $path, $mm)) {
                continue;
            }
            $matched = true;
            if ($m !== $method && !($method === 'HEAD' && $m === 'GET')) {
                continue;
            }
            $params = array_filter($mm, 'is_string', ARRAY_FILTER_USE_KEY);
            if ($method === 'POST' && !$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data')) {
                // PHP post_max_size dan oshgan yuklashni butunlay tashlab yuboradi — "sahifa eskirgan" deb adashtirmaymiz
                throw new HttpError(413, "Yuborilgan fayllar hajmi serverning chegarasidan katta (post_max_size = " . ini_get('post_max_size') . "). Rasmlarni kichraytirib yoki kamroq yuborib qayta urinib ko'ring");
            }
            if ($method === 'POST' && !Auth::checkCsrf(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null)) {
                throw new HttpError(419, 'Sahifa eskirgan. Sahifani yangilab qayta urinib ko\'ring');
            }
            $h($params);
            return;
        }
        if (!$matched && self::$fallback !== null && (self::$fallback)($method, $path) === true) {
            return;
        }
        throw new HttpError($matched ? 405 : 404, $matched ? 'Usul ruxsat etilmagan' : 'Sahifa topilmadi');
    }
}
