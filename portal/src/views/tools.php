<?php /** @var array $tools @var ?array $user */
$withThumbs = (bool)array_filter($tools, static fn($x) => !empty($x['marketing']['shots'][0]['file']));
$active = array_values(array_filter($tools, static fn($t) => $t['status'] === 'active'));
?>
<section class="page-top pt-cat"><div class="wrap">
  <div class="crumbs"><a href="<?= e(Util::url('/')) ?>">Biwork</a> / Mahsulotlar</div>
  <h1>Biwork mahsulotlari</h1>
  <p class="sub">Har bir ish yo'nalishi uchun alohida dastur. Brauzerda namuna ma'lumot bilan sinab ko'ring yoki litsenziya olib o'z korxonangizga o'rnating.</p>
</div></section>
<section class="section section-tight"><div class="wrap">
  <div class="grid g3">
    <?php foreach ($tools as $t): ?>
      <?= View::render('_tool_card', ['t' => $t, 'href' => Util::url('/' . $t['slug']), 'ctaLabel' => 'Batafsil', 'withThumbs' => $withThumbs]) ?>
    <?php endforeach; ?>
    <article class="card tool-card soon ask fill">
      <div class="head"><div class="tool-ic"><?= Ui::icon('plus') ?></div><div><h3>Sizga boshqa dastur kerakmi?</h3></div></div>
      <p class="tag">Qaysi jarayonni avtomatlashtirmoqchi ekaningizni yozing — katalogni shunga qarab to'ldiramiz.</p>
      <div class="foot"><a class="btn btn-sm" href="#sorov">Yozish</a></div>
    </article>
  </div>
</div></section>
<?= View::render('_lead_band', [
    'tool' => null, 'err' => $leadErr ?? '', 'in' => $in ?? [],
    'heading' => $active ? "Qaysi mahsulot sizga mos — birga aniqlaymiz" : "Yangi mahsulotlar tez orada",
    'text' => "Telefon raqamingizni qoldiring, siz bilan bog'lanib, korxonangiz uchun nima mos kelishini aytamiz. ",
    'btn' => "So'rov yuborish", 'points' => ["So'rov yuborish hech narsaga majbur qilmaydi", "Namuna ma'lumot bilan avval sinab ko'rishingiz mumkin", "Narx va muddat — so'rov bo'yicha kelishiladi"],
]) ?>
