<?php
/**
 * Mahsulot formasi (yangi va tahrirlash). Hammasi oddiy o'zbekcha: manifest.json kerak emas.
 * @var array $t ko'rsatiladigan qiymatlar  @var ?array $saved bazadagi holat (yangi mahsulotda null)
 * @var array $plans pulli tariflar  @var array $in xatodan keyin kiritilgan qiymatlar  @var string $err  @var ?string $lock  @var array $ready  @var bool $gd
 */
$id = (int)($saved['id'] ?? 0);
$isNew = $id === 0;
$status = (string)($saved['status'] ?? 'hidden');
$m = Marketing::clean(is_array($t['marketing'] ?? null) ? $t['marketing'] : []);
$action = Util::url($isNew ? '/admin/tools/new' : '/admin/tools/' . $id . '/edit');
$host = (string)parse_url(Util::absUrl('/'), PHP_URL_HOST);
$sitePath = rtrim((string)parse_url(Util::absUrl('/'), PHP_URL_PATH), '/') . '/';
$statusTone = ['active' => 'ok', 'soon' => 'warn', 'hidden' => ''];
$maxFile = Media::MAX_BYTES / 1048576;
$js = Util::url('/assets/editor.js') . '?v=' . substr((string)@filemtime(BW_ROOT . '/public/assets/editor.js'), -6);

$ctl = static function (bool $remove = true): string {
    return '<span class="ed-tools"><button type="button" class="ed-ib" data-act="up" title="Yuqoriga" aria-label="Yuqoriga ko\'chirish">↑</button>'
        . '<button type="button" class="ed-ib" data-act="down" title="Pastga" aria-label="Pastga ko\'chirish">↓</button>'
        . ($remove ? '<button type="button" class="ed-ib ed-del" data-act="del" title="O\'chirish" aria-label="O\'chirish">×</button>' : '') . '</span>';
};
/** Sarlavha + izoh juftligi (muammolar, kimga kerak, savol-javob). */
$pairRow = static function (string $name, string|int $i, array $v, array $k, array $lbl, array $ph, array $max) use ($ctl): string {
    return '<div class="ed-row" data-row><div class="ed-row-main">'
        . '<input class="in" name="' . $name . '[' . $i . '][' . $k[0] . ']" value="' . e($v[$k[0]] ?? '') . '" maxlength="' . $max[0] . '" placeholder="' . e($ph[0]) . '" aria-label="' . e($lbl[0]) . '">'
        . '<textarea class="in" name="' . $name . '[' . $i . '][' . $k[1] . ']" maxlength="' . $max[1] . '" rows="2" placeholder="' . e($ph[1]) . '" aria-label="' . e($lbl[1]) . '">' . e($v[$k[1]] ?? '') . '</textarea>'
        . '</div>' . $ctl() . '</div>';
};
$pairs = static function (string $name, array $rows, int $limit, callable $row, string $addLabel) use ($ctl): string {
    $out = '<div class="ed-rep" data-rep="' . e($name) . '" data-max="' . $limit . '" data-next="' . (count($rows) + 1) . '"><div class="ed-rows">';
    foreach (array_values($rows) as $i => $v) {
        $out .= $row($i, $v);
    }
    if (count($rows) < $limit) {
        $out .= $row(count($rows), []);
    }
    return $out . '</div><template>' . $row('__i__', []) . '</template>'
        . '<div class="ed-add"><button type="button" class="btn btn-sm" data-rep-add>+ ' . e($addLabel) . '</button><span class="muted small">Ko\'pi bilan ' . $limit . ' ta</span></div></div>';
};

// imkoniyatlar uchun rasm tanlash ro'yxati
$shotFiles = array_column($m['shots'], 'file');
$shotOptions = static function (string $cur) use ($m): string {
    $o = '<option value="">Rasmsiz</option>';
    $seen = [];
    foreach ($m['shots'] as $n => $s) {
        $seen[$s['file']] = true;
        $o .= '<option value="' . e($s['file']) . '"' . ($s['file'] === $cur ? ' selected' : '') . '>' . ($n + 1) . '-rasm' . ($s['caption'] !== '' ? ': ' . e(mb_substr($s['caption'], 0, 50)) : '') . '</option>';
    }
    if ($cur !== '' && !isset($seen[$cur])) {
        $o .= '<option value="' . e($cur) . '" selected>' . e($cur) . '</option>';
    }
    return $o;
};
$featureRow = static function (string|int $i, array $v) use ($ctl, $shotOptions): string {
    return '<div class="ed-row" data-row><div class="ed-row-main">'
        . '<input class="in" name="features[' . $i . '][title]" value="' . e($v['title'] ?? '') . '" maxlength="90" placeholder="Imkoniyat nomi, masalan: Ish rejasini avtomatik tuzish" aria-label="Imkoniyat nomi">'
        . '<textarea class="in" name="features[' . $i . '][text]" maxlength="600" rows="3" placeholder="Qisqacha tushuntiring: bu nimaga kerak va qanday foyda beradi" aria-label="Tushuntirish">' . e($v['text'] ?? '') . '</textarea>'
        . '<textarea class="in" name="features[' . $i . '][points]" rows="3" placeholder="Qo\'shimcha bandlar (har qator — bitta band, ko\'pi bilan 5 ta)" aria-label="Qo\'shimcha bandlar">' . e(implode("\n", $v['points'] ?? [])) . '</textarea>'
        . '<label class="ed-sel"><span>Shu imkoniyat yonida chiqadigan rasm</span><select class="in" name="features[' . $i . '][shot]">' . $shotOptions((string)($v['shot'] ?? '')) . '</select></label>'
        . '</div>' . $ctl() . '</div>';
};

$stepRow = static function (string|int $i, array $v) use ($ctl, $shotOptions): string {
    return '<div class="ed-row" data-row><div class="ed-row-main">'
        . '<input class="in" name="guide[steps][' . $i . '][title]" value="' . e($v['title'] ?? '') . '" maxlength="90" placeholder="Qadam nomi, masalan: Yangi buyurtma qo\'shing" aria-label="Qadam nomi">'
        . '<textarea class="in" name="guide[steps][' . $i . '][text]" maxlength="600" rows="5" placeholder="Nima qilish kerak va natijada nima chiqadi" aria-label="Qadam tushuntirishi">' . e($v['text'] ?? '') . '</textarea>'
        . '<textarea class="in" name="guide[steps][' . $i . '][points]" rows="3" placeholder="Qisqa bandlar (har qator — bitta band, ko\'pi bilan 4 ta)" aria-label="Qisqa bandlar">' . e(implode("\n", $v['points'] ?? [])) . '</textarea>'
        . '<label class="ed-sel"><span>Shu qadam yonida chiqadigan rasm</span><select class="in" name="guide[steps][' . $i . '][shot]">' . $shotOptions((string)($v['shot'] ?? '')) . '</select></label>'
        . '</div>' . $ctl() . '</div>';
};

// tarif maydoni: xatodan keyin kiritilgan qiymat ustun
$pv = static function (array $p, string $k) use ($in): string {
    $x = $in['plans'][$p['id']][$k] ?? null;
    if (is_string($x)) {
        return $x;
    }
    return $p[$k] === null ? '' : (string)$p[$k];
};
$planCols = static function (string $n, array $v, bool $new = false): string {
    $o = '<div class="ed-plan-grid">'
        . '<label class="field"><span class="f">Tarif nomi</span><input class="in" name="' . $n . '[name]" value="' . e($v['name'] ?? '') . '" maxlength="60" placeholder="Masalan: Standart"></label>'
        . '<label class="field"><span class="f">Narxi (so\'m)</span><input class="in" name="' . $n . '[price]" inputmode="numeric" value="' . e($v['price'] ?? '') . '" placeholder="Bo\'sh = narx so\'rov bo\'yicha"></label>'
        . '<label class="field ed-wide"><span class="f">Narx o\'rniga chiqadigan yozuv <em>(ixtiyoriy)</em></span><input class="in" name="' . $n . '[price_text]" value="' . e($v['price_text'] ?? '') . '" maxlength="60" placeholder="Masalan: 1 000 000 so\'mdan yoki Kelishilgan narx"></label>'
        . '<label class="field ed-wide"><span class="f">Qisqa izoh <em>(ixtiyoriy)</em></span><input class="in" name="' . $n . '[blurb]" value="' . e($v['blurb'] ?? '') . '" maxlength="120" placeholder="Masalan: Kichik jamoalar uchun"></label>'
        . '<label class="field"><span class="f">Qurilmalar soni</span><input class="in" name="' . $n . '[seats]" type="number" min="1" max="999" value="' . e($v['seats'] ?? '1') . '"></label>'
        . '<label class="field"><span class="f">Muddati (kun)</span><input class="in" name="' . $n . '[days]" inputmode="numeric" value="' . e($v['days'] ?? '') . '" placeholder="Bo\'sh = muddatsiz"></label>'
        . '</div>';
    return $o;
};
?>
<div class="crumbs"><a href="<?= e(Util::url('/admin/tools')) ?>">Mahsulotlar</a> / <?= $isNew ? 'Yangi mahsulot' : e($t['name']) ?></div>
<div class="page-h">
  <div>
    <h1><?= $isNew ? 'Yangi mahsulot' : e($t['name'] !== '' ? $t['name'] : 'Mahsulot') ?>
      <?php if (!$isNew): ?><span class="badge <?= $statusTone[$status] ?? '' ?>" style="vertical-align:middle;margin-left:8px"><?= e(Catalog::STATUS_UZ[$status] ?? $status) ?></span><?php endif; ?></h1>
    <p>Maydonlarni to'ldiring va «Saqlash»ni bosing: mahsulot sahifasi va katalog o'zi yangilanadi. Saqlashdan oldin «Oldindan ko'rish» bilan sahifa qanday chiqishini ko'rishingiz mumkin.</p>
  </div>
</div>
<?php if ($err !== ''): ?><div class="flash err"><?= e($err) ?><?= $in ? ' Kiritganlaringiz saqlanmadi, lekin shu yerda turibdi: tuzatib, qayta saqlang. Tanlangan rasm fayllarini qayta tanlash kerak bo\'ladi.' : '' ?></div><?php endif; ?>

<form id="ed" class="ed" method="post" enctype="multipart/form-data" action="<?= e($action) ?>" autocomplete="off">
  <?= Ui::csrf() ?>
  <input type="hidden" name="id" value="<?= $id ?>">
  <div class="ed-main">

    <section class="card ed-sec" id="asosiy">
      <div class="card-h"><h2><span class="ed-n">1</span> Asosiy ma'lumot</h2></div>
      <div class="card-b">
        <div class="field"><label class="f" for="f-name">Mahsulot nomi *</label>
          <input class="in" id="f-name" name="name" required maxlength="80" value="<?= e($t['name']) ?>" placeholder="Masalan: Biwork HR" data-name>
          <div class="hint">Saytda, katalogda va qidiruv natijasida shu nom chiqadi. Keyinroq istalgan vaqt o'zgartirish mumkin.</div></div>
        <div class="field"><label class="f" for="f-tag">Qisqa tavsif *</label>
          <input class="in" id="f-tag" name="tagline" maxlength="200" value="<?= e($t['tagline']) ?>" placeholder="Bir jumla: dastur nima qiladi" data-count>
          <div class="hint">Katalogdagi kartada nom ostida chiqadi.</div></div>
        <div class="field"><label class="f" for="f-desc">Batafsil tavsif *</label>
          <textarea class="in" id="f-desc" name="description" maxlength="4000" rows="7" placeholder="Dastur nima qiladi, kimga kerak, qanday foyda beradi. Abzatslarni bo'sh qator bilan ajrating." data-count><?= e($t['description']) ?></textarea>
          <div class="hint">Birinchi abzats sahifaning tepasida, sarlavha ostida chiqadi (agar pastda alohida izoh yozilmagan bo'lsa).</div></div>
        <div class="row2">
          <div class="field"><label class="f" for="f-cat">Bo'lim</label><input class="in" id="f-cat" name="category" maxlength="60" value="<?= e($t['category']) ?>" placeholder="Masalan: Kadrlar, Savdo, Moliya"></div>
          <div class="field"><label class="f" for="f-icon">Belgi (rasmcha)</label>
            <div class="ed-icon"><span class="tool-ic green" data-icon-prev><?= Ui::toolIcon($t['icon']) ?></span>
            <select class="in" id="f-icon" name="icon" data-icon><?php foreach (ToolEditor::ICONS as $k => $v): ?><option value="<?= e($k) ?>" data-svg="<?= e(Ui::toolIcon($k)) ?>"<?= $t['icon'] === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div></div>
        </div>
      </div>
    </section>

    <section class="card ed-sec" id="sahifa">
      <div class="card-h"><h2><span class="ed-n">2</span> Sahifaning tepa qismi</h2><span class="muted small">hammasi ixtiyoriy</span></div>
      <div class="card-b">
        <div class="field"><label class="f" for="f-head">Katta sarlavha</label>
          <input class="in" id="f-head" name="headline" maxlength="160" value="<?= e($m['headline']) ?>" placeholder="Bo'sh qolsa: «Nom — qisqa tavsif» chiqadi" data-count></div>
        <div class="field"><label class="f" for="f-lead">Sarlavha ostidagi izoh</label>
          <textarea class="in" id="f-lead" name="lead" maxlength="700" rows="3" placeholder="Bo'sh qolsa: batafsil tavsifning birinchi abzatsi chiqadi" data-count><?= e($m['lead']) ?></textarea></div>
        <div class="field"><label class="f" for="f-badges">Qisqa belgilar</label>
          <textarea class="in" id="f-badges" name="badges" rows="3" placeholder="Har qatorga bittadan, ko'pi bilan 6 ta. Masalan:&#10;Brauzerda ishlaydi&#10;Excel bilan ishlaydi"><?= e(implode("\n", $m['badges'])) ?></textarea>
          <div class="hint">Sarlavha tagida kichik yozuv sifatida chiqadi. Faqat haqiqatan to'g'ri bo'lgan gaplarni yozing.</div></div>
      </div>
    </section>

    <section class="card ed-sec" id="kimga">
      <div class="card-h"><h2><span class="ed-n">3</span> Kimga kerak</h2><span class="muted small">ko'pi bilan 6 ta</span></div>
      <div class="card-b">
        <p class="muted small" style="margin-top:0">Qaysi korxonalar yoki lavozimlar uchun mo'ljallangan. Har bir qatorda sarlavha va qisqa izoh.</p>
        <?= $pairs('audience', $m['audience'], 6, static fn($i, $v) => $pairRow('audience', $i, $v, ['title', 'text'], ['Kimga kerak', 'Izoh'], ["Masalan: Ishlab chiqarish korxonalari", 'Nima uchun aynan ularga kerak'], [80, 300]), 'Yana qo\'shish') ?>
      </div>
    </section>

    <section class="card ed-sec" id="muammo">
      <div class="card-h"><h2><span class="ed-n">4</span> Qanday muammolarni hal qiladi</h2><span class="muted small">ko'pi bilan 6 ta</span></div>
      <div class="card-b">
        <p class="muted small" style="margin-top:0">Mijoz o'zining og'riqli joyini tanib olishi uchun. Masalan: «Reja Excelda yuritiladi va doim eskirgan».</p>
        <?= $pairs('problems', $m['problems'], 6, static fn($i, $v) => $pairRow('problems', $i, $v, ['title', 'text'], ['Muammo', 'Izoh'], ['Muammo, mijoz tilida', 'Bu qanday zarar keltiradi'], [90, 320]), 'Yana qo\'shish') ?>
      </div>
    </section>

    <section class="card ed-sec" id="imkoniyat">
      <div class="card-h"><h2><span class="ed-n">5</span> Imkoniyatlar</h2><span class="muted small">ko'pi bilan 8 ta</span></div>
      <div class="card-b">
        <p class="muted small" style="margin-top:0">Dastur nima qila olishi. Har biriga nom, tushuntirish va xohlasangiz rasm tanlang (rasmlar keyingi bo'limda yuklanadi). Faqat dasturda haqiqatan bor narsalarni yozing.</p>
        <?= $pairs('features', $m['features'], 8, $featureRow, 'Imkoniyat qo\'shish') ?>
      </div>
    </section>

    <section class="card ed-sec" id="rasmlar">
      <div class="card-h"><h2><span class="ed-n">6</span> Ekran rasmlari</h2><span class="muted small">ko'pi bilan 12 ta</span></div>
      <div class="card-b">
        <p class="muted small" style="margin-top:0">Dasturning haqiqiy ekranidan olingan rasmlar (PNG, JPG yoki WEBP, bittasi <?= (int)$maxFile ?> MB gacha). <b>Birinchi rasm</b> sahifaning tepasida va ijtimoiy tarmoqlarda ulashilganda chiqadi, shuning uchun eng chiroyli ekranni birinchiga qo'ying.</p>
        <?php if (!$gd): ?><div class="flash info" style="font-size:13px">Serverda rasmni kichraytiruvchi dastur (GD) yo'q: rasmlar o'z holicha saqlanadi, shuning uchun har biri 5 MB dan kichik bo'lsin.</div><?php endif; ?>
        <div class="ed-rep ed-shots" data-rep="shots" data-nodel="1">
          <div class="ed-rows">
          <?php foreach ($m['shots'] as $i => $s): $im = Marketing::shot($t, $s['file']); ?>
            <div class="ed-row ed-shot" data-row>
              <input type="hidden" name="shots[<?= $i ?>][file]" value="<?= e($s['file']) ?>"><input type="hidden" name="shots[<?= $i ?>][alt]" value="<?= e($s['alt']) ?>">
              <div class="ed-thumb"><?php if ($im): ?><a href="<?= e($im['url']) ?>" data-zoom data-caption="<?= e($s['caption']) ?>"><img src="<?= e($im['url']) ?>" alt="" width="<?= (int)$im['w'] ?>" height="<?= (int)$im['h'] ?>" loading="lazy"></a><?php else: ?><span class="muted small">Rasm fayli topilmadi</span><?php endif; ?></div>
              <div class="ed-row-main">
                <span class="badge ok ed-first">Asosiy rasm</span>
                <input class="in" name="shots[<?= $i ?>][caption]" value="<?= e($s['caption']) ?>" maxlength="170" placeholder="Rasm tagidagi yozuv (masalan: Haftalik reja jadvali)" aria-label="Rasm tagidagi yozuv">
              </div>
              <div class="ed-tools"><button type="button" class="ed-ib" data-act="up" title="Yuqoriga" aria-label="Oldinga surish">↑</button><button type="button" class="ed-ib" data-act="down" title="Pastga" aria-label="Orqaga surish">↓</button>
                <label class="ed-rm" title="Saqlaganda bu rasm o'chadi"><input type="checkbox" name="shots[<?= $i ?>][remove]" value="1"> <span>O'chirish</span></label></div>
            </div>
          <?php endforeach; ?>
          </div>
          <?php if (!$m['shots']): ?><div class="ed-empty" data-empty>Hali rasm yo'q. Pastdagi tugma bilan yuklang.</div><?php endif; ?>
        </div>
        <div class="ed-upload">
          <label class="btn" for="f-newshots"><?= Ui::icon('plus') ?> Rasm qo'shish</label>
          <input class="sr" id="f-newshots" type="file" name="new_shots[]" multiple accept="image/png,image/jpeg,image/webp" data-newshots data-maxmb="<?= (int)$maxFile ?>" data-postmax="<?= Media::iniBytes('post_max_size') === PHP_INT_MAX ? 0 : Media::iniBytes('post_max_size') ?>" data-postlabel="<?= e(ini_get('post_max_size')) ?>">
          <span class="muted small">Tanlangan rasmlar «Saqlash» bosilganda yuklanadi. Yozuv va tartibini saqlagandan keyin o'zgartirasiz.</span>
        </div>
        <div class="ed-newprev" data-newprev></div>
      </div>
    </section>

    <section class="card ed-sec" id="savol">
      <div class="card-h"><h2><span class="ed-n">7</span> Savol-javob</h2><span class="muted small">ko'pi bilan 10 ta</span></div>
      <div class="card-b">
        <p class="muted small" style="margin-top:0">Mijozlar ko'p so'raydigan 3-5 ta savol: o'rnatish, narx, ma'lumot xavfsizligi, qaysi kompyuterda ishlashi.</p>
        <?= $pairs('faq', $m['faq'], 10, static fn($i, $v) => $pairRow('faq', $i, $v, ['q', 'a'], ['Savol', 'Javob'], ['Savol', 'Javob'], [220, 900]), 'Savol qo\'shish') ?>
      </div>
    </section>

    <section class="card ed-sec" id="qollanma">
      <div class="card-h"><h2><span class="ed-n">8</span> Qo'llanma: qanday ishlatiladi</h2><span class="muted small">ko'pi bilan 8 qadam</span></div>
      <div class="card-b">
        <p class="muted small" style="margin-top:0">Mahsulot sahifasidagi «Qanday ishlatiladi» bo'limi: mijoz qayerga bosishi, nima kiritishi va natijada nimani ko'rishini qadamma-qadam yozing. Har qadamga ekran rasmini tanlang (rasmlar 6-bo'limda yuklanadi). Faqat dasturda haqiqatan bor narsalarni yozing.</p>
        <div class="field"><label class="f" for="f-gintro">Qisqa kirish</label>
          <input class="in" id="f-gintro" name="guide[intro]" maxlength="400" value="<?= e($m['guide']['intro']) ?>" placeholder="Masalan: Dasturni birinchi marta ishga tushirganingizda shu 5 qadamni bajaring"></div>
        <?= $pairs('guide_steps', $m['guide']['steps'], 8, $stepRow, 'Qadam qo\'shish') ?>
        <div class="field" style="margin-top:14px"><label class="f" for="f-gtips">Foydali eslatmalar</label>
          <textarea class="in" id="f-gtips" name="guide[tips]" rows="3" placeholder="Har qator — bitta eslatma, ko'pi bilan 6 ta"><?= e(implode("\n", $m['guide']['tips'])) ?></textarea></div>
      </div>
    </section>

    <section class="card ed-sec" id="tarif">
      <div class="card-h"><h2><span class="ed-n">9</span> Tariflar va narx</h2></div>
      <div class="card-b">
        <?php if ($isNew): ?>
          <p class="muted small" style="margin-top:0">Ikkita boshlang'ich tarif («Standart» va «Korxona») avtomatik qo'shiladi. Saqlagandan keyin narx va shartlarni shu yerda tahrirlaysiz.</p>
        <?php else: ?>
          <p class="muted small" style="margin-top:0">Narx yozilmasa, saytda «Narx so'rov bo'yicha» chiqadi. Hozircha aniq narx hal qilinmagan bo'lsa, shunday qoldiring: narx to'qib chiqarilmaydi.</p>
          <?php foreach ($plans as $p): $pn = 'plans[' . (int)$p['id'] . ']'; ?>
          <div class="ed-plan <?= (int)$p['active'] ? '' : 'off' ?>">
            <?= $planCols($pn, ['name' => $pv($p, 'name'), 'price' => $pv($p, 'price'), 'price_text' => $pv($p, 'price_text'), 'blurb' => $pv($p, 'blurb'), 'seats' => $pv($p, 'seats'), 'days' => $pv($p, 'days')]) ?>
            <label class="check"><input type="checkbox" name="<?= $pn ?>[active]" value="1" <?= (isset($in['plans']) ? !empty($in['plans'][$p['id']]['active']) : (int)$p['active']) ? 'checked' : '' ?>> Saytda ko'rsatish <span class="muted small">(belgi olib tashlansa, tarif yashiriladi)</span></label>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
        <details class="ed-more"<?= isset($in['new_plans']) && array_filter(array_column($in['new_plans'], 'name')) ? ' open' : '' ?>><summary>+ Yana bitta tarif qo'shish</summary>
          <div class="ed-plan"><?= $planCols('new_plans[0]', ['seats' => '1']) ?></div>
        </details>
      </div>
    </section>

    <section class="card ed-sec" id="sinov">
      <div class="card-h"><h2><span class="ed-n">10</span> Brauzerda sinab ko'rish</h2></div>
      <div class="card-b">
        <div class="field"><label class="f" for="f-web">Dasturning brauzerdagi manzili</label>
          <input class="in" id="f-web" name="web_url" maxlength="300" value="<?= e($t['web_url']) ?>" placeholder="https://hr.biwork.uz/">
          <div class="hint">Mijoz «Sinab ko'rish»ni bosganda shu manzilga o'tadi. <b>Bo'sh qoldirilsa, saytda «Sinab ko'rish» tugmasi umuman chiqmaydi</b> va faqat «So'rov qoldirish» bo'ladi: ishlamaydigan narsa va'da qilinmaydi.
            Demo versiyalari (manzil yoki zip paket, versiya raqami va izoh bilan) <a href="<?= e(Util::url('/admin/tools')) ?>">Mahsulotlar ro'yxatidagi</a> «Demo versiyalari» bo'limida boshqariladi: u yerda joriy versiya tanlangan bo'lsa, shu manzil emas, o'sha versiya ochiladi.</div></div>
        <div class="row2">
          <div class="field"><label class="check" style="height:40px"><input type="checkbox" name="trial_enabled" value="1" <?= (int)$t['trial_enabled'] ? 'checked' : '' ?>> Bepul sinov muddatini ko'rsatish</label></div>
          <div class="field"><label class="f" for="f-tdays">Sinov muddati (kun)</label><input class="in" id="f-tdays" name="trial_days" type="number" min="1" max="90" value="<?= (int)$t['trial_days'] ?>"></div>
        </div>
      </div>
    </section>

    <section class="card ed-sec" id="qoshimcha">
      <details class="ed-adv"<?= $err !== '' ? ' open' : '' ?>>
        <summary class="card-h"><h2><span class="ed-n">11</span> Qo'shimcha sozlamalar</h2><span class="muted small">odatda tegmasangiz ham bo'ladi</span></summary>
        <div class="card-b">
          <h3 style="margin-bottom:10px">Sayt manzili va kod</h3>
          <div class="row2">
            <div class="field"><label class="f" for="f-slug">Sayt manzili</label>
              <?php if ($lock !== null): ?>
                <div class="ed-slug"><span><?= e($host . $sitePath) ?></span><input class="in" id="f-slug" value="<?= e($t['slug']) ?>" readonly aria-readonly="true"></div>
                <div class="hint">O'zgartirib bo'lmaydi: <?= e($lock) ?>.</div>
              <?php else: ?>
                <div class="ed-slug"><span><?= e($host . $sitePath) ?></span><input class="in" id="f-slug" name="slug" value="<?= e($isNew && $in === [] ? '' : $t['slug']) ?>" maxlength="31" placeholder="hr" data-slug></div>
                <div class="hint">Kichik lotin harf, raqam va chiziqcha. Bo'sh qolsa, nomdan o'zi yasaladi. <?= $isNew ? '' : "Manzil o'zgarsa, eski havola yangisiga o'tkazib yuboriladi." ?></div>
              <?php endif; ?></div>
            <div class="field"><label class="f" for="f-code">Litsenziya kodi (3 belgi)</label>
              <?php if ($lock !== null): ?>
                <input class="in" id="f-code" value="<?= e($t['code']) ?>" readonly aria-readonly="true" style="max-width:120px">
              <?php else: ?>
                <input class="in" id="f-code" name="code" value="<?= e($isNew && $in === [] ? '' : $t['code']) ?>" maxlength="3" placeholder="HRX" style="max-width:120px;text-transform:uppercase" data-code>
                <div class="hint">Litsenziya kalitlari shu harflar bilan boshlanadi (masalan HRX-…). Bo'sh qolsa, o'zi tanlanadi.</div>
              <?php endif; ?></div>
          </div>
          <div class="field" style="max-width:200px"><label class="f" for="f-sort">Katalogdagi o'rni</label><input class="in" id="f-sort" name="sort" type="number" min="1" max="9999" value="<?= (int)$t['sort'] ?>"><div class="hint">Kichik raqam — oldinroq.</div></div>

          <h3 style="margin:18px 0 10px">Tugmalar va so'rov bo'limi matni</h3>
          <div class="row2">
            <div class="field"><label class="f" for="f-ctat">«Sinab ko'rish» tugmasi</label><input class="in" id="f-ctat" name="cta[trial]" maxlength="40" value="<?= e($m['cta']['trial']) ?>" placeholder="Sinab ko'rish"></div>
            <div class="field"><label class="f" for="f-ctal">«So'rov qoldirish» tugmasi</label><input class="in" id="f-ctal" name="cta[lead]" maxlength="40" value="<?= e($m['cta']['lead']) ?>" placeholder="So'rov qoldirish"></div>
          </div>
          <div class="field"><label class="f" for="f-ctalt">So'rov bo'limi sarlavhasi</label><input class="in" id="f-ctalt" name="cta[lead_title]" maxlength="90" value="<?= e($m['cta']['lead_title']) ?>" placeholder="Bo'sh qolsa: «Nom haqida so'rov qoldiring»"></div>
          <div class="field"><label class="f" for="f-ctalx">So'rov bo'limi izohi</label><textarea class="in" id="f-ctalx" name="cta[lead_text]" maxlength="260" rows="2"><?= e($m['cta']['lead_text']) ?></textarea></div>
          <div class="field"><label class="f" for="f-ctap">«Qanday yordam kerak?» maydonidagi namuna</label><input class="in" id="f-ctap" name="cta[placeholder]" maxlength="140" value="<?= e($m['cta']['placeholder']) ?>" placeholder="Bo'sh qolsa: umumiy namuna chiqadi"></div>

          <h3 style="margin:18px 0 10px">Google va ijtimoiy tarmoqlar uchun</h3>
          <div class="serp" aria-hidden="true"><div class="serp-u"><?= e($host) ?> › <span data-serp-slug><?= e($t['slug']) ?></span></div><div class="serp-t" data-serp-title></div><div class="serp-d" data-serp-desc></div></div>
          <div class="field"><label class="f" for="f-st">Qidiruvdagi sarlavha</label><input class="in" id="f-st" name="seo[title]" maxlength="70" value="<?= e($m['seo']['title']) ?>" placeholder="Bo'sh qolsa: nom va qisqa tavsifdan yasaladi" data-count data-serp="title"></div>
          <div class="field"><label class="f" for="f-sd">Qidiruvdagi tavsif</label><textarea class="in" id="f-sd" name="seo[description]" maxlength="180" rows="2" placeholder="Bo'sh qolsa: tavsifdan o'zi olinadi" data-count data-serp="desc"><?= e($m['seo']['description']) ?></textarea></div>
          <div class="field"><label class="f" for="f-sk">Qidiruv so'zlari</label><input class="in" id="f-sk" name="seo[keywords]" maxlength="300" value="<?= e(implode(', ', $m['seo']['keywords'])) ?>" placeholder="vergul bilan: ish rejasi, kadrlar hisobi"></div>
          <div class="hint">Ulashish rasmi birinchi ekran rasmidan avtomatik yasaladi.</div>
        </div>
      </details>
    </section>

    <div class="ed-actions-m">
      <button class="btn btn-primary" type="submit" name="do" value="save" data-save>Saqlash</button>
      <button class="btn" type="submit" formaction="<?= e(Util::url('/admin/tools/preview')) ?>" formtarget="_blank" formnovalidate data-preview>Oldindan ko'rish</button>
    </div>
  </div>

  <aside class="ed-side">
    <section class="card">
      <div class="card-b">
        <h3 style="margin-bottom:10px">Holat</h3>
        <?php if ($isNew): ?>
          <p class="small muted" style="margin-top:0">Yangi mahsulot avval <b>yashirin</b> saqlanadi. To'ldirib bo'lgach «E'lon qilish»ni bosasiz.</p>
          <button class="btn btn-primary btn-block" type="submit" name="do" value="save" data-save>Qoralama sifatida saqlash</button>
        <?php else: ?>
          <p class="small" style="margin-top:0"><?= [
              'active' => "<b>Saytda ochiq.</b> Mijozlar sahifani ko'radi va sinab ko'ra oladi.",
              'soon' => "<b>«Tez orada».</b> Sahifa saytda, lekin sinash o'rniga mijozlar xabar kutish uchun so'rov qoldiradi.",
              'hidden' => "<b>Yashirin.</b> Saytda ko'rinmaydi, uni faqat xodimlar ko'radi.",
          ][$status] ?? '' ?></p>
          <div class="ed-btns">
            <button class="btn btn-primary btn-block" type="submit" name="do" value="save" data-save>Saqlash</button>
            <?php if ($status !== 'active'): ?><button class="btn btn-block" type="submit" name="do" value="publish">Saqlash va e'lon qilish</button><?php endif; ?>
            <?php if ($status === 'hidden'): ?><button class="btn btn-block" type="submit" name="do" value="soon">Saqlash va «Tez orada» deb e'lon qilish</button><?php endif; ?>
            <?php if ($status !== 'hidden'): ?><button class="btn btn-block btn-danger" type="submit" name="do" value="hide" data-confirm-btn="Mahsulot saytdan yashiriladi (mijozlar uni ko'rmaydi). Davom etilsinmi?">Saqlash va yashirish</button><?php endif; ?>
          </div>
        <?php endif; ?>
        <div class="ed-btns" style="margin-top:10px">
          <button class="btn btn-block" type="submit" formaction="<?= e(Util::url('/admin/tools/preview')) ?>" formtarget="_blank" formnovalidate data-preview>Oldindan ko'rish</button>
          <?php if (!$isNew): ?><a class="btn btn-block btn-ghost" href="<?= e(Util::url('/' . $saved['slug'])) ?>" target="_blank" rel="noopener"><?= $status === 'active' ? 'Saytdagi sahifani ochish' : "Saqlangan sahifani ko'rish" ?></a><?php endif; ?>
        </div>
        <p class="hint" style="margin-bottom:0">«Oldindan ko'rish» saqlamasdan, yangi oynada ochadi (yangi yuklangan rasmlar saqlangandan keyin chiqadi).</p>
      </div>
    </section>

    <?php if (!$isNew): ?>
    <section class="card">
      <div class="card-b">
        <h3 style="margin-bottom:4px">E'lon qilishdan oldin</h3>
        <p class="hint" style="margin-top:0">Oxirgi saqlangan holat bo'yicha</p>
        <ul class="ed-ready">
          <?php foreach ($ready as $r): ?>
          <li class="<?= $r['ok'] ? 'ok' : ($r['must'] ? 'must' : 'opt') ?>">
            <span class="ed-ck" aria-hidden="true"><?= $r['ok'] ? '✓' : ($r['must'] ? '!' : '○') ?></span>
            <span><b><?= e($r['label']) ?></b><?= !$r['ok'] ? '<span class="muted small"> — ' . ($r['must'] ? 'zarur. ' : '') . e($r['hint']) . '</span>' : '' ?></span>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </section>
    <?php endif; ?>

    <section class="card">
      <div class="card-b ed-jump">
        <h3 style="margin-bottom:8px">Bo'limlar</h3>
        <a href="#asosiy">1. Asosiy ma'lumot</a><a href="#sahifa">2. Sahifaning tepa qismi</a><a href="#kimga">3. Kimga kerak</a><a href="#muammo">4. Muammolar</a><a href="#imkoniyat">5. Imkoniyatlar</a>
        <a href="#rasmlar">6. Ekran rasmlari</a><a href="#savol">7. Savol-javob</a><a href="#qollanma">8. Qo'llanma</a><a href="#tarif">9. Tariflar</a><a href="#sinov">10. Sinab ko'rish</a><a href="#qoshimcha">11. Qo'shimcha</a>
      </div>
    </section>
  </aside>
</form>
<script src="<?= e($js) ?>" defer></script>
