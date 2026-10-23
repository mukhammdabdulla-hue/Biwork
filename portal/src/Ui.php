<?php
declare(strict_types=1);

/** Shablonlar uchun kichik yordamchilar. */
final class Ui
{
    private const ICONS = [
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'tool' => '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18v3h3l6.3-6.3a4 4 0 0 0 5.4-5.4l-2.6 2.6-2.4-.6-.6-2.4z"/>',
        'plan' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 9h5M7 13h9M7 17h4"/>',
        'key' => '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9m-3 3l3 3m-6-3l2 2"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.4 3.2-5.5 6.5-5.5s5.9 2.1 6.5 5.5M16 4.8a3.5 3.5 0 0 1 0 6.4M18 14.8c1.9.7 3.2 2.4 3.5 5.2"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c.7-4 3.8-6.5 8-6.5s7.3 2.5 8 6.5"/>',
        'list' => '<path d="M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01"/>',
        'inbox' => '<path d="M3 13l3-8h12l3 8v6a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1z"/><path d="M3 13h5l1 3h6l1-3h5"/>',
        'download' => '<path d="M12 3v12m0 0l-4.5-4.5M12 15l4.5-4.5M4 20h16"/>',
        'device' => '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
        'out' => '<path d="M9 4H5a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h4M16 8l4 4-4 4M20 12H9"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon' => '<path d="M20 14.5A8.5 8.5 0 0 1 9.5 4a8.5 8.5 0 1 0 10.5 10.5z"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'external' => '<path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
        'copy' => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h9"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'chart' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'building' => '<path d="M4 21V4a1 1 0 0 1 1-1h9a1 1 0 0 1 1 1v17M15 9h4a1 1 0 0 1 1 1v11M8 8h3M8 12h3M8 16h3M3 21h18"/>',
        'shield' => '<path d="M12 3l8 3v6c0 4.5-3.2 8-8 9-4.8-1-8-4.5-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
        'megaphone' => '<path d="M3 11v2a1 1 0 0 0 1 1h3l8 4V6L7 10H4a1 1 0 0 0-1 1zM19 9a4 4 0 0 1 0 6"/>',
        'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'play' => '<circle cx="12" cy="12" r="9"/><path d="M10 8.5l5 3.5-5 3.5z"/>',
        'send' => '<path d="M21 3L10 14M21 3l-7 18-4-7-7-4z"/>',
        'laptop' => '<rect x="4" y="5" width="16" height="11" rx="2"/><path d="M2 20h20"/>',
        'lock' => '<rect x="4" y="10" width="16" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 3.8 5.6 3.8 9s-1.3 6.4-3.8 9c-2.5-2.6-3.8-5.6-3.8-9S9.5 5.6 12 3z"/>',
        'check' => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'target' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.2"/>',
        'wrench' => '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18v3h3l6.3-6.3a4 4 0 0 0 5.4-5.4l-2.6 2.6-2.4-.6-.6-2.4z"/>',
        'excel' => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8l4 8M12 8l-4 8M16 8v8"/>',
        'phone' => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a1 1 0 0 1-1 1A16 16 0 0 1 4 5a1 1 0 0 1 1-1z"/>',
        'x' => '<path d="M6 6l12 12M18 6L6 18"/>',
        'left' => '<path d="M15 5l-7 7 7 7"/>',
        'right' => '<path d="M9 5l7 7-7 7"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    ];

    public static function icon(string $name): string
    {
        $p = self::ICONS[$name] ?? self::ICONS['tool'];
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
    }

    public static function csrf(): string
    {
        return '<input type="hidden" name="csrf" value="' . e(Auth::csrfToken()) . '">';
    }

    /** Litsenziya holati belgisi. */
    public static function state(string $state): string
    {
        $tone = ['active' => 'ok', 'pending' => 'info', 'suspended' => 'warn', 'expired' => 'bad', 'revoked' => 'bad'][$state] ?? '';
        return '<span class="badge ' . $tone . '">' . e(Licenses::STATUS_UZ[$state] ?? $state) . '</span>';
    }

    public static function type(string $type): string
    {
        $tone = ['trial' => 'info', 'paid' => 'ok', 'internal' => ''][$type] ?? '';
        return '<span class="badge ' . $tone . '">' . e(Licenses::TYPE_UZ[$type] ?? $type) . '</span>';
    }

    /** "12 kun qoldi" / "Muddatsiz" / "3 kun oldin tugagan". */
    public static function daysText(array $lic): string
    {
        if ($lic['valid_until'] === null) {
            return 'Muddatsiz';
        }
        $d = (int)$lic['days_left'];
        if ($d > 0) {
            return $d . ' kun qoldi';
        }
        return $d === 0 ? 'Bugun tugaydi' : abs($d) . ' kun oldin tugagan';
    }

    public static function toolIcon(string $icon): string
    {
        return self::icon($icon);
    }

    public static function pager(int $page, int $pages, string $baseQs): string
    {
        if ($pages <= 1) {
            return '';
        }
        $h = '<div class="actions" style="justify-content:center;margin-top:16px">';
        if ($page > 1) {
            $h .= '<a class="btn btn-sm" href="?' . e($baseQs . '&page=' . ($page - 1)) . '">← Oldingi</a>';
        }
        $h .= '<span class="muted small">' . $page . ' / ' . $pages . '</span>';
        if ($page < $pages) {
            $h .= '<a class="btn btn-sm" href="?' . e($baseQs . '&page=' . ($page + 1)) . '">Keyingi →</a>';
        }
        return $h . '</div>';
    }

    private static array $actors = [];

    /** license_events.actor ni o'qiladigan matnga aylantiradi. */
    public static function actor(string $a): string
    {
        if ($a === 'api') {
            return 'Ilova (API)';
        }
        if ($a === 'system' || $a === '') {
            return 'Tizim';
        }
        if (preg_match('/^(?:user|staff):(\d+)$/', $a, $m)) {
            $id = (int)$m[1];
            if (!isset(self::$actors[$id])) {
                $r = Db::one('SELECT name, email, role FROM users WHERE id = ?', [$id]);
                self::$actors[$id] = $r ? (($r['name'] ?: $r['email']) . ($r['role'] === 'staff' ? ' (xodim)' : '')) : 'Foydalanuvchi #' . $id;
            }
            return self::$actors[$id];
        }
        return $a;
    }

    public const ACTION_UZ = [
        'issued' => 'Litsenziya berildi', 'activated' => 'Faollashtirildi', 'device_released' => "Qurilma bo'shatildi", 'suspended' => "To'xtatildi",
        'resumed' => 'Qayta yoqildi', 'revoked' => 'Bekor qilindi', 'extended' => 'Muddat o\'zgardi', 'seats' => "O'rindiqlar o'zgardi",
        'launch' => 'Brauzerda ochildi', 'download' => 'Yuklab olindi', 'login' => 'Kirdi', 'customer_created' => 'Korxona yaratildi',
        'purchase_request' => "Sotib olish so'rovi", 'user_added' => "Xodim qo'shildi", 'settings' => 'Sozlamalar o\'zgardi', 'request_handled' => "So'rov bajarildi", 'lead_updated' => 'Murojaat yangilandi',
    ];
}
