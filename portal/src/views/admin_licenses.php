<?php /** @var array $rows @var string $q @var string $f @var int $tool @var array $tools @var int $page @var int $pages @var int $total */
$qs = fn(array $o) => http_build_query(array_filter(['q' => $o['q'] ?? $q, 'f' => $o['f'] ?? $f, 'tool' => $o['tool'] ?? $tool], static fn($v) => $v !== '' && $v !== 0));
?>
<div class="page-h"><div><h1>Litsenziyalar</h1><p><?= $total ?> ta</p></div><a class="btn btn-primary" href="<?= e(Util::url('/admin/licenses/new')) ?>"><?= Ui::icon('plus') ?> Litsenziya berish</a></div>
<div class="chips" style="margin-bottom:12px">
<?php foreach (['' => 'Hammasi', 'active' => 'Faol pullik', 'trial' => 'Sinov', 'expiring' => '30 kunda tugaydi', 'expired' => 'Muddati tugagan', 'suspended' => "To'xtatilgan", 'revoked' => 'Bekor qilingan'] as $k => $v): ?>
  <a class="chip <?= $f === $k ? 'on' : '' ?>" href="?<?= e($qs(['f' => $k])) ?>"><?= e($v) ?></a>
<?php endforeach; ?>
</div>
<form class="toolbar" method="get">
  <input type="hidden" name="f" value="<?= e($f) ?>">
  <input class="in" style="max-width:320px" name="q" value="<?= e($q) ?>" placeholder="Kalit yoki korxona nomi" aria-label="Qidirish">
  <select class="in" style="max-width:220px" name="tool" aria-label="Mahsulot"><option value="0">Barcha mahsulotlar</option><?php foreach ($tools as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $tool === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select>
  <button class="btn" type="submit">Qidirish</button>
</form>
<section class="card">
<?php if (!$rows): ?><div class="empty">Litsenziya topilmadi</div><?php else: ?>
<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Kalit</th><th>Korxona</th><th>Mahsulot</th><th>Tur</th><th>Holat</th><th>Muddat</th><th>O'rindiq</th></tr></thead><tbody>
<?php foreach ($rows as $l): ?>
  <tr>
    <td><a href="<?= e(Util::url('/admin/licenses/' . $l['id'])) ?>"><code><?= e($l['license_key']) ?></code></a></td>
    <td><a href="<?= e(Util::url('/admin/customers/' . $l['customer_id'])) ?>"><?= e($l['customer_name']) ?></a></td>
    <td><?= e($l['tool_name']) ?><div class="muted small"><?= e($l['plan_name']) ?></div></td>
    <td><?= Ui::type($l['type']) ?></td><td><?= Ui::state($l['state']) ?></td>
    <td class="nowrap small"><?= e(Util::date($l['valid_until'] !== null ? (int)$l['valid_until'] : null)) ?><div class="muted"><?= e(Ui::daysText($l)) ?></div></td>
    <td><?= (int)$l['seats_used'] ?> / <?= (int)$l['seats'] ?></td>
  </tr>
<?php endforeach; ?></tbody></table></div>
<?php endif; ?></section>
<?= Ui::pager($page, $pages, $qs([])) ?>
