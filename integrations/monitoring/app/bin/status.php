<?php
declare(strict_types=1);
/** Holatni terminalda ko'rsatadi (tuzatish uchun):  php bin/status.php */
require __DIR__ . '/../src/bootstrap.php';
Schema::ensure();
$now = Clock::now();
$s = Engine::snapshot($now);
echo 'Hozir: ', date('d.m H:i', $now), "\n\n";
foreach ($s['machines'] as $m) {
    $c = $m['current'];
    printf("%-15s %-6s %5s  navbat %2d (%3dh)  %s\n", $m['name'], $m['state'], $m['since'] ? Util::dur(($now - $m['since']) / 60) : '-', count($m['queue']),
        (int)round($m['queue_min'] / 60), $c ? $c['order_no'] . ' ' . $c['status'] : '');
}
echo "\nOchiq ogohlantirishlar:\n";
foreach (Db::all("SELECT * FROM signals WHERE status IN ('open','seen') ORDER BY CASE severity WHEN 'crit' THEN 0 WHEN 'warn' THEN 1 ELSE 2 END, opened_at") as $r) {
    printf("[%-4s] %-14s %s\n", $r['severity'], $r['rule'], $r['title']);
}
echo "\nBuyurtmalar xavfi:\n";
foreach ($s['orders'] as $o) {
    if ($o['risk']) {
        printf("%s %s muddat %s prognoz %s\n", $o['risk'], $o['no'], date('d.m H:i', $o['due_at']), date('d.m H:i', $o['forecast_end']));
    }
}
printf("\nJami ogohlantirish (tarix): %d, ochiq: %d; buyurtma ochiq %d, bajarilgan %d; fakt %d\n", Db::val('SELECT COUNT(*) FROM signals'), Db::val("SELECT COUNT(*) FROM signals WHERE status!='closed'"),
    Db::val("SELECT COUNT(*) FROM orders WHERE status='open'"), Db::val("SELECT COUNT(*) FROM orders WHERE status='done'"), Db::val('SELECT COUNT(*) FROM facts'));
