<?php
declare(strict_types=1);

/** Ekranlar uchun hisob-kitoblar: ko'rsatkichlar, uskuna vaqt chizig'i, buyurtmalar, reja va fakt. */
final class Metrics
{
    private const STATE_OF_FACT = ['run' => 'run', 'setup' => 'setup', 'stop' => 'stop'];

    /** @return array<string,float> uskuna => band daqiqa ([from,to) oralig'ida sozlash+ishlash) */
    public static function busyMinutes(int $from, int $to): array
    {
        $out = [];
        $rows = Db::all("SELECT machine_id, SUM(MAX(0, MIN(COALESCE(ended_at, :to), :to) - MAX(started_at, :from))) AS s
                         FROM facts WHERE kind IN ('setup','run') AND started_at < :to AND COALESCE(ended_at, :to) > :from GROUP BY machine_id",
            ['from' => $from, 'to' => $to]);
        foreach ($rows as $r) {
            $out[$r['machine_id']] = (float)$r['s'] / 60;
        }
        return $out;
    }

    /** To'xtash daqiqalari (tanaffus va "ish yo'q" hisoblanmaydi). */
    public static function stopMinutes(int $from, int $to): float
    {
        $s = Db::val("SELECT SUM(MAX(0, MIN(COALESCE(ended_at, :to), :to) - MAX(started_at, :from))) FROM facts
                      WHERE kind = 'stop' AND COALESCE(reason,'boshqa') NOT IN ('tanaffus','ish_yoq') AND started_at < :to AND COALESCE(ended_at, :to) > :from",
            ['from' => $from, 'to' => $to]);
        return (float)$s / 60;
    }

    /** O'z vaqtida boshlangan ishlar ulushi (%), reja bo'yicha boshlanishi [from, to-30daq) ichida bo'lganlar. null = ish yo'q. */
    public static function startOk(int $from, int $to): ?float
    {
        $r = Db::one("SELECT COUNT(*) n, SUM(CASE WHEN started_at IS NOT NULL AND started_at <= plan_start + 1800 THEN 1 ELSE 0 END) ok
                      FROM ops WHERE plan_start >= ? AND plan_start < ?", [$from, $to - 1800]);
        return $r && (int)$r['n'] > 0 ? round(100 * (int)$r['ok'] / (int)$r['n'], 1) : null;
    }

    public static function trend(int $now, int $days = 7): array
    {
        $machines = (int)Db::val('SELECT COUNT(*) FROM machines WHERE active = 1');
        $day0 = Clock::dayStart($now);
        $out = [];
        for ($d = $days - 1; $d >= 0; $d--) {
            $from = $day0 - $d * 86400;
            $to = min($from + 86400, $now);
            if ($to <= $from) {
                continue;
            }
            $busy = array_sum(self::busyMinutes($from, $to));
            $alerts = (int)Db::val("SELECT COUNT(*) FROM signals WHERE opened_at >= ? AND opened_at < ? AND severity IN ('crit','warn')", [$from, $from + 86400]);
            $out[] = [
                'day' => date('Y-m-d', $from + 3600 * 12), 'label' => date('d.m', $from + 3600 * 12), 'partial' => $to < $from + 86400,
                'util' => $machines ? round(100 * $busy / ($machines * ($to - $from) / 60), 1) : 0.0,
                'start_ok' => self::startOk($from, $to),
                'stop_h' => round(self::stopMinutes($from, $to) / 60, 1),
                'alerts' => $alerts,
            ];
        }
        return $out;
    }

    public static function overview(int $now, array $snap): array
    {
        $states = ['run' => 0, 'setup' => 0, 'stop' => 0, 'wait' => 0, 'idle' => 0];
        foreach ($snap['machines'] as $m) {
            $states[$m['state']]++;
        }
        $sev = ['crit' => 0, 'warn' => 0, 'info' => 0];
        foreach (Db::all("SELECT severity, COUNT(*) c FROM signals WHERE status IN ('open','seen') GROUP BY severity") as $r) {
            $sev[$r['severity']] = (int)$r['c'];
        }
        $risk = ['crit' => 0, 'warn' => 0];
        foreach ($snap['orders'] as $o) {
            if ($o['risk']) {
                $risk[$o['risk']]++;
            }
        }
        $from = Clock::dayStart($now);
        $machines = count($snap['machines']);
        $busy = array_sum(self::busyMinutes($from, $now));
        $trend = self::trend($now, 7);
        $prev = count($trend) >= 2 ? $trend[count($trend) - 2] : null;
        $top = Alerts::list(['status' => 'active', 'limit' => 9]);
        // oxirgi 24 soatda yopilgan ogohlantirishlar soni
        $closed24 = (int)Db::val("SELECT COUNT(*) FROM signals WHERE status = 'closed' AND closed_at >= ?", [$now - 86400]);
        $sections = [];
        foreach ($snap['machines'] as $m) {
            $sections[$m['section']]['code'] = $m['section'];
            $sections[$m['section']]['name'] = Domain::SECTIONS[$m['section']] ?? $m['section'];
            $worst = Db::val("SELECT MIN(CASE severity WHEN 'crit' THEN 0 WHEN 'warn' THEN 1 ELSE 2 END) FROM signals WHERE status IN ('open','seen') AND entity_type = 'machine' AND entity_id = ?", [$m['id']]);
            $sections[$m['section']]['machines'][] = [
                'id' => $m['id'], 'name' => $m['name'], 'state' => $m['state'], 'since' => $m['since'],
                'order' => $m['current']['order_no'] ?? ($m['state'] === 'wait' ? ($m['ready_op']['order_no'] ?? null) : null),
                'alert' => $worst === null ? null : ['crit', 'warn', 'info'][(int)$worst],
            ];
        }
        return [
            'now' => $now,
            'states' => $states, 'machines' => $machines,
            'alerts' => $sev, 'alerts_total' => array_sum($sev), 'closed24' => $closed24,
            'orders' => ['open' => count($snap['orders']), 'risk' => $risk],
            'util_today' => $machines ? round(100 * $busy / ($machines * max(60, $now - $from) / 60), 1) : 0.0,
            'util_prev' => $prev['util'] ?? null,
            'start_ok_today' => self::startOk($from, $now),
            'start_ok_prev' => $prev['start_ok'] ?? null,
            'stop_h_today' => round(self::stopMinutes($from, $now) / 60, 1),
            'trend' => $trend,
            'top' => $top['items'],
            'sections' => array_values($sections),
            'sources' => self::sources($now),
        ];
    }

    public static function sources(int $now): array
    {
        $out = [];
        foreach (Db::all('SELECT * FROM sources ORDER BY sort, code') as $s) {
            $last = $s['last_at'] === null ? null : (int)$s['last_at'];
            $lag = $last === null ? null : ($now - $last) / 60;
            $state = $last === null ? 'none' : ($lag >= $s['expect_min'] * 3 ? 'bad' : ($lag >= $s['expect_min'] * 1.5 ? 'late' : 'ok'));
            $out[] = ['code' => $s['code'], 'name' => $s['name'], 'kind' => $s['kind'], 'expect_min' => (int)$s['expect_min'], 'last_at' => $last,
                'lag_min' => $lag === null ? null : (int)round($lag), 'rows' => (int)$s['last_rows'], 'state' => $state];
        }
        return $out;
    }

    // ------------------------------------------------------------------ uskunalar

    /** Uskunaning [from,to] oralig'idagi vaqt chizig'i: [boshlanish, tugash, holat, sabab|null, buyurtma|null]. */
    public static function timeline(string $mid, int $from, int $to, int $now): array
    {
        $rows = Db::all('SELECT f.*, r.no AS order_no FROM facts f LEFT JOIN ops o ON o.id = f.op_id LEFT JOIN orders r ON r.id = o.order_id
                         WHERE f.machine_id = ? AND f.started_at < ? AND COALESCE(f.ended_at, ?) > ? ORDER BY f.started_at', [$mid, $to, $now, $from]);
        $out = [];
        foreach ($rows as $f) {
            $state = $f['kind'] === 'stop' && ($f['reason'] ?? '') === 'ish_yoq' ? 'idle' : (self::STATE_OF_FACT[$f['kind']] ?? 'run');
            $out[] = [max($from, (int)$f['started_at']), min($to, $f['ended_at'] === null ? $now : (int)$f['ended_at']), $state, $f['reason'], $f['order_no']];
        }
        return $out;
    }

    public static function machines(int $now, array $snap): array
    {
        $day = Clock::dayStart($now);
        $busy = self::busyMinutes($day, $now);
        $sev = [];
        foreach (Db::all("SELECT entity_id, MIN(CASE severity WHEN 'crit' THEN 0 WHEN 'warn' THEN 1 ELSE 2 END) w, COUNT(*) c FROM signals
                          WHERE status IN ('open','seen') AND entity_type = 'machine' GROUP BY entity_id") as $r) {
            $sev[$r['entity_id']] = ['worst' => ['crit', 'warn', 'info'][(int)$r['w']], 'n' => (int)$r['c']];
        }
        $out = [];
        foreach ($snap['machines'] as $m) {
            $c = $m['current'];
            $prog = null;
            if ($c) {
                $norm = max(1.0, $c['setup_norm'] + $c['run_norm']);
                $prog = (int)min(100, round(100 * ($c['setup_act'] + $c['run_act']) / $norm));
            }
            $out[] = [
                'id' => $m['id'], 'name' => $m['name'], 'section' => $m['section'], 'section_name' => Domain::SECTIONS[$m['section']] ?? $m['section'],
                'state' => $m['state'], 'since' => $m['since'],
                'reason' => $m['fact'] && $m['fact']['kind'] === 'stop' ? $m['fact']['reason'] : null,
                'order' => $c ? ['id' => $c['order_id'], 'no' => $c['order_no'], 'name' => $c['order_name'], 'kind' => $c['kind'], 'progress' => $prog] : null,
                'next' => $m['queue'] ? ['no' => $m['queue'][0]['order_no'], 'plan_start' => (int)$m['queue'][0]['plan_start']] : null,
                'queue' => count($m['queue']), 'queue_h' => round($m['queue_min'] / 60, 1),
                'util_today' => round(100 * ($busy[$m['id']] ?? 0) / max(60, ($now - $day) / 60), 1),
                'alert' => $sev[$m['id']] ?? null,
                'timeline' => self::timeline($m['id'], $now - 24 * 3600, $now, $now),
            ];
        }
        return $out;
    }

    public static function machineDetail(string $id, int $now, array $snap): array
    {
        $m = $snap['machines'][$id] ?? null;
        if (!$m) {
            throw new ApiError(404, 'not_found', 'Uskuna topilmadi');
        }
        $card = null;
        foreach (self::machines($now, $snap) as $x) {
            if ($x['id'] === $id) {
                $card = $x;
            }
        }
        $stops = Db::all("SELECT started_at, ended_at, reason FROM facts WHERE machine_id = ? AND kind = 'stop' AND started_at >= ? ORDER BY started_at DESC", [$id, $now - 14 * 86400]);
        $byReason = [];
        $list = [];
        foreach ($stops as $s) {
            $end = $s['ended_at'] === null ? $now : (int)$s['ended_at'];
            $min = ($end - (int)$s['started_at']) / 60;
            $r = $s['reason'] ?: 'boshqa';
            $byReason[$r] = ($byReason[$r] ?? 0) + $min;
            $list[] = ['start' => (int)$s['started_at'], 'end' => $s['ended_at'] === null ? null : (int)$s['ended_at'], 'min' => (int)round($min), 'reason' => $r, 'reason_name' => Domain::reasonLabel($r)];
        }
        arsort($byReason);
        $reasons = [];
        foreach ($byReason as $r => $min) {
            $reasons[] = ['reason' => $r, 'name' => Domain::reasonLabel($r), 'min' => (int)round($min)];
        }
        $queue = [];
        foreach ($m['queue'] as $q) {
            $o = $snap['orders'][$q['order_id']] ?? null;
            $queue[] = ['order_id' => $q['order_id'], 'no' => $q['order_no'], 'name' => $q['order_name'], 'plan_start' => (int)$q['plan_start'], 'plan_end' => (int)$q['plan_end'],
                'norm_min' => (int)round($q['setup_norm'] + $q['run_norm']), 'due_at' => $o['due_at'] ?? null, 'risk' => $o['risk'] ?? null, 'ready' => $q['ready_at'] !== null && $q['ready_at'] <= $now];
        }
        $days = [];
        $day0 = Clock::dayStart($now);
        for ($d = 6; $d >= 0; $d--) {
            $from = $day0 - $d * 86400;
            $to = min($from + 86400, $now);
            $busy = self::busyMinutes($from, $to)[$id] ?? 0;
            $stop = (float)Db::val("SELECT SUM(MAX(0, MIN(COALESCE(ended_at, :to), :to) - MAX(started_at, :from))) FROM facts WHERE machine_id = :m AND kind = 'stop'
                                    AND COALESCE(reason,'boshqa') NOT IN ('tanaffus','ish_yoq') AND started_at < :to AND COALESCE(ended_at, :to) > :from", ['from' => $from, 'to' => $to, 'm' => $id]) / 60;
            $days[] = ['label' => date('d.m', $from + 12 * 3600), 'util' => round(100 * $busy / max(60, ($to - $from) / 60), 1), 'stop_min' => (int)round($stop)];
        }
        $repairs = array_values(array_filter($list, static fn($s) => $s['reason'] === 'tamir' && $s['start'] >= $now - 14 * 86400));
        return [
            'machine' => $card,
            'timeline72' => self::timeline($id, $now - 72 * 3600, $now, $now),
            'plan' => self::planBlocks($id, $now - 6 * 3600, $now + 30 * 3600),
            'stops' => array_slice($list, 0, 12), 'stops_total' => count($list), 'reasons' => $reasons,
            'repairs14' => count($repairs),
            'days' => $days,
            'queue' => $queue,
            'alerts' => Alerts::list(['status' => 'active', 'entity_type' => 'machine', 'entity_id' => $id, 'limit' => 20])['items'],
        ];
    }

    private static function planBlocks(string $mid, int $from, int $to): array
    {
        $rows = Db::all('SELECT o.id, o.order_id, o.seq, o.kind, o.plan_start, o.plan_end, o.status, o.started_at, o.ended_at, r.no, r.name
                         FROM ops o JOIN orders r ON r.id = o.order_id WHERE o.machine_id = ? AND o.plan_start < ? AND o.plan_end > ? ORDER BY o.plan_start', [$mid, $to, $from]);
        return array_map(static fn($o) => ['id' => (int)$o['id'], 'order_id' => (int)$o['order_id'], 'no' => $o['no'], 'name' => $o['name'], 'kind' => $o['kind'],
            'start' => max($from, (int)$o['plan_start']), 'end' => min($to, (int)$o['plan_end']), 'status' => $o['status'],
            'started_at' => $o['started_at'] === null ? null : (int)$o['started_at'], 'ended_at' => $o['ended_at'] === null ? null : (int)$o['ended_at']], $rows);
    }

    // ------------------------------------------------------------------ buyurtmalar

    public static function orders(int $now, array $snap): array
    {
        $items = [];
        foreach ($snap['orders'] as $o) {
            $ops = [];
            foreach ($o['ops'] as $x) {
                $m = $snap['machines'][$x['machine_id']] ?? null;
                $ops[] = ['seq' => (int)$x['seq'], 'kind' => $x['kind'], 'kind_name' => Domain::SECTIONS[$x['kind']] ?? $x['kind'], 'machine_id' => $x['machine_id'],
                    'machine' => $m['name'] ?? $x['machine_id'], 'status' => $x['status'], 'plan_start' => (int)$x['plan_start'], 'plan_end' => (int)$x['plan_end'],
                    'f_start' => (int)$x['f_start'], 'f_end' => (int)$x['f_end'], 'slip_min' => (int)round($x['f_slip']),
                    'machine_state' => $m['state'] ?? null, 'started_at' => $x['started_at'] === null ? null : (int)$x['started_at'],
                    'ended_at' => $x['ended_at'] === null ? null : (int)$x['ended_at']];
            }
            $items[] = [
                'id' => $o['id'], 'no' => $o['no'], 'name' => $o['name'], 'urgent' => (bool)$o['urgent'], 'created_at' => $o['created_at'], 'due_at' => $o['due_at'],
                'plan_end' => $o['plan_end'], 'forecast_end' => $o['forecast_end'], 'delta_min' => $o['delta_min'] === null ? null : (int)round($o['delta_min']),
                'risk' => $o['risk'], 'done_ops' => $o['done_ops'], 'ops' => $ops, 'total_ops' => count($ops),
            ];
        }
        usort($items, static function ($a, $b) {
            $ra = ['crit' => 0, 'warn' => 1][$a['risk'] ?? ''] ?? 2;
            $rb = ['crit' => 0, 'warn' => 1][$b['risk'] ?? ''] ?? 2;
            return [$ra, $a['due_at'] ?? PHP_INT_MAX] <=> [$rb, $b['due_at'] ?? PHP_INT_MAX];
        });
        $done = Db::all("SELECT id, no, name, due_at, finished_at FROM orders WHERE status = 'done' AND finished_at >= ? ORDER BY finished_at DESC LIMIT 30", [$now - 14 * 86400]);
        $onTime = 0;
        foreach ($done as &$d) {
            $d['id'] = (int)$d['id'];
            $d['due_at'] = $d['due_at'] === null ? null : (int)$d['due_at'];
            $d['finished_at'] = (int)$d['finished_at'];
            $d['late_min'] = $d['due_at'] === null ? null : (int)round(($d['finished_at'] - $d['due_at']) / 60);
            if ($d['due_at'] === null || $d['finished_at'] <= $d['due_at']) {
                $onTime++;
            }
        }
        unset($d);
        $allDone = Db::one("SELECT COUNT(*) n, SUM(CASE WHEN due_at IS NULL OR finished_at <= due_at THEN 1 ELSE 0 END) ok FROM orders WHERE status = 'done' AND finished_at >= ?", [$now - 14 * 86400]);
        return ['items' => $items, 'done' => array_slice($done, 0, 8), 'done_total' => (int)($allDone['n'] ?? 0),
            'on_time_pct' => $allDone && (int)$allDone['n'] > 0 ? round(100 * (int)$allDone['ok'] / (int)$allDone['n'], 1) : null];
    }

    // ------------------------------------------------------------------ reja va fakt

    public static function planFact(int $now, array $snap, int $before = 12 * 3600, int $after = 12 * 3600): array
    {
        $from = $now - $before;
        $to = $now + $after;
        $day = Clock::dayStart($now);
        $out = [];
        foreach ($snap['machines'] as $m) {
            $due = Db::one('SELECT COUNT(*) n, SUM(CASE WHEN started_at IS NOT NULL AND started_at <= plan_start + 1800 THEN 1 ELSE 0 END) ok,
                                   AVG(CASE WHEN started_at IS NOT NULL AND started_at > plan_start + 1800 THEN (started_at - plan_start) / 60.0 END) late
                            FROM ops WHERE machine_id = ? AND plan_start >= ? AND plan_start < ?', [$m['id'], $day, $now - 1800]);
            $dur = Db::one("SELECT SUM(setup_norm + run_norm) norm, COUNT(*) n FROM ops WHERE machine_id = ? AND status = 'done' AND ended_at >= ?", [$m['id'], $day]);
            $act = (float)Db::val("SELECT SUM(MIN(COALESCE(f.ended_at, ?), ?) - f.started_at) FROM facts f JOIN ops o ON o.id = f.op_id
                                   WHERE o.machine_id = ? AND o.status = 'done' AND o.ended_at >= ? AND f.kind IN ('setup','run')", [$now, $now, $m['id'], $day]) / 60;
            $out[] = [
                'id' => $m['id'], 'name' => $m['name'], 'section' => $m['section'], 'state' => $m['state'],
                'plan' => self::planBlocks($m['id'], $from, $to),
                'fact' => self::timeline($m['id'], $from, min($to, $now), $now),
                'due_today' => (int)($due['n'] ?? 0), 'ok_today' => (int)($due['ok'] ?? 0), 'late_avg_min' => $due && $due['late'] !== null ? (int)round((float)$due['late']) : null,
                'done_today' => (int)($dur['n'] ?? 0), 'dur_ratio' => $dur && (float)$dur['norm'] > 0 ? round($act / (float)$dur['norm'], 2) : null,
            ];
        }
        return ['now' => $now, 'from' => $from, 'to' => $to, 'machines' => $out];
    }
}
