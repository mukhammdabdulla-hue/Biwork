<?php
declare(strict_types=1);

/**
 * Bepul sinov holati: kartalar va mahsulot sahifasi «30 kun bepul»ni halol ko'rsatadi.
 * Brauzer demo manzili bo'lmasa sinov ishlamaydi: shu holatda nima yetishmayotgani ochiq yoziladi (va'da qilinmaydi).
 */
final class Trial
{
    /**
     * state: ready (sinab bo'ladi) | demo (sinov muddatisiz demo) | nodemo (demo manzili ulanmagan) | soon (mahsulot chiqmagan) | off
     * @return array{state:string,days:int,can:bool,badge:string,note:string}
     */
    public static function of(array $t): array
    {
        $days = max(1, (int)($t['trial_days'] ?? 30));
        $soon = ($t['status'] ?? '') === 'soon';
        $on = !empty($t['trial_enabled']);
        $hasUrl = trim((string)($t['web_url'] ?? '')) !== '';
        if ($soon) {
            return ['state' => 'soon', 'days' => $days, 'can' => false, 'badge' => 'Tez orada',
                'note' => "Mahsulot hali chiqmagan, shu sababli sinov yo'q. So'rov qoldirsangiz, chiqqanda xabar beramiz."];
        }
        if ($hasUrl) {
            return $on
                ? ['state' => 'ready', 'days' => $days, 'can' => true, 'badge' => $days . ' kun bepul sinov', 'note' => "Korxona nomi va email yetarli. Namuna ma'lumot bilan brauzerda ochiladi."]
                : ['state' => 'demo', 'days' => $days, 'can' => true, 'badge' => "Brauzerda sinab ko'ring", 'note' => "Namuna ma'lumot bilan brauzerda ochiladi."];
        }
        if ($on) {
            return ['state' => 'nodemo', 'days' => $days, 'can' => false, 'badge' => $days . ' kun bepul sinov',
                'note' => "Hozircha ochilmagan: brauzerdagi demo manzili hali ulanmagan. So'rov qoldiring, demo ulangach xabar beramiz."];
        }
        return ['state' => 'off', 'days' => $days, 'can' => false, 'badge' => '', 'note' => ''];
    }

    /** «Sinab ko'rish» tugmasi: [manzil, yozuv] yoki null (demo yo'q bo'lsa tugma chiqmaydi). */
    public static function button(array $t, ?array $user): ?array
    {
        if (!self::of($t)['can']) {
            return null;
        }
        if ($user) {
            return $user['role'] === 'staff'
                ? [Util::url('/admin/tools'), 'Boshqaruvda ochish']
                : [Util::url('/app/tools/' . $t['slug']), 'Mening mahsulotlarimda ochish'];
        }
        $label = trim((string)($t['marketing']['cta']['trial'] ?? ''));
        return [Util::url('/register?tool=' . $t['slug']), $label !== '' ? $label : "Sinab ko'rish"];
    }
}
