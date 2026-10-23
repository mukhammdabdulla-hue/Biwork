<?php
declare(strict_types=1);

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $path = Config::dbPath();
            $dir = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $pdo = new PDO('sqlite:' . $path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');
            try {
                $pdo->exec('PRAGMA journal_mode = WAL');
            } catch (Throwable) {
                // ba'zi hostinglarda WAL ishlamaydi — oddiy rejim yetadi
            }
            self::$pdo = $pdo;
            Schema::ensure();
        }
        return self::$pdo;
    }

    public static function reset(): void
    {
        self::$pdo = null;
        self::$inTx = false;
    }

    public static function all(string $sql, array $args = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($args);
        return $st->fetchAll();
    }

    public static function one(string $sql, array $args = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($args);
        $r = $st->fetch();
        return $r === false ? null : $r;
    }

    public static function value(string $sql, array $args = []): mixed
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($args);
        $r = $st->fetchColumn();
        return $r === false ? null : $r;
    }

    public static function exec(string $sql, array $args = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($args);
        return $st->rowCount();
    }

    public static function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        self::exec($sql, array_values($row));
        return (int)self::pdo()->lastInsertId();
    }

    /** Qisman yangilash: faqat $row kalitlari. */
    public static function update(string $table, int $id, array $row): void
    {
        if (!$row) {
            return;
        }
        $set = implode(',', array_map(static fn($c) => $c . ' = ?', array_keys($row)));
        self::exec('UPDATE ' . $table . ' SET ' . $set . ' WHERE id = ?', [...array_values($row), $id]);
    }

    private static bool $inTx = false;

    /** BEGIN IMMEDIATE tranzaksiyasi (yozuvlar navbatga turadi). Ichma-ich chaqirilsa tashqi tranzaksiyaga qo'shiladi. */
    public static function tx(callable $fn): mixed
    {
        if (self::$inTx) {
            return $fn();
        }
        $pdo = self::pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        self::$inTx = true;
        try {
            $r = $fn();
            $pdo->exec('COMMIT');
            return $r;
        } catch (Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable) {
                // tranzaksiya allaqachon yopilgan
            }
            throw $e;
        } finally {
            self::$inTx = false;
        }
    }
}
