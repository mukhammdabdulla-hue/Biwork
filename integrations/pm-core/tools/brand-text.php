<?php
/** Mijozga ko'rinadigan matnlardagi haqiqiy tashkilot nomlarini neytral matnga almashtiradi.   php brand-text.php <PM_nusxa_papkasi>
 *  Faqat ekranga chiqadigan matnlar (import izohlari, KPI maslahati, misollar) — mantiq literallariga ('k_asi', 'avtosanoat invest' tekshiruvi) tegmaydi.
 *  Har juftlik app.js va til-lugat.js da BIR XIL almashtiriladi (tarjima lug'ati kalitlari app.js matni bilan mos qolishi uchun).
 *  app.js da juftlik topilmasa ogohlantiradi (PM matni o'zgargan bo'lishi mumkin). */
if (PHP_SAPI !== 'cli' || empty($argv[1]) || !is_dir($argv[1])) { fwrite(STDERR, "PM nusxa papkasi kerak\n"); exit(2); }
$root = rtrim(realpath($argv[1]), '/');
$pairs = [
    ["Avtosanoat Invest — katta oylik vedomost; Green House — ", "Katta oylik vedomost yoki "],
    ["Avtosanoat Invest — большая месячная ведомость; Green House — шаблон", "Большая месячная ведомость или шаблон"],
    ["(Green House materiali)", "(boshqa shakldagi material)"],
    ["(материалы Green House)", "(материалы другого формата)"],
    ["masalan: Green House — avgust savdosi", "masalan: Bahor Savdo — avgust savdosi"],
    ["например: Green House — продажи за август", "например: Bahor Savdo — продажи за август"],
    ["ASI realizatsiya summasi", "Realizatsiya summasi"],
    ["Реализация ASI", "Реализация"],
];
$changed = 0; $missing = [];
foreach (["$root/assets/app.js", "$root/assets/mod/til-lugat.js"] as $f) {
    if (!is_file($f)) continue;
    $s = file_get_contents($f); $o = $s;
    foreach ($pairs as [$a, $b]) { $n = 0; $s = str_replace($a, $b, $s, $n); $changed += $n; if ($n === 0 && str_ends_with($f, 'app.js') && !preg_match('/[а-я]/u', $a)) $missing[] = $a; }
    if ($s !== $o) file_put_contents($f, $s);
}
// korxona logotipi papkasi izohi (haqiqiy korxona nomlari bor) — neytral matn bilan almashtiriladi
$ok = "$root/assets/brend/OQING.txt";
if (is_file($ok)) file_put_contents($ok, "Korxona logotipi (yuk xati blankasi uchun): <korxona id>.png (yoki .jpg) shu papkaga qo'yilsa, PDF sarlavhasida shu rasm chiziladi.\nLogotipni Admin paneldan ham yuklash mumkin (Korxonalar > korxona > Sozlash > Logotip); u data/brend/ ga tushadi va shu papkadagidan ustun turadi.\n");
printf("Ko'rinadigan matnlar tozalandi: %d almashtirish\n", $changed);
if ($missing) fwrite(STDERR, "OGOHLANTIRISH: app.js da topilmadi (PM matni o'zgargan?): " . implode(' | ', $missing) . "\n");
