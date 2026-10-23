<?php
declare(strict_types=1);

/**
 * php -S uchun router:  php -S 0.0.0.0:8090 -t public public/router.php
 *   /api/*  -> api.php;  mavjud statik fayllar o'zi beriladi;  qolgani -> index.php
 */
if (!defined('BW_BASE')) {
    define('BW_BASE', '');
}
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

if ($path === '/api' || str_starts_with($path, '/api/')) {
    $_SERVER['SCRIPT_NAME'] = '/api.php';
    require __DIR__ . '/api.php';
    return true;
}
if (preg_match('#(^|/)\.#', $path)) {
    http_response_code(403);
    return true;
}
// brauzer demosi (admin «Demo versiyalari», zip paket): public/demo/<slug>/ ni php -S o'zi beradi (index.php/index.html bilan)
if (preg_match('#^/demo/([a-z0-9-]+)(/|$)#', $path, $dm) && is_dir(__DIR__ . '/demo/' . $dm[1])) {
    return false;
}
$file = realpath(__DIR__ . $path);
if ($path !== '/' && $file !== false && is_file($file) && str_starts_with($file, __DIR__ . DIRECTORY_SEPARATOR)
    && !in_array(basename($file), ['router.php', 'api.php', 'index.php'], true) && !str_ends_with($file, '.php')) {
    return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/index.php';
return true;
