<?php
declare(strict_types=1);
/**
 * Mahsulot namuna bazasini (shablon) yasaydi:  php seed.php <PM_papka> <slug> <seed.php> [<chiqish_papka>]
 *
 * Chiqish standarti: <PM_papka>/data/templates/<slug>/ (pm.db + uploads/ ...). Demo rejimidagi har (korxona × mahsulot)
 * sandbox shu papkadan ko'chiriladi. Seed fayli `biwork_seed(array $ctx): void` funksiyasini beradi; ichida PM'ning
 * o'z funksiyalari (db(), entity_yoz(), create_user(), meta_set() ...) ishlaydi. Faqat XAYOLIY ma'lumot yoziladi.
 * Sana — yasalgan kunga nisbatan (seed_lib.php: bw_date); namuna eskirmasligi uchun shablonlarni oyda bir qayta yasang.
 */
if (PHP_SAPI !== 'cli') exit(1);
[$self, $pm, $slug, $seedFile] = array_pad($argv, 4, '');
$out = $argv[4] ?? '';
$pm = realpath($pm) ?: '';
$seedFile = realpath($seedFile) ?: '';
if ($pm === '' || !is_file("$pm/php/db.php") || !is_file($seedFile) || !preg_match('/^[a-z0-9-]{2,31}$/', $slug)) {
    fwrite(STDERR, "foydalanish: php seed.php <PM_papka> <slug> <seed.php> [<chiqish>]\n");
    exit(2);
}
$out = $out !== '' ? $out : "$pm/data/templates/$slug";
if (is_dir($out)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($out, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
} else {
    mkdir($out, 0775, true);
}
define('DATA_DIR', realpath($out));
date_default_timezone_set('Asia/Tashkent');
chdir($pm);
require "$pm/php/db.php";
require "$pm/php/auth.php";
require "$pm/php/api.php";
require __DIR__ . '/seed_lib.php';
$product = json_decode((string)file_get_contents("$pm/biwork/products/$slug.json"), true) ?: [];
db();
// namuna ma'muriy hisobi: parol tasodifiy va hech qayerda saqlanmaydi (kirish faqat portaldan "Ochish" orqali)
$admin = create_user('namuna-admin', bin2hex(random_bytes(16)), 'admin', 'Namuna administratori');
require $seedFile;
biwork_seed(['pm' => $pm, 'slug' => $slug, 'product' => $product, 'kim' => 'namuna-admin', 'admin' => $admin, 'out' => DATA_DIR]);
// avtomatik zaxiralar shablonga kirmasin; jurnal/sessiya izlari tozalanadi
db()->exec('DELETE FROM sessions');
db()->exec('DELETE FROM login_attempts');
db()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
foreach (['backups', 'file-backups'] as $d) {
    $p = DATA_DIR . '/' . $d;
    if (is_dir($p)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f)
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        rmdir($p);
    }
}
foreach (['pm.db-wal', 'pm.db-shm', 'ADMIN-PAROL.txt'] as $f) @unlink(DATA_DIR . '/' . $f);
$n = (int)db()->query("SELECT COUNT(*) FROM entities WHERE deleted = 0")->fetchColumn();
echo "shablon tayyor: $slug -> " . DATA_DIR . " ($n ta yozuv, " . round(filesize(DATA_DIR . '/pm.db') / 1024) . " KB)\n";
