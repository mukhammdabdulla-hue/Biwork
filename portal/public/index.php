<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/routes_public.php';
require __DIR__ . '/../src/routes_app.php';
require __DIR__ . '/../src/routes_admin.php';
require __DIR__ . '/../src/routes_demos.php';

$path = rawurldecode((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
$bp = Util::basePath();
if ($bp !== '' && str_starts_with($path, $bp)) {
    $path = substr($path, strlen($bp));
}
$path = '/' . trim($path, '/');
if ($path === '/index.php') {
    $path = '/';
}

try {
    Router::dispatch((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'), $path === '/' ? '/' : $path);
} catch (HttpError $e) {
    View::error($e->status, $e->getMessage());
} catch (Throwable $e) {
    $log = Config::dataDir() . '/error.log';
    @file_put_contents($log, date('c') . ' ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n", FILE_APPEND | LOCK_EX);
    View::error(500, "Kutilmagan xatolik. Qayta urinib ko'ring; takrorlansa Biwork bilan bog'laning");
}
