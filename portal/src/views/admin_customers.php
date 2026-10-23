<?php /** @var array $rows @var string $q @var int $page @var int $pages @var int $total */ ?>
<div class="page-h"><div><h1>Korxonalar</h1><p><?= $total ?> ta korxona</p></div><a class="btn btn-primary" href="<?= e(Util::url('/admin/customers/new')) ?>"><?= Ui::icon('plus') ?> Yangi korxona</a></div>
<form class="toolbar" method="get"><input class="in grow" style="max-width:360px" name="q" value="<?= e($q) ?>" placeholder="Nomi, email, STIR yoki telefon" aria-label="Qidirish"><button class="btn" type="submit">Qidirish</button></form>
<section class="card">
<?php if (!$rows): ?><div class="empty">Korxona topilmadi</div><?php else: ?>
<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Korxona</th><th>Aloqa</th><th>Litsenziyalar</th><th>Xodimlar</th><th>Qo'shilgan</th></tr></thead><tbody>
<?php foreach ($rows as $c): ?>
  <tr>
    <td><a href="<?= e(Util::url('/admin/customers/' . $c['id'])) ?>"><b><?= e($c['name']) ?></b></a> <?= $c['status'] === 'blocked' ? '<span class="badge bad">Bloklangan</span>' : '' ?><div class="muted small"><?= e($c['city']) ?><?= $c['stir'] !== '' ? ' · STIR ' . e($c['stir']) : '' ?></div></td>
    <td class="small"><?= e($c['email']) ?><div class="muted"><?= e($c['phone']) ?></div></td>
    <td><?= (int)$c['lic_count'] ?></td><td><?= (int)$c['user_count'] ?></td>
    <td class="nowrap muted"><?= e(Util::date((int)$c['created_at'])) ?> <span class="small">(<?= $c['source'] === 'signup' ? "o'zi" : 'xodim' ?>)</span></td>
  </tr>
<?php endforeach; ?></tbody></table></div>
<?php endif; ?></section>
<?= Ui::pager($page, $pages, http_build_query(['q' => $q])) ?>
