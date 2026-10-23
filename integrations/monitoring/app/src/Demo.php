<?php
declare(strict_types=1);

/**
 * Namuna korxona: 14 uskunali bosmaxona. Ma'lumot tasodifiy emas, deterministik (har ishga tushganda bir xil):
 * buyurtmalar, ularning rejasi (Biwork Reja o'rnida oddiy navbat rejalashtiruvchi), ishlab chiqarishning "haqiqiy" kechishi
 * (me'yordan chetlanish, tasodifiy to'xtashlar) va bir nechta aniq voqea ("hikoya"), shu bois namunada har turdagi
 * ogohlantirish ko'rinadi. Tezlik, sozlash va to'xtash davomiyligi haqiqiy zavod ma'lumotlaridan o'rganilgan o'rtachalarga yaqin.
 * Ssenariy `script` jadvaliga yoziladi va soat surilganda Feeder orqali "keladi".
 */
final class Demo
{
    public const DEFAULT_NOW = '2026-10-20 15:00:00';
    private const HISTORY_DAYS = 9;
    private const PRODUCTS = ['Tort qutisi', 'Choy qutisi', 'Dori qutisi', 'Poyabzal qutisi', 'Shirinlik qutisi', 'Kosmetika qutisi', 'Konfet qutisi',
        'Pitsa qutisi', "Sovg'a qutisi", 'Elektronika qutisi', 'Yog\' qadog\'i', 'Sok qadog\'i', 'Qahva qutisi', 'Asal qadog\'i', 'Sovun qutisi', 'Meva qutisi',
        "O'yinchoq qutisi", 'Makaron qadog\'i', 'Un qadog\'i', 'Parfyum qutisi'];
    private const ROUTES = [
        ['w' => 30, 'k' => ['kesish', 'bosma', 'tigel']],
        ['w' => 22, 'k' => ['kesish', 'bosma', 'lam', 'tigel']],
        ['w' => 18, 'k' => ['kesish', 'bosma', 'tigel', 'yelim']],
        ['w' => 12, 'k' => ['kesish', 'bosma', 'lam', 'tigel', 'yelim']],
        ['w' => 10, 'k' => ['tigel', 'yelim']],
        ['w' => 8, 'k' => ['kesish', 'bosma']],
    ];

    // ------------------------------------------------------------------ deterministik tasodifiy sonlar

    private static string $seed = 'biwork-monitoring-demo-1';

    private static function u(string $key): float
    {
        return hexdec(substr(md5(self::$seed . '|' . $key), 0, 12)) / 281474976710655.0;
    }

    private static function between(string $key, float $a, float $b): float
    {
        return $a + ($b - $a) * self::u($key);
    }

    /** Logarifmik-normal: median va sigma bilan. */
    private static function logn(string $key, float $median, float $sigma): float
    {
        $u1 = max(1e-9, self::u($key . 'a'));
        $z = sqrt(-2 * log($u1)) * cos(2 * M_PI * self::u($key . 'b'));
        return $median * exp($sigma * $z);
    }

    /** @param array<int|string,float|int> $w */
    private static function pick(string $key, array $w): int|string
    {
        $t = array_sum($w);
        $x = self::u($key) * $t;
        foreach ($w as $k => $v) {
            $x -= $v;
            if ($x <= 0) {
                return $k;
            }
        }
        return array_key_last($w);
    }

    // ------------------------------------------------------------------ kirish nuqtalari

    /** Bazani tozalab, namuna korxonani yaratadi va tarixni (so'nggi kunlarni) qayta o'ynaydi. */
    public static function seed(?int $now = null): void
    {
        $now ??= (int)strtotime(self::DEFAULT_NOW);
        Schema::ensure();
        self::wipe();
        Db::tx(static function () use ($now): void {
            foreach (Domain::MACHINES as $id => [$name, $section, $speed, $setup]) {
                Db::insert('machines', ['id' => $id, 'name' => $name, 'section' => $section, 'sort' => count(Db::all('SELECT 1 FROM machines')) + 1,
                    'speed_h' => $speed, 'setup_min' => $setup, 'active' => 1]);
            }
            foreach (Domain::PEOPLE as [$n, $r]) {
                Db::insert('people', ['name' => $n, 'role' => $r]);
            }
            $src = [
                ['erp_fakt', 'ERP fakt hisoboti', 'Excel · har 4 soatda', 240],
                ['erp_toxtash', "To'xtashlar jurnali (ERP)", 'Excel · har 4 soatda', 240],
                ['reja', 'Biwork Reja', "Reja · kuniga bir marta", 1440],
                ['kunlik', 'Kunlik reja fayli', 'Excel · kuniga bir marta', 1440],
            ];
            foreach ($src as $i => [$c, $n, $k, $e]) {
                Db::insert('sources', ['code' => $c, 'name' => $n, 'kind' => $k, 'expect_min' => $e, 'sort' => $i + 1, 'last_at' => null]);
            }
            Db::setMeta('demo', '1');
            Db::setMeta('script_pos', '0');
            Db::setMeta('demo_origin', (string)$now);
        });

        $gen = self::generate($now);
        Db::tx(static function () use ($gen): void {
            foreach ($gen as $e) {
                Db::insert('script', ['at' => $e[0], 'kind' => $e[1], 'payload' => json_encode($e[2], JSON_UNESCAPED_UNICODE)]);
            }
        });

        // tarixni 30 daqiqalik qadamlar bilan qayta o'ynash: ogohlantirishlar tarixi ham shu orqali paydo bo'ladi
        $start = $now - self::HISTORY_DAYS * 86400;
        Feeder::advance($start);
        for ($t = $start; $t <= $now; $t += 1800) {
            Feeder::advance($t);
            Engine::run($t);
        }
        if ($t - 1800 < $now) {
            Feeder::advance($now);
            Engine::run($now);
        }
        // "Kunlik reja fayli" uzoq vaqtdan beri yangilanmagan (ssenariy: eskirgan manba)
        Db::exec("UPDATE sources SET last_at = ?, last_rows = 3 WHERE code = 'kunlik'", [$now - 40 * 3600]);
        Engine::run($now);
        Clock::setDemoNow($now);
    }

    /** Simulyatsiya soatini $minutes daqiqaga suradi: ma'lumot ssenariydan keladi, qoidalar har 30 daqiqada ishlaydi. */
    public static function advance(int $minutes): int
    {
        $minutes = max(1, min(24 * 60, $minutes));
        $t = Clock::now();
        $end = $t + $minutes * 60;
        while ($t < $end) {
            $t = min($t + 1800, $end);
            Feeder::advance($t);
            Engine::run($t);
            Clock::setDemoNow($t);
        }
        return $t;
    }

    /** Boshlang'ich holatga qaytarish. */
    public static function reset(): void
    {
        self::seed((int)Db::meta('demo_origin', (string)strtotime(self::DEFAULT_NOW)));
    }

    private static function wipe(): void
    {
        foreach (['signal_log', 'signals', 'facts', 'ops', 'orders', 'script', 'machines', 'people', 'sources', 'rules', 'meta'] as $t) {
            Db::exec("DELETE FROM $t");
        }
        Db::setMeta('schema', (string)Schema::VERSION);
    }

    // ------------------------------------------------------------------ ssenariy tuzish

    /**
     * @return list<array{0:int,1:string,2:array}>  [vaqt, tur, ma'lumot] — vaqt bo'yicha tartiblangan
     */
    private static function generate(int $T): array
    {
        $M = Domain::MACHINES;
        $hStart = $T - (self::HISTORY_DAYS + 3) * 86400;
        $hEnd = $T + 36 * 3600;
        $min = static fn(float|int $m): int => (int)round($m * 60);

        // ---- uskuna kalendari: to'xtashlar (yozib qo'yiladi) ----
        $stops = [];   // machine => list of [start, end, reason]
        $relMul = ['tg05' => 1.0, 'lam920' => 0.8];
        $reasonW = ['tamir' => 11, 'material' => 10, 'qolip' => 7, 'tozalash' => 17, 'boshqa_uchastka' => 8, 'tanaffus' => 9, 'ish_yoq' => 4, 'boshqa' => 28];
        $med = ['tamir' => [55, 1.0], 'material' => [35, 0.7], 'qolip' => [25, 0.6], 'tozalash' => [14, 0.5], 'boshqa_uchastka' => [60, 0.8],
            'tanaffus' => [30, 0.15], 'ish_yoq' => [90, 0.8], 'boshqa' => [26, 0.8]];
        $quiet = [$T - 4 * 3600, $T + 3 * 3600]; // hikoya oynasi: tasodifiy to'xtash tushmaydi
        foreach ($M as $mid => $_) {
            $t = $hStart + (int)(self::u("stop0:$mid") * 12 * 3600);
            $i = 0;
            while ($t < $hEnd) {
                $i++;
                $gap = -log(1 - self::u("stopgap:$mid:$i") * 0.999) * 22 * 3600 / ($relMul[$mid] ?? 1.0);
                $t += (int)$gap + 1800;
                $r = (string)self::pick("stopr:$mid:$i", $reasonW);
                [$m0, $sg] = $med[$r];
                $d = (int)max(5, min(480, self::logn("stopd:$mid:$i", $m0, $sg)));
                if ($t < $hEnd && !($t + $d * 60 > $quiet[0] && $t < $quiet[1])) {
                    $stops[$mid][] = [$t, $t + $d * 60, $r];
                }
                $t += $d * 60;
            }
        }
        // hikoya to'xtashlari
        $forced = [
            ['tg05', $T - 110 * 60, $T + 95 * 60, 'tamir'],
            ['tg05', $T - 2 * 86400 - 5 * 3600, $T - 2 * 86400 - 5 * 3600 + 70 * 60, 'tamir'],
            ['tg05', $T - 5 * 86400 + 3600, $T - 5 * 86400 + 3600 + 140 * 60, 'tamir'],
            ['tg03', $T - 40 * 60, $T + 35 * 60, 'boshqa'],
            ['tg03', $T - 5 * 3600, $T - 5 * 3600 + 30 * 60, 'boshqa'],
        ];
        foreach ($forced as [$mid, $a, $b, $r]) {
            $stops[$mid] = array_values(array_filter($stops[$mid] ?? [], static fn($s) => $s[1] < $a - 1800 || $s[0] > $b + 1800));
            $stops[$mid][] = [$a, $b, $r];
        }
        foreach ($stops as &$list) {
            usort($list, static fn($x, $y) => $x[0] <=> $y[0]);
        }
        unset($list);

        // ---- "hikoya" operatsiyalari (aniq vaqtli) ----
        // [buyurtma kaliti, uskuna, tur, reja boshlanishi, sozlash me'yori, ishlash me'yori, haqiqiy boshlanish, haqiqiy sozlash, haqiqiy ishlash, soni]
        $pinned = []; // orderKey => ['name','qty','created','due'|null,'urgent','ops'=>[seq=>spec]]
        $addOrder = static function (string $key, string $name, int $qty, int $created, ?int $due) use (&$pinned): void {
            $pinned[$key] = ['name' => $name, 'qty' => $qty, 'created' => $created, 'due' => $due, 'urgent' => 0, 'ops' => []];
        };
        $pin = static function (string $key, int $seq, string $mid, string $kind, float|int $planStart, float|int $setupN, float|int $runN, float|int $start, float|int $setupA, float|int $runA, int $qty) use (&$pinned): void {
            $pinned[$key]['ops'][$seq] = compact('mid', 'kind', 'planStart', 'setupN', 'runN', 'start', 'setupA', 'runA', 'qty');
        };
        $H = 3600;
        // O1 — tigel buzilgan (jiddiy kechikish xavfi)
        $addOrder('O1', 'Tort qutisi 32×32', 2860, $T - 36 * $H, $T + 7 * $H);
        $pin('O1', 1, 'list', 'kesish', $T - 34 * $H, 18, 60, $T - 34 * $H + 300, 18, 62, 2860);
        $pin('O1', 2, 'ofs72', 'bosma', $T - 31 * $H, 100, 214, $T - 31 * $H, 105, 230, 2860);
        $pin('O1', 3, 'tg05', 'tigel', $T - 5 * $H, 45, 330, $T - 5 * $H - 600, 50, 340, 2860);
        // O2 — ofset bo'sh turibdi, tayyor ish bor
        $addOrder('O2a', 'Konfet qutisi 20×14', 3000, $T - 30 * $H, $T + 2 * 86400);
        $pin('O2a', 1, 'ofs105', 'bosma', $T - 200 * 60, 30, 72, $T - 3 * $H, 32, 83, 3000);
        $addOrder('O2', 'Choy qutisi 18×9', 3750, $T - 20 * $H, $T + 3 * 86400);
        $pin('O2', 1, 'list', 'kesish', $T - 6 * $H, 18, 78, $T - 6 * $H + 120, 18, 80, 3750);
        $pin('O2', 2, 'ofs105', 'bosma', $T - 60 * 60, 30, 90, $T + 45 * 60, 30, 92, 3750);
        // O3 — tigelda oldingi ish cho'zilgan, keyingisi kechikmoqda
        $addOrder('O3a', "Pitsa qutisi 40×40", 2340, $T - 40 * $H, $T + 20 * $H);
        $pin('O3a', 1, 'ofs72', 'bosma', $T - 16 * $H, 100, 175, $T - 16 * $H, 104, 180, 2340);
        $pin('O3a', 2, 'tg07', 'tigel', $T - 6.5 * $H, 51, 270, $T - 6.5 * $H + 300, 55, 370, 2340);
        $addOrder('O3', 'Poyabzal qutisi 34×22', 1400, $T - 38 * $H, $T + 6 * $H);
        $pin('O3', 1, 'ofs72', 'bosma', $T - 10 * $H, 100, 105, $T - 10 * $H, 100, 108, 1400);
        $pin('O3', 2, 'tg07', 'tigel', $T - 75 * 60, 51, 180, $T + 50 * 60, 53, 190, 1400);
        // O4 — shtanzda sozlash cho'zilgan
        $addOrder('O4', 'Dori qutisi 12×6', 4800, $T - 28 * $H, $T + (int)(5.5 * $H));
        $pin('O4', 1, 'ofs105', 'bosma', $T - 20 * $H, 30, 115, $T - 20 * $H + 600, 30, 118, 4800);
        $pin('O4', 2, 'shg', 'tigel', $T - 110 * 60, 50, 210, $T - 95 * 60, 125, 220, 4800);
        // O5 — yelimlashda ish me'yordan ko'p ketyapti
        $addOrder('O5', 'Un qadog\'i 5 kg', 3300, $T - 44 * $H, $T + 10 * $H);
        $pin('O5', 1, 'tg04', 'tigel', $T - 30 * $H, 43, 400, $T - 30 * $H + 900, 45, 410, 3300);
        $pin('O5', 2, 'ye2500', 'yelim', $T - 9 * $H - 20 * 60, 12, 360, $T - 9 * $H - 15 * 60, 14, 560, 9900);

        // ---- pinned operatsiyalarning haqiqiy bajarilishi ----
        $facts = [];     // list of [machine, opKey|null, kind, reason, start, end, qty]
        $nextFact = 1;
        $free = [];      // machine => haqiqiy bo'shash vaqti
        $reserve = [];   // machine => list [a,b]
        $placed = [];    // opKey => ['start','end','setupEnd']
        $place = function (string $mid, int $t, float $workMin, string $kind, ?string $opKey, int $qty) use (&$facts, &$stops): int {
            $rem = (int)round($workMin * 60);
            $total = max(1, $rem);
            $cur = $t;
            $segs = [];
            $guard = 0;
            while ($rem > 0 && $guard++ < 60) {
                $hit = null;
                foreach ($stops[$mid] ?? [] as $s) {
                    if ($s[1] > $cur && $s[0] < $cur + $rem) {
                        $hit = $s;
                        break;
                    }
                }
                if ($hit === null) {
                    $segs[] = [$cur, $cur + $rem];
                    $cur += $rem;
                    $rem = 0;
                } elseif ($hit[0] <= $cur) {
                    $cur = $hit[1];
                } else {
                    $segs[] = [$cur, $hit[0]];
                    $rem -= $hit[0] - $cur;
                    $cur = $hit[1];
                }
            }
            $left = $qty;
            $n = count($segs);
            foreach ($segs as $i => [$a, $b]) {
                $q = $kind === 'run' ? ($i === $n - 1 ? $left : (int)round($qty * ($b - $a) / $total)) : 0;
                $left -= $q;
                $facts[] = [$mid, $opKey, $kind, null, $a, $b, $q];
            }
            return $cur;
        };

        $ops = [];       // opKey => op record
        $orders = [];    // orderKey => order record
        $orderSeq = 4001;
        $opSeq = 1;
        $mkOrder = static function (string $key, string $name, int $qty, int $created, ?int $due, int $urgent) use (&$orders, &$orderSeq): void {
            $orders[$key] = ['id' => $orderSeq, 'no' => '2026-' . $orderSeq, 'name' => $name, 'qty' => $qty, 'created_at' => $created, 'due_at' => $due, 'urgent' => $urgent, 'ops' => []];
            $orderSeq++;
        };

        // O1.. tartibi: raqamlar ssenariy tartibida (4001.. tarixiy fon buyurtmalar emas, pinned o'z kalitlari bilan)
        // Fon buyurtmalar avval yaratiladi (kichik raqamlar), keyin pinned.
        // ---- fon buyurtmalar ----
        $bg = [];
        $d0 = (int)strtotime(date('Y-m-d', $T) . ' 00:00:00');
        $idx = 0;
        for ($d = -(self::HISTORY_DAYS + 3); $d <= 1; $d++) {
            $n = 5 + (int)floor(self::u("dayn:$d") * 3);
            for ($j = 0; $j < $n; $j++) {
                $at = $d0 + $d * 86400 + (int)(8.5 * 3600 + self::u("arr:$d:$j") * 9 * 3600);
                if ($at > $hEnd - 6 * 3600 || $at < $hStart) {
                    continue;
                }
                $bg[] = ['k' => 'B' . (++$idx), 'at' => $at];
            }
        }
        usort($bg, static fn($a, $b) => $a['at'] <=> $b['at']);
        $all = [];
        foreach ($bg as $b) {
            $all[] = ['k' => $b['k'], 'at' => $b['at'], 'pinned' => false];
        }
        foreach ($pinned as $k => $p) {
            $all[] = ['k' => $k, 'at' => $p['created'], 'pinned' => true];
        }
        // laminatsiya navbati: bir necha katta buyurtma faqat Laminator 920 ga tushadi (tor joy hikoyasi)
        foreach (['L1' => -30, 'L2' => -26, 'L3' => -22, 'L4' => -15, 'L5' => -9, 'L6' => -4] as $k => $h) {
            $all[] = ['k' => $k, 'at' => $T + $h * $H, 'pinned' => false, 'lam' => true];
        }
        usort($all, static fn($a, $b) => $a['at'] <=> $b['at'] ?: strcmp($a['k'], $b['k']));

        // pinned operatsiyalarni oldindan joylash (uskuna oynalarini band qilish uchun)
        foreach ($pinned as $k => $p) {
            foreach ($p['ops'] as $seq => $o) {
                $mid = $o['mid'];
                $end0 = $place($mid, (int)$o['start'], $o['setupA'], 'setup', "$k#$seq", 0);
                $end = $place($mid, $end0, $o['runA'], 'run', "$k#$seq", $o['qty']);
                $placed["$k#$seq"] = ['start' => (int)$o['start'], 'end' => $end, 'setupEnd' => $end0];
                $reserve[$mid][] = [min((int)$o['planStart'], (int)$o['start']) - 1800, max((int)$o['planStart'] + $min($o['setupN'] + $o['runN']), $end) + 1800];
            }
        }

        // reja: navbat rejalashtiruvchi (har uskunada kelish tartibida)
        $avail = [];
        foreach ($M as $mid => $_) {
            $avail[$mid] = $hStart;
        }
        $groups = [];
        foreach ($M as $mid => [$nm, $sec]) {
            $groups[$sec][] = $mid;
        }
        $fit = static function (string $mid, int $earliest, int $durSec) use (&$avail, &$reserve): int {
            $start = max($avail[$mid], $earliest);
            $pad = (int)($durSec * 1.35) + 1800;
            for ($i = 0; $i < 10; $i++) {
                $moved = false;
                foreach ($reserve[$mid] ?? [] as [$a, $b]) {
                    if ($start < $b && $start + $pad > $a) {
                        $start = $b;
                        $moved = true;
                    }
                }
                if (!$moved) {
                    break;
                }
            }
            return $start;
        };
        $qtyFor = static fn(string $k): int => (int)(round(self::between("qty:$k", 1800, 7600) / 100) * 100);

        foreach ($all as $a) {
            $k = $a['k'];
            if ($a['pinned']) {
                $p = $pinned[$k];
                $mkOrder($k, $p['name'], $p['qty'], $p['created'], $p['due'], $p['urgent']);
                $prevEnd = $p['created'];
                // pinned bo'lmagan keyingi operatsiyalar marshrutdan (O1: yelim)
                $route = $k === 'O1' ? ['kesish', 'bosma', 'tigel', 'yelim'] : ($k === 'O2' ? ['kesish', 'bosma', 'tigel'] : array_map(static fn($o) => $o['kind'], $p['ops']));
                if ($k === 'O3a' || $k === 'O3') {
                    $route = ['bosma', 'tigel'];
                }
                if ($k === 'O4') {
                    $route = ['bosma', 'tigel'];
                }
                if ($k === 'O5') {
                    $route = ['tigel', 'yelim'];
                }
                if ($k === 'O2a') {
                    $route = ['bosma'];
                }
                foreach ($route as $i => $kind) {
                    $seq = $i + 1;
                    $o = $p['ops'][$seq] ?? null;
                    if ($o) {
                        $planStart = (int)$o['planStart'];
                        $rec = ['mid' => $o['mid'], 'kind' => $kind, 'plan_start' => $planStart, 'setup_norm' => $o['setupN'], 'run_norm' => $o['runN'], 'qty' => $o['qty'], 'pinned' => true];
                    } else {
                        $mid = self::chooseMachine($groups[$kind], $avail, $prevEnd, "$k:$seq", $kind);
                        $q = (int)($kind === 'yelim' ? $p['qty'] * 3 : $p['qty']);
                        $runN = $q / $M[$mid][2] * 60;
                        $planStart = $fit($mid, $prevEnd + ($i > 0 ? ($route[$i - 1] === 'bosma' ? 7200 : 0) : 0), $min($M[$mid][3] + $runN));
                        $rec = ['mid' => $mid, 'kind' => $kind, 'plan_start' => $planStart, 'setup_norm' => (float)$M[$mid][3], 'run_norm' => $runN, 'qty' => $q, 'pinned' => false];
                        $rec['plan_dur'] = ($M[$mid][3] + $runN) * 1.06 + 12;
                        $avail[$mid] = $planStart + $min($rec['plan_dur']);
                    }
                    $rec['plan_end'] = $rec['plan_start'] + $min($rec['plan_dur'] ?? ($rec['setup_norm'] + $rec['run_norm']));
                    $prevEnd = $rec['plan_end'];
                    $rec['key'] = "$k#$seq";
                    $rec['order'] = $k;
                    $rec['seq'] = $seq;
                    $rec['id'] = $opSeq++;
                    $orders[$k]['ops'][] = $rec['key'];
                    $ops[$rec['key']] = $rec;
                }
                continue;
            }
            // fon buyurtma
            $lamHeavy = !empty($a['lam']);
            $route = $lamHeavy ? ['kesish', 'bosma', 'lam', 'tigel'] : self::ROUTES[(int)self::pick("route:$k", array_column(self::ROUTES, 'w'))]['k'];
            $qty = $lamHeavy ? (int)(round(self::between("lq:$k", 5200, 7400) / 100) * 100) : $qtyFor($k);
            $name = self::PRODUCTS[(int)(self::u("name:$k") * count(self::PRODUCTS))] . ' ' . (int)self::between("sz:$k", 10, 44) . '×' . (int)self::between("sz2:$k", 6, 40);
            $mkOrder($k, $name, $qty, $a['at'], null, self::u("urg:$k") < 0.07 ? 1 : 0);
            $prevEnd = $a['at'] + 3600;
            foreach ($route as $i => $kind) {
                $seq = $i + 1;
                $mid = ($lamHeavy && $kind === 'lam') ? 'lam920' : self::chooseMachine($groups[$kind], $avail, $prevEnd, "$k:$seq", $kind);
                $q = (int)($kind === 'yelim' ? $qty * 3 : $qty);
                $runN = $q / $M[$mid][2] * 60;
                $setupN = $M[$mid][3] * self::between("su:$k:$seq", 0.75, 1.25);
                $gapSec = $i > 0 && $route[$i - 1] === 'bosma' ? 7200 : 0;
                $planStart = $fit($mid, $prevEnd + $gapSec, $min(($setupN + $runN) * 1.06 + 12));
                $planDur = ($setupN + $runN) * 1.06 + 12;
                $rec = ['mid' => $mid, 'kind' => $kind, 'plan_start' => $planStart, 'setup_norm' => $setupN, 'run_norm' => $runN, 'qty' => $q, 'pinned' => false,
                    'plan_end' => $planStart + $min($planDur), 'key' => "$k#$seq", 'order' => $k, 'seq' => $seq, 'id' => $opSeq++];
                $avail[$mid] = $rec['plan_end'];
                $prevEnd = $rec['plan_end'];
                $orders[$k]['ops'][] = $rec['key'];
                $ops[$rec['key']] = $rec;
            }
        }
        // muddatlar: fon buyurtmalar uchun reja tugashidan keyin zaxira
        foreach ($orders as $k => &$o) {
            if ($o['due_at'] !== null) {
                continue;
            }
            $last = $ops[$o['ops'][count($o['ops']) - 1]];
            $tight = self::u("tight:$k") < 0.14;
            $slackH = $tight ? self::between("slk:$k", 0.5, 7) : self::between("slk:$k", 9, 70);
            $o['due_at'] = $last['plan_end'] + (int)round($slackH * 3600);
        }
        unset($o);

        // haqiqiy bajarilish: plan_start bo'yicha ketma-ket
        $order = array_keys($ops);
        usort($order, static fn($a, $b) => [$ops[$a]['plan_start'], $ops[$a]['id']] <=> [$ops[$b]['plan_start'], $ops[$b]['id']]);
        foreach ($order as $key) {
            $op = &$ops[$key];
            $mid = $op['mid'];
            if ($op['pinned']) {
                $pl = $placed[$key];
                $op['act_start'] = $pl['start'];
                $op['act_end'] = $pl['end'];
                $free[$mid] = max($free[$mid] ?? 0, $pl['end']);
                unset($op);
                continue;
            }
            $prevKey = $op['seq'] > 1 ? $orders[$op['order']]['ops'][$op['seq'] - 2] : null;
            $ready = $orders[$op['order']]['created_at'] + 1800;
            if ($prevKey !== null) {
                $pe = $ops[$prevKey]['act_end'] ?? null;
                if ($pe === null) {
                    unset($op);
                    continue; // oldingi bosqich bajarilmadi (ufqdan keyin)
                }
                $ready = $pe + ($ops[$prevKey]['kind'] === 'bosma' ? 7200 : 0);
            }
            $lagU = self::u("lag:$key");
            $lagMin = $lagU < 0.62 ? self::between("lag1:$key", 0, 10) : ($lagU < 0.88 ? self::between("lag2:$key", 10, 40) : ($lagU < 0.98 ? self::between("lag3:$key", 40, 120) : self::between("lag4:$key", 120, 300)));
            $start = max($ready, $free[$mid] ?? $hStart, $op['plan_start'] - 2700) + $min($lagMin);
            foreach ($reserve[$mid] ?? [] as [$ra, $rb]) { // hikoya oynasi bilan to'qnashmaslik
                if ($start < $rb && $start + $min(($op['setup_norm'] + $op['run_norm']) * 1.1) > $ra) {
                    $start = max($start, $rb);
                }
            }
            if ($start >= $hEnd) {
                unset($op);
                continue;
            }
            $setupA = max(5.0, min($op['setup_norm'] * 3.5, self::logn("sa:$key", $op['setup_norm'], 0.25)));
            $runA = max($op['run_norm'] * 0.85, min($op['run_norm'] * 2.0, self::logn("ra:$key", $op['run_norm'] * 1.03, 0.13)));
            $e0 = $place($mid, $start, $setupA, 'setup', $key, 0);
            $e1 = $place($mid, $e0, $runA, 'run', $key, $op['qty']);
            $op['act_start'] = $start;
            $op['act_end'] = $e1;
            $free[$mid] = max($free[$mid] ?? 0, $e1);
            unset($op);
        }

        // to'xtash faktlari (kalendardan): faqat ufq ichida
        foreach ($stops as $mid => $list) {
            foreach ($list as [$a, $b, $r]) {
                if ($a < $hEnd) {
                    $facts[] = [$mid, null, 'stop', $r, $a, $b, 0];
                }
            }
        }

        // tekshiruv: bir uskunada ikki ish bir vaqtda bo'lmasligi kerak
        $byM = [];
        foreach ($facts as $f) {
            $byM[$f[0]][] = $f;
        }
        foreach ($byM as $mid => $list) {
            usort($list, static fn($a, $b) => $a[4] <=> $b[4]);
            for ($i = 1; $i < count($list); $i++) {
                if ($list[$i][4] < $list[$i - 1][5]) {
                    throw new RuntimeException(sprintf('Demo: %s uskunasida ishlar ustma-ust tushdi: %s %s [%s–%s] va %s %s [%s–%s]', $mid,
                        $list[$i - 1][2], (string)$list[$i - 1][1], date('d.m H:i', $list[$i - 1][4]), date('d.m H:i', $list[$i - 1][5]),
                        $list[$i][2], (string)$list[$i][1], date('d.m H:i', $list[$i][4]), date('d.m H:i', $list[$i][5])));
                }
            }
        }

        // ---- ssenariy voqealari ----
        $ev = [];
        foreach ($orders as $k => $o) {
            $opsList = [];
            foreach ($o['ops'] as $ok) {
                $r = $ops[$ok];
                $opsList[] = ['id' => $r['id'], 'seq' => $r['seq'], 'kind' => $r['kind'], 'machine_id' => $r['mid'], 'plan_start' => $r['plan_start'], 'plan_end' => $r['plan_end'],
                    'qty' => $r['qty'], 'setup_norm' => (int)round($r['setup_norm']), 'run_norm' => (int)round($r['run_norm'])];
            }
            $at = min($o['created_at'], $opsList ? $opsList[0]['plan_start'] : $o['created_at']);
            $ev[] = [max($hStart - 86400, min($o['created_at'], $at)), 'order', ['order' => ['id' => $o['id'], 'no' => $o['no'], 'name' => $o['name'], 'qty' => $o['qty'],
                'created_at' => $o['created_at'], 'due_at' => $o['due_at'], 'urgent' => $o['urgent']], 'ops' => $opsList]];
        }
        usort($facts, static fn($a, $b) => $a[4] <=> $b[4]);
        foreach ($facts as $f) {
            [$mid, $opKey, $kind, $reason, $a, $b, $q] = $f;
            $fid = $nextFact++;
            $opId = $opKey !== null && isset($ops[$opKey]) ? $ops[$opKey]['id'] : null;
            $ev[] = [$a, 'fact_open', ['id' => $fid, 'machine_id' => $mid, 'op_id' => $opId, 'kind' => $kind, 'reason' => $reason]];
            if ($b <= $hEnd) {
                $ev[] = [$b, 'fact_close', ['id' => $fid, 'op_id' => $opId, 'qty' => $q]];
            }
        }
        foreach ($ops as $r) {
            if (isset($r['act_end']) && $r['act_end'] <= $hEnd) {
                $ev[] = [$r['act_end'], 'op_done', ['op_id' => $r['id'], 'order_id' => $orders[$r['order']]['id']]];
            }
        }
        $prio = ['fact_close' => 0, 'op_done' => 1, 'order' => 2, 'fact_open' => 3];
        usort($ev, static fn($x, $y) => [$x[0], $prio[$x[1]]] <=> [$y[0], $prio[$y[1]]]);
        return $ev;
    }

    /** Guruhdagi eng erta bo'shaydigan uskunani tanlaydi (bosma va laminatsiyada asosiy uskuna afzal). */
    private static function chooseMachine(array $group, array $avail, int $earliest, string $key, string $kind): string
    {
        $pref = ['bosma' => ['ofs105' => 0, 'ofs72' => 14 * 3600], 'lam' => ['lam920' => 0, 'lam630' => 10 * 3600]];
        $best = null;
        $bestT = PHP_INT_MAX;
        foreach ($group as $mid) {
            $t = max($avail[$mid], $earliest) + ($pref[$kind][$mid] ?? 0) + (int)(self::u("cm:$key:$mid") * 3600);
            if ($t < $bestT) {
                $bestT = $t;
                $best = $mid;
            }
        }
        return (string)$best;
    }
}
