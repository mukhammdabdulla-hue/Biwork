<?php /** @var array $rows @var string $status */ ?>
<div class="page-h"><div><h1>Sotib olish so'rovlari</h1><p>Mijozlar portal orqali yuborgan so'rovlar. Hisob-faktura yuborib, to'lovdan so'ng litsenziya bering.</p></div></div>
<div class="chips" style="margin-bottom:16px">
<?php foreach (['new' => 'Yangi', 'handled' => 'Bajarilgan', 'rejected' => 'Rad etilgan', 'all' => 'Hammasi'] as $k => $v): ?>
  <a class="chip <?= $status === $k ? 'on' : '' ?>" href="?status=<?= $k ?>"><?= $v ?></a>
<?php endforeach; ?>
</div>
<section class="card">
<?php if (!$rows): ?><div class="empty">So'rovlar yo'q</div><?php else: ?>
<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Sana</th><th>Korxona</th><th>Mahsulot / tarif</th><th>Aloqa</th><th>Izoh</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?>
  <tr>
    <td class="nowrap"><?= e(Util::date((int)$r['created_at'])) ?></td>
    <td><a href="<?= e(Util::url('/admin/customers/' . $r['customer_id'])) ?>"><b><?= e($r['customer_name']) ?></b></a></td>
    <td><?= e($r['tool_name']) ?><div class="muted small"><?= e($r['plan_code'] ?: '—') ?> · <?= (int)$r['seats'] ?> o'rindiq</div></td>
    <td class="small"><?= e($r['user_name'] ?? '') ?><div class="muted"><?= e($r['user_email'] ?? '') ?> <?= e($r['user_phone'] ?? '') ?></div></td>
    <td class="muted small" style="max-width:260px"><?= e($r['comment']) ?></td>
    <td class="right nowrap">
      <?php if ($r['status'] === 'new'): ?>
        <a class="btn btn-sm btn-primary" href="<?= e(Util::url('/admin/licenses/new?request=' . $r['id'])) ?>">Litsenziya berish</a>
        <form method="post" action="<?= e(Util::url('/admin/requests/' . $r['id'] . '/status')) ?>" class="inline" data-confirm="So'rovni rad etamizmi?"><?= Ui::csrf() ?><input type="hidden" name="status" value="rejected"><button class="btn btn-sm" type="submit">Rad etish</button></form>
      <?php else: ?>
        <span class="badge <?= $r['status'] === 'handled' ? 'ok' : 'bad' ?>"><?= $r['status'] === 'handled' ? 'Bajarilgan' : 'Rad etilgan' ?></span>
        <?php if ($r['license_id']): ?><a class="small" href="<?= e(Util::url('/admin/licenses/' . $r['license_id'])) ?>">Litsenziya</a><?php endif; ?>
      <?php endif; ?>
    </td>
  </tr>
<?php endforeach; ?></tbody></table></div>
<?php endif; ?></section>
