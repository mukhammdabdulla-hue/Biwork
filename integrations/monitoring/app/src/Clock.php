<?php
declare(strict_types=1);

/** "Hozir" manbai: ishchi rejimda haqiqiy vaqt; namuna korxonada simulyatsiya soati (meta.demo_now). */
final class Clock
{
    public static function now(): int
    {
        $fake = Config::env('MON_NOW');
        if ($fake !== null && ($t = strtotime($fake)) !== false) {
            return $t;
        }
        if (self::isDemo()) {
            $v = Db::meta('demo_now');
            if ($v !== null) {
                return (int)$v;
            }
        }
        return time();
    }

    public static function isDemo(): bool
    {
        return Db::meta('demo', '0') === '1';
    }

    public static function setDemoNow(int $t): void
    {
        Db::setMeta('demo_now', (string)$t);
    }

    /** Ishlab chiqarish kuni 08:00 da boshlanadi. */
    public static function dayStart(int $t): int
    {
        $d = (int)strtotime(date('Y-m-d', $t) . ' 08:00:00');
        return $t >= $d ? $d : $d - 86400;
    }
}
