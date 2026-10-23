<?php
declare(strict_types=1);

/** SQLite (PDO) o'rami. Demo tashrif buyuruvchi uchun alohida nusxa: Db::use($path). */
final class Db
{
    private static ?PDO $pdo = null;
    private static ?string $path = null;

    public static function use(?string $path): void
    {
        self::$pdo = null;
        self::$path = $path;
    }

    public static function path(): string
    {
        return self::$path ?? Config::dbPath();
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $p = self::path();
            $dir = dirname($p);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException("Ma'lumotlar papkasini yaratib bo'lmadi: $dir");
            }
            $pdo = new PDO('sqlite:' . $p, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL; PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;');
            self::$pdo = $pdo;
        }
        return self::$pdo;
    }

    public static function close(): void
    {
        self::$pdo = null;
    }

    /**
     * So'rovni turlangan parametrlar bilan bajaradi. Muhim: PDO execute($args) hamma qiymatni matn deb beradi,
     * SQLite esa butun son bilan matnni solishtirganda har doim matnni "katta" deb hisoblaydi
     * (masalan COALESCE(ended_at, ?) > ? noto'g'ri ishlaydi). Shuning uchun butun sonlar PARAM_INT bilan beriladi.
     */
    private static function run(string $sql, array $args): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        foreach ($args as $k => $v) {
            if (is_float($v) && floor($v) === $v && abs($v) < 9.0e15) {
                $v = (int)$v;
            }
            $type = match (true) {
                is_int($v), is_bool($v) => PDO::PARAM_INT,
                $v === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $st->bindValue(is_int($k) ? $k + 1 : ':' . ltrim((string)$k, ':'), is_bool($v) ? (int)$v : $v, $type);
        }
        $st->execute();
        return $st;
    }

    /** @return list<array<string,mixed>> */
    public static function all(string $sql, array $args = []): array
    {
        return self::run($sql, $args)->fetchAll();
    }

    public static function one(string $sql, array $args = []): ?array
    {
        $r = self::run($sql, $args)->fetch();
        return $r === false ? null : $r;
    }

    public static function val(string $sql, array $args = []): mixed
    {
        $r = self::one($sql, $args);
        return $r === null ? null : reset($r);
    }

    public static function exec(string $sql, array $args = []): int
    {
        return self::run($sql, $args)->rowCount();
    }

    public static function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        self::exec($sql, array_values($row));
        return (int)self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $row, string $where, array $args = []): int
    {
        $set = implode(',', array_map(static fn($c) => "$c = ?", array_keys($row)));
        return self::exec("UPDATE $table SET $set WHERE $where", array_merge(array_values($row), $args));
    }

    public static function tx(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $r = $fn();
            $pdo->commit();
            return $r;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function meta(string $k, ?string $default = null): ?string
    {
        $v = self::val('SELECT v FROM meta WHERE k = ?', [$k]);
        return $v === null ? $default : (string)$v;
    }

    public static function setMeta(string $k, string $v): void
    {
        self::exec('INSERT INTO meta (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v', [$k, $v]);
    }
}
