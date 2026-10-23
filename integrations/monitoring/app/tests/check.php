<?php
declare(strict_types=1);

/**
 * Birlik sinovi: qoidalar mexanizmi, ogohlantirish hayot sikli, namuna ma'lumot, vaqtni surish.
 * Tarmoqsiz ishlaydi; vaqtinchalik baza yaratadi:  php tests/check.php
 */
$tmp = sys_get_temp_dir() . '/mon-check-' . getmypid();
@mkdir($tmp, 0775, true);
putenv('MON_DB=' . $tmp . '/check.sqlite');
putenv('MON_CONFIG=' . $tmp . '/none.php');
putenv('MON_NOW'); // soatni namuna korxona o'zi boshqaradi
register_shutdown_function(static function () use ($tmp): void {
    foreach (glob($tmp . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($tmp);
});

require __DIR__ . '/../src/bootstrap.php';

$fails = 0;
$total = 0;
function ok(bool $c, string $n): void
{
    global $fails, $total;
    $total++;
    echo ($c ? '  ✓ ' : '  ✗ ') . $n . "\n";
    if (!$c) {
        $fails++;
    }
}
function sig(string $where, array $args = []): array
{
    return Db::all("SELECT * FROM signals WHERE $where", $args);
}

echo "== Namuna korxona yaratiladi\n";
$t0 = microtime(true);
Demo::seed((int)strtotime(Demo::DEFAULT_NOW));
ok(microtime(true) - $t0 < 20, 'namuna ' . round(microtime(true) - $t0, 1) . ' soniyada tayyor bo\'ldi');
$now = Clock::now();
ok(date('Y-m-d H:i', $now) === '2026-10-20 15:00', 'namuna soati 20.10.2026 15:00');
ok(Clock::isDemo(), 'namuna rejimi yoqilgan');
ok((int)Db::val('SELECT COUNT(*) FROM machines') === 14, '14 ta uskuna');
ok((int)Db::val('SELECT COUNT(*) FROM people') === 4 && (int)Db::val('SELECT COUNT(*) FROM sources') === 4, '4 kishi va 4 ma\'lumot manbai');
ok((int)Db::val("SELECT COUNT(*) FROM facts WHERE started_at < ?", [$now - 5 * 86400]) > 100, 'tarix mavjud (5 kundan oldingi fakt yozuvlari)');

echo "== Vaqt oralig'idagi so'rovlar (SQLite butun/matn solishtirish xatosi qaytmasin)\n";
$d0 = Clock::dayStart($now);
$busy = array_sum(Metrics::busyMinutes($d0 - 4 * 86400, $d0 - 3 * 86400));
ok($busy > 600, 'o\'tgan kunlarning bandligi hisoblanadi (' . round($busy) . ' daqiqa)');
$tr = Metrics::trend($now, 7);
ok(count($tr) === 7 && min(array_column($tr, 'util')) > 10, '7 kunlik dinamikada nol kun yo\'q');

echo "== Qoidalar\n";
$codes = array_keys(Rules::DEFS);
ok(count($codes) === 10, '10 ta qoida: ' . implode(', ', $codes));
$fired = array_column(Db::all('SELECT DISTINCT rule FROM signals'), 'rule');
foreach ($codes as $c) {
    ok(in_array($c, $fired, true), "«{$c}» qoidasi namunada kamida bir marta ishlagan");
}
$open = sig("status IN ('open','seen')");
ok(count($open) >= 8 && count($open) <= 20, 'hozir ochiq ogohlantirishlar soni me\'yorida: ' . count($open));
$crit = array_filter($open, static fn($a) => $a['severity'] === 'crit');
ok(count($crit) >= 1 && count($crit) <= 4, 'jiddiylari kam va aniq: ' . count($crit));
$stop = sig("rule = 'stop_long' AND entity_id = 'tg05' AND status IN ('open','seen')");
ok(count($stop) === 1 && $stop[0]['severity'] === 'crit', 'Tigel-05 uzoq to\'xtashi «jiddiy»');
ok(count(sig("rule = 'late_risk' AND severity = 'crit' AND status IN ('open','seen')")) >= 1, 'muddatga ulgurmaydigan buyurtma «jiddiy» deb topilgan');
$keys = array_column($open, 'skey');
ok(count($keys) === count(array_unique($keys)), 'bir muammo uchun bitta ochiq ogohlantirish');
$bad = 0;
foreach (Db::all('SELECT * FROM signals') as $a) {
    $ev = json_decode((string)$a['evidence'], true);
    $txt = $a['title'] . $a['why'] . $a['action'];
    if (trim($a['title']) === '' || trim($a['why']) === '' || trim($a['action']) === '' || !is_array($ev) || !in_array($a['severity'], ['crit', 'warn', 'info'], true)
        || preg_match('/\\b(null|undefined|NaN|Exception)\\b|[{}$]/', $txt)) {
        $bad++;
    }
}
ok($bad === 0, 'har ogohlantirishda sarlavha, sabab, tavsiya va ko\'rsatkichlar bor, texnik so\'zlar yo\'q');
ok(Util::dur(110) === '1 soat 50 daqiqa' && Util::dur(45) === '45 daqiqa', 'davomiylik o\'zbekcha yoziladi');

echo "== Hayot sikli: vaqt o'tadi, muammo bartaraf bo'lsa ogohlantirish o'zi yopiladi\n";
$stopId = (int)$stop[0]['id'];
Demo::advance(180);
ok(Clock::now() === $now + 3 * 3600, 'soat 3 soatga surildi');
$st = Db::one('SELECT * FROM signals WHERE id = ?', [$stopId]);
ok($st['status'] === 'closed' && $st['closed_by'] === 'tizim', 'ta\'mir tugagach to\'xtash ogohlantirishi o\'zi yopildi');
$logk = array_column(Db::all('SELECT kind FROM signal_log WHERE signal_id = ? ORDER BY id', [$stopId]), 'kind');
ok($logk[0] === 'opened' && end($logk) === 'closed', 'tarix: ochildi → yopildi (' . implode(' → ', $logk) . ')');
$r = Engine::run(Clock::now());
ok($r['opened'] === 0 && $r['closed'] === 0, 'hisobni qayta ishga tushirish hech narsani o\'zgartirmaydi (idempotent)');

echo "== Foydalanuvchi amallari\n";
$a = sig("status IN ('open','seen') AND severity = 'warn' ORDER BY id LIMIT 1")[0];
$id = (int)$a['id'];
$res = Alerts::act($id, 'seen', [], 'Sinov');
ok($res['status'] === 'seen', '«Ko\'rdim» holatni o\'zgartiradi');
$res = Alerts::act($id, 'assign', ['owner' => 'Dilshod Aliyev'], 'Sinov');
ok($res['owner'] === 'Dilshod Aliyev', 'mas\'ul tayinlanadi');
$threw = false;
try {
    Alerts::act($id, 'comment', ['text' => '  '], 'Sinov');
} catch (ApiError $e) {
    $threw = $e->status === 422;
}
ok($threw, 'bo\'sh izoh rad etiladi');
Alerts::act($id, 'comment', ['text' => 'Ta\'mirchi chaqirildi'], 'Sinov');
$res = Alerts::act($id, 'close', ['note' => 'Hal qilindi'], 'Sinov');
ok($res['status'] === 'closed' && $res['closed_by'] === 'Sinov' && $res['close_note'] === 'Hal qilindi', 'qo\'lda yopish izoh bilan saqlanadi');
Engine::run(Clock::now());
ok(Db::val("SELECT COUNT(*) FROM signals WHERE skey = ? AND status IN ('open','seen')", [$a['skey']]) == 0, 'qo\'lda yopilgan ogohlantirish 4 soat qayta chiqmaydi (shart hali bor bo\'lsa ham)');
$res = Alerts::act($id, 'reopen', [], 'Sinov');
ok($res['status'] === 'open' && $res['closed_at'] === null, 'qayta ochish ishlaydi');
$kinds = array_column($res['log'], 'kind');
ok(in_array('comment', $kinds, true) && in_array('assigned', $kinds, true) && in_array('closed', $kinds, true) && in_array('reopened', $kinds, true), 'tarixda hamma amal yozilgan');

echo "== Qoidalarni sozlash\n";
$threw = false;
try {
    Rules::save('stop_long', true, ['minutes' => 5]);
} catch (ApiError $e) {
    $threw = $e->status === 422;
}
ok($threw, 'chegaradan tashqari qiymat rad etiladi');
$threw = false;
try {
    Rules::save('stop_long', true, ['minutes' => 'abc']);
} catch (ApiError $e) {
    $threw = true;
}
ok($threw, 'raqam bo\'lmagan qiymat rad etiladi');
Db::tx(static fn() => Rules::save('no_reason', false, []));
Engine::run(Clock::now());
ok((int)Db::val("SELECT COUNT(*) FROM signals WHERE rule = 'no_reason' AND status IN ('open','seen')") === 0, 'o\'chirilgan qoidaning ogohlantirishlari yopiladi');
$closeNote = Db::val("SELECT close_note FROM signals WHERE rule = 'no_reason' ORDER BY closed_at DESC LIMIT 1");
ok($closeNote !== null, 'yopilish sababi yozilgan: ' . $closeNote);
Db::tx(static fn() => Rules::save('no_reason', true, []));
$d = Rules::describe();
ok(count($d['rules']) === 10 && count($d['general']) === 2, 'qoidalar ro\'yxati to\'liq (10 + 2 umumiy sozlama)');

echo "== Buyurtma prognozi\n";
$snap = Engine::snapshot(Clock::now(), Rules::load());
ok(count($snap['machines']) === 14 && count($snap['orders']) > 10, 'holat: 14 uskuna, ' . count($snap['orders']) . ' ochiq buyurtma');
$withDue = array_filter($snap['orders'], static fn($o) => $o['forecast_end'] !== null && $o['due_at'] !== null);
ok(count($withDue) === count($snap['orders']), 'har buyurtmaga prognoz va muddat hisoblangan');

echo "\n" . ($fails ? "XATO: $fails / $total" : "Hammasi joyida: $total ta tekshiruv") . "\n";
exit($fails ? 1 : 0);
