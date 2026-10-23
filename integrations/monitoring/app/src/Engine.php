<?php
declare(strict_types=1);

/**
 * Monitoring yadrosi.
 *   snapshot()  — bazadagi plan va faktdan uskunalar holati, navbatlar va buyurtma prognozini hisoblaydi;
 *   findings()  — qoidalarni snapshot'ga qo'llab, "hozir muammo bo'layotgan" holatlar ro'yxatini beradi;
 *   run()       — shu ro'yxatni ogohlantirishlar jadvali bilan solishtiradi: yangisini ochadi, o'zgarganini yangilaydi,
 *                 shart yo'qolganini o'zi yopadi.
 * Hamma hisob "hozir" (Clock::now()) bo'yicha: tarixiy vaqtda ham, haqiqiy vaqtda ham bir xil ishlaydi.
 */
final class Engine
{
    // ------------------------------------------------------------------ holat

    /** @return array{now:int,machines:array,orders:array,ops:array,cfg:array} */
    public static function snapshot(int $now, ?array $cfg = null): array
    {
        $cfg ??= Rules::load();
        $stopExtra = (int)$cfg['general']['params']['stop_extra_min'];

        $machines = [];
        foreach (Db::all('SELECT * FROM machines WHERE active = 1 ORDER BY sort, name') as $m) {
            $machines[$m['id']] = $m + ['state' => 'idle', 'since' => null, 'fact' => null, 'op' => null, 'queue' => [], 'queue_min' => 0.0,
                'last_end' => null, 'stops' => [], 'slip' => 0.0, 'slip_cause' => null];
        }
        foreach (Db::all('SELECT * FROM facts WHERE ended_at IS NULL') as $f) {
            if (isset($machines[$f['machine_id']])) {
                $machines[$f['machine_id']]['fact'] = $f;
            }
        }
        foreach (Db::all('SELECT machine_id, MAX(ended_at) e FROM facts WHERE ended_at IS NOT NULL GROUP BY machine_id') as $r) {
            if (isset($machines[$r['machine_id']])) {
                $machines[$r['machine_id']]['last_end'] = (int)$r['e'];
            }
        }
        foreach (Db::all("SELECT * FROM facts WHERE kind = 'stop' AND started_at >= ? ORDER BY started_at", [$now - 30 * 86400]) as $f) {
            if (isset($machines[$f['machine_id']])) {
                $machines[$f['machine_id']]['stops'][] = $f;
            }
        }

        // operatsiyalar: ochiq buyurtmalarniki
        $minutes = [];
        foreach (Db::all("SELECT f.op_id, f.kind, SUM(COALESCE(f.ended_at, ?) - f.started_at) AS sec FROM facts f
                          WHERE f.op_id IN (SELECT o.id FROM ops o JOIN orders r ON r.id = o.order_id WHERE r.status = 'open') AND f.kind IN ('setup','run')
                          GROUP BY f.op_id, f.kind", [$now]) as $r) {
            $minutes[(int)$r['op_id']][$r['kind']] = (float)$r['sec'] / 60;
        }
        $rows = Db::all("SELECT o.*, r.no AS order_no, r.name AS order_name, r.due_at, r.urgent, r.created_at AS order_created
                         FROM ops o JOIN orders r ON r.id = o.order_id WHERE r.status = 'open' ORDER BY o.order_id, o.seq");
        $orders = [];
        $ops = [];
        foreach ($rows as $o) {
            $o['id'] = (int)$o['id'];
            $o['order_id'] = (int)$o['order_id'];
            $o['setup_act'] = $minutes[$o['id']]['setup'] ?? 0.0;
            $o['run_act'] = $minutes[$o['id']]['run'] ?? 0.0;
            $oid = $o['order_id'];
            if (!isset($orders[$oid])) {
                $orders[$oid] = ['id' => $oid, 'no' => $o['order_no'], 'name' => $o['order_name'], 'due_at' => $o['due_at'] === null ? null : (int)$o['due_at'],
                    'urgent' => (int)$o['urgent'], 'created_at' => (int)$o['order_created'], 'ops' => []];
            }
            $prev = $orders[$oid]['ops'] ? $orders[$oid]['ops'][count($orders[$oid]['ops']) - 1] : null;
            if ($prev === null) {
                $o['ready_at'] = (int)$o['order_created'];
            } elseif ($prev['status'] === 'done' && $prev['ended_at'] !== null) {
                $o['ready_at'] = (int)$prev['ended_at'] + (Domain::GAP_AFTER[$prev['kind']] ?? 0) * 60;
            } else {
                $o['ready_at'] = null;
            }
            $orders[$oid]['ops'][] = $o;
            $ops[$o['id']] = $o;
        }

        // uskunalar: holat, navbat
        foreach ($machines as $mid => &$m) {
            $m['current'] = null;
            $queue = [];
            foreach ($orders as $ord) {
                foreach ($ord['ops'] as $o) {
                    if ($o['machine_id'] !== $mid) {
                        continue;
                    }
                    if (in_array($o['status'], ['setup', 'run'], true)) {
                        $m['current'] = $o;
                    } elseif ($o['status'] === 'wait') {
                        $queue[] = $o;
                    }
                }
            }
            usort($queue, static fn($a, $b) => [$a['plan_start'], $a['id']] <=> [$b['plan_start'], $b['id']]);
            $m['queue'] = $queue;
            $qmin = 0.0;
            foreach ($queue as $q) {
                $qmin += $q['setup_norm'] + $q['run_norm'];
            }
            if ($m['current']) {
                $c = $m['current'];
                $qmin += max(0, $c['setup_norm'] + $c['run_norm'] - $c['setup_act'] - $c['run_act']);
            }
            $m['queue_min'] = $qmin;

            $f = $m['fact'];
            if ($f) {
                $m['since'] = (int)$f['started_at'];
                if ($f['kind'] === 'stop') {
                    $m['state'] = ($f['reason'] ?? '') === 'ish_yoq' ? 'idle' : 'stop';
                } else {
                    $m['state'] = $f['kind'] === 'setup' ? 'setup' : 'run';
                }
            } else {
                $ready = null;
                foreach ($queue as $q) {
                    if ($q['ready_at'] !== null && $q['ready_at'] <= $now && (int)$q['plan_start'] <= $now) {
                        $ready = $q;
                        break;
                    }
                }
                $m['ready_op'] = $ready;
                $m['state'] = $ready ? 'wait' : 'idle';
                $m['since'] = $m['last_end'];
                if ($ready) {
                    $m['since'] = max((int)($m['last_end'] ?? 0), (int)$ready['ready_at'], (int)$ready['plan_start']);
                }
            }
            // kechikish (slip): shu uskunadagi keyingi ishlar rejadan necha daqiqa suriladi
            $slip = 0.0;
            $cause = null;
            if ($f && $f['kind'] === 'stop' && !in_array($f['reason'] ?? '', ['tanaffus', 'ish_yoq'], true)) {
                $slip = ($now - (int)$f['started_at']) / 60 + $stopExtra;
                $cause = 'stop';
            }
            if ($m['current']) {
                $c = $m['current'];
                $fe = self::runningForecastEnd($c, $now, ($f && $f['kind'] === 'stop') ? $stopExtra : 0);
                $s = max(0.0, ($fe - (int)$c['plan_end']) / 60);
                if ($s > $slip) {
                    $slip = $s;
                    $cause = 'busy';
                }
            } elseif (!$f && $queue) {
                $s = max(0.0, ($now - (int)$queue[0]['plan_start']) / 60);
                if ($s > $slip) {
                    $slip = $s;
                    $cause = $m['state'] === 'wait' ? 'idle' : 'upstream';
                }
            }
            $m['slip'] = $slip;
            $m['slip_cause'] = $cause;
        }
        unset($m);

        // buyurtma prognozi
        $buffer = (float)$cfg['late_risk']['params']['buffer_h'] * 3600;
        foreach ($orders as &$ord) {
            $prevEnd = null;
            $worst = ['slip' => 0.0, 'op' => null];
            foreach ($ord['ops'] as &$o) {
                $m = $machines[$o['machine_id']] ?? null;
                if ($o['status'] === 'done') {
                    $o['f_start'] = (int)$o['started_at'];
                    $o['f_end'] = (int)$o['ended_at'];
                } elseif (in_array($o['status'], ['setup', 'run'], true)) {
                    $extra = ($m && $m['fact'] && $m['fact']['kind'] === 'stop') ? $stopExtra : 0;
                    $o['f_start'] = (int)$o['started_at'];
                    $o['f_end'] = (int)round(self::runningForecastEnd($o, $now, $extra));
                } else {
                    $slip = $m ? $m['slip'] : 0.0;
                    $gap = $prevEnd !== null ? $prevEnd : null;
                    $start = (int)$o['plan_start'] + (int)round($slip * 60);
                    if ($gap !== null) {
                        $start = max($start, $gap);
                    } elseif ($o['ready_at'] !== null) {
                        $start = max($start, (int)$o['ready_at']);
                    }
                    $start = max($start, $now);
                    $o['f_start'] = $start;
                    $o['f_end'] = $start + (int)(($o['setup_norm'] + $o['run_norm']) * 60);
                }
                $o['f_slip'] = max(0.0, ((int)$o['f_end'] - (int)$o['plan_end']) / 60);
                if ($o['status'] !== 'done' && $o['f_slip'] > $worst['slip']) {
                    $worst = ['slip' => $o['f_slip'], 'op' => $o];
                }
                $prevEnd = (int)$o['f_end'] + (Domain::GAP_AFTER[$o['kind']] ?? 0) * 60;
                $ops[$o['id']] = $o;
            }
            unset($o);
            $last = $ord['ops'][count($ord['ops']) - 1];
            $ord['forecast_end'] = (int)$last['f_end'];
            $ord['plan_end'] = (int)$last['plan_end'];
            $ord['done_ops'] = count(array_filter($ord['ops'], static fn($x) => $x['status'] === 'done'));
            $ord['worst'] = $worst;
            $ord['delta_min'] = $ord['due_at'] === null ? null : ($ord['forecast_end'] - $ord['due_at']) / 60;
            $ord['risk'] = null;
            if ($ord['due_at'] !== null) {
                if ($ord['forecast_end'] > $ord['due_at']) {
                    $ord['risk'] = 'crit';
                } elseif ($ord['due_at'] - $ord['forecast_end'] < $buffer) {
                    $ord['risk'] = 'warn';
                }
            }
        }
        unset($ord);

        return ['now' => $now, 'machines' => $machines, 'orders' => $orders, 'ops' => $ops, 'cfg' => $cfg];
    }

    /** Hozir ishlab turgan operatsiyaning taxminiy tugash vaqti (epoch). */
    private static function runningForecastEnd(array $o, int $now, int $stopExtraMin): float
    {
        $norm = (float)($o['setup_norm'] + $o['run_norm']);
        $done = $o['setup_act'] + $o['run_act'];
        $rem = max(0.0, $norm - $done);
        if ($rem < 5) {
            $rem = max(10.0, 0.15 * $norm); // me'yordan oshib ketgan: yana ozgina vaqt kerak deb olinadi
        }
        return $now + ($rem + $stopExtraMin) * 60;
    }

    // ------------------------------------------------------------------ qoidalar

    /** @return list<array<string,mixed>> */
    public static function findings(array $s): array
    {
        $out = [];
        $cfg = $s['cfg'];
        $now = $s['now'];
        $en = static fn(string $c): bool => $cfg[$c]['enabled'];
        if ($en('stop_long')) {
            array_push($out, ...self::ruleStopLong($s, $cfg['stop_long']['params']));
        }
        if ($en('stop_repeat')) {
            array_push($out, ...self::ruleStopRepeat($s, $cfg['stop_repeat']['params']));
        }
        if ($en('idle_ready')) {
            array_push($out, ...self::ruleIdleReady($s, $cfg['idle_ready']['params']));
        }
        if ($en('start_late')) {
            array_push($out, ...self::ruleStartLate($s, $cfg['start_late']['params']));
        }
        if ($en('late_risk')) {
            array_push($out, ...self::ruleLateRisk($s, $cfg['late_risk']['params']));
        }
        if ($en('setup_long')) {
            array_push($out, ...self::ruleSetupLong($s, $cfg['setup_long']['params']));
        }
        if ($en('overrun')) {
            array_push($out, ...self::ruleOverrun($s, $cfg['overrun']['params']));
        }
        if ($en('queue_overload')) {
            array_push($out, ...self::ruleQueue($s, $cfg['queue_overload']['params']));
        }
        if ($en('no_reason')) {
            array_push($out, ...self::ruleNoReason($s, $cfg['no_reason']['params']));
        }
        if ($en('source_stale')) {
            array_push($out, ...self::ruleStale($s, $cfg['source_stale']['params']));
        }
        return $out;
    }

    private static function finding(string $rule, string $skey, string $sev, string $type, string $id, string $name, string $title, string $why, string $action, array $evidence, string $role): array
    {
        return ['rule' => $rule, 'family' => Rules::DEFS[$rule]['family'], 'skey' => $skey, 'severity' => $sev, 'entity_type' => $type, 'entity_id' => $id,
            'entity_name' => $name, 'title' => $title, 'why' => $why, 'action' => $action, 'evidence' => $evidence, 'role' => $role];
    }

    private static function ev(string $k, string $v): array
    {
        return ['k' => $k, 'v' => $v];
    }

    private static function nos(array $ops, int $limit = 4): string
    {
        $n = array_map(static fn($o) => $o['order_no'], $ops);
        $more = count($n) - $limit;
        return implode(', ', array_slice($n, 0, $limit)) . ($more > 0 ? " va yana $more ta" : '');
    }

    private static function ruleStopLong(array $s, array $p): array
    {
        $out = [];
        $now = $s['now'];
        foreach ($s['machines'] as $m) {
            $f = $m['fact'];
            if (!$f || $f['kind'] !== 'stop') {
                continue;
            }
            $reason = ($f['reason'] ?? '') ?: 'boshqa';
            if (in_array($reason, ['tanaffus', 'ish_yoq'], true)) {
                continue;
            }
            $min = ($now - (int)$f['started_at']) / 60;
            if ($min < $p['minutes']) {
                continue;
            }
            $affected = $m['queue'];
            if ($m['current']) {
                array_unshift($affected, $m['current']);
            }
            $risky = 0;
            foreach ($affected as $o) {
                if (($s['orders'][$o['order_id']]['risk'] ?? null) !== null) {
                    $risky++;
                }
            }
            $crit = $min >= $p['minutes'] * $p['crit_factor'] || ($risky > 0 && $reason === 'tamir');
            $why = 'Sabab: ' . mb_strtolower(Domain::reasonLabel($reason)) . '. ';
            if ($affected) {
                $why .= count($affected) . ' ta buyurtma shu uskunaga bog\'liq: ' . self::nos($affected) . '.';
                if ($risky) {
                    $why .= " Ulardan $risky tasi muddatga ulgurmasligi mumkin.";
                }
            } else {
                $why .= "Hozircha shu uskunada navbatda buyurtma yo'q.";
            }
            $action = match ($reason) {
                'tamir' => "Ta'mirchi chaqirilganini va taxminiy tugash vaqtini aniqlang. 2 soatdan oshsa, navbatdagi ishlarni boshqa uskunaga o'tkazing (Biwork Rejada «Qayta tuzish»).",
                'material' => "Materialni qachon olib kelishini omborchidan aniqlang; shu orada boshqa buyurtmaga o'ting.",
                'qolip' => "Qolip qachon tayyor bo'lishini aniqlang; kerak bo'lsa qolipsiz boshqa buyurtmani oldinga suring.",
                'tozalash' => "Tozalash me'yordan uzoq cho'zildi — tugatishni va keyingi ishni boshlashni nazorat qiling.",
                default => "To'xtash sababini operatordan so'rab, jurnalga yozdiring va qancha davom etishini aniqlang.",
            };
            $out[] = self::finding('stop_long', 'stop_long:' . $f['id'], $crit ? 'crit' : 'warn', 'machine', $m['id'], $m['name'],
                $m['name'] . ' ' . Util::dur($min) . 'dan beri to\'xtab turibdi', $why, $action, [
                    self::ev("To'xtagan", Util::dur($min)), self::ev('Sabab', Domain::reasonLabel($reason)),
                    self::ev('Boshlangan', Util::dmhm((int)$f['started_at'])), self::ev('Bog\'liq buyurtma', (string)count($affected)),
                ], $reason === 'tamir' ? "Ta'mirchi" : "Smena boshlig'i");
        }
        return $out;
    }

    private static function ruleStopRepeat(array $s, array $p): array
    {
        $out = [];
        $now = $s['now'];
        foreach ($s['machines'] as $m) {
            $from = $now - (int)$p['days'] * 86400;
            $list = array_values(array_filter($m['stops'], static fn($f) => ($f['reason'] ?? '') === 'tamir' && (int)$f['started_at'] >= $from));
            $n = count($list);
            if ($n < $p['count']) {
                continue;
            }
            $total = 0.0;
            $longest = 0.0;
            foreach ($list as $f) {
                $d = (($f['ended_at'] ?? $now) - $f['started_at']) / 60;
                $total += $d;
                $longest = max($longest, $d);
            }
            $out[] = self::finding('stop_repeat', 'stop_repeat:' . $m['id'], $n >= $p['crit_count'] ? 'crit' : 'warn', 'machine', $m['id'], $m['name'],
                $m['name'] . ': oxirgi ' . (int)$p['days'] . " kunda $n marta buzildi",
                'Jami ' . Util::dur($total) . ' to\'xtab turdi, eng uzog\'i ' . Util::dur($longest) . '. Takroriy buzilish odatda tuzatilmagan asosiy nosozlikni bildiradi.',
                "Ta'mirchi bilan uskunani to'liq ko'rikdan o'tkazishni rejalashtiring; shu uskunadagi muhim buyurtmalarni zaxira uskunaga qo'ying.",
                [self::ev('Buzilishlar', "$n marta"), self::ev("Jami to'xtash", Util::dur($total)), self::ev("Eng uzog'i", Util::dur($longest)), self::ev('Davr', (int)$p['days'] . ' kun')],
                "Ta'mirchi");
        }
        return $out;
    }

    private static function ruleIdleReady(array $s, array $p): array
    {
        $out = [];
        $now = $s['now'];
        foreach ($s['machines'] as $m) {
            if ($m['state'] !== 'wait' || empty($m['ready_op'])) {
                continue;
            }
            $o = $m['ready_op'];
            $from = max((int)($m['last_end'] ?? 0), (int)$o['ready_at'], (int)$o['plan_start']);
            $min = ($now - $from) / 60;
            if ($min < $p['minutes']) {
                continue;
            }
            $out[] = self::finding('idle_ready', 'idle_ready:' . $m['id'], $min >= $p['minutes'] * $p['crit_factor'] ? 'crit' : 'warn', 'machine', $m['id'], $m['name'],
                $m['name'] . ' ' . Util::dur($min) . "dan beri bo'sh, lekin tayyor ish bor",
                'Navbatdagi buyurtma ' . $o['order_no'] . ' tayyor va rejada ' . Util::hm((int)$o['plan_start']) . ' da boshlanishi kerak edi. Boshlanmadi.',
                "Operatordan nega boshlanmaganini so'rang (material, qolip yoki odam yetishmayaptimi?) va sababini jurnalga yozdiring.",
                [self::ev("Bo'sh turgan", Util::dur($min)), self::ev('Kutayotgan buyurtma', $o['order_no']), self::ev('Reja bo\'yicha boshlanish', Util::dmhm((int)$o['plan_start'])), self::ev('Navbatda', count($m['queue']) . ' ta')],
                "Smena boshlig'i");
        }
        return $out;
    }

    private static function ruleStartLate(array $s, array $p): array
    {
        $out = [];
        $now = $s['now'];
        foreach ($s['machines'] as $m) {
            if (!$m['queue']) {
                continue;
            }
            $head = $m['queue'][0];
            if ($m['state'] === 'wait' && !empty($m['ready_op']) && $m['ready_op']['id'] === $head['id']) {
                continue; // "bo'sh, ish tayyor" qoidasi hal qiladi
            }
            $late = ($now - (int)$head['plan_start']) / 60;
            if ($late < $p['minutes']) {
                continue;
            }
            $others = count($m['queue']) - 1;
            if ($m['state'] === 'stop') {
                $cause = "uskuna to'xtab turibdi (" . mb_strtolower(Domain::reasonLabel($m['fact']['reason'] ?? null)) . ')';
                $action = "Uskuna tiklanishini kuting yoki buyurtmani boshqa uskunaga o'tkazing (Biwork Rejada «Qayta tuzish»).";
            } elseif ($m['current']) {
                $c = $m['current'];
                $over = max(0, $now - (int)$c['plan_end']) / 60;
                $cause = 'oldingi ish (' . $c['order_no'] . ') rejadagidan ' . Util::dur(max($over, 1)) . ' uzoq cho\'zildi';
                $action = "Oldingi ish nega cho'zilganini aniqlang; kerak bo'lsa keyingi buyurtmani boshqa uskunaga o'tkazing.";
            } else {
                $prev = null;
                foreach ($s['orders'][$head['order_id']]['ops'] as $x) {
                    if ($x['seq'] < $head['seq'] && $x['status'] !== 'done') {
                        $prev = $x;
                    }
                }
                $cause = $prev ? 'oldingi bosqich (' . (Domain::SECTIONS[$prev['kind']] ?? $prev['kind']) . ', ' . ($s['machines'][$prev['machine_id']]['name'] ?? '') . ') hali tugamagan' : "buyurtma hali tayyor emas";
                $action = "Oldingi bosqichni tezlashtiring yoki shu uskunaga boshqa tayyor buyurtmani qo'ying.";
            }
            $out[] = self::finding('start_late', 'start_late:' . $m['id'], $late >= $p['crit_minutes'] ? 'crit' : 'warn', 'machine', $m['id'], $m['name'],
                $m['name'] . ': ' . $head['order_no'] . ' ishi ' . Util::dur($late) . ' kechikmoqda',
                'Rejada ' . Util::dmhm((int)$head['plan_start']) . ' da boshlanishi kerak edi. Sabab: ' . $cause . '.' . ($others > 0 ? " Shu uskunada yana $others ta buyurtma kutmoqda." : ''),
                $action, [self::ev('Kechikish', Util::dur($late)), self::ev('Buyurtma', $head['order_no']), self::ev('Reja bo\'yicha', Util::dmhm((int)$head['plan_start'])), self::ev('Kutayotgan', ($others + 1) . ' ta')],
                "Smena boshlig'i");
        }
        return $out;
    }

    private static function ruleLateRisk(array $s, array $p): array
    {
        $out = [];
        foreach ($s['orders'] as $o) {
            if ($o['risk'] === null) {
                continue;
            }
            $w = $o['worst']['op'];
            $reasonText = '';
            if ($w) {
                $wm = $s['machines'][$w['machine_id']] ?? null;
                $reasonText = ' Asosiy sabab: ' . ($wm ? $wm['name'] : '') . 'da ' . Util::dur($o['worst']['slip']) . ' kechikish';
                $reasonText .= match ($wm['slip_cause'] ?? null) {
                    'stop' => ' (uskuna to\'xtab turibdi).',
                    'busy' => ' (oldingi ish cho\'zilgan).',
                    'idle' => ' (ish boshlanmagan).',
                    default => '.',
                };
            }
            $delta = (int)round($o['delta_min']);
            if ($o['risk'] === 'crit') {
                $title = $o['no'] . ' buyurtmasi muddatga ulgurmaydi: taxminan ' . Util::dur($delta) . ' kech qoladi';
            } else {
                $title = $o['no'] . ' buyurtmasida zaxira qolmadi: muddatgacha atigi ' . Util::dur(-$delta);
            }
            $out[] = self::finding('late_risk', 'late_risk:' . $o['id'], $o['risk'], 'order', (string)$o['id'], $o['no'], $title,
                'Muddat ' . Util::dmhm((int)$o['due_at']) . ', taxminiy tugash ' . Util::dmhm($o['forecast_end']) . '.' . $reasonText,
                $o['risk'] === 'crit'
                    ? "Shu buyurtma ishini uskunalarda birinchi navbatga qo'ying yoki mijoz bilan yangi muddatni oldindan kelishing."
                    : "Buyurtmaning keyingi ishlarini nazoratda ushlang: yana bir kechikish muddatni buzadi.",
                [self::ev('Muddat', Util::dmhm((int)$o['due_at'])), self::ev('Taxminiy tugash', Util::dmhm($o['forecast_end'])),
                    self::ev($delta > 0 ? 'Kechikish' : 'Zaxira', Util::dur(abs($delta))), self::ev('Bajarilgan', $o['done_ops'] . ' / ' . count($o['ops']) . ' operatsiya')],
                'Rejalashtiruvchi');
        }
        return $out;
    }

    private static function ruleSetupLong(array $s, array $p): array
    {
        $out = [];
        $now = $s['now'];
        foreach ($s['machines'] as $m) {
            $f = $m['fact'];
            if (!$f || $f['kind'] !== 'setup' || !$m['current']) {
                continue;
            }
            $c = $m['current'];
            $min = ($now - (int)$f['started_at']) / 60;
            $norm = (float)$c['setup_norm'];
            if ($min < $p['min_minutes'] || $min < $norm * $p['factor']) {
                continue;
            }
            $out[] = self::finding('setup_long', 'setup_long:' . $f['id'], $min >= $norm * $p['factor'] * 1.7 ? 'crit' : 'warn', 'machine', $m['id'], $m['name'],
                $m['name'] . ': sozlash ' . Util::dur($min) . 'dan beri davom etmoqda (me\'yor ' . Util::dur($norm) . ')',
                $c['order_no'] . ' buyurtmasi uchun sozlash me\'yordan ' . round($min / max($norm, 1), 1) . ' baravar uzoq. Har ortiqcha daqiqa uskuna ishlamay turishi demakdir.',
                "Sozlash nega cho'zilganini aniqlang (qolip, rang, material); kerak bo'lsa ustaning yordamini chaqiring.",
                [self::ev('Sozlash', Util::dur($min)), self::ev("Me'yor", Util::dur($norm)), self::ev('Buyurtma', $c['order_no'])], "Smena boshlig'i");
        }
        return $out;
    }

    private static function ruleOverrun(array $s, array $p): array
    {
        $out = [];
        foreach ($s['machines'] as $m) {
            $c = $m['current'];
            if (!$c || $c['status'] !== 'run' || $c['run_norm'] <= 0) {
                continue;
            }
            $act = $c['run_act'];
            if ($act < $p['min_minutes'] || $act < $c['run_norm'] * $p['factor']) {
                continue;
            }
            $pct = (int)round(($act / $c['run_norm'] - 1) * 100);
            $out[] = self::finding('overrun', 'overrun:' . $c['id'], $act >= $c['run_norm'] * $p['factor'] * 1.5 ? 'crit' : 'warn', 'machine', $m['id'], $m['name'],
                $m['name'] . ': ' . $c['order_no'] . " ishi me'yordan $pct% ko'p vaqt oldi",
                "Me'yor " . Util::dur($c['run_norm']) . ', hozirgacha ' . Util::dur($act) . ' ishlagan va hali tugamagan. Tezlik pasaygan bo\'lishi mumkin.',
                "Tezlik pasayishi sababini (material, sozlama, xodim) tekshiring; keyingi buyurtmalar kechikmasligi uchun rejani yangilang.",
                [self::ev("Me'yor", Util::dur($c['run_norm'])), self::ev('Ishlagan', Util::dur($act)), self::ev('Buyurtma', $c['order_no'])], "Smena boshlig'i");
        }
        return $out;
    }

    private static function ruleQueue(array $s, array $p): array
    {
        $out = [];
        foreach ($s['machines'] as $m) {
            $h = $m['queue_min'] / 60;
            if ($h < $p['hours']) {
                continue;
            }
            $alts = [];
            foreach ($s['machines'] as $x) {
                if ($x['id'] !== $m['id'] && $x['section'] === $m['section']) {
                    $alts[] = $x;
                }
            }
            usort($alts, static fn($a, $b) => $a['queue_min'] <=> $b['queue_min']);
            $alt = $alts ? $alts[0] : null;
            $shifts = round($h / 12, 1);
            $out[] = self::finding('queue_overload', 'queue_overload:' . $m['id'], $h >= $p['hours'] * $p['crit_factor'] ? 'crit' : 'warn', 'machine', $m['id'], $m['name'],
                $m['name'] . ' oldida ' . (int)round($h) . " soatlik ish yig'ildi",
                'Navbatda ' . count($m['queue']) . ' ta buyurtma, bu taxminan ' . str_replace('.', ',', (string)$shifts) . " smenalik ish. Boshqa buyurtmalar shu yerda kutib qoladi.",
                $alt ? "Ishning bir qismini " . $alt['name'] . " ga o'tkazing (hozir u yerda " . (int)round($alt['queue_min'] / 60) . " soatlik ish bor) yoki qo'shimcha smena belgilang."
                    : "Qo'shimcha smena belgilang yoki ishning bir qismini tashqariga bering.",
                [self::ev('Navbat', (int)round($h) . ' soat'), self::ev('Buyurtmalar', count($m['queue']) . ' ta'), self::ev('Smenalarda', str_replace('.', ',', (string)$shifts))],
                'Rejalashtiruvchi');
        }
        return $out;
    }

    private static function ruleNoReason(array $s, array $p): array
    {
        $out = [];
        $now = $s['now'];
        foreach ($s['machines'] as $m) {
            $list = array_values(array_filter($m['stops'], static function ($f) use ($now, $p) {
                $reason = ($f['reason'] ?? '') ?: 'boshqa';
                $end = $f['ended_at'] ?? $now;
                return $reason === 'boshqa' && $end >= $now - 86400 && ($end - $f['started_at']) / 60 >= $p['minutes'];
            }));
            if (count($list) < $p['count']) {
                continue;
            }
            $total = 0.0;
            foreach ($list as $f) {
                $total += (($f['ended_at'] ?? $now) - $f['started_at']) / 60;
            }
            $out[] = self::finding('no_reason', 'no_reason:' . $m['id'], 'info', 'machine', $m['id'], $m['name'],
                $m['name'] . ': ' . count($list) . " ta to'xtash sababi yozilmagan",
                'Oxirgi 24 soatda jami ' . Util::dur($total) . '. Sababsiz to\'xtashni keyin tahlil qilib bo\'lmaydi va u takrorlanishi mumkin.',
                "Smena boshlig'i to'xtash sabablarini jurnalga to'ldirsin.",
                [self::ev("To'xtashlar", count($list) . ' ta'), self::ev('Jami', Util::dur($total)), self::ev('Davr', '24 soat')], "Smena boshlig'i");
        }
        return $out;
    }

    private static function ruleStale(array $s, array $p): array
    {
        $out = [];
        $now = $s['now'];
        foreach (Db::all('SELECT * FROM sources ORDER BY sort, code') as $src) {
            $last = $src['last_at'] === null ? null : (int)$src['last_at'];
            $lag = $last === null ? PHP_FLOAT_MAX : ($now - $last) / 60;
            if ($lag < $src['expect_min'] * $p['factor']) {
                continue;
            }
            $crit = $lag >= $src['expect_min'] * $p['crit_factor'];
            $title = $last === null ? '«' . $src['name'] . '» hali kelmagan' : '«' . $src['name'] . '» ' . Util::dur($lag) . 'dan beri yangilanmagan';
            $out[] = self::finding('source_stale', 'source_stale:' . $src['code'], $crit ? 'crit' : 'warn', 'source', $src['code'], $src['name'], $title,
                'Odatda ' . Util::dur((float)$src['expect_min']) . 'da bir marta yangilanadi. Eskirgan ma\'lumot bilan boshqa ogohlantirishlar ishonchsiz bo\'ladi.',
                "Hisobotni yangilab papkaga tashlang yoki ma'lumot yuboruvchini tekshiring.",
                [self::ev('Oxirgi yangilanish', $last === null ? 'hali kelmagan' : Util::dmhm($last)), self::ev('Odatda', Util::dur((float)$src['expect_min']) . 'da bir marta')], 'Sex boshlig\'i');
        }
        return $out;
    }

    // ------------------------------------------------------------------ ogohlantirishlar hayoti

    /** Qoidalarni ishga tushirib, ogohlantirishlar jadvalini haqiqiy holatga keltiradi. */
    public static function run(int $now): array
    {
        return Db::tx(static function () use ($now): array {
            $cfg = Rules::load();
            $snap = self::snapshot($now, $cfg);
            $found = self::findings($snap);
            $snooze = (int)$cfg['general']['params']['snooze_min'] * 60;

            $openByKey = [];
            foreach (Db::all("SELECT * FROM signals WHERE status IN ('open','seen')") as $r) {
                $openByKey[$r['skey']] = $r;
            }
            $people = [];
            foreach (Db::all('SELECT name, role FROM people ORDER BY id') as $p) {
                $people[$p['role']] ??= $p['name'];
            }
            $stat = ['opened' => 0, 'updated' => 0, 'closed' => 0, 'skipped' => 0];
            $seen = [];
            foreach ($found as $f) {
                $seen[$f['skey']] = true;
                $ev = json_encode($f['evidence'], JSON_UNESCAPED_UNICODE);
                if (isset($openByKey[$f['skey']])) {
                    $cur = $openByKey[$f['skey']];
                    // Daraja chegarada tebransa ogohlantirish "Jiddiy ⇄ Diqqat" bo'lib turmasligi uchun:
                    // oshirilgan daraja kamida 3 soat saqlanadi, undan keyingina pasaytiriladi.
                    if ($cur['severity'] !== $f['severity'] && Domain::SEV_ORDER[$f['severity']] > Domain::SEV_ORDER[$cur['severity']]) {
                        $lastUp = (int)Db::val("SELECT MAX(at) FROM signal_log WHERE signal_id = ? AND kind = 'escalated'", [$cur['id']]);
                        if ($lastUp > 0 && $now - $lastUp < 3 * 3600) {
                            Db::update('signals', ['updated_at' => $now], 'id = ?', [$cur['id']]);
                            $stat['updated']++;
                            continue;
                        }
                    }
                    $upd = ['title' => $f['title'], 'why' => $f['why'], 'action' => $f['action'], 'evidence' => $ev, 'updated_at' => $now, 'entity_name' => $f['entity_name']];
                    if ($cur['severity'] !== $f['severity']) {
                        $upd['severity'] = $f['severity'];
                        $up = Domain::SEV_ORDER[$f['severity']] < Domain::SEV_ORDER[$cur['severity']];
                        self::log((int)$cur['id'], $now, 'tizim', $up ? 'escalated' : 'lowered',
                            ($up ? 'Daraja oshdi: ' : 'Daraja pasaydi: ') . Domain::SEVERITY[$cur['severity']] . ' → ' . Domain::SEVERITY[$f['severity']]);
                    }
                    Db::update('signals', $upd, 'id = ?', [$cur['id']]);
                    $stat['updated']++;
                    continue;
                }
                if ($snooze > 0) {
                    $recent = Db::val("SELECT COUNT(*) FROM signals WHERE skey = ? AND status = 'closed' AND closed_by != 'tizim' AND closed_at > ?", [$f['skey'], $now - $snooze]);
                    if ((int)$recent > 0) {
                        $stat['skipped']++;
                        continue;
                    }
                }
                $id = Db::insert('signals', ['skey' => $f['skey'], 'rule' => $f['rule'], 'family' => $f['family'], 'severity' => $f['severity'], 'entity_type' => $f['entity_type'],
                    'entity_id' => $f['entity_id'], 'entity_name' => $f['entity_name'], 'title' => $f['title'], 'why' => $f['why'], 'action' => $f['action'], 'evidence' => $ev,
                    'status' => 'open', 'owner' => $people[$f['role']] ?? null, 'opened_at' => $now, 'updated_at' => $now]);
                self::log($id, $now, 'tizim', 'opened', 'Ogohlantirish ochildi' . (isset($people[$f['role']]) ? ': mas\'ul ' . $people[$f['role']] : ''));
                $stat['opened']++;
            }
            foreach ($openByKey as $key => $cur) {
                if (isset($seen[$key])) {
                    continue;
                }
                $disabled = isset($cfg[$cur['rule']]) && !$cfg[$cur['rule']]['enabled'];
                Db::update('signals', ['status' => 'closed', 'closed_at' => $now, 'closed_by' => 'tizim', 'updated_at' => $now,
                    'close_note' => $disabled ? "Qoida o'chirildi" : 'Shart yo\'qoldi'], 'id = ?', [$cur['id']]);
                self::log((int)$cur['id'], $now, 'tizim', 'closed', $disabled ? "Qoida o'chirildi — ogohlantirish yopildi" : "Muammo bartaraf bo'ldi — ogohlantirish o'zi yopildi");
                $stat['closed']++;
            }
            Db::setMeta('last_run', (string)$now);
            return $stat;
        });
    }

    public static function log(int $signalId, int $at, string $who, string $kind, string $text): void
    {
        Db::insert('signal_log', ['signal_id' => $signalId, 'at' => $at, 'who' => $who, 'kind' => $kind, 'text' => $text]);
    }
}
