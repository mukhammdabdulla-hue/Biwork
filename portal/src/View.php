<?php
declare(strict_types=1);

/** Shablonlarni chizish: src/views/<nom>.php, ramka — views/layout.php. */
final class View
{
    public static function render(string $tpl, array $vars = []): string
    {
        $file = __DIR__ . '/views/' . $tpl . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('Shablon topilmadi: ' . $tpl);
        }
        extract($vars, EXTR_SKIP);
        ob_start();
        try {
            include $file;
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string)ob_get_clean();
    }

    /**
     * @param array $o area: 'public'|'auth'|'app'|'admin'; title; nav (faol menyu kaliti)
     */
    public static function page(string $tpl, array $vars = [], array $o = [], int $status = 200): never
    {
        $o += ['area' => 'public', 'title' => 'Biwork', 'nav' => ''];
        $content = self::render($tpl, $vars);
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: same-origin');
        echo self::render('layout', ['content' => $content, 'o' => $o, 'user' => Auth::user(), 'flash' => Auth::takeFlash()]);
        exit;
    }

    public static function error(int $status, string $message): never
    {
        $titles = [403 => 'Ruxsat yo\'q', 404 => 'Sahifa topilmadi', 405 => 'Usul ruxsat etilmagan', 413 => 'Fayllar juda katta', 419 => 'Sahifa eskirgan', 429 => "Juda ko'p urinish", 500 => 'Xatolik yuz berdi'];
        $area = 'public';
        $u = Auth::user();
        if ($u !== null) {
            $area = $u['role'] === 'staff' ? 'admin' : 'app';
        }
        self::page('error', ['status' => $status, 'message' => $message, 'heading' => $titles[$status] ?? 'Xatolik'], ['area' => $area, 'title' => $titles[$status] ?? 'Xatolik'], $status);
    }
}
