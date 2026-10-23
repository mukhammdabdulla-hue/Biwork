<?php
declare(strict_types=1);

/**
 * Ma'lumot kiritish: tashqi manba (ERP hisoboti, Biwork Reja) voqealarini jadvallarga o'tkazadi.
 * Namuna korxonada manba — `script` jadvali (oldindan tuzilgan ssenariy); Clock soati surilganda shu voqealar
 * ketma-ket "keladi". Ishchi rejimda xuddi shu funksiyalarni ERP hisobotini o'qiydigan kod chaqiradi.
 */
final class Feeder
{
    private const PRIO = ['fact_close' => 0, 'op_done' => 1, 'order' => 2, 'fact_open' => 3];

    /** Ssenariydagi $to vaqtigacha bo'lgan hamma yangi voqealarni qo'llaydi. */
    public static function advance(int $to): int
    {
        return Db::tx(static function () use ($to): int {
            $pos = (int)Db::meta('script_pos', '0');
            $rows = Db::all('SELECT id, at, kind, payload FROM script WHERE id > ? AND at <= ? ORDER BY id', [$pos, $to]);
            $facts = 0;
            $lastFact = null;
            foreach ($rows as $r) {
                $p = json_decode((string)$r['payload'], true);
                $at = (int)$r['at'];
                switch ($r['kind']) {
                    case 'order':
                        self::applyOrder($p);
                        break;
                    case 'fact_open':
                        self::applyOpen($p, $at);
                        $facts++;
                        $lastFact = $at;
                        break;
                    case 'fact_close':
                        self::applyClose($p, $at);
                        $lastFact = $at;
                        break;
                    case 'op_done':
                        self::applyOpDone($p, $at);
                        break;
                }
                $pos = (int)$r['id'];
            }
            if ($rows) {
                Db::setMeta('script_pos', (string)$pos);
            }
            self::touchSources($to, $lastFact, $facts, $rows);
            return count($rows);
        });
    }

    private static function applyOrder(array $p): void
    {
        $o = $p['order'];
        Db::insert('orders', ['id' => $o['id'], 'no' => $o['no'], 'name' => $o['name'], 'qty' => $o['qty'], 'created_at' => $o['created_at'],
            'due_at' => $o['due_at'], 'urgent' => $o['urgent'], 'status' => 'open']);
        foreach ($p['ops'] as $op) {
            Db::insert('ops', ['id' => $op['id'], 'order_id' => $o['id'], 'seq' => $op['seq'], 'kind' => $op['kind'], 'machine_id' => $op['machine_id'],
                'plan_start' => $op['plan_start'], 'plan_end' => $op['plan_end'], 'qty' => $op['qty'], 'setup_norm' => $op['setup_norm'],
                'run_norm' => $op['run_norm'], 'status' => 'wait']);
        }
    }

    private static function applyOpen(array $p, int $at): void
    {
        Db::insert('facts', ['id' => $p['id'], 'machine_id' => $p['machine_id'], 'op_id' => $p['op_id'], 'kind' => $p['kind'], 'reason' => $p['reason'],
            'started_at' => $at, 'ended_at' => null, 'qty' => 0]);
        if ($p['op_id'] && in_array($p['kind'], ['setup', 'run'], true)) {
            Db::exec("UPDATE ops SET status = ?, started_at = COALESCE(started_at, ?) WHERE id = ? AND status != 'done'", [$p['kind'], $at, $p['op_id']]);
        }
    }

    private static function applyClose(array $p, int $at): void
    {
        Db::exec('UPDATE facts SET ended_at = ?, qty = ? WHERE id = ?', [$at, $p['qty'], $p['id']]);
        if (!empty($p['op_id']) && $p['qty'] > 0) {
            Db::exec('UPDATE ops SET done_qty = done_qty + ? WHERE id = ?', [$p['qty'], $p['op_id']]);
        }
    }

    private static function applyOpDone(array $p, int $at): void
    {
        Db::exec("UPDATE ops SET status = 'done', ended_at = ? WHERE id = ?", [$at, $p['op_id']]);
        $left = (int)Db::val("SELECT COUNT(*) FROM ops WHERE order_id = ? AND status != 'done'", [$p['order_id']]);
        if ($left === 0) {
            Db::exec("UPDATE orders SET status = 'done', finished_at = ? WHERE id = ?", [$at, $p['order_id']]);
        }
    }

    private static function touchSources(int $to, ?int $lastFact, int $facts, array $rows): void
    {
        $quarter = static fn(int $t): int => $t - $t % 900;
        $hasOrder = false;
        foreach ($rows as $r) {
            if ($r['kind'] === 'order') {
                $hasOrder = true;
            }
        }
        if ($lastFact !== null || $rows) {
            foreach (['erp_fakt', 'erp_toxtash'] as $code) {
                Db::exec('UPDATE sources SET last_at = ?, last_rows = last_rows + ? WHERE code = ?', [$quarter($to), $code === 'erp_fakt' ? $facts : 0, $code]);
            }
        }
        $origin = (int)Db::meta('demo_origin', '0');
        if ($origin > 0 && $to <= $origin - 40 * 3600 && $rows) {
            Db::exec("UPDATE sources SET last_at = ?, last_rows = last_rows + 1 WHERE code = 'kunlik'", [$quarter($to)]);
        }
        if ($hasOrder) {
            Db::exec("UPDATE sources SET last_at = ? WHERE code = 'reja'", [$quarter($to)]);
        }
    }
}
