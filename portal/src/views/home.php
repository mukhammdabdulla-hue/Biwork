<?php /** @var array $tools */
$days = Catalog::trialDays();
$anyDemo = (bool)array_filter($tools, static fn($x) => Trial::of($x)['can']);
$guided = array_values(array_filter($tools, static fn($x) => $x['status'] === 'active' && !empty($x['marketing']['guide']['steps'])));
$nActive = count(array_filter($tools, static fn($x) => $x['status'] === 'active'));
$nSoon = count($tools) - $nActive;
?>
<section class="hero hero-home"><div class="wrap">
  <div class="hh-top">
    <h1>Korxonangiz ishini avtomatlashtiradigan <em>tayyor dasturlar</em></h1>
    <?php if ($anyDemo): ?>
    <p class="lead">Biwork — ish rejasi, xodimlar, loyiha, savdo, moliya va yuridik ishlar uchun tayyor dasturlar to'plami: har birini brauzerda <?= $days ?> kun bepul sinab ko'ring, yoqsa o'z kompyuteringizga o'rnating.</p>
    <?php else: ?>
    <p class="lead">Biwork — ish rejasi, xodimlar, loyiha, savdo, moliya va yuridik ishlar uchun tayyor dasturlar to'plami: har birining sahifasida nima qilishi, ekranlari va qo'llanmasi bor; <?= $days ?> kunlik bepul sinov demo ulangach ochiladi.</p>
    <?php endif; ?>
    <ul class="hh-facts">
      <li><?= $nActive ?> ta tayyor dastur<?= $nSoon ? ', yana ' . $nSoon . ' tasi tez orada' : '' ?></li>
      <li><?= $days ?> kun bepul sinov</li>
      <li>Interfeys o'zbek tilida</li>
    </ul>
  </div>
  <div class="ptiles" id="mahsulotlar">
    <?php foreach ($tools as $t): ?><?= View::render('_prod_tile', ['t' => $t]) ?><?php endforeach; ?>
    <article class="ptile ask">
      <a class="ptile-h" href="#sorov"><span class="tool-ic"><?= Ui::icon('plus') ?></span><span class="ptile-n"><b>Boshqa dastur kerakmi?</b><small>Yangi mahsulotlar</small></span></a>
      <p class="ptile-d">Qaysi jarayonni avtomatlashtirmoqchi ekaningizni yozing: katalogni shunga qarab to'ldiramiz.</p>
      <div class="ptile-b"><a class="btn btn-sm btn-ask" href="#sorov">Yozish</a></div>
    </article>
  </div>
</div></section>
<div class="spectrum" aria-hidden="true"></div>

<?php if ($guided): ?>
<section class="section" id="qollanmalar"><div class="wrap">
  <h2>Qanday ishlatiladi</h2>
  <p class="sub">Har mahsulotning sahifasida qadamma-qadam qo'llanma bor: qayerga bosiladi, nima kiritiladi, natijada nima ko'rinadi. Ekran rasmlari bilan.</p>
  <div class="grid g3 guides">
    <?php foreach ($guided as $t): $g = $t['marketing']['guide']; ?>
      <a class="card guide-card" href="<?= e(Util::url('/' . $t['slug'] . '#qollanma')) ?>" data-accent="<?= e(Marketing::theme($t)['accent']) ?>">
        <span class="guide-h"><span class="tool-ic<?= $t['status'] === 'active' ? ' green' : '' ?>"><?= Ui::toolIcon($t['icon']) ?></span><b><?= e($t['name']) ?></b></span>
        <?php $gi = preg_split('/(?<=\.)\s/u', (string)$g['intro'], 2)[0] ?? ''; ?>
        <?php if ($gi !== ''): ?><span class="guide-i"><?= e(mb_strlen($gi) > 170 ? rtrim((string)preg_replace('/\s+\S*$/u', '', mb_substr($gi, 0, 168)), ' ,;:—') . '…' : $gi) ?></span><?php endif; ?>
        <span class="guide-s"><?php foreach (array_slice($g['steps'], 0, 3) as $gn => $gs): ?><span><i><?= $gn + 1 ?></i><?= e($gs['title']) ?></span><?php endforeach; ?><?php if (count($g['steps']) > 3): ?><em>… va yana <?= count($g['steps']) - 3 ?> qadam</em><?php endif; ?></span>
        <span class="guide-n"><?= count($g['steps']) ?> qadam · qo'llanmani o'qish <?= Ui::icon('arrow') ?></span>
      </a>
    <?php endforeach; ?>
    <div class="guide-ask fill">
      <span class="tool-ic"><?= Ui::icon('send') ?></span>
      <div class="ga-t"><b>Qo'llanmada javob topolmadingizmi?</b><p>Savolingizni yozing: javob beramiz, kerak bo'lsa qo'llanmaga ham qo'shamiz.</p></div>
      <a class="btn btn-sm" href="#sorov">Savol yozish</a>
    </div>
  </div>
</div></section>
<?php endif; ?>

<section class="section alt" id="qanday"><div class="wrap">
  <h2>Sinovdan ishga tushirishgacha</h2>
  <div class="steps">
    <div class="card step"><b>Mahsulotni tanlang</b><span class="muted">Sahifasida nima qilishi, ekranlari va qo'llanmasi bor.</span></div>
    <?php if ($anyDemo): ?>
    <div class="card step"><b><?= $days ?> kun bepul sinab ko'ring</b><span class="muted">Demosi ulangan mahsulotni ro'yxatdan o'tib, namuna ma'lumot bilan brauzerda oching. Qolganlari uchun so'rov qoldiring.</span></div>
    <?php else: ?>
    <div class="card step"><b><?= $days ?> kun bepul sinov</b><span class="muted">Mahsulotning demosi ulangach «Sinab ko'rish» tugmasi chiqadi. Hozir so'rov qoldirsangiz, ulanganda xabar beramiz.</span></div>
    <?php endif; ?>
    <div class="card step"><b>Litsenziya oling</b><span class="muted">Tarifni tanlab so'rov yuboring: hisob-faktura beramiz, kalit kabinetingizda paydo bo'ladi.</span></div>
    <div class="card step"><b>Yuklab oling</b><span class="muted">O'z kompyuteringizga o'rnating va kalit bilan faollashtiring. Ma'lumot sizda saqlanadi.</span></div>
  </div>
</div></section>

<?= View::render('_lead_band', [
    'tool' => null, 'err' => $leadErr ?? '', 'in' => $in ?? [],
    'heading' => "Savolingiz bormi? Bizga yozing",
    'text' => "Qaysi sohada ishlashingizni va nimani avtomatlashtirmoqchi ekaningizni yozing: mos mahsulotni ko'rsatamiz yoki demo uchrashuv belgilaymiz.",
    'btn' => "So'rov yuborish", 'points' => ["So'rov yuborish hech narsaga majbur qilmaydi", "Telefon yoki email orqali bog'lanamiz", "Faqat siz so'ragan narsa haqida bog'lanamiz"],
]) ?>
