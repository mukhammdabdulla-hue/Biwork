<?php
/** Mahsulot reklama sahifasi. @var array $t @var array $m @var array $plans @var ?array $user @var string $leadErr @var array $in @var string $mode live|hidden|preview */
$mode = $mode ?? 'live';
$slug = $t['slug'];
$th = Marketing::theme($t);
// bo'lim belgisi («01 · Muammolar») va fon almashinuvi (oddiy, oq, mahsulot tusida): faqat ko'rinadigan bo'limlar sanaladi
$secNo = 0;
$eyebrow = static function (string $label) use (&$secNo): string {
    return '<span class="eyebrow"><i>' . sprintf('%02d', ++$secNo) . '</i>' . e($label) . '</span>';
};
$bandNo = 0;
$band = static function () use (&$bandNo): string {
    return ['', ' alt', '', ' tint'][$bandNo++ % 4];
};
$soon = $t['status'] === 'soon';
// brauzer manzili yo'q bo'lsa "Sinab ko'rish" ishlamaydi — reklamada va'da qilinmaydi; nima yetishmayotgani ochiq yoziladi (Trial)
$tr = Trial::of($t);
$canTry = $tr['can'];
$trial = $tr['state'] === 'ready';
$cta = $m['cta'] ?? [];
[$tryHref, $tryLabel] = Trial::button($t, $user) ?? [Util::url('/register?tool=' . $slug), "Sinab ko'rish"];
$demoVer = class_exists('Demos') && (int)$t['id'] > 0 ? Demos::current((int)$t['id']) : null;
$guide = $m['guide'] ?? ['intro' => '', 'steps' => [], 'tips' => []];
$leadLabel = $soon ? 'Chiqqanda xabar bering' : (($cta['lead'] ?? '') !== '' ? $cta['lead'] : "So'rov qoldirish");
$headline = ($m['headline'] ?? '') !== '' ? $m['headline'] : $t['name'] . ($t['tagline'] !== '' ? ' — ' . $t['tagline'] : '');
$paras = preg_split('/\n{2,}/', trim($t['description'])) ?: [];
$first = (string)($paras[0] ?? '');
$lead = ($m['lead'] ?? '') !== '' ? $m['lead'] : (mb_strlen($first) >= mb_strlen($t['name']) + 12 ? $first : '');
// ekran rasmlari: fayl nomi -> o'lchami bilan (diskda yo'qlari tashlanadi)
$shots = [];
foreach ($m['shots'] ?? [] as $s) {
    if ($im = Marketing::shot($t, $s['file'])) {
        $shots[$s['file']] = $im + ['caption' => $s['caption'], 'alt' => $s['alt'] !== '' ? $s['alt'] : $t['name']];
    }
}
$heroShot = $shots ? reset($shots) : null;
$subShot = count($shots) > 1 ? array_values($shots)[1] : null;
$usedInFeatures = [];
foreach ($m['features'] ?? [] as $f) {
    if ($f['shot'] !== '') {
        $usedInFeatures[$f['shot']] = true;
    }
}
$gallery = array_diff_key($shots, $usedInFeatures, $heroShot ? [array_key_first($shots) => true] : []);
$frame = static function (array $im, bool $first = false) use ($t): string {
    return '<a class="frame" href="' . e($im['url']) . '" data-zoom data-caption="' . e($im['caption']) . '">'
        . '<span class="fbar" aria-hidden="true"><i></i><i></i><i></i></span>'
        . '<img src="' . e($im['url']) . '" width="' . $im['w'] . '" height="' . $im['h'] . '" alt="' . e($im['alt']) . '"' . ($first ? ' fetchpriority="high"' : ' loading="lazy" decoding="async"') . '></a>';
};
$others = array_values(array_filter(Catalog::tools(), static fn($o) => $o['slug'] !== $slug));
$othersThumbs = (bool)array_filter(array_slice($others, 0, 3), static fn($x) => !empty($x['marketing']['shots'][0]['file']));
?>
<div class="pp" data-accent="<?= e($th['accent']) ?>">
<?php if ($mode !== 'live'): ?>
<div class="preview-bar" role="status"><div class="wrap">
  <b><?= $mode === 'preview' ? "Oldindan ko'rish" : 'Yashirin sahifa' ?></b>
  <span><?= $mode === 'preview' ? "Saqlanmagan o'zgarishlar bilan ko'rinish. Yangi yuklangan rasmlar saqlangandan keyin chiqadi. Mijozlar buni ko'rmaydi." : "Bu mahsulot yashirin, uni faqat xodimlar ko'radi. Saytda chiqarish uchun admin panelida «E'lon qilish»ni bosing." ?></span>
  <?php if ((int)$t['id'] > 0): ?><a href="<?= e(Util::url('/admin/tools/' . (int)$t['id'] . '/edit')) ?>">Tahrirlashga qaytish</a><?php endif; ?>
</div></div>
<?php endif; ?>
<section class="hero hero-p" data-hero="<?= e($th['hero']) ?>" data-tone="<?= e($th['tone']) ?>"><div class="wrap">
  <div class="hero-t">
    <div class="crumbs crumbs-w"><a href="<?= e(Util::url('/')) ?>">Biwork</a> / <a href="<?= e(Util::url('/mahsulotlar')) ?>">Mahsulotlar</a> / <?= e($t['name']) ?></div>
    <div class="prod-id"><span class="tool-ic green"><?= Ui::toolIcon($t['icon']) ?></span><span><b><?= e($t['name']) ?></b><?= $t['category'] !== '' ? ' · ' . e($t['category']) : '' ?><?= $soon ? ' · <em class="soon-tag">Tez orada</em>' : '' ?></span></div>
    <h1><?= e($headline) ?></h1>
    <?php if ($lead !== ''): ?><p class="lead"><?= e($lead) ?></p><?php endif; ?>
    <div class="actions">
      <?php if ($canTry): ?><a class="btn btn-lg btn-hero" href="<?= e($tryHref) ?>"><?= Ui::icon('play') ?> <?= e($tryLabel) ?></a><?php endif; ?>
      <a class="btn btn-lg <?= $canTry ? 'btn-outline-w' : 'btn-hero' ?>" href="#sorov"><?= e($leadLabel) ?></a>
    </div>
    <?php if ($trial && !$user): ?><p class="fine"><b><?= (int)$tr['days'] ?> kun bepul sinov</b> · korxona nomi va email yetarli</p><?php endif; ?>
    <?php if ($tr['state'] === 'nodemo' || $tr['state'] === 'soon'): ?>
      <div class="trial-note"><b><?= $tr['state'] === 'soon' ? 'Mahsulot hali chiqmagan' : (int)$tr['days'] . ' kunlik bepul sinov hozircha ochilmagan' ?></b><span><?= e($tr['note']) ?></span></div>
    <?php endif; ?>
    <?php if ($demoVer && $canTry): ?><p class="fine demo-ver"><?= e(Demos::label($demoVer)) ?></p><?php endif; ?>
    <?php if (!empty($m['badges'])): ?><div class="hero-badges"><?php foreach ($m['badges'] as $bd): ?><span><?= e($bd) ?></span><?php endforeach; ?></div><?php endif; ?>
  </div>
  <div class="art">
    <?php if ($heroShot): ?><div class="art-main"><?= $frame($heroShot, true) ?></div>
    <?php if ($th['hero'] === 'duo' && $subShot): ?><div class="art-sub"><?= $frame($subShot) ?></div><?php endif; ?>
    <?php else: ?><div class="art-icon"><?= Ui::toolIcon($t['icon']) ?></div><?php endif; ?>
  </div>
</div></section>

<?php if (!empty($m['problems'])): ?>
<section class="section<?= $band() ?>"><div class="wrap">
  <?= $eyebrow('Muammolar') ?>
  <h2>Qaysi muammolarni hal qiladi</h2>
  <div class="grid <?= count($m['problems']) === 4 ? 'g4' : 'g3' ?> problems">
    <?php foreach ($m['problems'] as $i => $p): ?>
      <div class="card pad"><span class="num"><?= $i + 1 ?></span><b><?= e($p['title']) ?></b><?php if ($p['text'] !== ''): ?><p class="muted"><?= e($p['text']) ?></p><?php endif; ?></div>
    <?php endforeach; ?>
  </div>
</div></section>
<?php endif; ?>

<?php if (!empty($m['features'])): ?>
<section class="section<?= $band() ?>" id="imkoniyatlar"><div class="wrap">
  <?= $eyebrow('Imkoniyatlar') ?>
  <h2>Nimalar qila oladi</h2>
  <div class="feats">
    <?php foreach ($m['features'] as $i => $f): $im = $f['shot'] !== '' ? ($shots[$f['shot']] ?? null) : null; ?>
      <div class="feat<?= $im ? '' : ' noshot' ?><?= $i % 2 ? ' rev' : '' ?>">
        <div class="feat-t">
          <h3><?= e($f['title']) ?></h3>
          <?php if ($f['text'] !== ''): ?><p><?= e($f['text']) ?></p><?php endif; ?>
          <?php if ($f['points']): ?><ul class="ticks"><?php foreach ($f['points'] as $pt): ?><li><?= e($pt) ?></li><?php endforeach; ?></ul><?php endif; ?>
        </div>
        <?php if ($im): ?><figure class="feat-s"><?= $frame($im) ?><?php if ($im['caption'] !== ''): ?><figcaption><?= e($im['caption']) ?></figcaption><?php endif; ?></figure><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div></section>
<?php elseif ($t['highlights']): ?>
<section class="section<?= $band() ?>"><div class="wrap">
  <?= $eyebrow('Imkoniyatlar') ?>
  <h2>Nimalar qila oladi</h2>
  <ul class="ticks cols2"><?php foreach ($t['highlights'] as $h): ?><li><?= e($h) ?></li><?php endforeach; ?></ul>
</div></section>
<?php endif; ?>

<?php if (!empty($guide['steps'])): ?>
<section class="section<?= $band() ?>" id="qollanma"><div class="wrap">
  <?= $eyebrow("Qo'llanma") ?>
  <h2>Qanday ishlatiladi</h2>
  <?php if ($guide['intro'] !== ''): ?><p class="sub"><?= e($guide['intro']) ?></p><?php endif; ?>
  <?php if ($tr['state'] === 'nodemo'): ?><p class="gwarn"><b>Eslatma:</b> brauzerda sinab ko'rish hozircha ulanmagan. Qadamlar dasturning haqiqiy ekranlari asosida yozilgan; sinov ochilgach shularni o'zingiz bajarib ko'rasiz.</p><?php endif; ?>
  <ol class="gsteps">
    <?php foreach ($guide['steps'] as $i => $st): $im = $st['shot'] !== '' ? ($shots[$st['shot']] ?? null) : null; ?>
      <li class="gstep<?= $im ? '' : ' noshot' ?>">
        <div class="gstep-t">
          <span class="gnum" aria-hidden="true"><?= $i + 1 ?></span>
          <h3><?= e($st['title']) ?></h3>
          <?php if ($st['text'] !== ''): ?><p><?= e($st['text']) ?></p><?php endif; ?>
          <?php if ($st['points']): ?><ul class="ticks small"><?php foreach ($st['points'] as $pt): ?><li><?= e($pt) ?></li><?php endforeach; ?></ul><?php endif; ?>
        </div>
        <?php if ($im): ?><figure class="gstep-s"><?= $frame($im) ?></figure><?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ol>
  <?php if ($guide['tips']): ?>
  <div class="gtips"><b>Foydali eslatmalar</b><ul class="ticks small"><?php foreach ($guide['tips'] as $tp): ?><li><?= e($tp) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
  <div class="actions gactions">
    <?php if ($canTry): ?><a class="btn btn-primary btn-lg" href="<?= e($tryHref) ?>"><?= Ui::icon('play') ?> <?= e($tryLabel) ?><?= $trial ? ' · ' . (int)$tr['days'] . ' kun bepul' : '' ?></a><?php endif; ?>
    <a class="btn btn-lg" href="#sorov"><?= e($leadLabel) ?></a>
  </div>
  <?php if (!$canTry && $tr['note'] !== ''): ?><p class="muted small gnote"><?= e($tr['note']) ?></p><?php endif; ?>
</div></section>
<?php endif; ?>

<?php if (!empty($m['audience'])): ?>
<section class="section<?= $band() ?>"><div class="wrap">
  <?= $eyebrow('Kimlar uchun') ?>
  <h2>Kimga kerak</h2>
  <div class="grid g3 aud">
    <?php foreach ($m['audience'] as $a): ?>
      <div class="card pad"><span class="ic"><?= Ui::icon('target') ?></span><b><?= e($a['title']) ?></b><?php if ($a['text'] !== ''): ?><p class="muted"><?= e($a['text']) ?></p><?php endif; ?></div>
    <?php endforeach; ?>
  </div>
</div></section>
<?php endif; ?>

<?php if ($gallery): ?>
<section class="section<?= $band() ?>"><div class="wrap">
  <?= $eyebrow('Ekranlar') ?>
  <h2>Ekranlar</h2>
  <p class="sub">Rasmni bossangiz kattalashadi.</p>
  <div class="grid g2 gallery">
    <?php foreach ($gallery as $im): ?><figure><?= $frame($im) ?><?php if ($im['caption'] !== ''): ?><figcaption><?= e($im['caption']) ?></figcaption><?php endif; ?></figure><?php endforeach; ?>
  </div>
</div></section>
<?php endif; ?>

<?php if (!$soon): ?>
<section class="section<?= $band() ?>" id="olish"><div class="wrap">
  <?= $eyebrow('Boshlash') ?>
  <h2>Qanday olinadi</h2>
  <div class="steps s3">
    <?php if ($canTry): ?>
    <div class="card step"><b>Brauzerda sinab ko'ring</b><span class="muted"><?= $trial ? (int)$tr['days'] . ' kun bepul, namuna ma\'lumot bilan — hech narsa o\'rnatish shart emas.' : "Namuna ma'lumot bilan, hech narsa o'rnatish shart emas." ?></span></div>
    <?php else: ?>
    <div class="card step"><b>So'rov qoldiring</b><span class="muted"><?= $tr['state'] === 'nodemo' ? (int)$tr['days'] . " kunlik bepul sinov demo ulangach ochiladi: so'rov qoldirsangiz, xabar beramiz. Siz bilan bog'lanib, mahsulot korxonangizga mos kelishini ham birga aniqlaymiz." : "Siz bilan bog'lanib, mahsulot korxonangizga mos kelishini birga aniqlaymiz." ?></span></div>
    <?php endif; ?>
    <div class="card step"><b>Litsenziya oling</b><span class="muted">Tarifni tanlab so'rov yuboring — hisob-faktura beramiz, kalit kabinetingizda paydo bo'ladi.</span></div>
    <div class="card step"><b>O'z kompyuteringizga o'rnating</b><span class="muted">Dasturni yuklab oling va kalit bilan faollashtiring. Ma'lumot sizda saqlanadi.</span></div>
  </div>
</div></section>
<?php endif; ?>

<?php if (!$soon && $plans): ?>
<section class="section<?= $band() ?>" id="tariflar"><div class="wrap">
  <?= $eyebrow('Tariflar') ?>
  <h2>Tariflar</h2>
  <?php $unpriced = (bool)array_filter($plans, static fn($x) => trim((string)($x['price_text'] ?? '')) === '' && !((int)$x['price'] > 0)); ?>
  <p class="sub"><?= $unpriced ? "Aniq narx va to'lov shartlari so'rov bo'yicha kelishiladi." : "To'lov shartlari so'rov bo'yicha kelishiladi." ?></p>
  <div class="grid plans plans-<?= min(count($plans), 4) ?>">
    <?php foreach ($plans as $p): ?>
      <div class="card plan">
        <h3><?= e($p['name']) ?></h3>
        <?php $ptxt = trim((string)($p['price_text'] ?? '')); ?>
        <div class="price"><?= $ptxt !== '' ? e($ptxt) : ($p['price'] !== null && (int)$p['price'] > 0 ? e(Util::money((int)$p['price'], $p['currency'])) : 'Narx so\'rov bo\'yicha') ?></div>
        <?php if (trim((string)($p['blurb'] ?? '')) !== ''): ?><p class="muted small plan-blurb"><?= e($p['blurb']) ?></p><?php endif; ?>
        <ul class="ticks small">
          <li><?= $p['days'] !== null ? (int)$p['days'] . ' kunlik litsenziya' : 'Muddatsiz litsenziya' ?></li>
          <li><?= (int)$p['seats'] ?> ta qurilmada o'rnatish</li>
          <?php if (isset($p['limits']['users'])): ?><li><?= (int)$p['limits']['users'] ?> tagacha foydalanuvchi</li><?php endif; ?>
        </ul>
        <a class="btn btn-sm" href="#sorov">Shu tarif haqida so'rash</a>
      </div>
    <?php endforeach; ?>
  </div>
</div></section>
<?php endif; ?>

<?php if (!empty($m['faq'])): ?>
<section class="section<?= $band() ?>" id="savollar"><div class="wrap faq-wrap">
  <div class="faq-h">
    <?= $eyebrow('Savollar') ?>
    <h2>Ko'p so'raladigan savollar</h2>
    <p>Javobini topolmadingizmi? <a href="#sorov">Savolingizni yozing</a>: o'zimiz javob beramiz.</p>
  </div>
  <div class="faq">
    <?php foreach ($m['faq'] as $f): ?><details><summary><?= e($f['q']) ?></summary><p><?= e($f['a']) ?></p></details><?php endforeach; ?>
  </div>
</div></section>
<?php endif; ?>

<?= View::render('_lead_band', [
    'tool' => $t, 'err' => $leadErr ?? '', 'in' => $in ?? [],
    'heading' => ($cta['lead_title'] ?? '') !== '' ? $cta['lead_title'] : ($soon ? $t['name'] . ' chiqqanda xabar beramiz' : $t['name'] . ' haqida so\'rov qoldiring'),
    'text' => ($cta['lead_text'] ?? '') !== '' ? $cta['lead_text'] : ($soon ? "Telefon yoki emailingizni qoldiring — dastur tayyor bo'lganda birinchilardan bo'lib xabar beramiz." : "Telefon raqamingizni qoldiring — siz bilan bog'lanib, savollaringizga javob beramiz va ishlashini ko'rsatamiz."),
    'btn' => $soon ? 'Xabar berishni so\'rash' : "So'rov yuborish", 'placeholder' => (string)($cta['placeholder'] ?? ''), 'disabled' => $mode === 'preview',
    'points' => $soon ? [] : array_values(array_filter([$trial ? "Avval " . (int)$tr['days'] . " kun bepul sinab ko'rishingiz mumkin" : '', $tr['state'] === 'nodemo' ? (int)$tr['days'] . " kunlik bepul sinov demo ulangach ochiladi, xabar beramiz" : '', "So'rov yuborish hech narsaga majbur qilmaydi", "Faqat siz so'ragan narsa haqida bog'lanamiz"])),
]) ?>

<?php if ($others): ?>
<section class="section<?= $band() ?>"><div class="wrap">
  <?= $eyebrow('Biwork oilasi') ?>
  <h2>Boshqa Biwork mahsulotlari</h2>
  <div class="grid g3 others">
    <?php foreach (array_slice($others, 0, 3) as $o): ?>
      <?= View::render('_tool_card', ['t' => $o, 'href' => Util::url('/' . $o['slug']), 'ctaLabel' => 'Batafsil', 'withThumbs' => $othersThumbs]) ?>
    <?php endforeach; ?>
  </div>
</div></section>
<?php endif; ?>

<div class="sticky-cta" role="region" aria-label="Tezkor harakatlar">
  <?php if ($canTry): ?><a class="btn btn-primary" href="<?= e($tryHref) ?>"><?= e($user ? 'Ochish' : $tryLabel) ?></a><?php endif; ?>
  <a class="btn" href="#sorov"><?= e($soon ? 'Xabar olish' : "So'rov qoldirish") ?></a>
</div>
</div>
