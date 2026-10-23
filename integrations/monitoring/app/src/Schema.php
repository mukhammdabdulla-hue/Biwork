<?php
declare(strict_types=1);

final class Schema
{
    public const VERSION = 1;

    public static function ensure(): void
    {
        $pdo = Db::pdo();
        $has = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='meta'")->fetchColumn();
        if ($has && (int)Db::meta('schema', '0') >= self::VERSION) {
            return;
        }
        foreach (self::ddl() as $sql) {
            $pdo->exec($sql);
        }
        Db::setMeta('schema', (string)self::VERSION);
    }

    /** @return list<string> */
    private static function ddl(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT)',
            'CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, login TEXT UNIQUE NOT NULL, name TEXT NOT NULL, role TEXT NOT NULL DEFAULT \'viewer\',
                password_hash TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1, created_at INTEGER NOT NULL)',
            'CREATE TABLE IF NOT EXISTS login_attempts (id INTEGER PRIMARY KEY, ip TEXT, login TEXT, at INTEGER)',
            'CREATE INDEX IF NOT EXISTS la_at ON login_attempts(at)',
            // uskunalar va mas\'ul odamlar
            'CREATE TABLE IF NOT EXISTS machines (id TEXT PRIMARY KEY, name TEXT NOT NULL, section TEXT NOT NULL, sort INTEGER NOT NULL DEFAULT 0,
                speed_h REAL NOT NULL DEFAULT 1000, setup_min INTEGER NOT NULL DEFAULT 30, active INTEGER NOT NULL DEFAULT 1)',
            'CREATE TABLE IF NOT EXISTS people (id INTEGER PRIMARY KEY, name TEXT NOT NULL, role TEXT NOT NULL)',
            // buyurtmalar va ularning operatsiyalari (reja + fakt natijasi)
            'CREATE TABLE IF NOT EXISTS orders (id INTEGER PRIMARY KEY, no TEXT UNIQUE NOT NULL, name TEXT NOT NULL, qty INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL, due_at INTEGER, urgent INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT \'open\', finished_at INTEGER)',
            'CREATE TABLE IF NOT EXISTS ops (id INTEGER PRIMARY KEY, order_id INTEGER NOT NULL, seq INTEGER NOT NULL, kind TEXT NOT NULL, machine_id TEXT NOT NULL,
                plan_start INTEGER NOT NULL, plan_end INTEGER NOT NULL, qty INTEGER NOT NULL DEFAULT 0, setup_norm INTEGER NOT NULL DEFAULT 0, run_norm INTEGER NOT NULL DEFAULT 0,
                status TEXT NOT NULL DEFAULT \'wait\', started_at INTEGER, ended_at INTEGER, done_qty INTEGER NOT NULL DEFAULT 0)',
            'CREATE INDEX IF NOT EXISTS ops_m ON ops(machine_id, status)',
            'CREATE INDEX IF NOT EXISTS ops_o ON ops(order_id, seq)',
            'CREATE INDEX IF NOT EXISTS ops_ps ON ops(plan_start)',
            // fakt: uskunadagi haqiqiy ish bo\'laklari (sozlash / ishlash / to\'xtash); ended_at NULL = hozir davom etmoqda
            'CREATE TABLE IF NOT EXISTS facts (id INTEGER PRIMARY KEY, machine_id TEXT NOT NULL, op_id INTEGER, kind TEXT NOT NULL, reason TEXT, note TEXT,
                started_at INTEGER NOT NULL, ended_at INTEGER, qty INTEGER NOT NULL DEFAULT 0)',
            'CREATE INDEX IF NOT EXISTS facts_m ON facts(machine_id, started_at)',
            'CREATE INDEX IF NOT EXISTS facts_open ON facts(ended_at)',
            'CREATE INDEX IF NOT EXISTS facts_op ON facts(op_id)',
            // ogohlantirishlar
            'CREATE TABLE IF NOT EXISTS signals (id INTEGER PRIMARY KEY, skey TEXT NOT NULL, rule TEXT NOT NULL, family TEXT NOT NULL, severity TEXT NOT NULL,
                entity_type TEXT NOT NULL, entity_id TEXT NOT NULL, entity_name TEXT NOT NULL, title TEXT NOT NULL, why TEXT NOT NULL DEFAULT \'\',
                action TEXT NOT NULL DEFAULT \'\', evidence TEXT NOT NULL DEFAULT \'[]\', status TEXT NOT NULL DEFAULT \'open\', owner TEXT,
                opened_at INTEGER NOT NULL, updated_at INTEGER NOT NULL, seen_at INTEGER, closed_at INTEGER, closed_by TEXT, close_note TEXT)',
            'CREATE INDEX IF NOT EXISTS sig_key ON signals(skey, status)',
            'CREATE INDEX IF NOT EXISTS sig_status ON signals(status, severity)',
            'CREATE INDEX IF NOT EXISTS sig_opened ON signals(opened_at)',
            'CREATE TABLE IF NOT EXISTS signal_log (id INTEGER PRIMARY KEY, signal_id INTEGER NOT NULL, at INTEGER NOT NULL, who TEXT NOT NULL, kind TEXT NOT NULL, text TEXT NOT NULL DEFAULT \'\')',
            'CREATE INDEX IF NOT EXISTS slog_sig ON signal_log(signal_id)',
            'CREATE TABLE IF NOT EXISTS rules (code TEXT PRIMARY KEY, enabled INTEGER NOT NULL DEFAULT 1, params TEXT NOT NULL DEFAULT \'{}\')',
            // ma\'lumot manbalari
            'CREATE TABLE IF NOT EXISTS sources (code TEXT PRIMARY KEY, name TEXT NOT NULL, kind TEXT NOT NULL DEFAULT \'\', expect_min INTEGER NOT NULL DEFAULT 240,
                last_at INTEGER, last_rows INTEGER NOT NULL DEFAULT 0, note TEXT NOT NULL DEFAULT \'\', sort INTEGER NOT NULL DEFAULT 0)',
            // namuna korxona ssenariysi: kelajakdagi voqealar (simulyatsiya soati surilganda uzatiladi)
            'CREATE TABLE IF NOT EXISTS script (id INTEGER PRIMARY KEY, at INTEGER NOT NULL, kind TEXT NOT NULL, payload TEXT NOT NULL)',
            'CREATE INDEX IF NOT EXISTS script_at ON script(at)',
        ];
    }
}
