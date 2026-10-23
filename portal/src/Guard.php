<?php
declare(strict_types=1);

/** Sahifalar uchun kirish nazorati. */
final class Guard
{
    /** Kirgan har qanday foydalanuvchi, aks holda /login ga. */
    public static function user(): array
    {
        $u = Auth::user();
        if ($u === null) {
            $next = (string)($_SERVER['REQUEST_URI'] ?? '');
            $bp = Util::basePath();
            if ($bp !== '' && str_starts_with($next, $bp)) {
                $next = substr($next, strlen($bp));
            }
            Util::redirect('/login' . (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && $next !== '' && $next !== '/' ? '?next=' . rawurlencode($next) : ''));
        }
        // egasi qo'shgan xodim vaqtinchalik parolni birinchi kirishda almashtirishi shart
        $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if ((int)$u['must_change_password'] === 1 && !preg_match('#/(app|admin)/profile$|/logout$#', $path)) {
            Auth::flash('info', 'Davom etish uchun vaqtinchalik parolni almashtiring');
            Util::redirect($u['role'] === 'staff' ? '/admin/profile' : '/app/profile');
        }
        return $u;
    }

    /** Korxona foydalanuvchisi (owner/member). Xodimlar /admin ga yo'naltiriladi. */
    public static function customer(): array
    {
        $u = self::user();
        if ($u['role'] === 'staff') {
            Util::redirect('/admin');
        }
        return $u;
    }

    public static function owner(): array
    {
        $u = self::customer();
        if ($u['role'] !== 'owner') {
            throw new HttpError(403, "Bu amal faqat korxona egasiga ruxsat etilgan");
        }
        return $u;
    }

    public static function staff(): array
    {
        $u = self::user();
        if ($u['role'] !== 'staff') {
            throw new HttpError(403, 'Bu bo\'lim faqat Biwork xodimlari uchun');
        }
        return $u;
    }

    /** Faqat ichki yo'llarga (open-redirect yo'q). */
    public static function safeNext(?string $next): string
    {
        if ($next === null || !preg_match('#^/(?!/)[A-Za-z0-9/_\-.?=&%]*$#', $next) || str_starts_with($next, '/login')) {
            return '';
        }
        return $next;
    }
}
