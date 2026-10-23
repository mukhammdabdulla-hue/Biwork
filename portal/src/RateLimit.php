<?php
declare(strict_types=1);

/** Oddiy oynali tezlik chegarasi (SQLite). */
final class RateLimit
{
    /** Urinishni hisoblaydi. true = ruxsat, false = chegaradan oshdi. */
    public static function hit(string $bucket, int $limit, int $window): bool
    {
        return Db::tx(static function () use ($bucket, $limit, $window): bool {
            $now = Util::now();
            $r = Db::one('SELECT window_start, hits FROM rate_limits WHERE bucket = ?', [$bucket]);
            if ($r === null || (int)$r['window_start'] + $window <= $now) {
                Db::exec('INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 1)
                          ON CONFLICT(bucket) DO UPDATE SET window_start = excluded.window_start, hits = 1', [$bucket, $now]);
                if (random_int(1, 100) === 1) {
                    Db::exec('DELETE FROM rate_limits WHERE window_start < ?', [$now - 86400 * 2]);
                }
                return true;
            }
            Db::exec('UPDATE rate_limits SET hits = hits + 1 WHERE bucket = ?', [$bucket]);
            return (int)$r['hits'] + 1 <= $limit;
        });
    }

    /** Urinishlar soni (oyna ichida), hisoblamasdan. */
    public static function count(string $bucket, int $window): int
    {
        $r = Db::one('SELECT window_start, hits FROM rate_limits WHERE bucket = ?', [$bucket]);
        return ($r !== null && (int)$r['window_start'] + $window > Util::now()) ? (int)$r['hits'] : 0;
    }

    public static function reset(string $bucket): void
    {
        Db::exec('DELETE FROM rate_limits WHERE bucket = ?', [$bucket]);
    }
}
