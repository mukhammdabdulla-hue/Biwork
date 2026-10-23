<?php
declare(strict_types=1);

/**
 * php -S uchun router:  php -S 0.0.0.0:8090 -t public public/router.php
 * Mavjud fayllar (assets/*) to'g'ridan-to'g'ri, /api/* — api.php, qolgani — SPA qobig'i (index.php).
 */
if (!defined('MON_BASE')) {
    define('MON_BASE', '');
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
$file = realpath(__DIR__ . $path);
if ($path !== '/' && $file !== false && is_file($file) && str_starts_with($file, __DIR__ . DIRECTORY_SEPARATOR)
    && !in_array(basename($file), ['router.php', 'api.php'], true)) {
    if (str_ends_with($file, '.php')) {
        require $file;
        return true;
    }
    return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/index.php';
return true;
