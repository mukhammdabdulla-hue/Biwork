<?php
declare(strict_types=1);
/** Qoidalarni bir marta ishga tushiradi (cron: har 5 daqiqada).  php bin/tick.php */
require __DIR__ . '/../src/bootstrap.php';
Boot::ready();
if (Clock::isDemo()) {
    fwrite(STDERR, "Namuna korxona soati qo'lda suriladi; tick kerak emas.\n");
    exit(0);
}
$r = Engine::run(Clock::now());
echo json_encode($r), "\n";
