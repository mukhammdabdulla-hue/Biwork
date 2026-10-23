<?php
declare(strict_types=1);

/** Tuzatish uchun: namuna soatini 30 daqiqalik qadamlar bilan suradi va har qadamda ochilgan/yopilgan ogohlantirishlarni chiqaradi.  php tests/probe.php [qadamlar] */
require __DIR__ . '/../src/bootstrap.php';
Schema::ensure();
$steps = (int)($argv[1] ?? 12);
for ($i = 0; $i < $steps; $i++) {
    $before = array_column(Db::all("SELECT id, title, severity FROM signals WHERE status IN ('open','seen')"), null, 'id');
    $t = Demo::advance(30);
    $after = array_column(Db::all("SELECT id, title, severity FROM signals WHERE status IN ('open','seen')"), null, 'id');
    printf("--- %s (ochiq %d)\n", date('d.m H:i', $t), count($after));
    foreach ($after as $id => $r) { if (!isset($before[$id])) printf("  + [%s] %s\n", $r['severity'], $r['title']); }
    foreach ($before as $id => $r) { if (!isset($after[$id])) printf("  - [%s] %s\n", $r['severity'], $r['title']); }
}
