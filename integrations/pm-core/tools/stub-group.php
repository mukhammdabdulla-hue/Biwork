<?php
/** PM nusxasidan guruh korxonalariga xos qismlarni bo'shatadi (stub).   php stub-group.php <PM_nusxa_papkasi>
 *  Qaysi qismlar va nega: ../GURUH-XOS-QISMLAR.md (A = hech bir Biwork mahsuloti ishlatmaydi; B va C ataylab kiritilmagan).
 *
 *  Fayllarni o'chirib bo'lmaydi: api.php/db.php ularni require qiladi, app.js statik import qiladi, sw.js keshlaydi.
 *  Shuning uchun har fayl IMZOLARI (funksiya/eksport/konstanta nomlari) saqlangan, tanasi neytral qiymat qaytaradigan kichik stubga almashtiriladi:
 *   PHP — token_get_all bilan: funksiya tanasi tip bo'yicha bo'sh qiymat (array → [], string → '', int → 0, bool → false, ?T → null),
 *         skalyar konstantalar o'z qiymati bilan, massiv konstantalar []. Sinf yo'q (shuni tekshiramiz).
 *   JS  — `export function` → ''; `export const` → [].
 *  Bundan tashqari php/seed/gh-biznes-reja.json (Green House reja raqamlari) olib tashlanadi.
 *  B qismi (Moliya 1C shakllari: bp-moliya, balans, gh-*) BU YERDA YO'Q — unga tegilmaydi.
 *  Natija `php -l` bilan tekshiriladi; xato bo'lsa fayl o'zgarishsiz qoladi. build.sh da `--keep-group` bilan o'chiriladi. */
if (PHP_SAPI !== 'cli' || empty($argv[1]) || !is_dir($argv[1])) { fwrite(STDERR, "PM nusxa papkasi kerak\n"); exit(2); }
$root = rtrim(realpath($argv[1]), '/');

const GRUH_PHP = [
    // A: OTP Avtosanoat Finance
    'otp-bp.php', 'otp-dds.php', 'otp-lizing.php', 'otp-savdo.php', 'otp-balans.php',
    // A: Avtosanoat Agro Lizing
    'lizing-bp.php', 'lizing-qarz.php', 'lizing-savdo.php',
    // A: Realizatsiya va ДДС kitobi (bitta tashkilotga bog'langan)
    'realizatsiya.php', 'dds.php',
];
const GRUH_JS = [
    'otpbosh.js', 'otpbosh-ui.js', 'otpbp.js', 'otpbp-ui.js', 'otplizing.js', 'otplizing-ui.js', 'otpsavdo.js', 'otpsavdo-ui.js',
    'lizingbp.js', 'lizingbp-ui.js',
];
/* C (versiya.php, manba-holat.php, manba.js, mavsumreja.js, aktiv.js) BU YERDA YO'Q: sinovda ular guruhga xos emas, umumiy xususiyat ekani
   chiqdi — stublansa Moliya bosh sahifasidagi «Aktivlar/Majburiyat» bloki yo'qoladi (aktiv.js), Ma'lumot markazi 4 mahsulotda «undefined»
   ko'rsatadi (manba.js, manba-holat.php), Biznes reja «Smeta ijrosi» paneli (mavsumreja.js) va /api/tekshir (versiya.php) ishlamaydi.
   Batafsil: ../GURUH-XOS-QISMLAR.md. */

function stub_php(string $src): array {
    $t = token_get_all($src); $n = count($t);
    $out = "<?php\ndeclare(strict_types=1);\n/* Biwork: bu qism paketga kiritilmagan. */\n"; $fn = 0;
    $skip = static fn($i) => is_array($t[$i]) && in_array($t[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    for ($i = 0; $i < $n; $i++) {
        $x = $t[$i];
        if (is_array($x) && in_array($x[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_NAMESPACE], true)) throw new RuntimeException('sinf/nomlar fazosi bor: stub yaratib bo\'lmaydi');
        if (is_array($x) && $x[0] === T_CONST) {
            $j = $i + 1; while ($skip($j)) $j++;
            $name = $t[$j][1]; $j++; while ($skip($j)) $j++; // '='
            $expr = ''; $scalar = true;
            for ($k = $j + 1; $k < $n && $t[$k] !== ';'; $k++) {
                if ($skip($k)) continue;
                $s = is_array($t[$k]) ? $t[$k][1] : $t[$k];
                if (!(is_array($t[$k]) && in_array($t[$k][0], [T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER], true)) && !in_array(strtolower($s), ['-', 'true', 'false', 'null'], true)) $scalar = false;
                $expr .= $s;
            }
            $out .= 'const ' . $name . ' = ' . ($scalar && $expr !== '' ? $expr : '[]') . ";\n"; $i = $k; continue;
        }
        if (is_array($x) && $x[0] === T_FUNCTION) {
            $p = $i - 1; while ($p >= 0 && $skip($p)) $p--;
            $prev = $t[$p] ?? null;
            if ($prev === '=' || $prev === '(' || $prev === ',' || (is_array($prev) && in_array($prev[0], [T_RETURN, T_DOUBLE_ARROW], true))) continue; // yopilma (closure)
            $j = $i + 1; while ($skip($j)) $j++;
            if ($t[$j] === '&') $j++;
            if (!is_array($t[$j]) || $t[$j][0] !== T_STRING) continue;
            $name = $t[$j][1];
            $k = $j + 1; while ($t[$k] !== '(') $k++;
            $sig = ''; $par = 0;
            for (; $k < $n; $k++) { $s = is_array($t[$k]) ? $t[$k][1] : $t[$k]; if ($s === '(') $par++; if ($s === ')') { $par--; if ($par === 0) { $sig .= ')'; $k++; break; } } $sig .= $s; }
            $ret = '';
            for (; $k < $n; $k++) { $s = is_array($t[$k]) ? $t[$k][1] : $t[$k]; if ($s === '{' || $s === ';') break; $ret .= $s; }
            $ret = trim($ret); $rt = strtolower(trim(ltrim($ret, ': ')));
            $parts = array_map(static fn($v) => ltrim(trim($v), '?'), explode('|', $rt));
            $body = match (true) {
                $rt === '' || $rt === 'mixed' || $rt[0] === '?' || in_array('null', $parts, true) => 'return null;',
                $rt === 'void' => 'return;',
                in_array('array', $parts, true) => 'return [];',
                in_array('string', $parts, true) => "return '';",
                in_array('int', $parts, true) => 'return 0;',
                in_array('float', $parts, true) => 'return 0.0;',
                in_array('bool', $parts, true) || in_array('false', $parts, true) => 'return false;',
                default => "throw new RuntimeException('Bu mahsulotda mavjud emas');",
            };
            $out .= "if (!function_exists('$name')) { function $name$sig" . ($ret !== '' ? " $ret" : '') . " { $body } }\n"; $fn++;
            if ($t[$k] === '{') { $b = 0; for (; $k < $n; $k++) { $c = $t[$k]; if ($c === '{' || (is_array($c) && in_array($c[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) $b++; if ($c === '}') { $b--; if ($b === 0) break; } } }
            $i = $k;
        }
    }
    return [$out, $fn];
}
function stub_js(string $src): array {
    $out = ["// Biwork: bu qism paketga kiritilmagan."]; $names = [];
    foreach (preg_split('/\R/', $src) as $line) {
        if (preg_match('/^export\s+(?:async\s+)?function\s*\*?\s*([A-Za-z0-9_$]+)\s*\(/', $line, $m)) { $out[] = "export function {$m[1]}() { return ''; }"; $names[$m[1]] = 1; }
        elseif (preg_match('/^export\s+(?:const|let|var)\s+([A-Za-z0-9_$]+)\s*=/', $line, $m)) { $out[] = "export const {$m[1]} = [];"; $names[$m[1]] = 1; }
        elseif (preg_match('/^export\s+class\s+([A-Za-z0-9_$]+)/', $line, $m)) { $out[] = "export class {$m[1]} {}"; $names[$m[1]] = 1; }
        elseif (preg_match('/^export\s*\{([^}]*)\}/', $line, $m)) foreach (explode(',', $m[1]) as $nm) { $a = trim((string)preg_split('/\s+as\s+/', trim($nm))[count(preg_split('/\s+as\s+/', trim($nm))) - 1]); if ($a !== '' && !isset($names[$a])) { $out[] = "export const $a = [];"; $names[$a] = 1; } }
    }
    return [implode("\n", $out) . "\n", count($names)];
}

$tot = ['php' => 0, 'js' => 0, 'from' => 0, 'to' => 0]; $bad = [];

/* php/seed/gh-biznes-reja.json — Green House biznes rejasi (reja raqamlari) KOD ichida yuboriladi; db_init uni «Green House» nomli korxona bo'lsa qo'shardi.
   Mijoz paketiga tushmaydi. Fayl yo'q bo'lsa PM jim o'tadi (bk_avto_koch -> 'seed_yoq'); o'rnatish tekshiruvi (versiya.php) ro'yxatidan ham chiqariladi. */
$seedDir = "$root/php/seed"; $seedF = "$seedDir/gh-biznes-reja.json"; $seedOlib = 0;
if (is_file($seedF)) { $seedOlib = filesize($seedF); unlink($seedF); }
if (is_dir($seedDir)) { $qolgan = array_values(array_diff(scandir($seedDir), ['.', '..'])); if ($qolgan) $bad[] = 'php/seed ichida boshqa fayllar: ' . implode(', ', $qolgan); else rmdir($seedDir); }
$vf = "$root/php/versiya.php";
if (is_file($vf)) {
    $v = (string)file_get_contents($vf);
    $v2 = preg_replace("~\\s*(?:/\\*(?:[^*]|\\*(?!/))*\\*/\\s*)?'php/seed/gh-biznes-reja\\.json',~", '', $v, -1, $n);
    if ($n) {
        file_put_contents("$vf.tmp", $v2); exec('php -l ' . escapeshellarg("$vf.tmp") . ' 2>&1', $o, $rc);
        if ($rc === 0) rename("$vf.tmp", $vf); else { unlink("$vf.tmp"); $bad[] = 'versiya.php (seed yozuvini olib tashlab bo\'lmadi)'; }
    }
}
foreach (GRUH_PHP as $rel) {
    $f = "$root/php/$rel"; if (!is_file($f)) { $bad[] = "$rel (yo'q)"; continue; }
    $src = file_get_contents($f);
    try { [$out] = stub_php($src); } catch (Throwable $e) { $bad[] = "$rel (" . $e->getMessage() . ')'; continue; }
    file_put_contents("$f.tmp", $out); exec('php -l ' . escapeshellarg("$f.tmp") . ' 2>&1', $o, $rc);
    if ($rc !== 0) { unlink("$f.tmp"); $bad[] = "$rel (sintaksis)"; continue; }
    rename("$f.tmp", $f); $tot['php']++; $tot['from'] += strlen($src); $tot['to'] += strlen($out);
}
foreach (GRUH_JS as $rel) {
    $f = "$root/assets/mod/$rel"; if (!is_file($f)) { $bad[] = "$rel (yo'q)"; continue; }
    $src = file_get_contents($f); [$out] = stub_js($src);
    file_put_contents($f, $out); $tot['js']++; $tot['from'] += strlen($src); $tot['to'] += strlen($out);
}
printf("Guruhga xos qismlar bo'shatildi: %d PHP + %d JS fayl, %d KB -> %d KB%s\n", $tot['php'], $tot['js'], $tot['from'] / 1024, $tot['to'] / 1024, $seedOlib ? "; php/seed/gh-biznes-reja.json olib tashlandi (" . intdiv($seedOlib, 1024) . " KB)" : '');
if ($bad) { fwrite(STDERR, "OGOHLANTIRISH: bo'shatilmadi: " . implode(', ', $bad) . "\n"); exit(1); }
