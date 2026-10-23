<?php /** @var array $rows @var int $page @var int $pages @var string $action */ ?>
<div class="page-h"><div><h1>Jurnal</h1><p>Litsenziya va hisob bo'yicha barcha hodisalar</p></div></div>
<form class="toolbar" method="get"><select class="in" style="max-width:260px" name="action" aria-label="Hodisa turi" onchange="this.form.submit()"><option value="">Barcha hodisalar</option><?php foreach (Ui::ACTION_UZ as $k => $v): ?><option value="<?= e($k) ?>" <?= $action === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select><noscript><button class="btn" type="submit">Ko'rsatish</button></noscript></form>
<section class="card"><?= View::render('_events', ['rows' => $rows, 'showCustomer' => true]) ?></section>
<?= Ui::pager($page, $pages, http_build_query(array_filter(['action' => $action]))) ?>
