<?php
declare(strict_types=1);
/** Namuna korxonani yaratadi:  php bin/seed.php [YYYY-MM-DD HH:MM]   (MON_DB bilan boshqa fayl ko'rsatish mumkin) */
require __DIR__ . '/../src/bootstrap.php';
$now = isset($argv[1]) ? strtotime($argv[1]) : null;
$t0 = microtime(true);
Demo::seed($now ?: null);
printf("Tayyor: %s (%.1f s). Hozir: %s\n", Db::path(), microtime(true) - $t0, date('d.m.Y H:i', Clock::now()));
