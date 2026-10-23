<?php
/** Mijozga beriladigan (app) nusxani ichki izohlardan tozalaydi.   php scrub.php <PM_nusxa_papkasi>
 *  PM manba kodidagi izohlarda haqiqiy tashkilot va foydalanuvchi iqtiboslari bor — mijozga ketmasligi kerak:
 *   1) php/*.php va ildizdagi *.php: izohlar va ortiqcha bo'shliq olib tashlanadi (php_strip_whitespace; heredoc SQL ichidagi blok izohlari ham), har fayl `php -l` bilan tekshiriladi
 *   2) Main.html va pages/*.html: HTML izohlari olib tashlanadi
 *   3) Ichki hujjatlar (DIZAYN.md, MALUMOTNOMA.md, QOLLANMA.md) va server-sozlama/ (tashkilotga xos) o'chiriladi
 *  Xato bo'lsa fayl o'zgarishsiz qoladi va ogohlantirish chiqadi. JS/CSS izohlarini strip-js-comments.js olib tashlaydi. */
if (PHP_SAPI !== 'cli' || empty($argv[1]) || !is_dir($argv[1])) { fwrite(STDERR, "PM nusxa papkasi kerak\n"); exit(2); }
$root = rtrim(realpath($argv[1]), '/');
$files = array_merge(glob("$root/php/*.php") ?: [], glob("$root/*.php") ?: []);
$before = $after = 0; $bad = [];
foreach ($files as $f) {
    $orig = file_get_contents($f);
    $out = php_strip_whitespace($f);
    if ($out === '' || strncmp($out, '<?php', 5) !== 0) { $bad[] = basename($f) . ' (bo\'sh natija)'; continue; }
    file_put_contents($f . '.tmp', $out);
    exec('php -l ' . escapeshellarg($f . '.tmp') . ' 2>&1', $o, $rc);
    if ($rc !== 0) { unlink($f . '.tmp'); $bad[] = basename($f) . ' (sintaksis)'; continue; }
    rename($f . '.tmp', $f);
    // sxema (heredoc SQL) ichidagi /* ... */ izohlari php_strip_whitespace'dan o'tib ketadi — ularni ham olib tashlaymiz
    $sql = preg_replace_callback('/(<<<SQL\n)(.*?)(\nSQL)/s', fn($m) => $m[1] . preg_replace('#/\*.*?\*/#s', '', $m[2]) . $m[3], $out);
    if ($sql !== null && $sql !== $out) {
        file_put_contents($f . '.tmp', $sql);
        exec('php -l ' . escapeshellarg($f . '.tmp') . ' 2>&1', $o2, $rc2);
        if ($rc2 === 0) { rename($f . '.tmp', $f); $out = $sql; } else { unlink($f . '.tmp'); $bad[] = basename($f) . ' (SQL izohlari)'; }
    }
    $before += strlen($orig); $after += strlen($out);
}
$h = 0;
foreach (array_merge(["$root/Main.html"], glob("$root/pages/*.html") ?: []) as $f) {
    if (!is_file($f)) continue;
    $s = file_get_contents($f);
    $n = preg_replace('/<!--(?!\[if).*?-->/s', '', $s);
    if ($n !== null && $n !== $s) { file_put_contents($f, $n); $h += strlen($s) - strlen($n); }
}
foreach (['DIZAYN.md', 'MALUMOTNOMA.md', 'QOLLANMA.md'] as $d) @unlink("$root/$d");
if (is_dir("$root/server-sozlama")) { foreach (glob("$root/server-sozlama/*") as $x) @unlink($x); @rmdir("$root/server-sozlama"); }
printf("PHP izohlari olib tashlandi: %d KB -> %d KB (%d fayl), HTML izohlari: %d KB\n", $before / 1024, $after / 1024, count($files) - count($bad), $h / 1024);
if ($bad) fwrite(STDERR, "OGOHLANTIRISH: tozalanmadi: " . implode(', ', $bad) . "\n");
