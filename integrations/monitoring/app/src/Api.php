<?php
declare(strict_types=1);

/** /api/* — JSON. GET ochiq (kirgan foydalanuvchiga), o'zgartirish CSRF va rol bilan himoyalangan. */
final class Api
{
    public static function handle(): never
    {
        try {
            self::dispatch();
        } catch (ApiError $e) {
            Util::json(['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]], $e->status);
        } catch (Throwable $e) {
            error_log('[Monitoring] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            Util::json(['error' => ['code' => 'server', 'message' => "Ichki xato. Qayta urinib ko'ring."]], 500);
        }
    }

    private static function path(): string
    {
        $p = rawurldecode((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
        $i = strpos($p, '/api');
        $p = $i === false ? $p : substr($p, $i + 4);
        return '/' . trim($p, '/');
    }

    private static function dispatch(): never
    {
        $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = self::path();
        if ($path === '/health') {
            Util::json(['ok' => true, 'service' => 'biwork-monitoring', 'version' => MON_VERSION]);
        }
        Auth::start();
        if (Auth::state() !== 'ok') {
            throw new ApiError(401, 'unauthorized', 'Kirish kerak');
        }
        if (($gate = Biwork::gate()) !== null && $path !== '/me' && $path !== '/logout') {
            Biwork::blockApi($gate);
        }
        if ($method !== 'GET') {
            $tok = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
            if (!Auth::checkCsrf(is_string($tok) ? $tok : null)) {
                throw new ApiError(403, 'csrf', "Sahifa eskirgan. Yangilab qayta urinib ko'ring.");
            }
        }
        Biwork::useVisitorDb();
        Boot::ready();
        $user = Auth::user();
        $now = Clock::now();
        if (!Clock::isDemo()) {
            $last = (int)Db::meta('last_run', '0');
            if ($now - $last > 60) {
                Engine::run($now);
            }
        }

        if ($method === 'GET') {
            switch (true) {
                case $path === '/me':
                    Util::json(self::me($user, $now));
                case $path === '/overview':
                    Util::json(Metrics::overview($now, Engine::snapshot($now)));
                case $path === '/alerts':
                    Util::json(Alerts::list($_GET));
                case (bool)preg_match('#^/alerts/(\d+)$#', $path, $m):
                    Util::json(Alerts::get((int)$m[1]));
                case $path === '/machines':
                    Util::json(['now' => $now, 'items' => Metrics::machines($now, Engine::snapshot($now))]);
                case (bool)preg_match('#^/machines/([a-z0-9_]+)$#', $path, $m):
                    Util::json(Metrics::machineDetail($m[1], $now, Engine::snapshot($now)));
                case $path === '/orders':
                    Util::json(Metrics::orders($now, Engine::snapshot($now)));
                case $path === '/planfact':
                    Util::json(Metrics::planFact($now, Engine::snapshot($now), max(3, min(48, (int)($_GET['before'] ?? 12))) * 3600, max(3, min(48, (int)($_GET['after'] ?? 12))) * 3600));
                case $path === '/rules':
                    Util::json(Rules::describe());
                case $path === '/sources':
                    Util::json(['now' => $now, 'items' => Metrics::sources($now)]);
            }
            throw new ApiError(404, 'not_found', 'Topilmadi');
        }

        if (!Auth::can('user')) {
            throw new ApiError(403, 'forbidden', "Bu amal uchun ruxsat yo'q");
        }
        $body = Util::body();
        $who = (string)$user['name'];
        switch (true) {
            case $method === 'POST' && (bool)preg_match('#^/alerts/(\d+)/(seen|assign|comment|close|reopen)$#', $path, $m):
                Util::json(Alerts::act((int)$m[1], $m[2], $body, $who));
            case $method === 'PUT' && (bool)preg_match('#^/rules/([a-z_]+)$#', $path, $m):
                if (!Auth::can('admin') || Biwork::sharedDemo()) {
                    throw new ApiError(403, 'forbidden', "Qoidalarni faqat administrator o'zgartiradi (umumiy namunada yopiq)");
                }
                Db::tx(static fn() => Rules::save($m[1], array_key_exists('enabled', $body) ? (bool)$body['enabled'] : null, is_array($body['params'] ?? null) ? $body['params'] : []));
                Engine::run(Clock::now());
                Util::json(Rules::describe());
            case $method === 'POST' && $path === '/engine/run':
                Util::json(['result' => Engine::run($now)]);
            case $method === 'POST' && $path === '/demo/advance':
                self::needDemo();
                $t = Demo::advance((int)($body['minutes'] ?? 30));
                Util::json(['now' => $t]);
            case $method === 'POST' && $path === '/demo/reset':
                self::needDemo();
                Demo::reset();
                Util::json(['now' => Clock::now()]);
            case $method === 'POST' && $path === '/logout':
                Auth::logout();
                Util::json(['ok' => true]);
        }
        throw new ApiError(404, 'not_found', 'Topilmadi');
    }

    private static function needDemo(): void
    {
        if (!Clock::isDemo()) {
            throw new ApiError(400, 'not_demo', "Bu amal faqat namuna korxonada ishlaydi");
        }
    }

    private static function me(?array $user, int $now): array
    {
        $demo = Clock::isDemo();
        return [
            'user' => $user ? ['id' => (int)$user['id'], 'name' => $user['name'], 'login' => $user['login'], 'role' => $user['role']] : null,
            'csrf' => Auth::csrfToken(), 'now' => $now, 'demo' => $demo, 'shared_demo' => Biwork::sharedDemo(), 'version' => MON_VERSION,
            'can_act' => Auth::can('user'), 'can_admin' => Auth::can('admin') && !Biwork::sharedDemo(),
            'portal_url' => Biwork::portalUrl(), 'reja_url' => (string)Config::get('reja_url', ''),
            'last_run' => (int)Db::meta('last_run', '0'),
        ];
    }
}
