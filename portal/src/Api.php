<?php
declare(strict_types=1);

/**
 * Litsenziya API (/api/v1). Protokol: docs/TOOL_CONTRACT.md §3.
 *   GET  /api/v1/health
 *   GET  /api/v1/pubkey
 *   POST /api/v1/licenses/activate     {key, device_id, tool?, device_name?, platform?, app_version?}
 *   POST /api/v1/licenses/check        {key, device_id, tool?, app_version?}
 *   POST /api/v1/licenses/deactivate   {key, device_id}
 * Muvaffaqiyat: {"ok":true,...}. Xato: {"ok":false,"error":{"code","message"}} + HTTP holati.
 */
final class Api
{
    public const VERSION = '1.0';

    public static function handle(): never
    {
        $path = rawurldecode((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
        $bp = Util::basePath();
        if ($bp !== '' && str_starts_with($path, $bp)) {
            $path = substr($path, strlen($bp));
        }
        $path = '/' . trim(preg_replace('#^/api#', '', $path) ?? '', '/');
        $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
        try {
            if ($method === 'GET' && $path === '/v1/health') {
                Util::json(['ok' => true, 'service' => 'biwork-license', 'api' => self::VERSION, 'time' => Util::now()]);
            }
            if ($method === 'GET' && $path === '/v1/pubkey') {
                Util::json(['ok' => true, 'issuer' => 'biwork', 'keys' => Tokens::publicKeys()]);
            }
            if ($method === 'POST' && preg_match('#^/v1/licenses/(activate|check|deactivate)$#', $path, $m)) {
                self::license($m[1]);
            }
            Util::json(['ok' => false, 'error' => ['code' => 'not_found', 'message' => 'Manzil topilmadi']], 404);
        } catch (LicenseError $e) {
            Util::json(['ok' => false, 'error' => ['code' => $e->errorCode, 'message' => $e->getMessage()] + $e->extra], $e->status);
        } catch (HttpError $e) {
            Util::json(['ok' => false, 'error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]], $e->status);
        } catch (Throwable $e) {
            @file_put_contents(Config::dataDir() . '/error.log', date('c') . ' API ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n", FILE_APPEND | LOCK_EX);
            Util::json(['ok' => false, 'error' => ['code' => 'server_error', 'message' => 'Server xatosi. Keyinroq qayta urinib ko\'ring']], 500);
        }
    }

    private static function body(): array
    {
        $raw = file_get_contents('php://input', false, null, 0, 16385);
        if ($raw !== false && strlen($raw) > 16384) {
            throw new LicenseError('bad_request', "So'rov juda katta", 413);
        }
        $ct = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
        $first = $raw !== false ? (ltrim($raw)[0] ?? '') : '';
        if (str_contains($ct, 'json') || $first === '{') {
            $d = json_decode((string)$raw, true);
            if (!is_array($d)) {
                throw new LicenseError('bad_request', "JSON noto'g'ri", 400);
            }
            return $d;
        }
        return $_POST;
    }

    private static function license(string $op): never
    {
        $ip = Util::ip();
        if (!RateLimit::hit('api:' . $ip, 120, 60)) {
            throw new LicenseError('rate_limited', "Juda ko'p so'rov. Bir daqiqadan so'ng qayta urinib ko'ring", 429);
        }
        if (RateLimit::count('badkey:' . $ip, 600) >= 10) {
            throw new LicenseError('rate_limited', "Noto'g'ri kalitlar juda ko'p kiritildi. 10 daqiqadan so'ng qayta urinib ko'ring", 429);
        }
        $b = self::body();
        $s = static fn(string $k, int $max = 200): string => is_scalar($b[$k] ?? null) ? mb_substr((string)$b[$k], 0, $max) : '';
        $key = $s('key', 40);
        $dev = $s('device_id', 80);
        if ($key === '' || $dev === '') {
            throw new LicenseError('bad_request', 'key va device_id majburiy', 400);
        }
        $meta = ['device_name' => $s('device_name', 80), 'platform' => $s('platform', 40), 'app_version' => $s('app_version', 20)];
        try {
            if ($op === 'deactivate') {
                Licenses::deactivate($key, $dev);
                Util::json(['ok' => true, 'server_time' => Util::now()]);
            }
            $res = $op === 'activate'
                ? Licenses::activate($key, $dev, $meta, $s('tool', 40) ?: null)
                : Licenses::check($key, $dev, $meta, $s('tool', 40) ?: null);
        } catch (LicenseError $e) {
            if ($e->errorCode === 'invalid_key') {
                RateLimit::hit('badkey:' . $ip, 10, 600);
            }
            throw $e;
        }
        Util::json(['ok' => true, 'server_time' => Util::now()] + $res);
    }
}
